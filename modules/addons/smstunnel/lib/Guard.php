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
 * a warning, notice or deprecation raised by the module's own files becomes an exception that the
 * module's handlers catch, so the WHMCS action always goes on.
 *
 * A warning raised by WHMCS's own code (localAPI(), formatCurrency()...) or another module meanwhile is
 * handed to the handler that was there before, as if the module were not running: it must not cancel
 * the module's work (a deprecation in WHMCS on a newer PHP would otherwise skip texts).
 */
final class Guard
{
    /** @var string[]|null path prefixes of the module's own files, with forward slashes */
    private static $own;

    /**
     * Runs $fn; a PHP warning, notice or deprecation raised meanwhile by the module's own code is thrown
     * as an \ErrorException.
     *
     * @return mixed what $fn returns
     */
    public static function run(callable $fn)
    {
        $previous = null;
        $previous = set_error_handler(function ($severity, $message, $file = '', $line = 0) use (&$previous) {
            if (!(error_reporting() & $severity)) {
                // Silenced with @.
                return false;
            }
            if (!Guard::isOwn((string) $file)) {
                return is_callable($previous) ? call_user_func($previous, $severity, $message, $file, $line) : false;
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

    /** True for a file of the addon (modules/addons/smstunnel) or of the sign-in code module. */
    public static function isOwn(string $file): bool
    {
        $file = str_replace('\\', '/', $file);
        foreach (self::ownPaths() as $prefix) {
            if (strpos($file, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts files under $path as the module's own too (tests).
     */
    public static function addOwnPath(string $path)
    {
        self::ownPaths();
        self::$own[] = rtrim(str_replace('\\', '/', $path), '/') . '/';
    }

    /**
     * @return string[]
     */
    private static function ownPaths(): array
    {
        if (self::$own === null) {
            $addon = str_replace('\\', '/', dirname(__DIR__));
            self::$own = array(
                rtrim($addon, '/') . '/',
                str_replace('\\', '/', dirname($addon, 2)) . '/security/smstunnel2fa/',
            );
        }

        return self::$own;
    }
}
