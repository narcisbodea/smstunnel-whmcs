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
 * Keeps the module's PHP warnings away from WHMCS.
 *
 * The hooks run inside WHMCS pages and inside the JSON answers of WHMCS's own AJAX actions (a staff
 * reply on a ticket, for instance): a warning printed there breaks the page or the JSON. Inside run(),
 * a warning, notice or deprecation becomes an exception that the module's handlers catch, so the
 * WHMCS action always goes on.
 */
final class Guard
{
    /**
     * Runs $fn; a PHP warning, notice or deprecation raised meanwhile is thrown as an \ErrorException.
     *
     * @return mixed what $fn returns
     */
    public static function run(callable $fn)
    {
        set_error_handler(function ($severity, $message, $file = '', $line = 0) {
            if (!(error_reporting() & $severity)) {
                // Silenced with @.
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return call_user_func($fn);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Runs $fn and ignores whatever goes wrong (the last resort, for writing to a log).
     */
    public static function quietly(callable $fn)
    {
        set_error_handler(function () {
            return true;
        });
        try {
            call_user_func($fn);
        } catch (\Throwable $e) {
            // Nothing more can be done.
        } finally {
            restore_error_handler();
        }
    }
}
