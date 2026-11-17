<?php
/**
 * SMSTunnel for WHMCS: the hooks. WHMCS loads this file on every page while the addon is active.
 *
 * Each hook only hands its parameters to SmsTunnel\Whmcs\Hooks, which catches every error: a text
 * that cannot go out never stops the WHMCS action that started it.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

use SmsTunnel\Whmcs\Hooks;

// Client texts.
add_hook('InvoiceCreated', 1, function ($vars) {
    Hooks::event('InvoiceCreated', $vars);
});
add_hook('InvoicePaymentReminder', 1, function ($vars) {
    Hooks::event('InvoicePaymentReminder', $vars);
});
add_hook('InvoicePaid', 1, function ($vars) {
    Hooks::event('InvoicePaid', $vars);
});
add_hook('AfterModuleCreate', 1, function ($vars) {
    Hooks::event('AfterModuleCreate', $vars);
});
add_hook('AfterModuleSuspend', 1, function ($vars) {
    Hooks::event('AfterModuleSuspend', $vars);
});
add_hook('AfterModuleUnsuspend', 1, function ($vars) {
    Hooks::event('AfterModuleUnsuspend', $vars);
});
add_hook('TicketAdminReply', 1, function ($vars) {
    Hooks::event('TicketAdminReply', $vars);
});
add_hook('AfterRegistrarRegistration', 1, function ($vars) {
    Hooks::event('AfterRegistrarRegistration', $vars);
});
add_hook('AfterRegistrarRenewal', 1, function ($vars) {
    Hooks::event('AfterRegistrarRenewal', $vars);
});

// Staff alerts.
add_hook('OrderPaid', 1, function ($vars) {
    Hooks::event('OrderPaid', $vars);
});
add_hook('TicketOpen', 1, function ($vars) {
    Hooks::event('TicketOpen', $vars);
});
add_hook('TicketUserReply', 1, function ($vars) {
    Hooks::event('TicketUserReply', $vars);
});

// Retries (every cron run) and the log's clean-up (once a day).
add_hook('AfterCronJob', 1, function ($vars) {
    Hooks::cron();
});
add_hook('DailyCronJob', 1, function ($vars) {
    Hooks::dailyCron();
});
