<?php
/**
 * The admin page: CSRF tokens, escaping, saving, the test text, the log.
 *
 * @license MIT
 */

use SmsTunnel\Whmcs\Admin\Page;
use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;

group('admin page');

/**
 * Renders the page as WHMCS would (smstunnel_output echoes it).
 *
 * @param array<string, mixed> $get
 * @param array<string, mixed> $post
 */
function adminPage(array $get = array(), array $post = array()): string
{
    $_GET = $get + array('module' => 'smstunnel');
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    ob_start();
    smstunnel_output(array('modulelink' => 'addonmodules.php?module=smstunnel', 'version' => '1.0.0', '_lang' => array()));
    $html = (string) ob_get_clean();
    Module::reset();

    return $html;
}

function token(): string
{
    return generate_token('plain');
}

test('every tab renders; forms carry the CSRF token; the key is never shown in full', function () {
    Harness::activate(Harness::testKey());
    foreach (array('setup', 'texts', 'staff', 'log') as $tab) {
        $html = adminPage(array('tab' => $tab));
        has($html, '<li class="active"><a href="addonmodules.php?module=smstunnel&amp;tab=' . $tab . '">', $tab . ' tab active');
        lacks($html, Harness::testKey(), $tab . ': no full key');
        if ($tab !== 'log') {
            has($html, '<input type="hidden" name="token" value="' . token() . '">', $tab . ': CSRF token');
        }
    }
    eq(count(Harness::sends('/api/v1/devices')), 1, 'only the Setup tab lists the phones');
    Harness::$requests = array();
    $html = adminPage();
    has($html, 'Saved: sk_live_••••••••' . substr(Harness::testKey(), -4), 'the masked key');
    has($html, 'Store phone (online)', 'phones listed from GET /api/v1/devices');
    has($html, 'Any of my phones (the one that is online)');
    has($html, Version_play(), 'the Google Play link');
    has($html, 'Not affiliated with, endorsed by, or sponsored by WHMCS Limited.');
    eq(count(Harness::sends('/api/v1/devices')), 1, 'one devices call');
    $texts = adminPage(array('tab' => 'texts'));
    has($texts, 'data-insert="{invoice_total}"', 'merge field buttons');
    has($texts, '1 SMS · ', 'the part counter, filled on the server too');
    has($texts, 'SmsTunnelCounter', 'the counter script');
    foreach (array('gateway', 'bulk', 'campaign', 'marketing') as $word) {
        lacks(strtolower(strip_tags($html . $texts)), $word, 'wording: ' . $word);
    }
});

function Version_play(): string
{
    return htmlspecialchars(\SmsTunnel\Whmcs\Version::GOOGLE_PLAY_URL, ENT_QUOTES, 'UTF-8');
}

test('a form without the right CSRF token changes nothing', function () {
    Harness::activate(Harness::testKey());
    $form = array('smstunnel_action' => 'save_setup', 'api_url' => 'https://evil.example', 'log_days' => '5', 'device_id' => '');
    $html = adminPage(array(), $form);
    has($html, 'CSRF check');
    $html = adminPage(array(), $form + array('token' => 'wrong'));
    has($html, 'CSRF check');
    eq(Module::settings()->apiUrl(), 'https://smstunnel.io', 'unchanged');
    eq(Module::settings()->logDays(), 90, 'unchanged');
    $html = adminPage(array(), array('smstunnel_action' => 'send_test', 'test_number' => '+14155550101', 'test_message' => 'x', 'token' => ''));
    eq(count(Harness::sends()), 0, 'no test text without the token');
    $html = adminPage(array(), array('smstunnel_action' => 'save_staff', 'staff_numbers' => '+14155550190', 'token' => 'nope'));
    eq(Module::settings()->staffNumbers(), array(), 'staff numbers unchanged');
});

test('saving the setup: a new key is stored encrypted, the old one is kept when the box is empty', function () {
    Harness::activate();
    $newKey = 'sk_' . 'live_' . 'admin0123456789abcdef0123456789';
    $html = adminPage(array(), array('smstunnel_action' => 'save_setup', 'token' => token(), 'api_key' => $newKey, 'api_url' => 'https://smstunnel.io', 'device_id' => '6650c0ffee0000000000a001', 'log_days' => '30', 'opt_in' => '1', 'delete_on_deactivate' => '1'));
    has($html, 'Settings saved.');
    lacks($html, $newKey, 'the new key is not echoed back');
    $settings = Module::settings();
    eq($settings->apiKey(), $newKey);
    eq($settings->deviceId(), '6650c0ffee0000000000a001');
    eq($settings->logDays(), 30);
    eq($settings->optIn(), true);
    eq($settings->deleteOnDeactivate(), true);
    ok((int) $settings->get(Settings::FIELD_OPT_IN) > 0, 'opt-in mode created its field');
    adminPage(array(), array('smstunnel_action' => 'save_setup', 'token' => token(), 'api_key' => '', 'api_url' => 'https://smstunnel.io', 'device_id' => '', 'log_days' => '30'));
    eq(Module::settings()->apiKey(), $newKey, 'an empty key box keeps the key');
    eq(Module::settings()->optIn(), false, 'unticked');
    $html = adminPage(array(), array('smstunnel_action' => 'save_setup', 'token' => token(), 'api_url' => 'ftp://nope', 'log_days' => '30'));
    has($html, 'must be an address like https://smstunnel.io');
    $html = adminPage(array(), array('smstunnel_action' => 'save_setup', 'token' => token(), 'api_url' => 'http://127.0.0.1:3171', 'log_days' => '30'));
    has($html, 'does not use https://');
    adminPage(array(), array('smstunnel_action' => 'save_setup', 'token' => token(), 'remove_key' => '1', 'api_url' => 'https://smstunnel.io', 'log_days' => '30'));
    eq(Module::settings()->hasApiKey(), false, 'removed');
    $activity = json_encode(Harness::$activity);
    lacks($activity, $newKey, 'the key never reaches the Activity Log');
});

test('everything shown is escaped: phone names, errors, texts, company name', function () {
    Harness::activate(Harness::testKey());
    Harness::setting('CompanyName', 'Evil <script>alert(1)</script> Co');
    Harness::respond(array('status' => 200, 'headers' => array(), 'body' => json_encode(array('success' => true, 'data' => array('devices' => array(
        array('id' => 'x1', 'name' => '<img src=x onerror=alert(2)>', 'isOnline' => false, 'lastSeenAt' => ''),
    ))))));
    $html = adminPage();
    lacks($html, '<img src=x', 'phone name escaped');
    has($html, '&lt;img src=x onerror=alert(2)&gt;');
    lacks($html, '<script>alert(1)</script>', 'company name escaped');
    Module::settings()->setEvent('invoice_paid', true, '</textarea><script>alert(3)</script>');
    $texts = adminPage(array('tab' => 'texts'));
    lacks($texts, '<script>alert(3)</script>', 'a text is escaped inside its textarea');
    Module::log()->create(array('event' => 'invoice_paid', 'audience' => 'client', 'status' => TextLog::FAILED, 'error' => '<b onmouseover=alert(4)>', 'recipient' => '<i>'));
    $log = adminPage(array('tab' => 'log'));
    lacks($log, '<b onmouseover', 'errors escaped');
    lacks($log, '<i>', 'numbers escaped');
    $log = adminPage(array('tab' => 'log', 'status' => '"><script>', 'event' => '<x>', 'page' => '-3'));
    lacks($log, '"><script>', 'filters are checked, not echoed');
});

test('the log names each event once, linked to what it was about', function () {
    Harness::activate(Harness::testKey());
    Harness::usClient(1, '4155550101');
    $rows = array(
        array('invoice_paid', 'client', 'invoice', 1041),
        array('ticket_reply', 'client', 'ticket', 77),
        array('staff_order_paid', 'staff', 'order', 512),
        array('service_activated', 'client', 'service', 9),
        array('domain_registered', 'client', 'domain', 4),
        array('invoice_created', 'client', 'invoice', 0),
    );
    foreach ($rows as $row) {
        Module::log()->create(array('event' => $row[0], 'audience' => $row[1], 'client_id' => 1, 'related_type' => $row[2], 'related_id' => $row[3], 'recipient' => '+14155550101', 'status' => TextLog::SENT, 'message_id' => ''));
    }
    $log = adminPage(array('tab' => 'log'));
    has($log, 'Invoice paid <a href="invoices.php?action=edit&amp;id=1041">#1041</a>', 'an invoice: the name, then its number');
    has($log, '<a href="supporttickets.php?action=view&amp;id=77">Staff reply on a ticket</a>', 'a ticket: the name is the link');
    has($log, '<a href="orders.php?action=view&amp;id=512">New paid order</a>', 'an order: the name is the link');
    has($log, '<a href="clientsservices.php?userid=1&amp;id=9">Service activated</a>');
    has($log, '<a href="clientsdomains.php?userid=1&amp;id=4">Domain registered</a>');
    foreach (array('>ticket</a>', '>order</a>', '>service</a>', '>domain</a>') as $word) {
        lacks($log, $word, 'no word said twice: ' . $word);
    }
    has($log, '<td>Invoice created</td>', 'nothing to link: the name alone');
    lacks($log, '<code></code>', 'no empty box where a text has no message ID');
});

test('the test text: sent now with a key, the result shown, errors explained', function () {
    Harness::activate(Harness::testKey());
    Harness::respond(Harness::sent('msg-test-1'));
    $html = adminPage(array(), array('smstunnel_action' => 'send_test', 'token' => token(), 'test_number' => '(415) 555-0123', 'test_message' => 'Hello from WHMCS'));
    has($html, 'message ID msg-test-1');
    $send = Harness::sends()[0];
    eq($send['body']['to'], '+14155550123', 'a national number completed with the default country (US)');
    eq($send['body']['message'], 'Hello from WHMCS');
    ok(isset($send['headers']['idempotency-key']), 'with an Idempotency-Key');
    $row = Harness::logRows()[0];
    eq(array($row['event'], $row['status'], $row['request']), array('test', TextLog::SENT, null));

    Harness::respond(Harness::sent('msg-test-2', true));
    $html = adminPage(array(), array('smstunnel_action' => 'send_test', 'token' => token(), 'test_number' => '+14155550123', 'test_message' => ''));
    has($html, 'your phone is offline: it goes out as soon as the phone is back online');
    has(Harness::sends()[1]['body']['message'], 'This is a test text from Example Hosting', 'the default test text');

    Harness::respond(Harness::error(401, array('message' => 'Invalid or expired API key')));
    $html = adminPage(array(), array('smstunnel_action' => 'send_test', 'token' => token(), 'test_number' => '+14155550123', 'test_message' => 'x'));
    has($html, 'SMSTunnel refused the API key');
    has($html, 'Apps → Add an app');
    Harness::respond(Harness::error(403, array('code' => 'PERMISSION_REQUIRED', 'message' => "This API key lacks the 'sms:send' permission")));
    has(adminPage(array(), array('smstunnel_action' => 'send_test', 'token' => token(), 'test_number' => '+14155550123')), 'This API key may not send texts');
    Harness::respond(new \SmsTunnel\Whmcs\Api\TransportException('Could not reach SMSTunnel'));
    has(adminPage(array(), array('smstunnel_action' => 'send_test', 'token' => token(), 'test_number' => '+14155550123')), 'could not be reached from your server');
    $html = adminPage(array(), array('smstunnel_action' => 'send_test', 'token' => token(), 'test_number' => '+1 900 555 0123'));
    has($html, 'premium-rate');
    eq(count(Harness::sends()), 5, 'no call for a premium number');
});

test('texts and staff numbers are checked when saved', function () {
    Harness::activate(Harness::testKey());
    $html = adminPage(array('tab' => 'texts'), array('smstunnel_action' => 'save_texts', 'token' => token(),
        'on' => array('invoice_created' => '1', 'invoice_paid' => '1'),
        'text' => array('invoice_created' => 'Invoice {invoice_numbr} is ready', 'invoice_paid' => '')));
    has($html, '{invoice_numbr} is not a field of this text');
    $settings = Module::settings();
    eq($settings->eventText('invoice_created'), 'Invoice {invoice_numbr} is ready', 'saved anyway');
    eq($settings->eventOn('service_suspended'), false, 'an unticked box turns the text off');
    eq($settings->customText('invoice_paid'), '', 'an empty text keeps the suggestion');
    $html = adminPage(array('tab' => 'texts'), array('smstunnel_action' => 'save_texts', 'token' => token(), 'text' => array('invoice_paid' => str_repeat('x', 1601))));
    has($html, 'longer than 1600 characters');
    // WHMCS hands modules HTML-encoded request values: "Tom & Co" arrives as "Tom &amp; Co".
    adminPage(array('tab' => 'texts'), array('smstunnel_action' => 'save_texts', 'token' => token(), 'on' => array('invoice_paid' => '1'), 'text' => array('invoice_paid' => 'Thanks from Tom &amp; Co &lt;3')));
    eq(Module::settings()->eventText('invoice_paid'), 'Thanks from Tom & Co <3', 'saved as typed');

    $html = adminPage(array('tab' => 'staff'), array('smstunnel_action' => 'save_staff', 'token' => token(), 'staff_numbers' => "+14155550190\nnot-a-number"));
    has($html, 'not-a-number');
    eq(Module::settings()->staffNumbers(), array(), 'not saved');
    adminPage(array('tab' => 'staff'), array('smstunnel_action' => 'save_staff', 'token' => token(), 'staff_numbers' => "+1 415 555 0190\n(415) 555-0191, +1 415 555 0190", 'on' => array('staff_order_paid' => '1')));
    eq(Module::settings()->staffNumbers(), array('+14155550190', '+14155550191'), 'E.164, national ones completed with the default country, no duplicates');
    eq(Module::settings()->eventOn('staff_ticket_opened'), false);
    eq(Module::settings()->eventOn('staff_order_paid'), true);
});

test('the log: rows with client links, states, errors and pages', function () {
    Harness::activate(Harness::testKey());
    Harness::usClient(1);
    $log = Module::log();
    $id = $log->create(array('event' => 'invoice_paid', 'audience' => 'client', 'client_id' => 1, 'related_type' => 'invoice', 'related_id' => 77, 'status' => TextLog::SENDING, 'recipient' => '+14155550101'));
    $log->finish($id, TextLog::SENT, 'msg-77');
    $id = $log->create(array('event' => 'staff_order_paid', 'audience' => 'staff', 'status' => TextLog::SENDING, 'recipient' => '+14155550190'));
    $log->retryAt($id, Harness::$now + 300, 'HTTP 503: Service Unavailable');
    for ($i = 0; $i < 60; ++$i) {
        $log->create(array('event' => 'test', 'audience' => 'test', 'status' => TextLog::SKIPPED));
    }
    $html = adminPage(array('tab' => 'log', 'event' => 'invoice_paid'));
    has($html, '<a href="clientssummary.php?userid=1">Alexandra Popescu</a>');
    has($html, 'invoices.php?action=edit&amp;id=77');
    has($html, 'msg-77');
    has($html, 'label-success');
    $html = adminPage(array('tab' => 'log', 'status' => 'retry'));
    has($html, 'Will retry at');
    has($html, 'HTTP 503: Service Unavailable');
    has($html, 'wait for another try', 'the summary says texts are waiting');
    $html = adminPage(array('tab' => 'log'));
    has($html, '62 text(s)');
    has($html, 'page=2', 'pagination');
});

test('the page refuses a visitor who is not signed in to the admin area', function () {
    Harness::activate(Harness::testKey());
    $_SESSION = array();
    $html = adminPage();
    has($html, 'Sign in to the admin area first.');
    lacks($html, 'token');
});
