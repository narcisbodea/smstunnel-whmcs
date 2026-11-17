<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Api;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * SMSTunnel answered, but with an error (read the way the SMSTunnel SDKs read it).
 *
 * - 400: the request is wrong (a number SMSTunnel cannot text, no code in a sign-in text...).
 * - 401: the API key is wrong, was deleted, or its account is suspended.
 * - 403: the key lacks a permission (code PERMISSION_REQUIRED), or the plan does not include the feature.
 * - 409 IDEMPOTENCY_KEY_IN_PROGRESS: the first request with this Idempotency-Key is still running.
 * - 422 IDEMPOTENCY_KEY_REUSED: the Idempotency-Key was used for another text.
 * - 429: too many requests, or the plan's daily limit (Retry-After says how long to wait).
 * - 5xx: a problem on SMSTunnel's side.
 */
class ApiException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string|null */
    private $errorCode;

    /** @var string|null */
    private $permission;

    /** @var int|null seconds, from Retry-After */
    private $retryAfter;

    /** @var bool the server answered from its Idempotency-Key record (Idempotent-Replayed: true) */
    private $replayed;

    /**
     * @param int                   $status  the HTTP status
     * @param mixed                 $body    the decoded JSON body (array), the raw text, or null
     * @param array<string, string> $headers response headers, names in lower case
     * @param string|null           $message overrides the message read from the body
     */
    public function __construct(int $status, $body = null, array $headers = array(), $message = null)
    {
        $this->status = $status;
        if (is_array($body)) {
            if (isset($body['code']) && is_string($body['code'])) {
                $this->errorCode = $body['code'];
            }
            if (isset($body['permission']) && is_string($body['permission'])) {
                $this->permission = $body['permission'];
            }
        }
        $this->retryAfter = self::parseRetryAfter(isset($headers['retry-after']) ? $headers['retry-after'] : null);
        $this->replayed = isset($headers['idempotent-replayed']) && strtolower(trim($headers['idempotent-replayed'])) === 'true';
        parent::__construct(is_string($message) && $message !== '' ? $message : self::messageOf($body, $status), $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** SMSTunnel's own code, such as PERMISSION_REQUIRED, when it sent one. */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /** With PERMISSION_REQUIRED: the permission the key lacks, such as sms:send. */
    public function getPermission()
    {
        return $this->permission;
    }

    /** Seconds to wait before trying again, when SMSTunnel said (429, 409 in progress). */
    public function getRetryAfter()
    {
        return $this->retryAfter;
    }

    /**
     * True when the answer is the one SMSTunnel stored for this Idempotency-Key: a server error
     * replayed this way stays the answer for that key for 24 hours.
     */
    public function isReplayed(): bool
    {
        return $this->replayed;
    }

    /**
     * Seconds from a Retry-After header: a number of seconds or an HTTP date.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    public static function parseRetryAfter($value)
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,9}$/', $value)) {
            return (int) $value;
        }
        $time = strtotime($value);
        if ($time === false) {
            return null;
        }

        return max(0, $time - time());
    }

    /**
     * The words of an error answer, whatever its shape (the rules of the SMSTunnel SDKs).
     *
     * @param mixed $body
     */
    public static function messageOf($body, int $status): string
    {
        if (is_string($body) && trim($body) !== '') {
            // A proxy's error page, for instance: its text, on one line, at most 300 characters.
            $text = (string) preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $body);
            $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $text), ENT_QUOTES, 'UTF-8')));
            if ($text !== '') {
                return self::cut($text, 300);
            }
        }
        if (is_array($body)) {
            $message = isset($body['message']) ? $body['message'] : null;
            if (is_array($message)) {
                $message = implode('; ', array_filter($message, 'is_string'));
            }
            if (is_string($message) && $message !== '') {
                return self::cut($message, 300);
            }
            if (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                return self::cut($body['error'], 300);
            }
        }

        return 'SMSTunnel returned error: ' . $status;
    }

    private static function cut(string $text, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, $length, 'UTF-8');
        }

        return substr($text, 0, $length);
    }
}
