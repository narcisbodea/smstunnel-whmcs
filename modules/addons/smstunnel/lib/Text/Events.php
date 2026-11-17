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
 * The events that can send a text: who gets it, which WHMCS hook starts it, its merge fields and the
 * text it starts with.
 *
 * The suggested texts stay in the GSM 7-bit alphabet and under 160 characters with typical values, so
 * each one is a single SMS. Every text is about the client's own account (a service text): nothing
 * here texts many clients at once.
 */
final class Events
{
    const CLIENT = 'client';
    const STAFF = 'staff';

    /** Kinds of rows in the log that are not WHMCS events. */
    const TEST = 'test';
    const SIGN_IN_CODE = 'sign_in_code';

    /** Merge fields every client text has. */
    const CLIENT_FIELDS = array('client_first_name', 'client_last_name', 'client_name', 'client_company_name', 'company_name', 'whmcs_url');

    const INVOICE_FIELDS = array('invoice_id', 'invoice_num', 'invoice_total', 'invoice_balance', 'invoice_due_date', 'invoice_url');

    const SERVICE_FIELDS = array('service_id', 'service_name', 'service_domain');

    const EVENTS = array(
        'invoice_created' => array(
            'audience' => self::CLIENT,
            'label' => 'Invoice created',
            'hook' => 'InvoiceCreated',
            'when' => 'WHMCS issues an invoice to the client (it leaves Draft), right after the Invoice Created email. An invoice with nothing left to pay sends nothing.',
            'on' => true,
            'fields' => array('invoice'),
            'text' => 'Hi {client_first_name}, invoice #{invoice_num} for {invoice_total} is ready and due on {invoice_due_date}. Thank you! {company_name}',
        ),
        'payment_reminder' => array(
            'audience' => self::CLIENT,
            'label' => 'Payment reminder',
            'hook' => 'InvoicePaymentReminder',
            'when' => 'WHMCS sends its payment reminder for an unpaid invoice that is not overdue yet (Automation Settings: Invoice Unpaid Reminder).',
            'on' => false,
            'fields' => array('invoice'),
            'text' => 'Hi {client_first_name}, a friendly reminder: invoice #{invoice_num} for {invoice_balance} is due on {invoice_due_date}. {company_name}',
        ),
        'overdue_reminder' => array(
            'audience' => self::CLIENT,
            'label' => 'Overdue reminder',
            'hook' => 'InvoicePaymentReminder',
            'when' => 'WHMCS sends one of its overdue notices (Automation Settings: First, Second and Third Overdue Reminder).',
            'on' => true,
            'fields' => array('invoice', 'invoice_days_overdue'),
            'text' => 'Hi {client_first_name}, invoice #{invoice_num} for {invoice_balance} was due on {invoice_due_date}. Please pay it to keep your services running. {company_name}',
        ),
        'invoice_paid' => array(
            'audience' => self::CLIENT,
            'label' => 'Invoice paid',
            'hook' => 'InvoicePaid',
            'when' => 'An invoice is marked paid: paid online, with credit, or by staff. A free invoice sends nothing.',
            'on' => true,
            'fields' => array('invoice'),
            'text' => 'Hi {client_first_name}, thank you! We received your payment for invoice #{invoice_num} ({invoice_total}). {company_name}',
        ),
        'service_activated' => array(
            'audience' => self::CLIENT,
            'label' => 'Service activated',
            'hook' => 'AfterModuleCreate',
            'when' => "WHMCS creates the service through the product's module (Module Create), automatically after payment or from the admin area.",
            'on' => true,
            'fields' => array('service'),
            'text' => 'Hi {client_first_name}, your {service_name} {service_domain} is now active. Thank you for choosing {company_name}!',
        ),
        'service_suspended' => array(
            'audience' => self::CLIENT,
            'label' => 'Service suspended',
            'hook' => 'AfterModuleSuspend',
            'when' => "WHMCS suspends the service through the product's module, by the overdue automation or from the admin area.",
            'on' => true,
            'fields' => array('service', 'service_suspend_reason'),
            'text' => 'Hi {client_first_name}, your {service_name} {service_domain} is suspended. Please pay any overdue invoice or contact us to restore it. {company_name}',
        ),
        'service_unsuspended' => array(
            'audience' => self::CLIENT,
            'label' => 'Service unsuspended',
            'hook' => 'AfterModuleUnsuspend',
            'when' => "WHMCS unsuspends the service through the product's module, after payment or from the admin area.",
            'on' => true,
            'fields' => array('service'),
            'text' => 'Hi {client_first_name}, good news: your {service_name} {service_domain} is active again. {company_name}',
        ),
        'ticket_reply' => array(
            'audience' => self::CLIENT,
            'label' => 'Staff reply on a ticket',
            'hook' => 'TicketAdminReply',
            'when' => "A staff member replies to one of the client's tickets. Tickets from visitors without an account send nothing.",
            'on' => true,
            'fields' => array('ticket_number', 'ticket_subject', 'ticket_department', 'ticket_status'),
            'text' => 'Hi {client_first_name}, we replied to your ticket #{ticket_number}: {ticket_subject}. {company_name}',
        ),
        'domain_registered' => array(
            'audience' => self::CLIENT,
            'label' => 'Domain registered',
            'hook' => 'AfterRegistrarRegistration',
            'when' => "The domain's registrar module registers it successfully.",
            'on' => true,
            'fields' => array('domain_name', 'domain_years'),
            'text' => 'Hi {client_first_name}, your domain {domain_name} is now registered. Thank you! {company_name}',
        ),
        'domain_renewed' => array(
            'audience' => self::CLIENT,
            'label' => 'Domain renewed',
            'hook' => 'AfterRegistrarRenewal',
            'when' => "The domain's registrar module renews it successfully.",
            'on' => false,
            'fields' => array('domain_name', 'domain_years'),
            'text' => 'Hi {client_first_name}, your domain {domain_name} has been renewed. Thank you! {company_name}',
        ),
        'staff_order_paid' => array(
            'audience' => self::STAFF,
            'label' => 'New paid order',
            'hook' => 'OrderPaid',
            'when' => 'The first invoice of a new order is paid.',
            'on' => true,
            'fields' => array('order_id', 'order_num', 'order_total', 'order_payment_method', 'client_id', 'client_name', 'company_name'),
            'text' => 'New paid order #{order_num} from {client_name}: {order_total}.',
        ),
        'staff_ticket_opened' => array(
            'audience' => self::STAFF,
            'label' => 'New ticket',
            'hook' => 'TicketOpen',
            'when' => 'A client opens a ticket, in the client area or by email.',
            'on' => true,
            'fields' => array('ticket_number', 'ticket_subject', 'ticket_department', 'ticket_priority', 'client_id', 'client_name', 'company_name'),
            'text' => 'New ticket #{ticket_number} ({ticket_department}, {ticket_priority}) from {client_name}: {ticket_subject}',
        ),
        'staff_ticket_reply' => array(
            'audience' => self::STAFF,
            'label' => 'Client reply on a ticket',
            'hook' => 'TicketUserReply',
            'when' => 'A client replies to a ticket.',
            'on' => true,
            'fields' => array('ticket_number', 'ticket_subject', 'ticket_department', 'ticket_priority', 'client_id', 'client_name', 'company_name'),
            'text' => 'Client reply on ticket #{ticket_number} ({ticket_department}) from {client_name}: {ticket_subject}',
        ),
    );

    /** What each field is, for the text editor. */
    const FIELD_HELP = array(
        'client_first_name' => "The client's first name.",
        'client_last_name' => "The client's last name.",
        'client_name' => "The client's full name.",
        'client_company_name' => "The client's company name (often empty).",
        'company_name' => 'Your company name (System Settings → General Settings).',
        'whmcs_url' => 'The address of your WHMCS (System URL).',
        'invoice_id' => "The invoice's ID.",
        'invoice_num' => 'The invoice number (the sequential number when you use them, otherwise the ID).',
        'invoice_total' => "The invoice total, in the client's currency.",
        'invoice_balance' => 'What is left to pay.',
        'invoice_due_date' => "The due date, in the client area's date format.",
        'invoice_url' => 'The link to the invoice in the client area.',
        'invoice_days_overdue' => 'How many days the invoice is overdue.',
        'service_id' => "The service's ID.",
        'service_name' => 'The product name.',
        'service_domain' => "The service's domain (empty for products without one).",
        'service_suspend_reason' => 'The suspension reason WHMCS saved.',
        'ticket_number' => 'The ticket number the client sees.',
        'ticket_subject' => "The ticket's subject (cut at 60 characters).",
        'ticket_department' => 'The support department.',
        'ticket_status' => "The ticket's status after the reply.",
        'ticket_priority' => "The ticket's priority.",
        'domain_name' => 'The domain name.',
        'domain_years' => 'The registration or renewal period, in years.',
        'order_id' => "The order's ID.",
        'order_num' => 'The order number.',
        'order_total' => "The order total, in the client's currency.",
        'order_payment_method' => 'The payment method of the order.',
        'client_id' => "The client's ID.",
    );

    /** Example values: the editor counts SMS parts with them. */
    const SAMPLES = array(
        'client_first_name' => 'Alexandra',
        'client_last_name' => 'Popescu',
        'client_name' => 'Alexandra Popescu',
        'client_company_name' => 'Popescu Design',
        'company_name' => 'Example Hosting',
        'whmcs_url' => 'https://billing.example.com',
        'invoice_id' => '10234',
        'invoice_num' => '10234',
        'invoice_total' => '$25.00 USD',
        'invoice_balance' => '$25.00 USD',
        'invoice_due_date' => '12/31/2026',
        'invoice_url' => 'https://billing.example.com/viewinvoice.php?id=10234',
        'invoice_days_overdue' => '3',
        'service_id' => '77',
        'service_name' => 'Starter Hosting',
        'service_domain' => 'example.com',
        'service_suspend_reason' => 'Overdue on payment',
        'ticket_number' => '482915',
        'ticket_subject' => 'Email not working',
        'ticket_department' => 'Support',
        'ticket_status' => 'Answered',
        'ticket_priority' => 'Medium',
        'domain_name' => 'example.com',
        'domain_years' => '1',
        'order_id' => '512',
        'order_num' => '7858259149',
        'order_total' => '$95.40 USD',
        'order_payment_method' => 'Credit Card',
        'client_id' => '42',
    );

    /** @return string[] the event keys, in the order the settings show them */
    public static function keys(): array
    {
        return array_keys(self::EVENTS);
    }

    /**
     * @return array<string, array<string, mixed>> the events of one audience (client or staff)
     */
    public static function forAudience(string $audience): array
    {
        return array_filter(self::EVENTS, function ($event) use ($audience) {
            return $event['audience'] === $audience;
        });
    }

    public static function exists(string $event): bool
    {
        return isset(self::EVENTS[$event]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $event): array
    {
        if (!isset(self::EVENTS[$event])) {
            throw new \InvalidArgumentException('Unknown event ' . $event);
        }

        return self::EVENTS[$event];
    }

    /** The label of an event or of another kind of row in the log. */
    public static function label(string $event): string
    {
        if (isset(self::EVENTS[$event])) {
            return self::EVENTS[$event]['label'];
        }
        if ($event === self::TEST) {
            return 'Test text';
        }
        if ($event === self::SIGN_IN_CODE) {
            return 'Sign-in code';
        }

        return $event;
    }

    public static function defaultText(string $event): string
    {
        $definition = self::get($event);

        return $definition['text'];
    }

    public static function defaultOn(string $event): bool
    {
        $definition = self::get($event);

        return $definition['on'];
    }

    /**
     * The merge fields an event's text can use.
     *
     * @return string[]
     */
    public static function fields(string $event): array
    {
        $definition = self::get($event);
        $fields = $definition['audience'] === self::CLIENT ? self::CLIENT_FIELDS : array();
        foreach ($definition['fields'] as $field) {
            if ($field === 'invoice') {
                $fields = array_merge($fields, self::INVOICE_FIELDS);
            } elseif ($field === 'service') {
                $fields = array_merge($fields, self::SERVICE_FIELDS);
            } else {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * The example values of an event's fields.
     *
     * @return array<string, string>
     */
    public static function samples(string $event, string $companyName = ''): array
    {
        $samples = array();
        foreach (self::fields($event) as $field) {
            $samples[$field] = isset(self::SAMPLES[$field]) ? self::SAMPLES[$field] : '';
        }
        if (trim($companyName) !== '' && array_key_exists('company_name', $samples)) {
            $samples['company_name'] = $companyName;
        }

        return $samples;
    }
}
