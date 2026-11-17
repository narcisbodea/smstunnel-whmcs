<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\TwoFactor;

use SmsTunnel\Whmcs\Redact;
use SmsTunnel\Whmcs\Send\OutgoingText;
use SmsTunnel\Whmcs\Send\Outbox;
use SmsTunnel\Whmcs\Store\Schema;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;
use SmsTunnel\Whmcs\Text\Events;
use SmsTunnel\Whmcs\Text\Template;
use SmsTunnel\Whmcs\Whmcs\Platform;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Sign-in codes by text message, for the Two-Factor Authentication module.
 *
 * - A code is 6 random digits (random_int), sent with POST /api/v1/sms/send-2fa, valid 10 minutes,
 *   for one use, in the browser session that asked for it.
 * - Only a keyed hash of the code is stored (HMAC-SHA256 with a secret kept encrypted by WHMCS), and
 *   typed codes are compared with hash_equals(). No code is ever written to a log, and the text of a
 *   sign-in code is never stored.
 * - 5 wrong tries end a code. A user gets at most 5 codes in 30 minutes, at least 60 seconds apart;
 *   a number gets at most 5 codes an hour, and the whole WHMCS at most 20 setup codes an hour.
 */
class SignInCodes
{
    const LENGTH = 6;
    const TTL = 600;
    const MAX_ATTEMPTS = 5;
    const COOLDOWN = 60;
    const MAX_PER_WINDOW = 5;
    const WINDOW = 1800;

    /** Codes to one number in an hour, whoever asks for them. */
    const MAX_PER_NUMBER = 5;

    /** Setup codes in an hour, for the whole WHMCS. */
    const MAX_SETUPS_PER_HOUR = 20;

    const LOGIN = 'login';
    const SETUP = 'setup';

    /** Where the session keeps its nonce (codes are bound to it) and the number being set up. */
    const SESSION = 'smstunnel2fa';

    /** @var Settings */
    private $settings;

    /** @var Platform */
    private $platform;

    /** @var Outbox */
    private $outbox;

    public function __construct(Settings $settings, Platform $platform, Outbox $outbox)
    {
        $this->settings = $settings;
        $this->platform = $platform;
        $this->outbox = $outbox;
    }

    /**
     * A stable key for the person signing in, from WHMCS's user_info (staff or client user).
     *
     * @param mixed $userInfo
     */
    public static function userKey($userInfo): string
    {
        $info = is_array($userInfo) ? $userInfo : array();
        $parts = array();
        foreach (array('id', 'email', 'username') as $field) {
            $parts[$field] = isset($info[$field]) && is_scalar($info[$field]) ? strtolower(trim((string) $info[$field])) : '';
        }
        if ($parts['id'] === '' && $parts['email'] === '' && $parts['username'] === '') {
            return '';
        }

        return hash('sha256', 'smstunnel2fa|' . json_encode($parts));
    }

    /**
     * Texts a new code, unless a valid one was sent to this session already (then nothing is sent).
     *
     * @param bool $again true when the person asked for a new code
     *
     * @return array{ok: bool, sent: bool, error: string, sentAt: int}
     */
    public function issue(string $userKey, string $phone, string $purpose, bool $again = false): array
    {
        if ($userKey === '' || !preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
            return self::result(false, false, 'No mobile number is saved for sign-in codes.', 0);
        }
        if (!$this->settings->hasApiKey()) {
            return self::result(false, false, 'Sign-in codes by text message are not set up yet (SMSTunnel for WHMCS has no API key).', 0);
        }
        Schema::installCodes();
        $now = $this->platform->now();
        $session = $this->sessionKey();
        $phoneHash = hash('sha256', $phone);
        $active = $this->active($userKey, $session, $purpose, $phoneHash);
        if ($active !== null) {
            $sentAt = (int) strtotime((string) $active->created_at);
            if (!$again || $now - $sentAt < self::COOLDOWN) {
                return self::result(true, false, $again ? sprintf('A code was sent less than a minute ago. Wait %d seconds for a new one.', max(1, self::COOLDOWN - ($now - $sentAt))) : '', $sentAt);
            }
        }
        $recent = Capsule::table(Schema::CODES)->where('user_key', $userKey)->where('created_at', '>', date('Y-m-d H:i:s', $now - self::WINDOW))->count();
        if ($recent >= self::MAX_PER_WINDOW) {
            return self::result(false, false, 'Too many codes were sent. Wait a few minutes, or use your backup code.', 0);
        }
        $last = Capsule::table(Schema::CODES)->where('user_key', $userKey)->orderBy('id', 'desc')->first();
        if ($last !== null && $now - (int) strtotime((string) $last->created_at) < self::COOLDOWN) {
            return self::result(false, false, 'A code was sent less than a minute ago. Wait a moment and try again.', 0);
        }
        // Anyone who can sign up can type any number into the setup form: limits per number, whoever asks,
        // and for all setups together, keep the form from texting strangers or using up the plan.
        $hourAgo = date('Y-m-d H:i:s', $now - 3600);
        if (Capsule::table(Schema::CODES)->where('phone_hash', $phoneHash)->where('created_at', '>', $hourAgo)->count() >= self::MAX_PER_NUMBER) {
            return self::result(false, false, 'Too many codes were sent to this number in the last hour. Wait, or use your backup code.', 0);
        }
        if ($purpose === self::SETUP && Capsule::table(Schema::CODES)->where('purpose', self::SETUP)->where('created_at', '>', $hourAgo)->count() >= self::MAX_SETUPS_PER_HOUR) {
            return self::result(false, false, 'Too many sign-in code setups right now. Try again in an hour.', 0);
        }

        $code = str_pad((string) random_int(0, (int) str_repeat('9', self::LENGTH)), self::LENGTH, '0', STR_PAD_LEFT);
        // Only the newest code of a session counts.
        Capsule::table(Schema::CODES)->where('user_key', $userKey)->where('session_key', $session)->where('purpose', $purpose)->whereNull('used_at')
            ->update(array('used_at' => date('Y-m-d H:i:s', $now)));
        $id = (int) Capsule::table(Schema::CODES)->insertGetId(array(
            'user_key' => $userKey,
            'session_key' => $session,
            'purpose' => $purpose,
            'phone_hash' => $phoneHash,
            'code_hash' => $this->hash($code, $userKey, $purpose),
            'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', $now + self::TTL),
            'created_at' => date('Y-m-d H:i:s', $now),
            'used_at' => null,
        ));

        $text = new OutgoingText(Events::SIGN_IN_CODE, 'user');
        $text->to = $phone;
        $text->message = self::message($code, $this->platform->companyName(), $this->host());
        $text->endpoint = OutgoingText::SIGN_IN_CODE;
        $text->loggedRecipient = Redact::number($phone);
        $result = $this->outbox->sendOnce($text);
        unset($code, $text);
        if ($result['status'] !== TextLog::SENT && $result['status'] !== TextLog::QUEUED) {
            Capsule::table(Schema::CODES)->where('id', $id)->update(array('used_at' => date('Y-m-d H:i:s', $now)));

            return self::result(false, false, 'The code could not be sent: ' . $result['error'], 0);
        }

        return self::result(true, true, '', $now);
    }

    /**
     * Checks a typed code: right, not expired, not used, fewer than 5 wrong tries, same session and number.
     */
    public function verify(string $userKey, string $phone, string $purpose, string $typed): bool
    {
        $typed = (string) preg_replace('/\D+/', '', $typed);
        if ($userKey === '' || $phone === '' || $typed === '' || strlen($typed) > 10) {
            return false;
        }
        Schema::installCodes();
        $row = $this->active($userKey, $this->sessionKey(), $purpose, hash('sha256', $phone));
        if ($row === null) {
            return false;
        }
        $attempts = (int) $row->attempts;
        // Count the try first, so two requests at once cannot both get a try in for free.
        $counted = Capsule::table(Schema::CODES)->where('id', $row->id)->where('attempts', $attempts)->whereNull('used_at')
            ->update(array('attempts' => $attempts + 1));
        if ($counted < 1) {
            return false;
        }
        if (!hash_equals((string) $row->code_hash, $this->hash($typed, $userKey, $purpose))) {
            return false;
        }

        // One use only.
        return Capsule::table(Schema::CODES)->where('id', $row->id)->whereNull('used_at')
            ->update(array('used_at' => $this->platform->nowSql())) > 0;
    }

    /** The time the session's valid code was sent (0 for none), for the screens. */
    public function pendingSince(string $userKey, string $phone, string $purpose): int
    {
        if ($userKey === '' || $phone === '') {
            return 0;
        }
        Schema::installCodes();
        $row = $this->active($userKey, $this->sessionKey(), $purpose, hash('sha256', $phone));

        return $row !== null ? (int) strtotime((string) $row->created_at) : 0;
    }

    /** Removes codes older than a day (the daily cron). */
    public function purge()
    {
        if (Capsule::schema()->hasTable(Schema::CODES)) {
            Capsule::table(Schema::CODES)->where('created_at', '<', $this->platform->nowSql(-86400))->delete();
        }
    }

    /**
     * The text of a sign-in code: the code first (phones offer it for autofill), at most 320
     * characters, and the line browsers use to fill the code in on the WHMCS site (WebOTP).
     */
    public static function message(string $code, string $company, string $host): string
    {
        $company = Template::value($company, 50);
        $text = $code . ' is your sign-in code' . ($company !== '' ? ' for ' . $company : '') . '. It expires in ' . (int) (self::TTL / 60) . ' minutes. Never share it.';
        if (preg_match('/^[a-z0-9.-]{1,100}$/i', $host)) {
            $text .= "\n\n@" . strtolower($host) . ' #' . $code;
        }

        return Template::cut($text, 320);
    }

    /** The WHMCS site's host name, from the System URL. */
    private function host(): string
    {
        $host = parse_url($this->platform->systemUrl(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * The session's newest code that can still be typed.
     *
     * @return object|null
     */
    private function active(string $userKey, string $session, string $purpose, string $phoneHash)
    {
        return Capsule::table(Schema::CODES)
            ->where('user_key', $userKey)
            ->where('session_key', $session)
            ->where('purpose', $purpose)
            ->where('phone_hash', $phoneHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', $this->platform->nowSql())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id', 'desc')
            ->first();
    }

    private function hash(string $code, string $userKey, string $purpose): string
    {
        return hash_hmac('sha256', $purpose . '|' . $userKey . '|' . $code, $this->settings->twoFactorSecret());
    }

    /**
     * The hash of this browser session's nonce: a code works only in the session it was sent to.
     */
    private function sessionKey(): string
    {
        if (!isset($_SESSION[self::SESSION]) || !is_array($_SESSION[self::SESSION])) {
            $_SESSION[self::SESSION] = array();
        }
        if (!isset($_SESSION[self::SESSION]['nonce']) || !is_string($_SESSION[self::SESSION]['nonce']) || strlen($_SESSION[self::SESSION]['nonce']) < 32) {
            $_SESSION[self::SESSION]['nonce'] = bin2hex(random_bytes(24));
        }

        return hash('sha256', $_SESSION[self::SESSION]['nonce']);
    }

    /**
     * @return array{ok: bool, sent: bool, error: string, sentAt: int}
     */
    private static function result(bool $ok, bool $sent, string $error, int $sentAt): array
    {
        return array('ok' => $ok, 'sent' => $sent, 'error' => $error, 'sentAt' => $sentAt);
    }
}
