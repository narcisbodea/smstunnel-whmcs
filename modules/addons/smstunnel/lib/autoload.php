<?php
/**
 * SMSTunnel for WHMCS: the class loader of the module's own code.
 *
 * No Composer and no WHMCS class in here, so the same code serves the addon module
 * (modules/addons/smstunnel) and the sign-in code module (modules/security/smstunnel2fa).
 * Plain PHP 7.2 or newer: WHMCS 8.10 runs on PHP 7.2 to 8.1, WHMCS 9.1 on PHP 8.2 to 8.4.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

if (!defined('SMSTUNNEL_WHMCS_LIB')) {
    define('SMSTUNNEL_WHMCS_LIB', __DIR__);

    spl_autoload_register(function ($class) {
        $prefix = 'SmsTunnel\\Whmcs\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if (!preg_match('/^[A-Za-z0-9]+(\\\\[A-Za-z0-9]+)*$/', $relative)) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
