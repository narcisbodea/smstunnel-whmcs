<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\TwoFactor;

use SmsTunnel\Whmcs\Guard;
use SmsTunnel\Whmcs\Hooks;
use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Phone\ClientNumber;
use SmsTunnel\Whmcs\Phone\PhoneNumber;
use SmsTunnel\Whmcs\Redact;
use SmsTunnel\Whmcs\Store\Schema;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * What the sign-in code module (modules/security/smstunnel2fa) shows and checks.
 *
 * Turning it on (activate → activateverify): the person types their mobile number and presses the
 * button; a code is texted to it; they type the code into the same form and press the button again.
 * The number is saved only once a code sent to it was typed back.
 *
 * Signing in (challenge → verify): a code is texted to the saved number (once per 10 minutes, unless
 * the person asks for a new one) and the typed code is checked.
 */
final class Screens
{
    /** Saved numbers, by user key, encrypted (in case WHMCS does not hand the module its user settings back). */
    const PHONE_SETTING = 'two_factor_phone.';

    /**
     * The setup form.
     *
     * @param array<string, mixed> $params what WHMCS passes to {module}_activate
     */
    public static function activate(array $params): string
    {
        try {
            return Guard::run(function () use ($params) {
                return Screens::activateForm($params, '');
            });
        } catch (\Throwable $e) {
            return self::box('danger', 'Sign-in codes by text message are not available: ' . Hooks::describe($e));
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function activateForm(array $params, string $notice): string
    {
        $userKey = SignInCodes::userKey(isset($params['user_info']) ? $params['user_info'] : null);
        $pending = self::pendingSetupPhone();
        $phone = $pending !== '' ? $pending : self::suggestedPhone();
        $since = $pending !== '' ? Module::signInCodes()->pendingSince($userKey, $pending, SignInCodes::SETUP) : 0;
        $company = Module::platform()->companyName();

        $html = '<div class="smstunnel-2fa">';
        $html .= $notice !== '' ? self::box('info', $notice) : '';
        $html .= '<p>' . self::e(($company !== '' ? $company : 'We') . ' will text a code to your mobile phone each time you sign in.') . '</p>';
        if ($since > 0) {
            $html .= self::box('info', 'We texted a code to ' . Redact::number($pending) . ' at ' . date('H:i', $since) . '. Type it below and press Confirm.');
        }
        $html .= '<div class="form-group"><label for="smstunnel2faPhone">Mobile number</label>'
            . '<input type="tel" name="smstunnel_phone" id="smstunnel2faPhone" class="form-control" value="' . self::e($phone) . '" placeholder="+14155550123" autocomplete="tel" maxlength="25">'
            . '<p class="help-block">In international format: + and your country code.</p></div>';
        $html .= '<div class="form-group"><label for="smstunnel2faCode">Code from the text message</label>'
            . '<input type="text" name="smstunnel_code" id="smstunnel2faCode" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="10" placeholder="123456">'
            . '<p class="help-block">' . self::e($since > 0 ? 'No text after a minute? Clear the code box and press the button for a new code.' : 'Press the button to get a code by text message, then type it here and press the button again.') . '</p></div>';
        $html .= '<p><button type="submit" class="btn btn-primary" onclick="if (typeof dialogSubmit === \'function\') { dialogSubmit(); return false; }">'
            . self::e($since > 0 ? 'Confirm' : 'Text me a code') . '</button></p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Checks the setup form: texts a code to the number, or checks the code typed for it.
     *
     * @param array<string, mixed> $params what WHMCS passes to {module}_activateverify
     *
     * @return array{settings: array{phone: string}, msg: string} the settings WHMCS keeps for the user
     *
     * @throws \Exception (a \WHMCS\Exception in WHMCS) with what to do next, shown to the person
     */
    public static function activateVerify(array $params): array
    {
        $post = self::post($params);
        $userKey = SignInCodes::userKey(isset($params['user_info']) ? $params['user_info'] : null);
        if ($userKey === '') {
            self::fail('Your account could not be identified. Reload the page and try again.');
        }
        list($phone, $problem) = PhoneNumber::analyse(isset($post['smstunnel_phone']) ? (string) $post['smstunnel_phone'] : '', '');
        if ($problem !== PhoneNumber::OK) {
            self::fail('Type your mobile number in international format: + and your country code, for example +14155550123.');
        }
        $typed = isset($post['smstunnel_code']) ? (string) preg_replace('/\D+/', '', (string) $post['smstunnel_code']) : '';
        $codes = Module::signInCodes();

        try {
            if ($typed !== '' && self::pendingSetupPhone() === $phone) {
                if (!$codes->verify($userKey, $phone, SignInCodes::SETUP, $typed)) {
                    self::fail('That code is not right, or it has expired. Check the text message and try again, or clear the code box for a new code.');
                }
                self::setPendingSetupPhone('');
                self::savePhone($userKey, $phone);
                Module::platform()->logActivity('sign-in codes by text message turned on for ' . Redact::number($phone));

                return array('settings' => array('phone' => $phone), 'msg' => 'Sign-in codes will be texted to ' . Redact::number($phone) . '.');
            }

            $sent = $codes->issue($userKey, $phone, SignInCodes::SETUP, self::pendingSetupPhone() === $phone);
        } catch (\Exception $e) {
            if (self::isNotice($e)) {
                throw $e;
            }
            self::fail('Sign-in codes are not available right now: ' . Hooks::describe($e));
        }
        if (!$sent['ok']) {
            self::fail($sent['error']);
        }
        self::setPendingSetupPhone($phone);
        // WHMCS shows this message with the form again: the person types the code and presses the button.
        self::fail($sent['sent']
            ? 'We texted a code to ' . Redact::number($phone) . '. Type it in the code box and press the button again.'
            : ($sent['error'] !== '' ? $sent['error'] : 'We texted you a code a moment ago: type it in the code box and press the button again.'));
    }

    /**
     * The sign-in challenge: texts the code (when needed) and shows the box to type it in.
     *
     * @param array<string, mixed> $params what WHMCS passes to {module}_challenge
     */
    public static function challenge(array $params): string
    {
        try {
            return Guard::run(function () use ($params) {
                return Screens::challengeForm($params);
            });
        } catch (\Throwable $e) {
            return self::box('danger', 'The sign-in code could not be sent (' . Hooks::describe($e) . '). Use your backup code.')
                . self::codeBox();
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function challengeForm(array $params): string
    {
        $userKey = SignInCodes::userKey(isset($params['user_info']) ? $params['user_info'] : null);
        $phone = self::savedPhone($params, $userKey);
        if ($phone === '') {
            return self::box('warning', 'No mobile number is saved for your sign-in codes. Use your backup code.') . self::codeBox();
        }
        $again = isset($_GET['smstunnel_resend']) && $_GET['smstunnel_resend'] === '1';
        $sent = Module::signInCodes()->issue($userKey, $phone, SignInCodes::LOGIN, $again);

        $html = '';
        if (!$sent['ok']) {
            $html .= self::box('danger', $sent['error'] . ' You can also use your backup code.');
        } else {
            $html .= self::box('info', 'We texted a sign-in code to ' . Redact::number($phone) . ($sent['sentAt'] > 0 ? ' at ' . date('H:i', $sent['sentAt']) : '') . '. It works for 10 minutes.'
                . ($sent['error'] !== '' ? ' ' . $sent['error'] : ''));
        }
        $html .= self::codeBox();
        $html .= '<p class="small text-muted" style="margin-top:10px">No text after a minute? <a href="' . self::e(self::resendLink()) . '">Text me a new code</a>, or use your backup code.</p>';

        return $html;
    }

    /**
     * Checks the code typed at sign-in.
     *
     * @param array<string, mixed> $params what WHMCS passes to {module}_verify
     */
    public static function verify(array $params): bool
    {
        try {
            return (bool) Guard::run(function () use ($params) {
                $post = Screens::post($params);
                $typed = isset($post['key']) ? (string) $post['key'] : (isset($post['smstunnel_code']) ? (string) $post['smstunnel_code'] : '');
                $userKey = SignInCodes::userKey(isset($params['user_info']) ? $params['user_info'] : null);
                $phone = Screens::savedPhone($params, $userKey);

                return $phone !== '' && Module::signInCodes()->verify($userKey, $phone, SignInCodes::LOGIN, $typed);
            });
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The number saved for the user: from WHMCS's user settings, or the module's own encrypted copy.
     *
     * @param array<string, mixed> $params
     */
    public static function savedPhone(array $params, string $userKey): string
    {
        foreach (array('user_settings', 'admin_settings') as $key) {
            if (isset($params[$key]['phone']) && is_string($params[$key]['phone']) && PhoneNumber::isE164($params[$key]['phone'])) {
                return $params[$key]['phone'];
            }
        }
        if ($userKey === '' || !Schema::installed()) {
            return '';
        }
        $stored = Module::settings()->get(self::PHONE_SETTING . $userKey, '');
        $phone = is_string($stored) && $stored !== '' ? Module::platform()->decrypt($stored) : '';

        return PhoneNumber::isE164($phone) ? $phone : '';
    }

    private static function savePhone(string $userKey, string $phone)
    {
        Module::settings()->set(self::PHONE_SETTING . $userKey, Module::platform()->encrypt($phone));
    }

    /**
     * The POST fields WHMCS hands the module ($params['post_vars']), or $_POST.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function post(array $params): array
    {
        if (isset($params['post_vars']) && is_array($params['post_vars'])) {
            return $params['post_vars'];
        }

        return is_array($_POST) ? $_POST : array();
    }

    private static function codeBox(): string
    {
        return '<div class="form-group"><input type="text" name="key" class="form-control input-lg form-control-lg" inputmode="numeric" autocomplete="one-time-code" maxlength="10" placeholder="123456" aria-label="Sign-in code" autofocus></div>'
            . '<input type="submit" class="btn btn-primary btn-block btn-lg" value="Sign in">';
    }

    /** The current page with smstunnel_resend=1, to text a new code. */
    private static function resendLink(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $uri = (string) preg_replace('/([?&])smstunnel_resend=[^&]*(&|$)/', '$1', $uri);
        $uri = rtrim($uri, '?&');

        return $uri . (strpos($uri, '?') !== false ? '&' : '?') . 'smstunnel_resend=1';
    }

    /** The client's own number as a suggestion, when a client is setting this up. */
    private static function suggestedPhone(): string
    {
        $clientId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
        if ($clientId <= 0) {
            return '';
        }
        $client = Module::platform()->client($clientId);
        if ($client === null) {
            return '';
        }
        $analysed = ClientNumber::analyse($client);

        return $analysed[0];
    }

    private static function pendingSetupPhone(): string
    {
        return isset($_SESSION[SignInCodes::SESSION]['setup']) && is_string($_SESSION[SignInCodes::SESSION]['setup']) ? $_SESSION[SignInCodes::SESSION]['setup'] : '';
    }

    private static function setPendingSetupPhone(string $phone)
    {
        if (!isset($_SESSION[SignInCodes::SESSION]) || !is_array($_SESSION[SignInCodes::SESSION])) {
            $_SESSION[SignInCodes::SESSION] = array();
        }
        $_SESSION[SignInCodes::SESSION]['setup'] = $phone;
    }

    /**
     * Stops activateverify with a message for the person (WHMCS shows it and keeps the setup open).
     *
     * @throws \Exception
     */
    private static function fail(string $message)
    {
        if (class_exists('WHMCS\\Exception')) {
            throw new \WHMCS\Exception($message);
        }
        throw new NoticeException($message);
    }

    private static function isNotice(\Exception $e): bool
    {
        return $e instanceof NoticeException || get_class($e) === 'WHMCS\\Exception';
    }

    private static function box(string $kind, string $text): string
    {
        return '<div class="alert alert-' . self::e($kind) . '">' . self::e($text) . '</div>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
