<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs;

use SmsTunnel\Whmcs\Store\Schema;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * What hooks.php calls. Nothing here may stop a WHMCS page, payment callback or cron run: every
 * error is caught, PHP warnings included, and written to the Activity Log without numbers, words or
 * the API key.
 */
final class Hooks
{
    /** The WHMCS hook points the module listens to, and the Notifier method each one calls. */
    const EVENTS = array(
        'InvoiceCreated' => 'invoiceCreated',
        'InvoicePaymentReminder' => 'invoicePaymentReminder',
        'InvoicePaid' => 'invoicePaid',
        'AfterModuleCreate' => 'serviceActivated',
        'AfterModuleSuspend' => 'serviceSuspended',
        'AfterModuleUnsuspend' => 'serviceUnsuspended',
        'TicketAdminReply' => 'ticketAdminReply',
        'TicketOpen' => 'ticketOpened',
        'TicketUserReply' => 'ticketUserReply',
        'AfterRegistrarRegistration' => 'domainRegistered',
        'AfterRegistrarRenewal' => 'domainRenewed',
        'OrderPaid' => 'orderPaid',
    );

    /**
     * A WHMCS event: the texts it calls for.
     *
     * @param mixed $vars the hook's parameters
     */
    public static function event(string $hook, $vars)
    {
        if (!isset(self::EVENTS[$hook])) {
            return;
        }
        $method = self::EVENTS[$hook];
        self::safely($hook, function () use ($method, $vars) {
            if (!Schema::installed()) {
                return;
            }
            Module::notifier()->$method(is_array($vars) ? $vars : array());
        });
    }

    /** AfterCronJob (every cron run): the texts waiting for a retry. */
    public static function cron()
    {
        self::safely('AfterCronJob', function () {
            if (Schema::installed()) {
                Module::outbox()->retryDue();
            }
        });
    }

    /** DailyCronJob: removes old log rows and used sign-in codes. */
    public static function dailyCron()
    {
        self::safely('DailyCronJob', function () {
            if (!Schema::installed()) {
                return;
            }
            Module::log()->purge(Module::settings()->logDays());
            Module::signInCodes()->purge();
        });
    }

    private static function safely(string $where, callable $fn)
    {
        try {
            Guard::run($fn);
        } catch (\Throwable $e) {
            $message = self::describe($e);
            Guard::quietly(function () use ($where, $message) {
                Module::platform()->logActivity($where . ': ' . $message);
            });
        }
    }

    /**
     * An error safe for the Activity Log. A database error's message holds the query and its values
     * (a number, a text's words), so only its class and code are kept.
     */
    public static function describe(\Throwable $e): string
    {
        if ($e instanceof \PDOException || stripos(get_class($e), 'QueryException') !== false) {
            return 'database error (' . get_class($e) . ' ' . $e->getCode() . ')';
        }
        $message = Redact::text($e->getMessage());

        return get_class($e) . ': ' . (function_exists('mb_substr') ? mb_substr($message, 0, 200, 'UTF-8') : substr($message, 0, 200));
    }
}
