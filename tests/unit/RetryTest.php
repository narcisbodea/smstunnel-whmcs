<?php
/**
 * When texts are tried again, with which key, and when never.
 *
 * @license MIT
 */

use SmsTunnel\Whmcs\Api\ApiException;
use SmsTunnel\Whmcs\Api\TransportException;
use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Send\RetryPolicy;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;

group('retries');

/** One client, one unpaid invoice per call; returns a function that fires InvoiceCreated for a new invoice. */
function invoiceMaker(): callable
{
    Harness::activate(Harness::testKey());
    Harness::usClient(1, '4155550101');

    return function () {
        static $id = 2000;
        ++$id;
        Harness::invoice($id, 1);
        Harness::fire('InvoiceCreated', array('invoiceid' => $id));

        return $id;
    };
}

function lastRow(): array
{
    $rows = Harness::logRows();

    return $rows[count($rows) - 1];
}

test('the rules: what is retried, what never is', function () {
    $cases = array(
        'network' => array(new TransportException('Could not reach SMSTunnel'), RetryPolicy::RETRY, true),
        'timeout after sending' => array(new TransportException('No answer', 28, true), RetryPolicy::RETRY, true),
        '500' => array(new ApiException(500, array('message' => 'Internal server error')), RetryPolicy::RETRY, true),
        '503 from a proxy' => array(new ApiException(503, '<html>Service Unavailable</html>'), RetryPolicy::RETRY, true),
        '500 replayed' => array(new ApiException(500, array('message' => 'Internal server error'), array('idempotent-replayed' => 'true')), RetryPolicy::FINAL, false),
        '429' => array(new ApiException(429, array('message' => 'Too Many Requests'), array('retry-after' => '42')), RetryPolicy::RETRY, true),
        '409 in progress' => array(new ApiException(409, array('code' => 'IDEMPOTENCY_KEY_IN_PROGRESS'), array('retry-after' => '1')), RetryPolicy::RETRY, false),
        '409 other' => array(new ApiException(409, array('message' => 'Conflict')), RetryPolicy::FINAL, false),
        '400' => array(new ApiException(400, array('message' => array('to must be a string'))), RetryPolicy::FINAL, false),
        '401' => array(new ApiException(401, array('message' => 'Invalid or expired API key')), RetryPolicy::FINAL, true),
        '403' => array(new ApiException(403, array('code' => 'PERMISSION_REQUIRED')), RetryPolicy::FINAL, true),
        '404' => array(new ApiException(404, array('message' => 'Device not found')), RetryPolicy::FINAL, false),
        '422 key reused' => array(new ApiException(422, array('code' => 'IDEMPOTENCY_KEY_REUSED')), RetryPolicy::FINAL, false),
    );
    foreach ($cases as $name => $case) {
        $result = RetryPolicy::classify($case[0]);
        eq(array($result[0], $result[2]), array($case[1], $case[2]), $name);
    }
    eq(RetryPolicy::classify(new ApiException(429, null, array('retry-after' => '42')))[1], 42, 'Retry-After read');

    $first = 1000000;
    eq(RetryPolicy::nextAttempt(1, null, $first, $first), $first + 300, 'after the 1st try: 5 minutes');
    eq(RetryPolicy::nextAttempt(2, null, $first, $first + 400), $first + 400 + 900, 'then 15 minutes');
    eq(RetryPolicy::nextAttempt(5, null, $first, $first + 20000), $first + 20000 + 21600, 'then 6 hours');
    eq(RetryPolicy::nextAttempt(6, null, $first, $first + 50000), null, 'at most 6 tries');
    eq(RetryPolicy::nextAttempt(1, 120, $first, $first), $first + 120, 'Retry-After honoured');
    eq(RetryPolicy::nextAttempt(1, 5, $first, $first), $first + 60, 'not sooner than a minute');
    eq(RetryPolicy::nextAttempt(1, 90000, $first, $first), null, 'never past 23 hours after the first try (SMSTunnel keeps a key 24 h)');
    eq(RetryPolicy::nextAttempt(3, null, $first, $first + 82000), null, 'the window is closing');
});

test('a network error: retried by the cron with the same key and the same request, then sent once', function () {
    $invoice = invoiceMaker();
    Harness::respond(new TransportException('Could not reach SMSTunnel at https://smstunnel.io: Connection timed out', 28, true));
    $invoice();
    $row = lastRow();
    eq(array($row['status'], (int) $row['attempts']), array(TextLog::RETRY, 1));
    eq($row['next_attempt_at'], date('Y-m-d H:i:s', Harness::$now + 300), 'in 5 minutes');
    ok($row['request'] !== null, 'the request is kept for the retry');
    $first = Harness::sends()[0];

    Harness::nextRequest();
    Harness::$now += 120;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 1, 'not due yet: no call');

    Harness::nextRequest();
    Harness::$now += 200;
    Harness::respond(Harness::sent('msg-after-retry'));
    Harness::fire('AfterCronJob');
    $sends = Harness::sends();
    eq(count($sends), 2, 'retried');
    eq($sends[1]['headers']['idempotency-key'], $first['headers']['idempotency-key'], 'the SAME Idempotency-Key');
    eq($sends[1]['raw'], $first['raw'], 'exactly the same request');
    $row = lastRow();
    eq(array($row['status'], $row['message_id'], (int) $row['attempts'], $row['request']), array(TextLog::SENT, 'msg-after-retry', 2, null));

    Harness::nextRequest();
    Harness::$now += 3600;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 2, 'nothing more once sent');
});

test('the retry keeps its phone and words even after the settings change', function () {
    $invoice = invoiceMaker();
    Module::settings()->set(Settings::DEVICE_ID, 'phone-a');
    Harness::respond(Harness::error(503, array('message' => 'Service Unavailable')));
    $invoice();
    Module::settings()->set(Settings::DEVICE_ID, 'phone-b');
    Module::settings()->setEvent('invoice_created', true, 'A different text now');
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::fire('AfterCronJob');
    $sends = Harness::sends();
    eq($sends[1]['body'], $sends[0]['body'], 'same body: a changed body under the same key would be refused (422)');
    eq($sends[1]['body']['deviceId'], 'phone-a');
});

test('429: waits as long as Retry-After says, and the texts after it in the same request wait too', function () {
    $invoice = invoiceMaker();
    Harness::respond(Harness::error(429, array('message' => 'ThrottlerException: Too Many Requests'), array('Retry-After' => '120')));
    $invoice();
    $invoice();
    $invoice();
    eq(count(Harness::sends()), 1, 'only the first text called SMSTunnel');
    $rows = Harness::logRows();
    eq(array_column($rows, 'status'), array(TextLog::RETRY, TextLog::RETRY, TextLog::RETRY));
    eq($rows[0]['next_attempt_at'], date('Y-m-d H:i:s', Harness::$now + 120), 'Retry-After: 120');
    eq((int) $rows[1]['attempts'], 0, 'the others were not tried');
    has((string) $rows[1]['error'], 'Not tried yet: HTTP 429');

    Harness::nextRequest();
    Harness::$now += 301;
    Harness::fire('AfterCronJob');
    $sends = Harness::sends();
    eq(count($sends), 4, 'all three went out on the next cron run');
    eq(count(array_unique(array_map(function ($send) {
        return $send['headers']['idempotency-key'];
    }, array_slice($sends, 1)))), 3, 'each with its own key');
    eq(array_column(Harness::logRows(), 'status'), array(TextLog::SENT, TextLog::SENT, TextLog::SENT));
});

test('4xx: never retried; 401 stops the rest of the request', function () {
    $invoice = invoiceMaker();
    Harness::respond(Harness::error(400, array('message' => array('Phone number must contain 7-25 characters'), 'error' => 'Bad Request')));
    $invoice();
    $row = lastRow();
    eq(array($row['status'], $row['request'], $row['next_attempt_at']), array(TextLog::FAILED, null, null));
    has((string) $row['error'], 'HTTP 400: Phone number must contain 7-25 characters');

    Harness::nextRequest();
    Harness::respond(Harness::error(401, array('message' => 'Invalid or expired API key', 'error' => 'Unauthorized')));
    $invoice();
    $invoice();
    eq(count(Harness::sends()), 2, 'the second text of the request was not tried');
    $rows = Harness::logRows();
    eq(array($rows[1]['status'], $rows[2]['status']), array(TextLog::FAILED, TextLog::FAILED));
    has((string) $rows[2]['error'], 'Not sent: HTTP 401');
    has(json_encode(Harness::$activity), 'texts are not going out: HTTP 401', 'the Activity Log says why');

    Harness::nextRequest();
    Harness::$now += 7200;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 2, 'the cron retries none of them');
    foreach (array(403, 404, 422) as $status) {
        Harness::nextRequest();
        Harness::respond(Harness::error($status, array('message' => 'refused')));
        $invoice();
        eq(lastRow()['status'], TextLog::FAILED, $status . ' is final');
    }
});

test('5xx: retried; a 5xx SMSTunnel replays from its key record is final', function () {
    $invoice = invoiceMaker();
    Harness::respond(Harness::error(500, array('message' => 'Internal server error')));
    $invoice();
    eq(lastRow()['status'], TextLog::RETRY);
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::respond(Harness::error(500, array('message' => 'Internal server error'), array('Idempotent-Replayed' => 'true')));
    Harness::fire('AfterCronJob');
    $row = lastRow();
    eq($row['status'], TextLog::FAILED, 'final: the same key keeps answering this error, and a new key could send the text twice');
    has((string) $row['error'], 'check Messages in your dashboard');
    Harness::nextRequest();
    Harness::$now += 86400;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 2, 'not retried again');
});

test('a 2xx replayed from the key record: the first attempt had worked', function () {
    $invoice = invoiceMaker();
    Harness::respond(new TransportException('No answer from SMSTunnel', 28, true));
    $invoice();
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::respond(Harness::sent('msg-first-attempt', false, array('Idempotent-Replayed' => 'true')));
    Harness::fire('AfterCronJob');
    eq(array(lastRow()['status'], lastRow()['message_id']), array(TextLog::SENT, 'msg-first-attempt'));
});

test('409 IDEMPOTENCY_KEY_IN_PROGRESS is asked again later (the same key can only return the first answer)', function () {
    $invoice = invoiceMaker();
    Harness::respond(new TransportException('No answer', 28, true));
    $invoice();
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::respond(Harness::error(409, array('code' => 'IDEMPOTENCY_KEY_IN_PROGRESS', 'message' => 'A request with this Idempotency-Key is still being processed.'), array('Retry-After' => '1')));
    Harness::fire('AfterCronJob');
    $row = lastRow();
    eq($row['status'], TextLog::RETRY);
    eq($row['next_attempt_at'], date('Y-m-d H:i:s', Harness::$now + 60), 'a minute later');
    Harness::nextRequest();
    Harness::$now += 61;
    Harness::fire('AfterCronJob');
    eq(lastRow()['status'], TextLog::SENT);
});

test('at most 6 tries, then given up', function () {
    $invoice = invoiceMaker();
    for ($i = 0; $i < 6; ++$i) {
        Harness::respond(Harness::error(502, '<html><h1>502 Bad Gateway</h1></html>'));
    }
    $invoice();
    foreach (array(301, 901, 3601, 10801, 21601, 21601, 21601) as $wait) {
        Harness::nextRequest();
        Harness::$now += $wait;
        Harness::fire('AfterCronJob');
    }
    eq(count(Harness::sends()), 6, 'six calls');
    $row = lastRow();
    eq(array($row['status'], (int) $row['attempts'], $row['request']), array(TextLog::FAILED, 6, null));
    has((string) $row['error'], 'Gave up after 6 tries: HTTP 502: 502 Bad Gateway');
});

test('the queue: the phone is offline, SMSTunnel keeps the text (not an error, not retried)', function () {
    $invoice = invoiceMaker();
    Harness::respond(Harness::sent('msg-queued', true));
    $invoice();
    $row = lastRow();
    eq(array($row['status'], $row['message_id'], $row['error']), array(TextLog::QUEUED, 'msg-queued', null));
    Harness::nextRequest();
    Harness::$now += 3600;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 1, 'never sent again');
});

test('a cron run retries at most 20 texts and stops at the first sign that SMSTunnel is unwell', function () {
    $invoice = invoiceMaker();
    for ($i = 0; $i < 25; ++$i) {
        Harness::nextRequest();
        Harness::respond(new TransportException('Could not reach SMSTunnel'));
        $invoice();
    }
    eq(count(Harness::sends()), 25);
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 45, '20 retried in one run');
    Harness::nextRequest();
    Harness::$now += 60;
    Harness::respond(Harness::error(503, array('message' => 'down')));
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 46, 'one call, then the run stopped');
    Harness::nextRequest();
    Harness::$now += 60;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 50, 'the next run sent the 4 still due');
});

test('a text left "sending" by a request that died is retried with its key', function () {
    $invoice = invoiceMaker();
    Harness::respond(function () {
        throw new \Error('PHP died here');
    });
    try {
        $invoice();
    } catch (\Throwable $e) {
        // Hooks catches everything; nothing reaches here.
    }
    $row = lastRow();
    // Simulate a request that died between the claim and the answer.
    \WHMCS\Database\Capsule::table('mod_smstunnel_log')->where('id', $row['id'])->update(array('status' => TextLog::SENDING, 'request' => json_encode(array('to' => '+14155550101', 'message' => 'Hello'))));
    Harness::nextRequest();
    Harness::$now += 300;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 1, 'not stale yet (10 minutes)');
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::fire('AfterCronJob');
    $sends = Harness::sends();
    eq(count($sends), 2);
    eq($sends[1]['headers']['idempotency-key'], $row['idempotency_key'], 'the same key');
    eq(lastRow()['status'], TextLog::SENT);
});

test('two cron runs at once cannot both take the same text', function () {
    $invoice = invoiceMaker();
    Harness::respond(new TransportException('down'));
    $invoice();
    $id = (int) lastRow()['id'];
    Harness::$now += 301;
    ok(Module::log()->claim($id), 'the first run takes it');
    ok(!Module::log()->claim($id), 'the second cannot');
});

test('texts older than the 23-hour window are given up without a call', function () {
    $invoice = invoiceMaker();
    Harness::respond(new TransportException('down'));
    $invoice();
    $id = (int) lastRow()['id'];
    \WHMCS\Database\Capsule::table('mod_smstunnel_log')->where('id', $id)->update(array('first_attempt_at' => date('Y-m-d H:i:s', Harness::$now - 90000)));
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::fire('AfterCronJob');
    eq(count(Harness::sends()), 1, 'no call');
    eq(lastRow()['status'], TextLog::FAILED);
});
