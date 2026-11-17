<?php
/**
 * The PHP side of the end-to-end test (tests/e2e/run.mjs starts it in Docker): the module on the test
 * WHMCS of tests/harness, with the real HTTP client, against a local SMSTunnel API with a simulated
 * phone. It fires WHMCS hook callbacks through hooks.php and writes what it sent to
 * tests/e2e/.state/results.json; run.mjs checks those texts in the API's database, reads the sign-in
 * code the "phone" got and writes it to .state/code.txt; this script then verifies that code.
 *
 *   SMSTUNNEL_E2E_URL=http://host.docker.internal:3171 SMSTUNNEL_E2E_KEY=... SMSTUNNEL_E2E_DEVICE=... php tests/e2e/e2e.php
 *
 * Every recipient is a +1415555xxxx test number.
 *
 * @license MIT
 */

require __DIR__ . '/../harness/bootstrap.php';
require SMSTUNNEL_REPO . '/modules/addons/smstunnel/lib/autoload.php';

use SmsTunnel\Whmcs\Api\Client;
use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Store\Settings;
use WHMCS\Database\Capsule;

$url = rtrim((string) getenv('SMSTUNNEL_E2E_URL'), '/');
$key = (string) getenv('SMSTUNNEL_E2E_KEY');
$device = (string) getenv('SMSTUNNEL_E2E_DEVICE');
$state = __DIR__ . '/.state';
if ($url === '' || $key === '' || !preg_match('~^http://(host\.docker\.internal|127\.0\.0\.1|localhost):\d+$~', $url)) {
    fwrite(STDERR, "SMSTUNNEL_E2E_URL (a local API) and SMSTUNNEL_E2E_KEY are needed.\n");
    exit(2);
}

$GLOBALS['e2e_requests'] = array();

/**
 * The real HTTP client (cURL), recording each request so the test can send one again with its key.
 * Harness::reset() puts the fake transport in; this takes it out again.
 */
function realHttp()
{
    Module::$clientOptions = array('transport' => function ($method, $url, $headers, $payload, $timeout, $connectTimeout) {
        $GLOBALS['e2e_requests'][] = array('url' => $url, 'headers' => $headers, 'payload' => $payload);

        return Client::curlTransport($method, $url, $headers, $payload, $timeout, $connectTimeout);
    });
    Module::reset();
}

function logRow(int $offset = -1): array
{
    $rows = Harness::logRows();

    return $rows[$offset < 0 ? count($rows) + $offset : $offset];
}

Harness::boot();
Harness::$now = time();
realHttp();
Harness::activate($key);
$settings = Module::settings();
$settings->set(Settings::API_URL, $url);
$settings->set(Settings::DEVICE_ID, $device);
$settings->set(Settings::STAFF_NUMBERS, array('+14155550190', '+14155550191'));
foreach (\SmsTunnel\Whmcs\Text\Events::keys() as $event) {
    $settings->setEvent($event, true, '');
}
$optOut = (int) $settings->get(Settings::FIELD_OPT_OUT);

// Clients: WHMCS's formatted number, a national number completed from the country, an opted-out
// client, one without a number, one with a premium number.
Harness::usClient(1, '4155550101');
Harness::client(2, array('firstname' => 'Mihai', 'lastname' => 'Ionescu', 'countrycode' => 'US', 'phonenumber' => '(415) 555-0102'));
Harness::usClient(3, '4155550103', array('firstname' => 'Opted', 'lastname' => 'Out'));
Harness::tick($optOut, 3);
Harness::client(4, array('firstname' => 'No', 'lastname' => 'Phone'));
Harness::client(5, array('firstname' => 'Premium', 'lastname' => 'Number', 'phonenumber' => '+1 900 555 0105'));
Harness::invoice(4001, 1, array('invoicenum' => 'E2E-4001', 'total' => '25.00', 'balance' => '25.00', 'duedate' => date('Y-m-d', time() + 7 * 86400)));
Harness::invoice(4002, 2, array('total' => '40.00', 'balance' => '40.00', 'duedate' => date('Y-m-d', time() - 3 * 86400)));
Harness::invoice(4003, 2, array('status' => 'Paid', 'total' => '120.50', 'balance' => '0.00'));
foreach (array(3, 4, 5) as $client) {
    Harness::invoice(4100 + $client, $client);
}
Harness::service(61, 1, array('name' => 'Business Hosting', 'domain' => 'shop.example.com'));
Harness::ticket(71, 2, array('tid' => 'E2E-7101', 'subject' => 'Email not working', 'deptname' => 'Support'));
Harness::ticket(72, 1, array('tid' => 'E2E-7202', 'subject' => 'Server down', 'deptname' => 'Technical', 'priority' => 'High'));
Harness::order(81, 1, array('ordernum' => 5550001234, 'amount' => '95.40'));

$results = array('texts' => array(), 'skipped' => array(), 'checks' => array());

// Every text comes from a real hook callback of hooks.php; each new log row is recorded.
$fireEach = function (string $hook, array $vars, string $label) use (&$results) {
    $before = count(Harness::logRows());
    Harness::fire($hook, $vars);
    $rows = Harness::logRows();
    for ($i = $before; $i < count($rows); ++$i) {
        $row = $rows[$i];
        $results[$row['status'] === 'skipped' ? 'skipped' : 'texts'][] = array(
            'label' => $label, 'event' => $row['event'], 'to' => $row['recipient'], 'status' => $row['status'],
            'messageId' => (string) $row['message_id'], 'key' => (string) $row['idempotency_key'], 'error' => (string) $row['error'],
        );
    }
};

$fireEach('InvoiceCreated', array('source' => 'autogen', 'user' => 'system', 'invoiceid' => 4001, 'status' => 'Unpaid'), 'invoice created');
$fireEach('InvoicePaymentReminder', array('invoiceid' => 4001, 'type' => 'reminder'), 'payment reminder');
$fireEach('InvoicePaymentReminder', array('invoiceid' => 4002, 'type' => 'firstoverdue'), 'overdue reminder');
$fireEach('InvoicePaid', array('invoiceid' => 4003), 'invoice paid');
$params = array('serviceid' => 61, 'userid' => 1, 'domain' => 'shop.example.com', 'username' => 'shopexam', 'password' => 'not-logged');
$fireEach('AfterModuleCreate', array('params' => $params), 'service activated');
$fireEach('AfterModuleSuspend', array('params' => $params), 'service suspended');
$fireEach('AfterModuleUnsuspend', array('params' => $params), 'service unsuspended');
$fireEach('TicketAdminReply', array('ticketid' => 71, 'replyid' => 1, 'deptname' => 'Support', 'subject' => 'Email not working', 'message' => 'Fixed.', 'status' => 'Answered'), 'ticket reply');
$fireEach('AfterRegistrarRegistration', array('params' => array('userid' => 1, 'domainid' => 91, 'sld' => 'example-shop', 'tld' => 'com', 'regperiod' => 1)), 'domain registered');
$fireEach('AfterRegistrarRenewal', array('params' => array('userid' => 2, 'domainid' => 92, 'sld' => 'mihai-blog', 'tld' => '.net', 'regperiod' => 2)), 'domain renewed');
$fireEach('OrderPaid', array('orderId' => 81, 'userId' => 1, 'invoiceId' => 4001), 'new paid order');
$fireEach('TicketOpen', array('ticketid' => 72, 'ticketmask' => 'E2E-7202', 'userid' => 1, 'deptname' => 'Technical', 'subject' => 'Server down', 'priority' => 'High', 'message' => 'Help'), 'new ticket');
$fireEach('TicketUserReply', array('ticketid' => 72, 'replyid' => 2, 'userid' => 1, 'deptname' => 'Technical', 'subject' => 'Server down', 'priority' => 'High', 'message' => 'Still down', 'status' => 'Customer-Reply'), 'client reply');
foreach (array(3, 4, 5) as $client) {
    $fireEach('InvoiceCreated', array('invoiceid' => 4100 + $client), 'skipped client ' . $client);
}
// The same hook again: nothing new.
$before = count(Harness::logRows());
Harness::fire('InvoiceCreated', array('invoiceid' => 4001));
$results['checks']['duplicateHookSentNothing'] = count(Harness::logRows()) === $before;

// The test text, through the admin page's form with its CSRF token.
$_GET = array('module' => 'smstunnel');
$_POST = array('smstunnel_action' => 'send_test', 'token' => generate_token('plain'), 'test_number' => '+14155550199', 'test_message' => 'SMSTunnel for WHMCS end-to-end test text');
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
smstunnel_output(array('modulelink' => 'addonmodules.php?module=smstunnel'));
$page = (string) ob_get_clean();
$row = logRow();
$results['texts'][] = array('label' => 'test text', 'event' => $row['event'], 'to' => $row['recipient'], 'status' => $row['status'], 'messageId' => (string) $row['message_id'], 'key' => (string) $row['idempotency_key'], 'error' => (string) $row['error']);
$results['checks']['testTextShownOnPage'] = strpos($page, (string) $row['message_id']) !== false;
$devicesShown = strpos($page, '(online)') !== false;
$results['checks']['phonesListed'] = $devicesShown;
realHttp();

// Idempotency against the real server: the first text's exact request again, with its key (what a
// retry after a lost answer does).
$first = $results['texts'][0];
$firstRequest = null;
foreach ($GLOBALS['e2e_requests'] as $request) {
    if (substr($request['url'], -strlen('/api/v1/sms/send')) === '/api/v1/sms/send') {
        $firstRequest = $request;
        break;
    }
}
$firstKey = '';
foreach ($firstRequest['headers'] as $header) {
    if (stripos($header, 'Idempotency-Key:') === 0) {
        $firstKey = trim(substr($header, strlen('Idempotency-Key:')));
    }
}
$body = json_decode((string) $firstRequest['payload'], true);
$client = new Client($key, $url);
$replay = $client->sendSms($body, $firstKey);
$results['checks']['replay'] = array('messageId' => $replay->messageId, 'replayed' => $replay->replayed, 'sameMessageId' => $replay->messageId === $first['messageId'], 'keyIsTheLoggedOne' => $firstKey === $first['key']);
try {
    $client->sendSms(array('message' => 'Another text') + $body, $firstKey);
    $results['checks']['reusedKey'] = 'accepted';
} catch (\SmsTunnel\Whmcs\Api\ApiException $e) {
    $results['checks']['reusedKey'] = $e->getStatus() . ' ' . $e->getErrorCode();
}

// An outage: the API cannot be reached (a closed port of this container), so the text waits; the
// next cron run sends it with its key.
Module::settings()->set(Settings::API_URL, 'http://127.0.0.1:9');
Harness::invoice(4004, 1, array('status' => 'Paid', 'total' => '9.99', 'balance' => '0.00'));
Harness::fire('InvoicePaid', array('invoiceid' => 4004));
$waiting = logRow();
$results['checks']['outage'] = array('status' => $waiting['status'], 'error' => (string) $waiting['error'], 'key' => (string) $waiting['idempotency_key']);
Harness::nextRequest();
Module::settings()->set(Settings::API_URL, $url);
Harness::$now += 301;
Harness::fire('AfterCronJob');
$retried = Capsule::table('mod_smstunnel_log')->where('id', $waiting['id'])->first();
$results['texts'][] = array('label' => 'invoice paid after an outage', 'event' => $retried->event, 'to' => $retried->recipient, 'status' => $retried->status, 'messageId' => (string) $retried->message_id, 'key' => (string) $retried->idempotency_key, 'error' => (string) $retried->error, 'attempts' => (int) $retried->attempts);

// A sign-in code to the user's phone.
require SMSTUNNEL_REPO . '/modules/security/smstunnel2fa/smstunnel2fa.php';
$user = array('settings' => array(), 'user_info' => array('id' => 7, 'email' => 'jane@example.com', 'username' => 'jane'), 'user_settings' => array('phone' => '+14155550177'), 'post_vars' => array());
$challenge = smstunnel2fa_challenge($user);
$row = logRow();
$results['signIn'] = array('to' => '+14155550177', 'status' => $row['status'], 'messageId' => (string) $row['message_id'], 'shown' => strpos($challenge, 'We texted a sign-in code') !== false, 'loggedRequest' => $row['request']);

// What each text said, from the requests that left (the log does not keep the words).
$sentBodies = array();
foreach ($GLOBALS['e2e_requests'] as $request) {
    foreach ($request['headers'] as $header) {
        if (stripos($header, 'Idempotency-Key:') === 0 && $request['payload'] !== null) {
            $sentBodies[trim(substr($header, strlen('Idempotency-Key:')))] = json_decode((string) $request['payload'], true);
        }
    }
}
foreach ($results['texts'] as $i => $text) {
    $results['texts'][$i]['body'] = isset($sentBodies[$text['key']]) ? $sentBodies[$text['key']] : null;
}
$results['requests'] = count($GLOBALS['e2e_requests']);

$results['log'] = array_map(function ($row) {
    return array('event' => $row['event'], 'status' => $row['status'], 'recipient' => $row['recipient'], 'error' => $row['error'], 'hasRequest' => $row['request'] !== null);
}, Harness::logRows());
file_put_contents($state . '/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "results written\n";

// Wait for run.mjs to read the code from the API's database (the "phone" got it).
$codeFile = $state . '/code.txt';
for ($i = 0; $i < 600 && !is_file($codeFile); ++$i) {
    usleep(100000);
}
$code = is_file($codeFile) ? trim((string) file_get_contents($codeFile)) : '';
$verify = function ($typed) use ($user) {
    $params = $user;
    $params['post_vars'] = array('key' => $typed);

    return smstunnel2fa_verify($params);
};
$wrong = $code === '000000' ? '111111' : '000000';
$verified = array(
    'codeReceived' => preg_match('/^\d{6}$/', $code) === 1,
    'wrong' => $verify($wrong),
    'right' => $verify($code),
    'again' => $verify($code),
    'codeNotStored' => strpos(json_encode(Capsule::table('mod_smstunnel_codes')->get()) . json_encode(Harness::logRows()) . json_encode(Harness::$activity), $code) === false,
);
file_put_contents($state . '/verify.json', json_encode($verified, JSON_PRETTY_PRINT));
echo "verify written\n";
