<?php
/**
 * Sign-in codes: setup, challenge, verify, expiry, attempt limits, sessions, and no code in any log.
 *
 * @license MIT
 */

use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\TwoFactor\Screens;
use SmsTunnel\Whmcs\TwoFactor\SignInCodes;

group('sign-in codes');

function twoFactorModule()
{
    require_once SMSTUNNEL_REPO . '/modules/security/smstunnel2fa/smstunnel2fa.php';
}

/** The code in the last text sent to /send-2fa (as the phone would show it). */
function lastCode(): string
{
    $sends = Harness::sends('/api/v1/sms/send-2fa');
    if (!$sends) {
        return '';
    }
    preg_match('/^(\d{6}) is your sign-in code/', $sends[count($sends) - 1]['body']['message'], $match);

    return isset($match[1]) ? $match[1] : '';
}

/** Follows the "Text me a new code" link of the current session. */
function askNewCode(array $params): string
{
    $_GET['smstunnel_resend'] = Screens::resendToken(false);
    $html = smstunnel2fa_challenge($params);
    unset($_GET['smstunnel_resend']);

    return $html;
}

function userParams(array $settings = array()): array
{
    return array(
        'settings' => array(),
        'user_info' => array('id' => 7, 'email' => 'jane@example.com', 'username' => 'jane'),
        'user_settings' => $settings,
        'post_vars' => array(),
    );
}

/** Runs activateverify and returns the message WHMCS would show (or the settings array). */
function activateVerify(array $post)
{
    $params = userParams();
    $params['post_vars'] = $post;
    try {
        return smstunnel2fa_activateverify($params);
    } catch (\WHMCS\Exception $e) {
        return $e->getMessage();
    }
}

test('the module files: function names that cannot clash with the addon, and its description', function () {
    Harness::activate(Harness::testKey());
    twoFactorModule();
    foreach (array('config', 'activate', 'activateverify', 'challenge', 'verify') as $function) {
        ok(function_exists('smstunnel2fa_' . $function), 'smstunnel2fa_' . $function);
    }
    ok(function_exists('smstunnel_config') && function_exists('smstunnel_activate'), 'the addon functions are loaded in the same request without a clash');
    $config = smstunnel2fa_config();
    eq($config['FriendlyName'], array('Type' => 'System', 'Value' => 'SMSTunnel: codes by text message'));
    has($config['Description']['Value'], 'SMSTunnel for WHMCS addon');
});

test('setup: a code to the typed number, then the code typed back saves the number', function () {
    Harness::activate(Harness::testKey());
    twoFactorModule();
    $form = smstunnel2fa_activate(userParams());
    has($form, 'name="smstunnel_phone"');
    has($form, 'name="smstunnel_code"');
    has($form, 'Text me a code');

    has((string) activateVerify(array('smstunnel_phone' => '12')), 'international format', 'a bad number');
    eq(count(Harness::$requests), 0, 'no call for a bad number');

    $message = activateVerify(array('smstunnel_phone' => '+1 415 555 0177', 'smstunnel_code' => ''));
    has((string) $message, 'We texted a code to +•••••••0177');
    $sends = Harness::sends('/api/v1/sms/send-2fa');
    eq(count($sends), 1, 'one call to send-2fa');
    eq($sends[0]['body']['to'], '+14155550177');
    $code = lastCode();
    ok(strlen($code) === 6, 'a 6-digit code');
    ok((bool) preg_match('/(?<![\w+]|\d[.,:\/])\d{4,10}(?!\w|[.,:\/]\d)/', $sends[0]['body']['message']), "the text passes the server's code rule");
    ok(strlen($sends[0]['body']['message']) <= 320, 'at most 320 characters');
    has($sends[0]['body']['message'], "\n\n@billing.example.com #" . $code, 'the WebOTP line for the WHMCS host');
    ok(isset($sends[0]['headers']['idempotency-key']), 'with an Idempotency-Key');

    $form = smstunnel2fa_activate(userParams());
    has($form, 'We texted a code to +•••••••0177', 'the form shows the pending number');
    has($form, 'value="+14155550177"');
    has($form, 'Confirm');

    $wrong = $code === '000000' ? '111111' : '000000';
    has((string) activateVerify(array('smstunnel_phone' => '+14155550177', 'smstunnel_code' => $wrong)), 'not right');
    $result = activateVerify(array('smstunnel_phone' => '+14155550177', 'smstunnel_code' => substr($code, 0, 3) . '-' . substr($code, 3)));
    ok(is_array($result), 'the right code turns it on (dashes ignored)');
    eq($result['settings'], array('phone' => '+14155550177'), 'the setting WHMCS keeps for the user');
    eq(Screens::savedPhone(userParams(), SignInCodes::userKey(userParams()['user_info'])), '+14155550177', 'and an encrypted copy in the module');
    Harness::$now += 61;
    has((string) activateVerify(array('smstunnel_phone' => '+14155550177', 'smstunnel_code' => $code)), 'We texted a code', 'a code works once: the same code starts a new setup');
});

test('sign-in: one code per 10 minutes, checked in constant time, 5 tries, one use, its own session', function () {
    Harness::activate(Harness::testKey());
    twoFactorModule();
    $params = userParams(array('phone' => '+14155550177'));

    $html = smstunnel2fa_challenge($params);
    has($html, 'name="key"');
    has($html, 'We texted a sign-in code to +•••••••0177');
    has($html, 'smstunnel_resend=' . Screens::resendToken(false), 'the resend link carries the session token');
    $code = lastCode();
    smstunnel2fa_challenge($params);
    eq(count(Harness::sends('/api/v1/sms/send-2fa')), 1, 'showing the page again sends no new code');

    $wrong = $code === '999999' ? '888888' : '999999';
    $try = function ($typed) use ($params) {
        $params['post_vars'] = array('key' => $typed);

        return smstunnel2fa_verify($params);
    };
    eq($try(''), false, 'empty');
    eq($try('abc'), false, 'letters');
    eq($try($wrong), false, 'wrong code');
    eq($try($code), true, 'the right code');
    eq($try($code), false, 'used once only');

    // A new code: the old ones stop working; 5 wrong tries end it.
    Harness::$now += 61;
    askNewCode($params);
    $code2 = lastCode();
    eq(count(Harness::sends('/api/v1/sms/send-2fa')), 2, 'a new code on request');
    for ($i = 0; $i < 5; ++$i) {
        $try($code2 === '123456' ? '654321' : '123456');
    }
    eq($try($code2), false, 'after 5 wrong tries even the right code fails');

    // Expiry.
    Harness::$now += 61;
    askNewCode($params);
    $code3 = lastCode();
    Harness::$now += 601;
    eq($try($code3), false, 'expired after 10 minutes');

    // Another browser session cannot use a code sent to this one.
    smstunnel2fa_challenge($params);
    $code4 = lastCode();
    $mine = $_SESSION[SignInCodes::SESSION];
    $_SESSION[SignInCodes::SESSION] = array();
    eq($try($code4), false, 'another session');
    $_SESSION[SignInCodes::SESSION] = $mine;
    eq($try($code4), true, 'the session it was sent to');
});

test('limits: 60 seconds between codes, 5 codes in 30 minutes', function () {
    Harness::activate(Harness::testKey());
    twoFactorModule();
    $params = userParams(array('phone' => '+14155550177'));
    smstunnel2fa_challenge($params);
    $html = askNewCode($params);
    has($html, 'less than a minute ago', 'too soon');
    for ($i = 0; $i < 6; ++$i) {
        Harness::$now += 61;
        $html = askNewCode($params);
    }
    eq(count(Harness::sends('/api/v1/sms/send-2fa')), 5, 'at most 5 codes in 30 minutes');
    has($html, 'Too many codes');
});

test('"Text me a new code" works once per link: an old link or a stranger\'s token sends nothing', function () {
    Harness::activate(Harness::testKey());
    twoFactorModule();
    $params = userParams(array('phone' => '+14155550177'));
    smstunnel2fa_challenge($params);
    Harness::$now += 61;
    $used = Screens::resendToken(false);
    askNewCode($params);
    eq(count(Harness::sends('/api/v1/sms/send-2fa')), 2, 'the link worked');
    Harness::$now += 61;
    $_GET['smstunnel_resend'] = $used;
    smstunnel2fa_challenge($params);
    $_GET['smstunnel_resend'] = '1';
    smstunnel2fa_challenge($params);
    unset($_GET['smstunnel_resend']);
    eq(count(Harness::sends('/api/v1/sms/send-2fa')), 2, 'the same link again, or a made-up token: no new code');
});

test('abuse limits: one number gets at most 5 codes an hour, all setups together 20 an hour', function () {
    Harness::activate(Harness::testKey());
    twoFactorModule();
    // Five different "users" (sign-ups) ask for setup codes to the same stranger's number.
    for ($user = 1; $user <= 6; ++$user) {
        $params = userParams();
        $params['user_info'] = array('id' => 100 + $user, 'email' => 'user' . $user . '@example.com');
        $params['post_vars'] = array('smstunnel_phone' => '+14155550166', 'smstunnel_code' => '');
        $_SESSION = array('adminid' => 1);
        try {
            smstunnel2fa_activateverify($params);
        } catch (\WHMCS\Exception $e) {
            $last = $e->getMessage();
        }
    }
    eq(count(Harness::sends('/api/v1/sms/send-2fa')), 5, 'five codes to that number, then no more this hour');
    has($last, 'Too many codes were sent to this number');
    // Twenty setups an hour for the whole WHMCS, whatever the numbers.
    for ($user = 1; $user <= 20; ++$user) {
        $params = userParams();
        $params['user_info'] = array('id' => 200 + $user, 'email' => 'other' . $user . '@example.com');
        $params['post_vars'] = array('smstunnel_phone' => '+1415555' . (1000 + $user), 'smstunnel_code' => '');
        $_SESSION = array('adminid' => 1);
        try {
            smstunnel2fa_activateverify($params);
        } catch (\WHMCS\Exception $e) {
            $last = $e->getMessage();
        }
    }
    eq(count(Harness::sends('/api/v1/sms/send-2fa')), 20, 'twenty setup codes in all, then no more this hour');
    has($last, 'Too many sign-in code setups right now');
    $_SESSION = array('adminid' => 1);
    $html = smstunnel2fa_challenge(userParams(array('phone' => '+14155550177')));
    has($html, 'We texted a sign-in code', 'signing in with a confirmed number still works');
});

test('no code is stored, logged or shown anywhere but in the text', function () {
    Harness::activate(Harness::testKey());
    twoFactorModule();
    $params = userParams(array('phone' => '+14155550177'));
    smstunnel2fa_challenge($params);
    $code = lastCode();
    $params['post_vars'] = array('key' => $code);
    smstunnel2fa_verify($params);
    $codesTable = json_encode(\WHMCS\Database\Capsule::table('mod_smstunnel_codes')->get());
    lacks($codesTable, $code, 'the codes table holds hashes only');
    $log = Harness::logRows();
    eq(count($log), 1);
    eq(array($log[0]['event'], $log[0]['status'], $log[0]['request'], $log[0]['recipient']), array('sign_in_code', 'sent', null, '+•••••••0177'), 'logged without words, number masked');
    lacks(json_encode($log), $code, 'not in the module log');
    lacks(json_encode(Harness::$activity), $code, 'not in the Activity Log');
    lacks(json_encode(\WHMCS\Database\Capsule::table('mod_smstunnel_settings')->get()), $code, 'not in the settings');
});

test('when it cannot send: no number saved, no key, SMSTunnel refuses', function () {
    Harness::activate();
    twoFactorModule();
    $html = smstunnel2fa_challenge(userParams());
    has($html, 'No mobile number is saved');
    has($html, 'backup code');
    $html = smstunnel2fa_challenge(userParams(array('phone' => '+14155550177')));
    has($html, 'not set up yet');
    $params = userParams(array('phone' => '+14155550177'));
    $params['post_vars'] = array('key' => '123456');
    eq(smstunnel2fa_verify($params), false, 'nothing to check against');

    Module::settings()->setApiKey(Harness::testKey());
    Harness::respond(Harness::error(429, array('message' => "You reached today's limit of sign-in codes"), array('Retry-After' => '3600')));
    $html = smstunnel2fa_challenge(userParams(array('phone' => '+14155550177')));
    has($html, 'The code could not be sent: HTTP 429');
    has($html, 'backup code');
    Harness::$now += 61;
    smstunnel2fa_challenge(userParams(array('phone' => '+14155550177')));
    eq(count(Harness::sends('/api/v1/sms/send-2fa')), 2, 'a failed send leaves no code pending, so the next page tries again');
    eq(count(Harness::sends('/api/v1/sms/send')), 0, 'sign-in codes never use the normal send');
});

test('the user key tells staff and client users apart', function () {
    $a = SignInCodes::userKey(array('id' => 1, 'email' => 'a@example.com'));
    eq($a, SignInCodes::userKey(array('id' => '1', 'email' => 'A@Example.com ')), 'stable');
    ok($a !== SignInCodes::userKey(array('id' => 1, 'email' => 'b@example.com')), 'another email');
    ok($a !== SignInCodes::userKey(array('id' => 1, 'username' => 'admin')), 'a staff username');
    eq(SignInCodes::userKey(null), '', 'nothing to go on');
    eq(strlen($a), 64);
});
