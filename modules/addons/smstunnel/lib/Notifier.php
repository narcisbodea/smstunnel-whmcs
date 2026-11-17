<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs;

use SmsTunnel\Whmcs\Phone\ClientNumber;
use SmsTunnel\Whmcs\Phone\PhoneNumber;
use SmsTunnel\Whmcs\Send\OutgoingText;
use SmsTunnel\Whmcs\Send\Outbox;
use SmsTunnel\Whmcs\Store\Consent;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Text\Events;
use SmsTunnel\Whmcs\Text\Template;
use SmsTunnel\Whmcs\Whmcs\Platform;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Turns WHMCS hooks into texts: which event, to whom, with which words.
 *
 * Client texts go to the client's own phone number, one text per event, only about the client's own
 * account, and only when the client did not opt out (or did opt in, in opt-in mode). Staff alerts go
 * to the numbers under Staff alerts, one call per number. Nothing here texts many clients at once.
 */
class Notifier
{
    /** At most this many alerts of one kind to one staff number in an hour. */
    const STAFF_ALERTS_PER_HOUR = 10;

    /** @var Settings */
    private $settings;

    /** @var Platform */
    private $platform;

    /** @var Outbox */
    private $outbox;

    /** @var Consent */
    private $consent;

    public function __construct(Settings $settings, Platform $platform, Outbox $outbox, Consent $consent)
    {
        $this->settings = $settings;
        $this->platform = $platform;
        $this->outbox = $outbox;
        $this->consent = $consent;
    }

    /** True when the module can send at all (an API key is saved). */
    public function ready(): bool
    {
        return $this->settings->hasApiKey();
    }

    // ---- Invoices ------------------------------------------------------------------------------

    /** InvoiceCreated: the invoice left Draft and the client can see it. */
    public function invoiceCreated(array $vars)
    {
        if (!$this->wants('invoice_created')) {
            return;
        }
        $invoice = $this->platform->invoice(self::int($vars, 'invoiceid'));
        // Nothing to pay (a free invoice, or one paid with credit as it was made): no text.
        if ($invoice === null || strcasecmp($invoice['status'], 'Unpaid') !== 0 || $invoice['balance'] <= 0) {
            return;
        }
        $this->textClient('invoice_created', $invoice['clientId'], $this->invoiceValues($invoice), 'invoice', $invoice['id'], 'invoice_created:' . $invoice['id']);
    }

    /**
     * InvoicePaymentReminder: WHMCS sent a payment reminder ($vars['type'] "reminder") or an overdue
     * notice (a type with "overdue" in it: firstoverdue, secondoverdue, thirdoverdue).
     */
    public function invoicePaymentReminder(array $vars)
    {
        $type = strtolower(trim(isset($vars['type']) && is_scalar($vars['type']) ? (string) $vars['type'] : ''));
        $event = strpos($type, 'overdue') !== false ? 'overdue_reminder' : 'payment_reminder';
        if (!$this->wants($event)) {
            return;
        }
        $invoice = $this->platform->invoice(self::int($vars, 'invoiceid'));
        if ($invoice === null || strcasecmp($invoice['status'], 'Unpaid') !== 0 || $invoice['balance'] <= 0) {
            return;
        }
        $values = $this->invoiceValues($invoice);
        $due = strtotime(substr($invoice['dueDate'], 0, 10));
        $values['invoice_days_overdue'] = $due !== false ? (string) max(0, (int) floor(($this->platform->now() - $due) / 86400)) : '';
        $kind = preg_replace('/[^a-z0-9]+/', '', $type);
        $this->textClient($event, $invoice['clientId'], $values, 'invoice', $invoice['id'], $event . ':' . $invoice['id'] . ':' . ($kind !== '' ? $kind : 'reminder') . ':' . date('Ymd', $this->platform->now()));
    }

    /** InvoicePaid: the invoice was paid (online, with credit, or by staff). */
    public function invoicePaid(array $vars)
    {
        if (!$this->wants('invoice_paid')) {
            return;
        }
        $invoice = $this->platform->invoice(self::int($vars, 'invoiceid'));
        if ($invoice === null || $invoice['total'] <= 0) {
            return;
        }
        $this->textClient('invoice_paid', $invoice['clientId'], $this->invoiceValues($invoice), 'invoice', $invoice['id'], 'invoice_paid:' . $invoice['id']);
    }

    // ---- Services ------------------------------------------------------------------------------

    /** AfterModuleCreate. */
    public function serviceActivated(array $vars)
    {
        $this->serviceEvent('service_activated', $vars);
    }

    /** AfterModuleSuspend. */
    public function serviceSuspended(array $vars)
    {
        $this->serviceEvent('service_suspended', $vars);
    }

    /** AfterModuleUnsuspend. */
    public function serviceUnsuspended(array $vars)
    {
        $this->serviceEvent('service_unsuspended', $vars);
    }

    // ---- Tickets -------------------------------------------------------------------------------

    /** TicketAdminReply: a staff member replied; a text to the client who owns the ticket. */
    public function ticketAdminReply(array $vars)
    {
        if (!$this->wants('ticket_reply')) {
            return;
        }
        $ticketId = self::int($vars, 'ticketid');
        $ticket = $this->platform->ticket($ticketId);
        if ($ticket === null) {
            return;
        }
        $values = array(
            'ticket_number' => $ticket['number'],
            'ticket_subject' => self::string($vars, 'subject', $ticket['subject']),
            'ticket_department' => self::string($vars, 'deptname', $ticket['department']),
            'ticket_status' => self::string($vars, 'status', $ticket['status']),
        );
        $replyId = self::int($vars, 'replyid');
        $dedupe = 'ticket_reply:' . $ticketId . ':' . ($replyId > 0 ? $replyId : 't' . $this->platform->now());
        if ($ticket['clientId'] <= 0) {
            // A ticket from a visitor without an account: no client, no number.
            $text = new OutgoingText('ticket_reply', Events::CLIENT);
            $text->relatedType = 'ticket';
            $text->relatedId = $ticketId;
            $text->dedupeKey = $dedupe;
            $this->outbox->skip($text, 'the ticket is not from a client account');

            return;
        }
        $this->textClient('ticket_reply', $ticket['clientId'], $values, 'ticket', $ticketId, $dedupe);
    }

    /** TicketOpen: a staff alert. */
    public function ticketOpened(array $vars)
    {
        if (!$this->wants('staff_ticket_opened')) {
            return;
        }
        $ticketId = self::int($vars, 'ticketid');
        $ticket = $this->platform->ticket($ticketId);
        $clientId = self::int($vars, 'userid') ?: ($ticket !== null ? $ticket['clientId'] : 0);
        $values = array(
            'ticket_number' => self::string($vars, 'ticketmask', $ticket !== null ? $ticket['number'] : (string) $ticketId),
            'ticket_subject' => self::string($vars, 'subject', $ticket !== null ? $ticket['subject'] : ''),
            'ticket_department' => self::string($vars, 'deptname', $ticket !== null ? $ticket['department'] : ''),
            'ticket_priority' => self::string($vars, 'priority', $ticket !== null ? $ticket['priority'] : ''),
            'client_id' => $clientId > 0 ? (string) $clientId : '',
            'client_name' => $this->clientName($clientId, $ticket !== null ? $ticket['name'] : ''),
        );
        $this->textStaff('staff_ticket_opened', $values, 'ticket', $ticketId, 'staff_ticket_opened:' . $ticketId, $clientId);
    }

    /** TicketUserReply: a staff alert. */
    public function ticketUserReply(array $vars)
    {
        if (!$this->wants('staff_ticket_reply')) {
            return;
        }
        $ticketId = self::int($vars, 'ticketid');
        $ticket = $this->platform->ticket($ticketId);
        $clientId = self::int($vars, 'userid') ?: ($ticket !== null ? $ticket['clientId'] : 0);
        $replyId = self::int($vars, 'replyid');
        $values = array(
            'ticket_number' => $ticket !== null ? $ticket['number'] : (string) $ticketId,
            'ticket_subject' => self::string($vars, 'subject', $ticket !== null ? $ticket['subject'] : ''),
            'ticket_department' => self::string($vars, 'deptname', $ticket !== null ? $ticket['department'] : ''),
            'ticket_priority' => self::string($vars, 'priority', $ticket !== null ? $ticket['priority'] : ''),
            'client_id' => $clientId > 0 ? (string) $clientId : '',
            'client_name' => $this->clientName($clientId, $ticket !== null ? $ticket['name'] : ''),
        );
        $dedupe = 'staff_ticket_reply:' . $ticketId . ':' . ($replyId > 0 ? $replyId : 't' . $this->platform->now());
        $this->textStaff('staff_ticket_reply', $values, 'ticket', $ticketId, $dedupe, $clientId);
    }

    // ---- Domains -------------------------------------------------------------------------------

    /** AfterRegistrarRegistration. */
    public function domainRegistered(array $vars)
    {
        $this->domainEvent('domain_registered', $vars);
    }

    /** AfterRegistrarRenewal. */
    public function domainRenewed(array $vars)
    {
        $this->domainEvent('domain_renewed', $vars);
    }

    // ---- Orders --------------------------------------------------------------------------------

    /** OrderPaid: the first invoice of a new order was paid; a staff alert. */
    public function orderPaid(array $vars)
    {
        if (!$this->wants('staff_order_paid')) {
            return;
        }
        $orderId = self::int($vars, 'orderId') ?: self::int($vars, 'orderid');
        $order = $this->platform->order($orderId);
        $clientId = $order !== null && $order['clientId'] > 0 ? $order['clientId'] : (self::int($vars, 'userId') ?: self::int($vars, 'userid'));
        if ($order === null && $orderId <= 0) {
            return;
        }
        $values = array(
            'order_id' => (string) $orderId,
            'order_num' => $order !== null ? $order['number'] : (string) $orderId,
            'order_total' => $order !== null ? $this->platform->money($order['amount'], $clientId, $order['currencyPrefix'], $order['currencySuffix']) : '',
            'order_payment_method' => $order !== null ? $order['paymentMethod'] : '',
            'client_id' => $clientId > 0 ? (string) $clientId : '',
            'client_name' => $this->clientName($clientId, $order !== null ? $order['clientName'] : ''),
        );
        $this->textStaff('staff_order_paid', $values, 'order', $orderId, 'staff_order_paid:' . $orderId, $clientId);
    }

    // ---- Shared --------------------------------------------------------------------------------

    /** True when the module is set up and the event is turned on. */
    private function wants(string $event): bool
    {
        return $this->settings->eventOn($event) && $this->ready();
    }

    private function serviceEvent(string $event, array $vars)
    {
        if (!$this->wants($event)) {
            return;
        }
        $params = isset($vars['params']) && is_array($vars['params']) ? $vars['params'] : array();
        $serviceId = self::int($params, 'serviceid');
        $clientId = self::int($params, 'userid');
        if ($serviceId <= 0 || $clientId <= 0) {
            return;
        }
        $service = $this->platform->service($serviceId, $clientId);
        $values = array(
            'service_id' => (string) $serviceId,
            'service_name' => $service !== null ? $service['name'] : '',
            'service_domain' => $service !== null && $service['domain'] !== '' ? $service['domain'] : self::string($params, 'domain', ''),
            'service_suspend_reason' => $service !== null ? $service['suspendReason'] : '',
        );
        // A service can be suspended and unsuspended again later: one text per event and hour.
        $this->textClient($event, $clientId, $values, 'service', $serviceId, $event . ':' . $serviceId . ':' . date('YmdH', $this->platform->now()));
    }

    private function domainEvent(string $event, array $vars)
    {
        if (!$this->wants($event)) {
            return;
        }
        $params = isset($vars['params']) && is_array($vars['params']) ? $vars['params'] : array();
        $clientId = self::int($params, 'userid');
        $domainId = self::int($params, 'domainid');
        $sld = self::string($params, 'sld', '');
        $tld = ltrim(self::string($params, 'tld', ''), '.');
        $domain = $sld !== '' && $tld !== '' ? $sld . '.' . $tld : self::string($params, 'domain', '');
        if ($clientId <= 0 || $domain === '') {
            return;
        }
        $years = self::int($params, 'regperiod');
        $values = array(
            'domain_name' => $domain,
            'domain_years' => $years > 0 ? (string) $years : '',
        );
        $this->textClient($event, $clientId, $values, 'domain', $domainId, $event . ':' . ($domainId > 0 ? $domainId : $domain) . ':' . date('Ymd', $this->platform->now()));
    }

    /**
     * One text to a client: checks the client's choice and number, writes the text, sends it.
     *
     * @param array<string, string> $values the event's own merge fields
     */
    private function textClient(string $event, int $clientId, array $values, string $relatedType, int $relatedId, string $dedupe)
    {
        $text = new OutgoingText($event, Events::CLIENT);
        $text->clientId = max(0, $clientId);
        $text->relatedType = $relatedType;
        $text->relatedId = max(0, $relatedId);
        $text->dedupeKey = $dedupe;

        $client = $this->platform->client($clientId);
        if ($client === null) {
            $this->outbox->skip($text, 'client not found');

            return;
        }
        list($allowed, $reason) = $this->consent->check($clientId);
        if (!$allowed) {
            $this->outbox->skip($text, $reason);

            return;
        }
        list($number, $problem, $raw, $callingCode) = ClientNumber::analyse($client);
        $text->countryCode = $callingCode;
        if ($problem !== PhoneNumber::OK) {
            $text->loggedRecipient = Template::cut(Template::value($raw, 0), 40);
            $this->outbox->skip($text, self::numberProblem($problem));

            return;
        }
        $text->to = $number;
        $text->message = Template::render($this->settings->eventText($event), $values + $this->clientValues($client));
        if ($text->message === '') {
            $this->outbox->skip($text, 'the text is empty');

            return;
        }
        $this->outbox->deliver($text);
    }

    /**
     * One alert to each staff number.
     *
     * @param array<string, string> $values
     */
    private function textStaff(string $event, array $values, string $relatedType, int $relatedId, string $dedupe, int $clientId = 0)
    {
        $numbers = $this->settings->staffNumbers();
        if (!$numbers) {
            return;
        }
        $message = Template::render($this->settings->eventText($event), $values + array('company_name' => $this->platform->companyName()));
        if ($message === '') {
            return;
        }
        foreach ($numbers as $number) {
            $text = new OutgoingText($event, Events::STAFF);
            $text->clientId = max(0, $clientId);
            $text->relatedType = $relatedType;
            $text->relatedId = max(0, $relatedId);
            $text->to = $number;
            $text->message = $message;
            $text->dedupeKey = $dedupe . ':' . substr(sha1($number), 0, 10);
            // A flood of tickets (spam to the support address, say) must not flood your phones or use up the plan.
            if ($this->outbox->sentRecently($event, $number, 3600) >= self::STAFF_ALERTS_PER_HOUR) {
                $this->outbox->skip($text, sprintf('more than %d alerts of this kind in an hour to this number', self::STAFF_ALERTS_PER_HOUR));
                continue;
            }
            $this->outbox->deliver($text);
        }
    }

    /**
     * @param array<string, mixed> $client
     *
     * @return array<string, string>
     */
    private function clientValues(array $client): array
    {
        return array(
            'client_first_name' => (string) $client['firstName'],
            'client_last_name' => (string) $client['lastName'],
            'client_name' => (string) $client['fullName'],
            'client_company_name' => (string) $client['companyName'],
            'company_name' => $this->platform->companyName(),
            'whmcs_url' => $this->platform->systemUrl(),
        );
    }

    /**
     * @param array<string, mixed> $invoice
     *
     * @return array<string, string>
     */
    private function invoiceValues(array $invoice): array
    {
        $url = $this->platform->systemUrl();

        return array(
            'invoice_id' => (string) $invoice['id'],
            'invoice_num' => (string) $invoice['number'],
            'invoice_total' => $this->platform->money($invoice['total'], $invoice['clientId']),
            'invoice_balance' => $this->platform->money($invoice['balance'], $invoice['clientId']),
            'invoice_due_date' => $this->platform->date((string) $invoice['dueDate']),
            'invoice_url' => $url !== '' ? $url . '/viewinvoice.php?id=' . (int) $invoice['id'] : '',
        );
    }

    private function clientName(int $clientId, string $fallback): string
    {
        if ($clientId > 0) {
            $client = $this->platform->client($clientId);
            if ($client !== null && $client['fullName'] !== '') {
                return $client['companyName'] !== '' ? $client['fullName'] . ' (' . $client['companyName'] . ')' : $client['fullName'];
            }
        }

        return $fallback;
    }

    private static function numberProblem(string $problem): string
    {
        switch ($problem) {
            case PhoneNumber::MISSING:
                return 'no phone number on the client profile';
            case PhoneNumber::PREMIUM:
                return 'premium-rate or short number, not texted';
            default:
                return 'the phone number is not valid';
        }
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function int(array $vars, string $key): int
    {
        return isset($vars[$key]) && is_scalar($vars[$key]) && is_numeric($vars[$key]) ? (int) $vars[$key] : 0;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function string(array $vars, string $key, string $default): string
    {
        return isset($vars[$key]) && is_scalar($vars[$key]) && trim((string) $vars[$key]) !== '' ? trim((string) $vars[$key]) : $default;
    }
}
