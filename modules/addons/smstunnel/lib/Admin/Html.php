<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Admin;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Escaping for the admin pages: every value that reaches the HTML goes through e().
 */
final class Html
{
    /** Text or an attribute value, escaped. */
    public static function e($value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * JSON for a data-* attribute (escaped for the attribute, so </script> or quotes cannot break out).
     *
     * @param mixed $value
     */
    public static function json($value): string
    {
        $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return self::e(is_string($json) ? $json : 'null');
    }

    /**
     * A link with query parameters added to the module's page.
     *
     * @param array<string, scalar> $query
     */
    public static function url(string $moduleLink, array $query = array()): string
    {
        $query = array_filter($query, function ($value) {
            return $value !== null && $value !== '';
        });

        return $moduleLink . ($query ? (strpos($moduleLink, '?') !== false ? '&' : '?') . http_build_query($query) : '');
    }

    public static function alert(string $kind, string $text): string
    {
        $kinds = array('success', 'info', 'warning', 'danger');

        return '<div class="alert alert-' . (in_array($kind, $kinds, true) ? $kind : 'info') . '">' . self::e($text) . '</div>';
    }

    public static function checked(bool $on): string
    {
        return $on ? ' checked="checked"' : '';
    }

    public static function selected(bool $on): string
    {
        return $on ? ' selected="selected"' : '';
    }
}
