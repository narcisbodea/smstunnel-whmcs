<?php
/**
 * SMSTunnel for WHMCS: texts your clients and staff about WHMCS events, from your own Android phone
 * and your own number, through SMSTunnel (https://smstunnel.io).
 *
 * Activate it under System Settings → Addon Modules, give your admin roles access (Configure →
 * Access Control), then open Addons → SMSTunnel for WHMCS and paste your SMSTunnel API key.
 *
 * Not affiliated with, endorsed by, or sponsored by WHMCS Limited.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

use SmsTunnel\Whmcs\Admin\Page;
use SmsTunnel\Whmcs\Hooks;
use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Store\Schema;
use SmsTunnel\Whmcs\Version;

/**
 * @return array<string, mixed>
 */
function smstunnel_config()
{
    return array(
        'name' => Version::NAME,
        'description' => 'Texts your clients about invoices, services, tickets and domains, and alerts your staff, '
            . 'from your own Android phone and number with SMSTunnel. After activating, open Addons → SMSTunnel for WHMCS.',
        'author' => 'SMSTunnel',
        'language' => 'english',
        'version' => Version::NUMBER,
        'fields' => array(),
    );
}

/**
 * Creates the module's tables, its settings and the client field "No text messages".
 * Activating it again after a deactivation keeps what was there.
 *
 * @return array{status: string, description: string}
 */
function smstunnel_activate()
{
    try {
        Module::reset();
        Schema::install();
        Module::settings()->installDefaults();
        Module::consent()->sync();
        Module::platform()->logActivity('SMSTunnel for WHMCS ' . Version::NUMBER . ' activated');

        return array(
            'status' => 'success',
            'description' => 'SMSTunnel for WHMCS is active. Give your admin roles access with Configure, then open Addons → SMSTunnel for WHMCS and paste your SMSTunnel API key.',
        );
    } catch (\Throwable $e) {
        return array('status' => 'error', 'description' => 'SMSTunnel for WHMCS could not set itself up: ' . Hooks::describe($e));
    }
}

/**
 * Keeps the settings and the log, unless "Delete all data on deactivate" is ticked under Setup.
 *
 * @return array{status: string, description: string}
 */
function smstunnel_deactivate()
{
    try {
        Module::reset();
        if (!Schema::installed()) {
            return array('status' => 'success', 'description' => 'SMSTunnel for WHMCS is deactivated.');
        }
        if (Module::settings()->deleteOnDeactivate()) {
            Module::consent()->remove();
            Schema::drop();
            Module::reset();
            Module::platform()->logActivity('SMSTunnel for WHMCS deactivated; all its data was deleted');

            return array('status' => 'success', 'description' => 'SMSTunnel for WHMCS is deactivated and all its data was deleted: settings, API key, log and the client field.');
        }
        Module::platform()->logActivity('SMSTunnel for WHMCS deactivated; settings and log kept');

        return array('status' => 'success', 'description' => 'SMSTunnel for WHMCS is deactivated. Its settings and log are kept for when you activate it again.');
    } catch (\Throwable $e) {
        return array('status' => 'error', 'description' => 'SMSTunnel for WHMCS could not be deactivated cleanly: ' . Hooks::describe($e));
    }
}

/**
 * WHMCS calls this the first time the module is used after its files were updated.
 *
 * @param array<string, mixed> $vars $vars['version'] is the version that was installed
 */
function smstunnel_upgrade($vars)
{
    try {
        Module::reset();
        Schema::install();
        Module::settings()->installDefaults();
        Module::consent()->sync();
        $from = is_array($vars) && isset($vars['version']) && is_scalar($vars['version']) ? (string) $vars['version'] : '';
        Module::platform()->logActivity('SMSTunnel for WHMCS upgraded' . ($from !== '' ? ' from ' . $from : '') . ' to ' . Version::NUMBER);
    } catch (\Throwable $e) {
        Module::platform()->logActivity('SMSTunnel for WHMCS upgrade: ' . Hooks::describe($e));
    }
}

/**
 * The page under Addons → SMSTunnel for WHMCS (WHMCS checks the admin role's access before calling it).
 *
 * @param array<string, mixed> $vars
 */
function smstunnel_output($vars)
{
    if (Module::platform()->adminId() <= 0) {
        echo '<div class="alert alert-danger">Sign in to the admin area first.</div>';

        return;
    }
    try {
        if (!Schema::installed()) {
            Schema::install();
            Module::settings()->installDefaults();
        }
        $page = new Page(Module::settings(), Module::platform(), Module::log(), Module::outbox(), Module::consent());
        echo $page->render(
            is_array($vars) ? $vars : array(),
            is_array($_GET) ? $_GET : array(),
            is_array($_POST) ? $_POST : array(),
            isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET'
        );
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">' . htmlspecialchars('SMSTunnel for WHMCS: ' . Hooks::describe($e), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}
