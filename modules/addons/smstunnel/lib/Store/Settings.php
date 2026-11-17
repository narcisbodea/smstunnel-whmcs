<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Store;

use SmsTunnel\Whmcs\Api\Client;
use SmsTunnel\Whmcs\Text\Events;
use SmsTunnel\Whmcs\Whmcs\Platform;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * The module's settings, in mod_smstunnel_settings (one row per setting, the value as JSON).
 *
 * They live in the module's own table rather than in WHMCS's addon settings, so deactivating the
 * module keeps them (unless "Delete all data on deactivate" is ticked). The API key is stored
 * encrypted with WHMCS's encrypt() and is never shown back in full.
 */
class Settings
{
    const API_KEY = 'api_key';
    const API_URL = 'api_url';
    const DEVICE_ID = 'device_id';
    const STAFF_NUMBERS = 'staff_numbers';
    const OPT_IN = 'opt_in';
    const FIELD_OPT_OUT = 'field_opt_out';
    const FIELD_OPT_IN = 'field_opt_in';
    const LOG_DAYS = 'log_days';
    const DELETE_ON_DEACTIVATE = 'delete_on_deactivate';
    const TWO_FACTOR_SECRET = 'two_factor_secret';
    const SCHEMA_VERSION = 'schema_version';

    const DEFAULT_LOG_DAYS = 90;
    const MAX_STAFF_NUMBERS = 10;

    /** @var Platform */
    private $platform;

    /** @var array<string, mixed>|null */
    private $values;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
    }

    public function platform(): Platform
    {
        return $this->platform;
    }

    /**
     * Every stored setting (read once per request).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->values === null) {
            $this->values = array();
            foreach (Capsule::table(Schema::SETTINGS)->get() as $row) {
                $decoded = json_decode((string) $row->value, true);
                $this->values[(string) $row->setting] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }
        }

        return $this->values;
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $values = $this->all();

        return array_key_exists($key, $values) && $values[$key] !== null ? $values[$key] : $default;
    }

    /**
     * Saves one setting now.
     *
     * @param mixed $value anything json_encode() takes
     */
    public function set(string $key, $value)
    {
        // Default flags: non-ASCII characters are escaped, so a template with emoji fits any MySQL charset.
        $json = json_encode($value);
        if (!is_string($json)) {
            throw new \InvalidArgumentException('The setting ' . $key . ' cannot be stored.');
        }
        $query = Capsule::table(Schema::SETTINGS)->where('setting', $key);
        if ($query->exists()) {
            Capsule::table(Schema::SETTINGS)->where('setting', $key)->update(array('value' => $json));
        } else {
            try {
                Capsule::table(Schema::SETTINGS)->insert(array('setting' => $key, 'value' => $json));
            } catch (\Exception $e) {
                // Inserted meanwhile by another request.
                Capsule::table(Schema::SETTINGS)->where('setting', $key)->update(array('value' => $json));
            }
        }
        $this->all();
        $this->values[$key] = $value;
    }

    /** Forgets what was read, so the next call reads the table again. */
    public function reload()
    {
        $this->values = null;
    }

    /** Stores the settings a fresh installation starts with; existing ones stay. */
    public function installDefaults()
    {
        $values = $this->all();
        $defaults = array(
            self::API_URL => Client::DEFAULT_URL,
            self::DEVICE_ID => '',
            self::STAFF_NUMBERS => array(),
            self::OPT_IN => false,
            self::LOG_DAYS => self::DEFAULT_LOG_DAYS,
            self::DELETE_ON_DEACTIVATE => false,
            self::SCHEMA_VERSION => Schema::VERSION,
        );
        foreach (Events::keys() as $event) {
            $defaults['event.' . $event] = array('on' => Events::defaultOn($event), 'text' => '');
        }
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $values)) {
                $this->set($key, $value);
            }
        }
    }

    public function apiKey(): string
    {
        $stored = $this->get(self::API_KEY, '');

        return is_string($stored) && $stored !== '' ? trim($this->platform->decrypt($stored)) : '';
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    /** Saves a new API key, encrypted; an empty key removes it. */
    public function setApiKey(string $key)
    {
        $key = (string) preg_replace('/\s+/', '', $key);
        $this->set(self::API_KEY, $key === '' ? '' : $this->platform->encrypt($key));
    }

    /**
     * The key as the settings page shows it: its kind and last 4 characters only.
     */
    public static function mask(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $prefix = preg_match('/^((?:sk|rk)_(?:live|test)_)/', $key, $match) ? $match[1] : '';
        $tail = strlen($key) > 12 ? substr($key, -4) : '';

        return $prefix . str_repeat('•', 8) . $tail;
    }

    public function maskedApiKey(): string
    {
        return self::mask($this->apiKey());
    }

    public function apiUrl(): string
    {
        $url = rtrim(trim((string) $this->get(self::API_URL, '')), '/');

        return $url !== '' ? $url : Client::DEFAULT_URL;
    }

    /** The phone that sends, '' for any of the account's phones. */
    public function deviceId(): string
    {
        return trim((string) $this->get(self::DEVICE_ID, ''));
    }

    /**
     * The numbers that get staff alerts (E.164), at most 10.
     *
     * @return string[]
     */
    public function staffNumbers(): array
    {
        $numbers = $this->get(self::STAFF_NUMBERS, array());
        if (!is_array($numbers)) {
            return array();
        }

        return array_slice(array_values(array_filter($numbers, function ($number) {
            return is_string($number) && preg_match('/^\+[1-9]\d{6,14}$/', $number);
        })), 0, self::MAX_STAFF_NUMBERS);
    }

    /** True when only clients who ticked "Send me text messages" get texts. */
    public function optIn(): bool
    {
        return $this->get(self::OPT_IN, false) === true;
    }

    public function logDays(): int
    {
        $days = (int) $this->get(self::LOG_DAYS, self::DEFAULT_LOG_DAYS);

        return $days >= 1 ? min($days, 3650) : self::DEFAULT_LOG_DAYS;
    }

    public function deleteOnDeactivate(): bool
    {
        return $this->get(self::DELETE_ON_DEACTIVATE, false) === true;
    }

    /**
     * @return array{on: bool, text: string}
     */
    private function event(string $event): array
    {
        $stored = $this->get('event.' . $event, null);
        $on = is_array($stored) && array_key_exists('on', $stored) ? $stored['on'] === true : Events::defaultOn($event);
        $text = is_array($stored) && isset($stored['text']) && is_string($stored['text']) ? $stored['text'] : '';

        return array('on' => $on, 'text' => $text);
    }

    public function eventOn(string $event): bool
    {
        $state = $this->event($event);

        return $state['on'];
    }

    /** The text written for an event, '' for the suggested one. */
    public function customText(string $event): string
    {
        $state = $this->event($event);

        return trim($state['text']);
    }

    /** The text an event sends: the one written for it, or the suggested one. */
    public function eventText(string $event): string
    {
        $custom = $this->customText($event);

        return $custom !== '' ? $custom : Events::defaultText($event);
    }

    /**
     * @param string $text the text; '' or the suggested text itself keeps the suggestion (and its later improvements)
     */
    public function setEvent(string $event, bool $on, string $text)
    {
        $text = trim(str_replace(array("\r\n", "\r"), "\n", \SmsTunnel\Whmcs\Send\OutgoingText::utf8($text)));
        if ($text === Events::defaultText($event)) {
            $text = '';
        }
        $this->set('event.' . $event, array('on' => $on, 'text' => $text));
    }

    /**
     * The secret that keys the hashes of sign-in codes, made on first use and stored encrypted.
     */
    public function twoFactorSecret(): string
    {
        $stored = $this->get(self::TWO_FACTOR_SECRET, '');
        $secret = is_string($stored) && $stored !== '' ? $this->platform->decrypt($stored) : '';
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->set(self::TWO_FACTOR_SECRET, $this->platform->encrypt($secret));
        }

        return $secret;
    }
}
