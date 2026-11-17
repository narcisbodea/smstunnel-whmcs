<?php
/**
 * The hooks: each WHMCS event fired through the callbacks of hooks.php, and what reaches SMSTunnel.
 *
 * @license MIT
 */

use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;

group('client texts');

/** Activates the module with a key and two US clients; returns the opt-out field id. */
function setupClients(): int
{
    Harness::activate(Harness::testKey());
    Harness::usClient(1, '4155550101');
    Harness::usClient(2, '4155550102', array('firstname' => 'Mihai', 'lastname' => 'Ionescu'));

    return (int) Module::settings()->get(Settings::FIELD_OPT_OUT);
}

test('InvoiceCreated: one text to the client, one recipient, an Idempotency-Key, the country code', function () {
    setupClients();
    Module::settings()->set(Settings::DEVICE_ID, '6650c0ffee0000000000a001');
    Harness::invoice(501, 1, array('invoicenum' => 'INV-2026-77', 'total' => '25.00', 'balance' => '25.00', 'duedate' => '2026-11-25'));
    Harness::respond(Harness::sent('msg-501'));
    Harness::fire('InvoiceCreated', array('source' => 'autogen', 'user' => 'system', 'invoiceid' => 501, 'status' => 'Unpaid'));

    $sends = Harness::sends();
    eq(count($sends), 1, 'one call');
    $send = $sends[0];
    eq($send['method'], 'POST');
    eq($send['url'], 'https://smstunnel.io/api/v1/sms/send');
    eq($send['body']['to'], '+14155550101', 'E.164, one recipient');
    eq($send['body']['message'], 'Hi Alexandra, invoice #INV-2026-77 for $25.00 USD is ready and due on 11/25/2026. Thank you! Example Hosting');
    eq($send['body']['defaultCountryCode'], '+1', "the client's country");
    eq($send['body']['deviceId'], '6650c0ffee0000000000a001', 'the chosen phone');
    eq(array_keys($send['body']), array('to', 'message', 'deviceId', 'defaultCountryCode'), 'no other fields');
    eq($send['headers']['x-api-key'], Harness::testKey());
    ok((bool) preg_match('/^whmcs-[0-9a-f]{32}$/', $send['headers']['idempotency-key']), 'an Idempotency-Key per text');
    has($send['headers']['user-agent'], 'SMSTunnel-WHMCS/');
    eq($send['headers']['content-type'], 'application/json');
    eq(array($send['connectTimeout'], $send['timeout']), array(5.0, 10.0), 'short timeouts');

    $rows = Harness::logRows();
    eq(count($rows), 1);
    eq(array($rows[0]['event'], $rows[0]['status'], $rows[0]['message_id'], (int) $rows[0]['client_id'], $rows[0]['recipient']), array('invoice_created', TextLog::SENT, 'msg-501', 1, '+14155550101'));
    eq(array($rows[0]['related_type'], (int) $rows[0]['related_id'], (int) $rows[0]['attempts']), array('invoice', 501, 1));
    eq($rows[0]['request'], null, 'the words are not kept once the text went out');
    eq($rows[0]['idempotency_key'], $send['headers']['idempotency-key']);
});

test('InvoiceCreated: nothing for an invoice already paid, a second firing, or a switched-off event', function () {
    setupClients();
    Harness::invoice(502, 1, array('status' => 'Paid', 'balance' => '0.00'));
    Harness::fire('InvoiceCreated', array('invoiceid' => 502, 'status' => 'Paid'));
    eq(count(Harness::sends()), 0, 'paid: no text');

    Harness::invoice(503, 1);
    Harness::fire('InvoiceCreated', array('invoiceid' => 503));
    Harness::nextRequest();
    Harness::fire('InvoiceCreated', array('invoiceid' => 503));
    eq(count(Harness::sends()), 1, 'the same invoice texts once even when the hook runs twice');

    Module::settings()->setEvent('invoice_created', false, '');
    Harness::invoice(504, 1);
    Harness::fire('InvoiceCreated', array('invoiceid' => 504));
    eq(count(Harness::sends()), 1, 'switched off');
    eq(count(Harness::logRows()), 1, 'nothing logged for a switched-off event');
});

test('skipped and logged: opted-out clients, no number, premium numbers, unknown clients', function () {
    $optOut = setupClients();
    Harness::tick($optOut, 2);
    Harness::client(3, array('firstname' => 'No', 'lastname' => 'Phone', 'phonenumber' => ''));
    Harness::client(4, array('firstname' => 'Premium', 'phonenumber' => '+1 900 555 0123'));
    foreach (array(2, 3, 4, 99) as $client) {
        Harness::invoice(600 + $client, $client);
        Harness::fire('InvoiceCreated', array('invoiceid' => 600 + $client));
    }
    eq(count(Harness::sends()), 0, 'no text went out');
    $rows = Harness::logRows();
    eq(count($rows), 4);
    eq(array_column($rows, 'status'), array_fill(0, 4, TextLog::SKIPPED));
    eq(array_column($rows, 'error'), array(
        'opted out (No text messages)',
        'no phone number on the client profile',
        'premium-rate or short number, not texted',
        'client not found',
    ));
    eq($rows[2]['recipient'], '+1 900 555 0123', 'the number as typed, so the admin sees why');
});

test('no API key: nothing is sent or logged', function () {
    Harness::activate();
    Harness::usClient(1);
    Harness::invoice(700, 1);
    Harness::fire('InvoiceCreated', array('invoiceid' => 700));
    Harness::fire('OrderPaid', array('orderId' => 1, 'userId' => 1, 'invoiceId' => 700));
    eq(count(Harness::$requests), 0);
    eq(count(Harness::logRows()), 0);
});

test('payment reminders: the reminder and the overdue notices have their own texts', function () {
    setupClients();
    Harness::invoice(800, 1, array('duedate' => '2026-11-15', 'balance' => '10.00', 'total' => '10.00'));
    Harness::fire('InvoicePaymentReminder', array('invoiceid' => 800, 'type' => 'reminder'));
    eq(count(Harness::sends()), 0, 'the pre-due reminder starts off');
    Module::settings()->setEvent('payment_reminder', true, '');
    Harness::fire('InvoicePaymentReminder', array('invoiceid' => 800, 'type' => 'reminder'));
    Harness::fire('InvoicePaymentReminder', array('invoiceid' => 800, 'type' => 'firstoverdue'));
    Harness::fire('InvoicePaymentReminder', array('invoiceid' => 800, 'type' => 'secondoverdue'));
    $sends = Harness::sends();
    eq(count($sends), 3);
    eq($sends[0]['body']['message'], 'Hi Alexandra, a friendly reminder: invoice #800 for $10.00 USD is due on 11/15/2026. Example Hosting');
    eq($sends[1]['body']['message'], 'Hi Alexandra, invoice #800 for $10.00 USD was due on 11/15/2026. Please pay it to keep your services running. Example Hosting');
    eq(array_column(Harness::logRows(), 'event'), array('payment_reminder', 'overdue_reminder', 'overdue_reminder'));
    Module::settings()->setEvent('overdue_reminder', true, 'Invoice #{invoice_num} is {invoice_days_overdue} days overdue.');
    Harness::fire('InvoicePaymentReminder', array('invoiceid' => 800, 'type' => 'thirdoverdue'));
    $sends = Harness::sends();
    eq($sends[3]['body']['message'], 'Invoice #800 is 3 days overdue.', 'days overdue from the due date');
    Harness::invoice(801, 1, array('status' => 'Paid'));
    Harness::fire('InvoicePaymentReminder', array('invoiceid' => 801, 'type' => 'firstoverdue'));
    eq(count(Harness::sends()), 4, 'no reminder for a paid invoice');
});

test('InvoicePaid, and nothing for a free invoice', function () {
    setupClients();
    Harness::invoice(900, 2, array('status' => 'Paid', 'total' => '120.50', 'balance' => '0.00'));
    Harness::fire('InvoicePaid', array('invoiceid' => 900));
    Harness::invoice(901, 2, array('status' => 'Paid', 'total' => '0.00', 'balance' => '0.00'));
    Harness::fire('InvoicePaid', array('invoiceid' => 901));
    $sends = Harness::sends();
    eq(count($sends), 1);
    eq($sends[0]['body']['to'], '+14155550102');
    eq($sends[0]['body']['message'], 'Hi Mihai, thank you! We received your payment for invoice #900 ($120.50 USD). Example Hosting');
});

test('services: activated, suspended, unsuspended (the module parameters never reach a log)', function () {
    setupClients();
    Harness::service(55, 1, array('name' => 'Business Hosting', 'domain' => 'shop.example.com', 'suspensionreason' => 'Overdue on Payment'));
    $params = array('serviceid' => 55, 'userid' => 1, 'domain' => 'shop.example.com', 'username' => 'shopexam', 'password' => 'Secret-Pass-123', 'serverpassword' => 'Root-Pass-456');
    Harness::fire('AfterModuleCreate', array('params' => $params));
    Harness::fire('AfterModuleSuspend', array('params' => $params));
    Harness::fire('AfterModuleUnsuspend', array('params' => $params));
    Harness::fire('AfterModuleCreate', array('params' => array('serviceid' => 0)));
    $sends = Harness::sends();
    eq(count($sends), 3);
    eq($sends[0]['body']['message'], 'Hi Alexandra, your Business Hosting shop.example.com is now active. Thank you for choosing Example Hosting!');
    eq($sends[1]['body']['message'], 'Hi Alexandra, your Business Hosting shop.example.com is suspended. Please pay any overdue invoice or contact us to restore it. Example Hosting');
    eq($sends[2]['body']['message'], 'Hi Alexandra, good news: your Business Hosting shop.example.com is active again. Example Hosting');
    $everything = json_encode(Harness::logRows()) . json_encode(Harness::$activity) . json_encode(Harness::$requests);
    lacks($everything, 'Secret-Pass-123', 'the service password');
    lacks($everything, 'Root-Pass-456', 'the server password');
    Module::settings()->setEvent('service_suspended', true, 'Suspended: {service_suspend_reason}');
    Harness::$now += 3600;
    Harness::fire('AfterModuleSuspend', array('params' => $params));
    eq(Harness::sends()[3]['body']['message'], 'Suspended: Overdue on Payment');
});

test('TicketAdminReply: a text to the client; a visitor ticket is skipped', function () {
    setupClients();
    Harness::ticket(31, 1, array('tid' => 'ABC-123456', 'subject' => 'Please help me move my website, my email and all of my databases to the new server today'));
    Harness::fire('TicketAdminReply', array('ticketid' => 31, 'replyid' => 7, 'deptid' => 1, 'deptname' => 'Support', 'subject' => 'Please help me move my website, my email and all of my databases to the new server today', 'message' => 'Hello, done.', 'priority' => 'High', 'admin' => 'Jane', 'status' => 'Answered'));
    Harness::ticket(32, 0);
    Harness::fire('TicketAdminReply', array('ticketid' => 32, 'replyid' => 8, 'subject' => 'Question'));
    $sends = Harness::sends();
    eq(count($sends), 1);
    eq($sends[0]['body']['message'], 'Hi Alexandra, we replied to your ticket #ABC-123456: Please help me move my website, my email and all of my... Example Hosting');
    lacks($sends[0]['body']['message'], 'Hello, done.', "the reply's words stay in WHMCS");
    $rows = Harness::logRows();
    eq(array($rows[1]['status'], $rows[1]['error']), array(TextLog::SKIPPED, 'the ticket is not from a client account'));
});

test('domains: registered (on) and renewed (off until turned on)', function () {
    setupClients();
    $params = array('userid' => 2, 'domainid' => 12, 'sld' => 'example-shop', 'tld' => '.co.uk', 'regperiod' => 2);
    Harness::fire('AfterRegistrarRegistration', array('params' => $params));
    Harness::fire('AfterRegistrarRenewal', array('params' => $params));
    eq(count(Harness::sends()), 1, 'renewal texts start off');
    Module::settings()->setEvent('domain_renewed', true, 'Renewed {domain_name} for {domain_years} years.');
    Harness::fire('AfterRegistrarRenewal', array('params' => $params));
    $sends = Harness::sends();
    eq($sends[0]['body']['message'], 'Hi Mihai, your domain example-shop.co.uk is now registered. Thank you! Example Hosting');
    eq($sends[1]['body']['message'], 'Renewed example-shop.co.uk for 2 years.');
});

group('staff alerts');

test('OrderPaid, TicketOpen, TicketUserReply: one text per staff number', function () {
    setupClients();
    Harness::fire('OrderPaid', array('orderId' => 41, 'userId' => 1, 'invoiceId' => 900));
    eq(count(Harness::sends()), 0, 'no staff numbers yet: nothing');
    Module::settings()->set(Settings::STAFF_NUMBERS, array('+14155550190', '+14155550191'));
    Harness::order(41, 1, array('ordernum' => 1234567890, 'amount' => '95.40'));
    Harness::fire('OrderPaid', array('orderId' => 41, 'userId' => 1, 'invoiceId' => 900));
    Harness::ticket(51, 2, array('tid' => 'XYZ-777', 'subject' => 'Server down', 'priority' => 'High', 'deptname' => 'Technical'));
    Harness::fire('TicketOpen', array('ticketid' => 51, 'ticketmask' => 'XYZ-777', 'userid' => 2, 'deptid' => 2, 'deptname' => 'Technical', 'subject' => 'Server down', 'message' => 'Private details', 'priority' => 'High'));
    Harness::fire('TicketUserReply', array('ticketid' => 51, 'replyid' => 9, 'userid' => 2, 'deptname' => 'Technical', 'subject' => 'Server down', 'message' => 'More private details', 'priority' => 'High', 'status' => 'Customer-Reply'));
    Harness::ticket(52, 0, array('tid' => 'GUEST-1', 'subject' => 'Pre-sales question', 'name' => 'Visitor Name'));
    Harness::fire('TicketOpen', array('ticketid' => 52, 'ticketmask' => 'GUEST-1', 'userid' => 0, 'deptname' => 'Sales', 'subject' => 'Pre-sales question', 'priority' => 'Low'));
    $sends = Harness::sends();
    eq(count($sends), 8, '4 alerts x 2 numbers');
    eq(array_column(array_column($sends, 'body'), 'to'), array('+14155550190', '+14155550191', '+14155550190', '+14155550191', '+14155550190', '+14155550191', '+14155550190', '+14155550191'));
    eq($sends[0]['body']['message'], 'New paid order #1234567890 from Alexandra Popescu: $95.40 USD.');
    eq($sends[2]['body']['message'], 'New ticket #XYZ-777 (Technical, High) from Mihai Ionescu: Server down');
    eq($sends[4]['body']['message'], 'Client reply on ticket #XYZ-777 (Technical) from Mihai Ionescu: Server down');
    eq($sends[6]['body']['message'], 'New ticket #GUEST-1 (Sales, Low) from Visitor Name: Pre-sales question');
    ok(!isset($sends[0]['body']['defaultCountryCode']), 'staff numbers are E.164 already');
    eq(count(array_unique(array_map(function ($send) {
        return $send['headers']['idempotency-key'];
    }, $sends))), 8, 'each text its own key');
    foreach ($sends as $send) {
        lacks($send['body']['message'], 'private details', "a ticket's words never go into a text");
    }
    Harness::nextRequest();
    Harness::fire('OrderPaid', array('orderId' => 41, 'userId' => 1, 'invoiceId' => 900));
    eq(count(Harness::sends()), 8, 'the same order alerts once');
});

group('robustness');

test('a failing WHMCS call, a PHP warning or a database error never escapes a hook', function () {
    setupClients();
    Harness::invoice(1000, 1);
    Harness::$apiFailure = function () {
        throw new \RuntimeException('WHMCS API down');
    };
    Harness::fire('InvoiceCreated', array('invoiceid' => 1000));
    eq(count(Harness::sends()), 0, 'localAPI throwing: nothing sent, nothing breaks');

    Harness::$apiFailure = function ($command) {
        if ($command === 'GetClientsDetails') {
            trigger_error('Undefined index: phonenumber', E_USER_WARNING);
        }
    };
    ob_start();
    Harness::fire('InvoiceCreated', array('invoiceid' => 1000));
    eq(ob_get_clean(), '', 'a PHP warning prints nothing into the WHMCS page');
    Harness::$apiFailure = null;
    eq(Harness::logRows()[0]['error'], 'client not found', 'the warning ended that lookup only');

    // A database error's message holds the query and its values: only its class goes to the Activity Log.
    \WHMCS\Database\Capsule::schema()->drop('mod_smstunnel_log');
    \WHMCS\Database\Capsule::schema()->create('mod_smstunnel_log', function ($table) {
        $table->increments('id');
        $table->string('only_column');
    });
    Harness::invoice(1001, 1);
    Harness::fire('InvoiceCreated', array('invoiceid' => 1001));
    $activity = json_encode(Harness::$activity);
    has($activity, 'InvoiceCreated: database error', 'the failure is in the Activity Log');
    lacks($activity, '4155550101', 'without the number');
    lacks($activity, 'Alexandra', 'without the words of the text');

    Harness::fire('InvoiceCreated', array('invoiceid' => 'not a number'));
    Harness::fire('InvoiceCreated', array());
    \SmsTunnel\Whmcs\Hooks::event('InvoiceCreated', 'not an array');
    ok(true, 'odd hook parameters are ignored');
});

test("a deprecation inside WHMCS's own code does not cancel the module's work", function () {
    setupClients();
    Harness::invoice(1100, 1);
    Harness::$deprecationIn = 'GetClientsDetails';
    Harness::fire('InvoiceCreated', array('invoiceid' => 1100));
    Harness::$deprecationIn = '';
    eq(count(Harness::sends()), 1, 'the text went out');
    eq($GLOBALS['smstunnel_test_notices'], array('Optional parameter declared before required parameter'), "WHMCS's handler got its deprecation");
    ok(\SmsTunnel\Whmcs\Guard::isOwn(SMSTUNNEL_REPO . '/modules/addons/smstunnel/lib/Notifier.php'), 'the addon is the module\'s own code');
    ok(\SmsTunnel\Whmcs\Guard::isOwn(SMSTUNNEL_REPO . '/modules/security/smstunnel2fa/smstunnel2fa.php'), 'so is the sign-in code module');
    ok(!\SmsTunnel\Whmcs\Guard::isOwn(SMSTUNNEL_REPO . '/tests/harness/bootstrap.php'), 'WHMCS (here its stand-in) is not');
});

test('staff alerts: at most 10 of a kind an hour to each number (a ticket flood cannot flood the phones)', function () {
    setupClients();
    Module::settings()->set(Settings::STAFF_NUMBERS, array('+14155550190'));
    for ($i = 1; $i <= 12; ++$i) {
        Harness::ticket(1200 + $i, 0, array('tid' => 'SPAM-' . $i, 'subject' => 'Buy now'));
        Harness::fire('TicketOpen', array('ticketid' => 1200 + $i, 'ticketmask' => 'SPAM-' . $i, 'userid' => 0, 'subject' => 'Buy now', 'priority' => 'Low'));
    }
    eq(count(Harness::sends()), 10, 'ten alerts');
    $rows = Harness::logRows();
    eq(array($rows[10]['status'], $rows[11]['status']), array(TextLog::SKIPPED, TextLog::SKIPPED));
    has((string) $rows[11]['error'], 'more than 10 alerts of this kind in an hour');
    Harness::$now += 3601;
    Harness::ticket(1300, 1);
    Harness::fire('TicketOpen', array('ticketid' => 1300, 'userid' => 1, 'subject' => 'Real question'));
    eq(count(Harness::sends()), 11, 'an hour later alerts go out again');
});

test('hooks.php registers every hook point the module documents', function () {
    Harness::loadHooks();
    $hooks = array_keys($GLOBALS['smstunnel_test_hooks']);
    sort($hooks);
    $expected = array_merge(array_keys(\SmsTunnel\Whmcs\Hooks::EVENTS), array('AfterCronJob', 'DailyCronJob'));
    sort($expected);
    eq($hooks, $expected);
});
