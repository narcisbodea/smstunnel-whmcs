<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Send;

use SmsTunnel\Whmcs\Api\ApiException;
use SmsTunnel\Whmcs\Api\Client;
use SmsTunnel\Whmcs\Api\SendResult;
use SmsTunnel\Whmcs\Redact;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;
use SmsTunnel\Whmcs\Version;
use SmsTunnel\Whmcs\Whmcs\Platform;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Sends texts and tries them again when that is safe (see RetryPolicy).
 *
 * Every text gets its own Idempotency-Key, stored with it before the first attempt; every later attempt
 * sends exactly the same request with the same key, so SMSTunnel sends the text at most once even when
 * an answer was lost on the way back.
 *
 * Within one PHP request, the first error that is not about the text itself (SMSTunnel cannot be
 * reached, answers 5xx or 429, or refuses the key) stops further attempts: the next texts of the same
 * page or cron run wait for the cron instead (or fail at once for a refused key), so a page waits for
 * at most one timeout.
 */
class Outbox
{
    /** Texts one cron run retries at most (SMSTunnel takes 30 send requests a minute per key). */
    const RUN_LIMIT = 20;

    /**
     * Per SMSTunnel address, the first such error of this PHP request.
     *
     * @var array<string, array{retry: bool, error: string, retryAfter: int|null}>
     */
    private static $circuits = array();

    /** @var Settings */
    private $settings;

    /** @var TextLog */
    private $log;

    /** @var Platform */
    private $platform;

    /** @var array<string, mixed> */
    private $clientOptions;

    /**
     * @param array<string, mixed> $clientOptions options for Api\Client (tests pass a transport)
     */
    public function __construct(Settings $settings, TextLog $log, Platform $platform, array $clientOptions = array())
    {
        $this->settings = $settings;
        $this->log = $log;
        $this->platform = $platform;
        $this->clientOptions = $clientOptions;
    }

    /** Forgets the errors this PHP request met (long-running scripts and tests). */
    public static function reset()
    {
        self::$circuits = array();
    }

    /** A new Idempotency-Key: unique per text. */
    public static function newKey(): string
    {
        return 'whmcs-' . bin2hex(random_bytes(16));
    }

    public function client(): Client
    {
        return new Client($this->settings->apiKey(), $this->settings->apiUrl(), $this->clientOptions + array(
            'userAgent' => Version::userAgent($this->platform->whmcsVersion()),
        ));
    }

    /**
     * Logs a text that is not sent, and why ("opted out", "number: invalid"...).
     *
     * @return int the row id, 0 when the same event was logged already
     */
    public function skip(OutgoingText $text, string $reason): int
    {
        return $this->log->create(array(
            'event' => $text->event,
            'audience' => $text->audience,
            'client_id' => $text->clientId,
            'related_type' => $text->relatedType,
            'related_id' => $text->relatedId,
            'recipient' => $text->loggedRecipient !== '' ? $text->loggedRecipient : $text->to,
            'status' => TextLog::SKIPPED,
            'error' => $reason,
            'dedupe_key' => $text->dedupeKey,
        ));
    }

    /**
     * Records a text and sends it now; when that fails in a way worth retrying, the cron retries it.
     *
     * @return int the row id, 0 when this text was handled already (the same event again)
     */
    public function deliver(OutgoingText $text): int
    {
        $body = $text->body($this->settings->deviceId());
        $key = self::newKey();
        $id = $this->log->create(array(
            'event' => $text->event,
            'audience' => $text->audience,
            'client_id' => $text->clientId,
            'related_type' => $text->relatedType,
            'related_id' => $text->relatedId,
            'recipient' => $text->loggedRecipient !== '' ? $text->loggedRecipient : $text->to,
            'status' => TextLog::SENDING,
            'endpoint' => $text->endpoint,
            'idempotency_key' => $key,
            // Default flags: escaped non-ASCII characters fit any MySQL character set.
            'request' => json_encode($body),
            'dedupe_key' => $text->dedupeKey,
        ));
        if ($id === 0) {
            return 0;
        }
        $this->attempt($id, $body, $key, $text->endpoint, true);

        return $id;
    }

    /**
     * Sends a text once, now, without retries and without keeping its words (the test text, sign-in
     * codes): the person who asked for it is waiting for the answer.
     *
     * @return array{status: string, messageId: string, error: string, logId: int, exception: \Throwable|null}
     */
    public function sendOnce(OutgoingText $text): array
    {
        $body = $text->body($this->settings->deviceId());
        $key = self::newKey();
        $id = $this->log->create(array(
            'event' => $text->event,
            'audience' => $text->audience,
            'client_id' => $text->clientId,
            'related_type' => $text->relatedType,
            'related_id' => $text->relatedId,
            'recipient' => $text->loggedRecipient !== '' ? $text->loggedRecipient : $text->to,
            'status' => TextLog::SENDING,
            'endpoint' => $text->endpoint,
            'idempotency_key' => $key,
            'request' => null,
        ));
        if ($id > 0) {
            $this->log->claim($id, true);
        }
        try {
            $result = $this->call($text->endpoint, $body, $key);
        } catch (\Throwable $e) {
            $error = self::describe($e);
            if ($id > 0) {
                $this->log->finish($id, TextLog::FAILED, '', $error);
            }

            return array('status' => TextLog::FAILED, 'messageId' => '', 'error' => $error, 'logId' => $id, 'exception' => $e);
        }
        $status = $result->queued ? TextLog::QUEUED : TextLog::SENT;
        if ($id > 0) {
            $this->log->finish($id, $status, $result->messageId, '');
        }

        return array('status' => $status, 'messageId' => $result->messageId, 'error' => '', 'logId' => $id, 'exception' => null);
    }

    /**
     * The cron: tries again the texts that are due, oldest first, at most RUN_LIMIT of them.
     *
     * @return array{tried: int, sent: int, retry: int, failed: int}
     */
    public function retryDue(int $limit = self::RUN_LIMIT): array
    {
        $stats = array('tried' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0);
        if (!$this->settings->hasApiKey()) {
            return $stats;
        }
        $now = $this->platform->now();
        foreach ($this->log->due($limit) as $row) {
            if (isset(self::$circuits[$this->settings->apiUrl()])) {
                // SMSTunnel cannot be reached, is failing or limits the rate: the rest waits for the next run.
                break;
            }
            $id = (int) $row['id'];
            $body = json_decode((string) $row['request'], true);
            $key = (string) $row['idempotency_key'];
            if (!is_array($body) || $key === '') {
                $this->log->finish($id, TextLog::FAILED, '', 'Not retried: the request of this text is missing.');
                ++$stats['failed'];
                continue;
            }
            $first = $row['first_attempt_at'] !== null ? strtotime((string) $row['first_attempt_at']) : false;
            if ((int) $row['attempts'] >= RetryPolicy::MAX_ATTEMPTS || ($first !== false && $now > $first + RetryPolicy::WINDOW)) {
                $this->log->finish($id, TextLog::FAILED, '', sprintf('Gave up after %d tries: %s', (int) $row['attempts'], (string) $row['error']));
                ++$stats['failed'];
                continue;
            }
            $status = $this->attempt($id, $body, $key, (string) $row['endpoint'], false);
            if ($status === '') {
                // Another cron run took it.
                continue;
            }
            ++$stats['tried'];
            if ($status === TextLog::SENT || $status === TextLog::QUEUED) {
                ++$stats['sent'];
            } elseif ($status === TextLog::RETRY) {
                ++$stats['retry'];
            } else {
                ++$stats['failed'];
            }
        }

        return $stats;
    }

    /**
     * One attempt of a logged text.
     *
     * @param array<string, string> $body
     *
     * @return string the text's state after it, or '' when another request has it
     */
    private function attempt(int $id, array $body, string $key, string $endpoint, bool $fresh): string
    {
        $url = $this->settings->apiUrl();
        if (isset(self::$circuits[$url])) {
            $circuit = self::$circuits[$url];
            if ($circuit['retry']) {
                $wait = max(RetryPolicy::BACKOFF[0], (int) $circuit['retryAfter']);
                $this->log->retryAt($id, $this->platform->now() + $wait, 'Not tried yet: ' . $circuit['error']);

                return TextLog::RETRY;
            }
            $this->log->finish($id, TextLog::FAILED, '', 'Not sent: ' . $circuit['error']);

            return TextLog::FAILED;
        }
        if (!$this->log->claim($id, $fresh)) {
            return '';
        }
        try {
            $result = $this->call($endpoint, $body, $key);
        } catch (\Throwable $e) {
            return $this->failed($id, $e, $url);
        }
        $status = $result->queued ? TextLog::QUEUED : TextLog::SENT;
        $this->log->finish($id, $status, $result->messageId, '');

        return $status;
    }

    private function failed(int $id, \Throwable $e, string $url): string
    {
        list($kind, $retryAfter, $stopsOthers) = RetryPolicy::classify($e);
        $error = self::describe($e);
        if ($stopsOthers) {
            self::$circuits[$url] = array('retry' => $kind === RetryPolicy::RETRY, 'error' => $error, 'retryAfter' => $retryAfter);
            if ($kind === RetryPolicy::FINAL) {
                $this->platform->logActivity('texts are not going out: ' . $error);
            }
        }
        if ($kind === RetryPolicy::RETRY) {
            $row = $this->log->find($id);
            $attempts = $row !== null ? (int) $row['attempts'] : RetryPolicy::MAX_ATTEMPTS;
            $first = $row !== null && $row['first_attempt_at'] !== null ? strtotime((string) $row['first_attempt_at']) : false;
            $now = $this->platform->now();
            $next = RetryPolicy::nextAttempt($attempts, $retryAfter, $first !== false ? $first : $now, $now);
            if ($next !== null) {
                $this->log->retryAt($id, $next, $error);

                return TextLog::RETRY;
            }
            $this->log->finish($id, TextLog::FAILED, '', sprintf('Gave up after %d tries: %s', $attempts, $error));

            return TextLog::FAILED;
        }
        $this->log->finish($id, TextLog::FAILED, '', $error);

        return TextLog::FAILED;
    }

    /**
     * @param array<string, string> $body
     */
    private function call(string $endpoint, array $body, string $key): SendResult
    {
        $client = $this->client();

        return $endpoint === OutgoingText::SIGN_IN_CODE ? $client->sendSignInCode($body, $key) : $client->sendSms($body, $key);
    }

    /**
     * An error in a few words, safe for the log (no key, numbers masked).
     */
    public static function describe(\Throwable $e): string
    {
        if ($e instanceof ApiException) {
            $code = $e->getErrorCode();
            $text = 'HTTP ' . $e->getStatus() . ($code ? ' ' . $code : '') . ': ' . $e->getMessage();
            if ($e->getRetryAfter() !== null) {
                $text .= ' (retry after ' . $e->getRetryAfter() . ' s)';
            }
            if ($e->isReplayed()) {
                $text .= ' (SMSTunnel kept this answer for the text: check Messages in your dashboard before sending it again)';
            }

            return Redact::text($text);
        }

        return Redact::text($e->getMessage() !== '' ? $e->getMessage() : get_class($e));
    }
}
