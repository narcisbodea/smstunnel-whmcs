<?php
/**
 * The real HTTP client (cURL) against the fake SMSTunnel API (tests/fake-api/server.mjs), which keeps
 * Idempotency-Keys like the server does. Skipped when SMSTUNNEL_FAKE_URL is not set.
 *
 * @license MIT
 */

use SmsTunnel\Whmcs\Api\ApiException;
use SmsTunnel\Whmcs\Api\Client;
use SmsTunnel\Whmcs\Api\TransportException;
use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Send\Outbox;
use SmsTunnel\Whmcs\Send\RetryPolicy;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;

group('HTTP (fake SMSTunnel API)');

function fakeUrl(): string
{
    $url = getenv('SMSTUNNEL_FAKE_URL');

    return is_string($url) ? rtrim($url, '/') : '';
}

function fakeKey(): string
{
    return 'sk_' . 'test_' . 'FakeWhmcsKey000000000000000001';
}

/** @param array<string, mixed> $body */
function fakeCall(string $path, array $body = array()): array
{
    $handle = curl_init(fakeUrl() . $path);
    curl_setopt_array($handle, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body ?: new \stdClass()),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT => 5,
    ));
    $raw = curl_exec($handle);

    return is_string($raw) ? (array) json_decode($raw, true) : array();
}

function fakeClient(float $timeout = 10.0): Client
{
    return new Client(fakeKey(), fakeUrl(), array('timeout' => $timeout, 'connectTimeout' => min(5.0, $timeout), 'userAgent' => 'SMSTunnel-WHMCS/test'));
}

function skipWithoutFake(): bool
{
    if (fakeUrl() === '') {
        ok(true, 'skipped: SMSTUNNEL_FAKE_URL is not set');

        return true;
    }
    fakeCall('/__reset');

    return false;
}

test('a text: headers, body, one recipient, and the answer read', function () {
    if (skipWithoutFake()) {
        return;
    }
    $result = fakeClient()->sendSms(array('to' => '+14155550123', 'message' => 'Hello ăîș 👍', 'defaultCountryCode' => '+1', 'extra' => 'dropped'), 'whmcs-test-key-1');
    ok(strlen($result->messageId) === 24, 'message id');
    eq(array($result->queued, $result->replayed), array(false, false));
    $seen = fakeCall('/__requests');
    $request = $seen['requests'][0];
    ok($request['keyOk'], 'X-API-Key sent');
    eq($request['contentType'], 'application/json');
    eq($request['userAgent'], 'SMSTunnel-WHMCS/test');
    eq($request['idempotencyKey'], 'whmcs-test-key-1');
    eq($request['body'], array('to' => '+14155550123', 'message' => 'Hello ăîș 👍', 'defaultCountryCode' => '+1'), 'only the known fields, Unicode intact');
    $devices = fakeClient()->getDevices();
    eq(array($devices[0]['name'], $devices[0]['isOnline'], $devices[1]['isOnline']), array('Office phone', true, false));
});

test('the same Idempotency-Key twice: the stored answer, one text; another text under it: 422', function () {
    if (skipWithoutFake()) {
        return;
    }
    $body = array('to' => '+14155550123', 'message' => 'Invoice #1 is ready');
    $first = fakeClient()->sendSms($body, 'whmcs-same-key');
    $again = fakeClient()->sendSms($body, 'whmcs-same-key');
    eq($again->messageId, $first->messageId, 'the same message id');
    eq($again->replayed, true, 'Idempotent-Replayed read');
    eq(count(fakeCall('/__requests')['texts']), 1, 'one text');
    $e = throws(function () use ($body) {
        fakeClient()->sendSms(array('message' => 'Something else') + $body, 'whmcs-same-key');
    }, ApiException::class);
    eq(array($e->getStatus(), $e->getErrorCode(), RetryPolicy::classify($e)[0]), array(422, 'IDEMPOTENCY_KEY_REUSED', RetryPolicy::FINAL));
});

test('errors as the API sends them: 401, 403, 429 with Retry-After, a proxy page, 409 in progress, 400 lists', function () {
    if (skipWithoutFake()) {
        return;
    }
    $body = array('to' => '+14155550123', 'message' => 'x');
    $e = throws(function () use ($body) {
        (new Client('sk_' . 'live_wrong0000000000000', fakeUrl()))->sendSms($body);
    }, ApiException::class);
    eq(array($e->getStatus(), $e->getMessage()), array(401, 'Invalid or expired API key'));
    fakeCall('/__control', array('mode' => 'forbidden', 'times' => 1));
    $e = throws(function () use ($body) {
        fakeClient()->sendSms($body);
    }, ApiException::class);
    eq(array($e->getStatus(), $e->getErrorCode(), $e->getPermission()), array(403, 'PERMISSION_REQUIRED', 'sms:send'));
    fakeCall('/__control', array('mode' => 'rate', 'times' => 1));
    $e = throws(function () use ($body) {
        fakeClient()->sendSms($body, 'whmcs-rate');
    }, ApiException::class);
    eq(array($e->getStatus(), $e->getRetryAfter()), array(429, 42));
    fakeCall('/__control', array('mode' => 'html502', 'times' => 1));
    $e = throws(function () use ($body) {
        fakeClient()->sendSms($body);
    }, ApiException::class);
    eq(array($e->getStatus(), $e->getMessage()), array(502, '502 Bad Gateway 502 Bad Gateway nginx'));
    fakeCall('/__control', array('mode' => 'inprogress', 'times' => 1));
    $e = throws(function () use ($body) {
        fakeClient()->sendSms($body, 'whmcs-in-progress');
    }, ApiException::class);
    eq(array($e->getStatus(), $e->getErrorCode(), $e->getRetryAfter(), RetryPolicy::classify($e)[0]), array(409, 'IDEMPOTENCY_KEY_IN_PROGRESS', 1, RetryPolicy::RETRY));
    $e = throws(function () {
        fakeClient()->sendSms(array('to' => '12', 'message' => 'x'));
    }, ApiException::class);
    eq(array($e->getStatus(), $e->getMessage()), array(400, 'Phone number must contain 7-25 characters (digits, spaces, dots, dashes, + allowed)'));
    $e = throws(function () {
        fakeClient()->sendSignInCode(array('to' => '+14155550123', 'message' => 'Your code is ABCDEF'));
    }, ApiException::class);
    eq(array($e->getStatus(), $e->getMessage()), array(400, 'No valid verification code found in message'), 'send-2fa without a code');
});

test('no answer: a timeout and a dropped connection are network errors (retried), a refused connection too', function () {
    if (skipWithoutFake()) {
        return;
    }
    $body = array('to' => '+14155550123', 'message' => 'x');
    fakeCall('/__control', array('mode' => 'hang', 'times' => 1));
    $started = microtime(true);
    $e = throws(function () use ($body) {
        fakeClient(1.5)->sendSms($body, 'whmcs-hang');
    }, TransportException::class);
    ok(microtime(true) - $started < 4, 'the timeout ends it');
    ok($e->mayHaveArrived(), 'the request left: it may have arrived');
    eq(RetryPolicy::classify($e)[0], RetryPolicy::RETRY);
    fakeCall('/__control', array('mode' => 'drop', 'times' => 1));
    throws(function () use ($body) {
        fakeClient()->sendSms($body);
    }, TransportException::class);
    $e = throws(function () use ($body) {
        (new Client(fakeKey(), 'http://127.0.0.1:1', array('connectTimeout' => 2, 'timeout' => 2)))->sendSms($body);
    }, TransportException::class);
    ok(!$e->mayHaveArrived(), 'never connected');
    has($e->getMessage(), 'Could not reach SMSTunnel at http://127.0.0.1:1');
    $e = throws(function () use ($body) {
        (new Client(fakeKey(), 'not a url'))->sendSms($body);
    }, TransportException::class);
    has($e->getMessage(), 'must look like https://smstunnel.io');
});

test('through the module with real HTTP: an outage, then the cron retry with the same key, one text', function () {
    if (skipWithoutFake()) {
        return;
    }
    Module::$clientOptions = array('timeout' => 2.0, 'connectTimeout' => 2.0);
    Harness::activate(fakeKey());
    Module::settings()->set(Settings::API_URL, fakeUrl());
    Harness::usClient(1, '4155550101');
    Harness::invoice(3001, 1);

    fakeCall('/__control', array('mode' => 'error500', 'times' => 1));
    Harness::fire('InvoiceCreated', array('invoiceid' => 3001));
    $row = Harness::logRows()[0];
    eq($row['status'], TextLog::RETRY, 'a 500: retried later');

    // The 500 was stored with the key: SMSTunnel replays it, so the module gives up instead of sending twice.
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::fire('AfterCronJob');
    $row = Harness::logRows()[0];
    eq($row['status'], TextLog::FAILED);
    has((string) $row['error'], 'kept this answer');

    // A proxy's 502 is not stored: the retry with the same key goes through, once.
    Harness::invoice(3002, 1);
    Harness::nextRequest();
    fakeCall('/__control', array('mode' => 'html502', 'times' => 1));
    Harness::fire('InvoiceCreated', array('invoiceid' => 3002));
    eq(Harness::logRows()[1]['status'], TextLog::RETRY);
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::fire('AfterCronJob');
    $row = Harness::logRows()[1];
    eq($row['status'], TextLog::SENT, 'sent on the retry');
    $seen = fakeCall('/__requests');
    eq(count($seen['texts']), 1, 'one text in all');
    $keys = array_values(array_filter(array_column($seen['requests'], 'idempotencyKey')));
    eq(count($keys), 4, 'four calls with a key');
    eq($keys[2], $keys[3], 'the retry used the same key as the attempt that hit the proxy error');
    Harness::nextRequest();
    Harness::$now += 301;
    Harness::fire('AfterCronJob');
    eq(count(fakeCall('/__requests')['requests']), 4, 'nothing more');
    Outbox::reset();
});
