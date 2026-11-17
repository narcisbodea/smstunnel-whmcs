<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Text;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Fills a text's merge fields and tidies the result: an empty field leaves no double spaces behind,
 * and the text is cut at the 1600 characters SMSTunnel accepts.
 *
 * A field is written {name}; {$name}, the way WHMCS email templates write it, works too. A field the
 * text does not know stays as typed, so a typo shows in the test text instead of vanishing.
 */
final class Template
{
    /** The longest text SMSTunnel accepts (10 joined SMS parts). */
    const MAX_LENGTH = 1600;

    /** The longest value a single field puts into a text (a ticket subject, a product name). */
    const MAX_FIELD_LENGTH = 60;

    /** Fields whose values are never shortened (amounts, links, numbers). */
    const NEVER_SHORTENED = array('invoice_url', 'whmcs_url', 'invoice_total', 'invoice_balance', 'order_total', 'code');

    /**
     * @param string               $template the text with fields such as {client_first_name}
     * @param array<string, mixed> $values   the value of each field, by name without braces
     */
    public static function render(string $template, array $values): string
    {
        $clean = array();
        foreach ($values as $name => $value) {
            $clean[strtolower((string) $name)] = self::value($value, in_array($name, self::NEVER_SHORTENED, true) ? 0 : self::MAX_FIELD_LENGTH);
        }
        // A text saved HTML-encoded (WHMCS encodes request values) reads as typed.
        $text = html_entity_decode(str_replace(array("\r\n", "\r"), "\n", $template), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace_callback('/\{\$?([A-Za-z][A-Za-z0-9_]*)\}/', function ($match) use ($clean) {
            $name = strtolower($match[1]);

            return array_key_exists($name, $clean) ? $clean[$name] : $match[0];
        }, $text);

        $lines = array();
        foreach (explode("\n", $text) as $line) {
            $line = (string) preg_replace('/[ \t]+/', ' ', $line);
            // A field left empty before a comma or a full stop: "Hi , thanks" → "Hi, thanks".
            $line = (string) preg_replace('/ +([,.;:!?])/', '$1', $line);
            // A shortened value ("...") before the text's own full stop.
            $line = (string) preg_replace('/\.{4,}/', '...', $line);
            $lines[] = trim($line);
        }
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return self::cut($text, self::MAX_LENGTH);
    }

    /**
     * The field names a text uses ({name} or {$name}), lower case, each once.
     *
     * @return string[]
     */
    public static function fieldsIn(string $template): array
    {
        preg_match_all('/\{\$?([A-Za-z][A-Za-z0-9_]*)\}/', $template, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * The fields a text uses that are not in $known.
     *
     * @param string[] $known
     *
     * @return string[]
     */
    public static function unknownFields(string $template, array $known): array
    {
        return array_values(array_diff(self::fieldsIn($template), $known));
    }

    /**
     * A field's value as it goes into a text: plain text on one line, HTML entities decoded (WHMCS stores
     * many values HTML-escaped), no-break spaces made plain, at most $max characters (0: no limit).
     *
     * @param mixed $value
     */
    public static function value($value, int $max = self::MAX_FIELD_LENGTH): string
    {
        if (is_bool($value) || !is_scalar($value)) {
            return '';
        }
        $text = (string) $value;
        if (preg_match('//u', $text) !== 1) {
            // Not UTF-8: an old database's Latin-1 ("Andr\xE9" is André).
            $text = function_exists('mb_convert_encoding') ? (string) mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1') : (string) preg_replace('/[\x80-\xFF]/', '?', $text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Tags only: "I <3 hosting" or "a < b" keep their words (strip_tags() would drop them).
        $text = (string) preg_replace('~<(script|style)\b.*?</\1\s*>~is', ' ', $text);
        $text = (string) preg_replace('~</?[A-Za-z][A-Za-z0-9]*(\s[^<>]*)?/?>~', ' ', $text);
        $text = self::plainSpaces($text);
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
        $text = (string) preg_replace('/ {2,}/', ' ', $text);
        if ($max > 0 && self::length($text) > $max) {
            $cut = self::cut($text, $max - 3);
            // End on a whole word when one ends close enough.
            $next = function_exists('mb_substr') ? (string) mb_substr($text, self::length($cut), 1, 'UTF-8') : substr($text, strlen($cut), 1);
            $space = strrpos($cut, ' ');
            if ($next !== ' ' && $space !== false && strlen($cut) - $space <= 15) {
                $cut = substr($cut, 0, $space);
            }
            $text = rtrim($cut, ' ,.;:-') . '...';
        }

        return $text;
    }

    /**
     * The text, cut to at most $length characters.
     */
    public static function cut(string $text, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $length ? rtrim((string) mb_substr($text, 0, $length, 'UTF-8')) : $text;
        }

        return strlen($text) > $length ? rtrim(substr($text, 0, $length)) : $text;
    }

    /** Characters, not bytes. */
    public static function length(string $text): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * No-break and thin spaces (prices, some names) are not in the basic SMS alphabet: a plain space
     * keeps each SMS part 160 characters long instead of 70.
     */
    public static function plainSpaces(string $text): string
    {
        return str_replace(array("\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xE2\x80\x88", "\xE2\x80\x87"), ' ', $text);
    }
}
