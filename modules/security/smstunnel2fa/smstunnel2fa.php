<?php
/**
 * SMSTunnel for WHMCS: sign-in codes by text message (a Two-Factor Authentication module).
 *
 * The codes go out through the SMSTunnel for WHMCS addon (modules/addons/smstunnel), from your own
 * Android phone: set the addon up first and send a test text. Then turn this module on under
 * System Settings → Two-Factor Authentication.
 *
 * The folder is smstunnel2fa, not smstunnel: WHMCS calls a module's functions <folder>_<function>,
 * and the addon already defines smstunnel_config() and smstunnel_activate().
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$smstunnel2faLib = dirname(__DIR__, 2) . '/addons/smstunnel/lib/autoload.php';
if (is_file($smstunnel2faLib)) {
    require_once $smstunnel2faLib;
}
unset($smstunnel2faLib);

/**
 * @return array<string, array<string, string>>
 */
function smstunnel2fa_config()
{
    $ready = class_exists('SmsTunnel\\Whmcs\\TwoFactor\\Screens');

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'SMSTunnel: codes by text message',
        ),
        'ShortDescription' => array(
            'Type' => 'System',
            'Value' => 'Get a sign-in code by text message, sent from your own Android phone with SMSTunnel.',
        ),
        'Description' => array(
            'Type' => 'System',
            'Value' => $ready
                ? 'Each sign-in texts a 6-digit code to the mobile number the person confirmed when turning this on. The codes go out through the SMSTunnel for WHMCS addon: set it up under Addons → SMSTunnel for WHMCS and send a test text first.'
                : 'This module needs the SMSTunnel for WHMCS addon: upload modules/addons/smstunnel too, then activate it under System Settings → Addon Modules.',
        ),
    );
}

/**
 * The setup form shown when a person turns sign-in codes by text message on.
 *
 * @param array<string, mixed> $params
 */
function smstunnel2fa_activate($params)
{
    if (!class_exists('SmsTunnel\\Whmcs\\TwoFactor\\Screens')) {
        return '<div class="alert alert-danger">Upload the SMSTunnel for WHMCS addon (modules/addons/smstunnel) first.</div>';
    }

    return \SmsTunnel\Whmcs\TwoFactor\Screens::activate(is_array($params) ? $params : array());
}

/**
 * Checks the setup form; returns the settings WHMCS keeps for the person.
 *
 * @param array<string, mixed> $params
 *
 * @return array<string, mixed>
 */
function smstunnel2fa_activateverify($params)
{
    if (!class_exists('SmsTunnel\\Whmcs\\TwoFactor\\Screens')) {
        throw new \Exception('Upload the SMSTunnel for WHMCS addon (modules/addons/smstunnel) first.');
    }

    return \SmsTunnel\Whmcs\TwoFactor\Screens::activateVerify(is_array($params) ? $params : array());
}

/**
 * The sign-in step: texts the code and shows the box to type it in.
 *
 * @param array<string, mixed> $params
 */
function smstunnel2fa_challenge($params)
{
    if (!class_exists('SmsTunnel\\Whmcs\\TwoFactor\\Screens')) {
        return '<div class="alert alert-danger">Sign-in codes by text message are not available. Use your backup code.</div>'
            . '<input type="text" name="key" class="form-control input-lg" autocomplete="one-time-code"><br><input type="submit" class="btn btn-primary btn-block btn-lg" value="Sign in">';
    }

    return \SmsTunnel\Whmcs\TwoFactor\Screens::challenge(is_array($params) ? $params : array());
}

/**
 * True when the typed code is right.
 *
 * @param array<string, mixed> $params
 */
function smstunnel2fa_verify($params)
{
    if (!class_exists('SmsTunnel\\Whmcs\\TwoFactor\\Screens')) {
        return false;
    }

    return \SmsTunnel\Whmcs\TwoFactor\Screens::verify(is_array($params) ? $params : array());
}
