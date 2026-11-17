<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * What may be written to WHMCS's Activity Log: phone numbers with only their last digits, never an API
 * key, never the words of a text.
 */
final class Redact
{
    /**
     * A phone number with all but its last $shown digits hidden: "+14155550123" → "+•••••••0123".
     */
    public static function number($number, int $shown = 4): string
    {
        $number = trim((string) $number);
        if ($number === '' || strpos($number, '•') !== false) {
            return $number;
        }
        $digits = (string) preg_replace('/\D+/', '', $number);
        if ($digits === '') {
            return self::cut($number, 12);
        }
        $visible = substr($digits, -max(1, $shown));
        $hidden = max(0, strlen($digits) - strlen($visible));

        return (strpos($number, '+') === 0 ? '+' : '') . str_repeat('•', min($hidden, 15)) . $visible;
    }

    /**
     * A text safe for a log: API keys removed, long digit runs (phone numbers) masked.
     */
    public static function text($text): string
    {
        $text = (string) $text;
        $text = (string) preg_replace('/\b(sk|rk)_(live|test)_[A-Za-z0-9_*]+/', '[API key]', $text);
        $text = (string) preg_replace('/(?<![A-Za-z0-9])(?:key|token|api[_-]?key)\s*[=:]\s*\S+/i', '[API key]', $text);

        return (string) preg_replace_callback('/\+?\d[\d\s().\-]{4,}\d/', function ($match) {
            $found = trim($match[0]);
            // IP addresses and dates are not phone numbers.
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $found) || preg_match('/^\d{4}-\d{2}-\d{2}/', $found)) {
                return $match[0];
            }
            $digits = preg_replace('/\D+/', '', $found);

            // Invoice numbers, HTTP statuses and ports stay; anything as long as a phone number is masked.
            return strlen((string) $digits) >= 8 ? self::number($found, 2) : $match[0];
        }, $text);
    }

    private static function cut(string $text, int $length): string
    {
        return function_exists('mb_substr') ? (string) mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }
}
