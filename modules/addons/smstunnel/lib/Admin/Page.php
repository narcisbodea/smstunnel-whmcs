<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Admin;

use SmsTunnel\Whmcs\Api\ApiException;
use SmsTunnel\Whmcs\Api\Client;
use SmsTunnel\Whmcs\Api\TransportException;
use SmsTunnel\Whmcs\Hooks;
use SmsTunnel\Whmcs\Phone\CallingCodes;
use SmsTunnel\Whmcs\Phone\PhoneNumber;
use SmsTunnel\Whmcs\Redact;
use SmsTunnel\Whmcs\Send\OutgoingText;
use SmsTunnel\Whmcs\Send\Outbox;
use SmsTunnel\Whmcs\Store\Consent;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;
use SmsTunnel\Whmcs\Text\Events;
use SmsTunnel\Whmcs\Text\SmsCounter;
use SmsTunnel\Whmcs\Text\Template;
use SmsTunnel\Whmcs\Version;
use SmsTunnel\Whmcs\Whmcs\Platform;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * The module's page under Addons → SMSTunnel for WHMCS: Setup, Client texts, Staff alerts, Log.
 *
 * WHMCS shows it only to staff whose admin role was ticked under the addon's Access Control
 * (System Settings → Addon Modules → Configure). Every form carries WHMCS's CSRF token and is refused
 * without it; every value written into the page is escaped.
 */
final class Page
{
    const TABS = array('setup' => 'Setup', 'texts' => 'Client texts', 'staff' => 'Staff alerts', 'log' => 'Log');

    const PER_PAGE = 50;

    /** @var Settings */
    private $settings;

    /** @var Platform */
    private $platform;

    /** @var TextLog */
    private $log;

    /** @var Outbox */
    private $outbox;

    /** @var Consent */
    private $consent;

    /** @var array<int, array{0: string, 1: string}> notices to show at the top */
    private $notices = array();

    /** @var array<string, string> what was typed into the test form */
    private $testForm = array();

    public function __construct(Settings $settings, Platform $platform, TextLog $log, Outbox $outbox, Consent $consent)
    {
        $this->settings = $settings;
        $this->platform = $platform;
        $this->log = $log;
        $this->outbox = $outbox;
        $this->consent = $consent;
    }

    /**
     * The whole page.
     *
     * @param array<string, mixed> $vars what WHMCS passes to smstunnel_output()
     * @param array<string, mixed> $get  $_GET
     * @param array<string, mixed> $post $_POST
     */
    public function render(array $vars, array $get, array $post, string $method): string
    {
        $link = isset($vars['modulelink']) && is_string($vars['modulelink']) && $vars['modulelink'] !== '' ? $vars['modulelink'] : 'addonmodules.php?module=' . Version::MODULE;
        $tab = isset($get['tab']) && is_string($get['tab']) && isset(self::TABS[$get['tab']]) ? $get['tab'] : 'setup';

        if (strtoupper($method) === 'POST' && isset($post['smstunnel_action'])) {
            if (!$this->platform->csrfValid(isset($post['token']) ? $post['token'] : null)) {
                $this->notice('danger', 'The form expired or did not come from this page (CSRF check). Nothing was changed: reload the page and try again.');
            } else {
                $this->handle((string) $post['smstunnel_action'], $post);
            }
        }

        $html = '<div class="smstunnel-admin">' . $this->style();
        $html .= '<h2 style="margin-top:0">' . Html::e(Version::NAME) . ' <small>' . Html::e(Version::NUMBER) . '</small></h2>';
        $html .= '<p class="text-muted">Texts your clients and staff about WHMCS events from your own Android phone, with your own number. '
            . 'Not affiliated with, endorsed by, or sponsored by WHMCS Limited.</p>';
        foreach ($this->notices as $notice) {
            $html .= Html::alert($notice[0], $notice[1]);
        }
        $html .= $this->summary($link);
        $html .= '<ul class="nav nav-tabs" style="margin-bottom:15px">';
        foreach (self::TABS as $key => $label) {
            $html .= '<li' . ($key === $tab ? ' class="active"' : '') . '><a href="' . Html::e(Html::url($link, array('tab' => $key))) . '">' . Html::e($label) . '</a></li>';
        }
        $html .= '</ul>';

        switch ($tab) {
            case 'texts':
                $html .= $this->textsTab($link, Events::CLIENT);
                break;
            case 'staff':
                $html .= $this->textsTab($link, Events::STAFF);
                break;
            case 'log':
                $html .= $this->logTab($link, $get);
                break;
            default:
                $html .= $this->setupTab($link);
        }
        $html .= $this->script();

        return $html . '</div>';
    }

    // ---- Actions --------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $post
     */
    private function handle(string $action, array $post)
    {
        try {
            switch ($action) {
                case 'save_setup':
                    $this->saveSetup($post);
                    break;
                case 'send_test':
                    $this->sendTest($post);
                    break;
                case 'save_texts':
                    $this->saveTexts($post, Events::CLIENT);
                    break;
                case 'save_staff':
                    $this->saveStaff($post);
                    break;
                case 'create_field':
                    $this->consent->sync();
                    $this->notice('success', 'The client field is in place again.');
                    break;
                default:
                    $this->notice('warning', 'Unknown action: nothing was changed.');
            }
        } catch (\Throwable $e) {
            $this->notice('danger', 'Not saved: ' . Hooks::describe($e));
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    private function saveSetup(array $post)
    {
        $url = rtrim(trim(self::field($post, 'api_url')), '/');
        if ($url === '') {
            $url = Client::DEFAULT_URL;
        }
        if (!Client::isValidBaseUrl($url)) {
            $this->notice('danger', 'The SMSTunnel server must be an address like https://smstunnel.io. Nothing was saved.');

            return;
        }
        $days = (int) self::field($post, 'log_days');
        if ($days < 1 || $days > 3650) {
            $this->notice('danger', 'Keep the log for 1 to 3650 days. Nothing was saved.');

            return;
        }
        $device = trim(self::field($post, 'device_id'));
        if ($device !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $device)) {
            $this->notice('danger', 'That phone ID does not look right. Nothing was saved.');

            return;
        }

        $newKey = (string) preg_replace('/\s+/', '', self::field($post, 'api_key'));
        if (!empty($post['remove_key'])) {
            $this->settings->setApiKey('');
            $this->notice('info', 'The API key was removed: no texts go out until you save a new one.');
        } elseif ($newKey !== '') {
            if (strlen($newKey) < 12 || strlen($newKey) > 200) {
                $this->notice('danger', 'That does not look like an SMSTunnel API key (copy it from Apps → Add an app). Nothing was saved.');

                return;
            }
            $this->settings->setApiKey($newKey);
        }
        $this->settings->set(Settings::API_URL, $url);
        $this->settings->set(Settings::DEVICE_ID, $device);
        $this->settings->set(Settings::LOG_DAYS, $days);
        $this->settings->set(Settings::DELETE_ON_DEACTIVATE, !empty($post['delete_on_deactivate']));
        $optIn = !empty($post['opt_in']);
        $modeChanged = $optIn !== $this->settings->optIn();
        $this->settings->set(Settings::OPT_IN, $optIn);
        $this->consent->sync();
        Outbox::reset();

        $this->notice('success', 'Settings saved.');
        if ($modeChanged) {
            $this->notice('info', $optIn
                ? 'Only clients who tick "' . Consent::OPT_IN_NAME . '" in their profile get texts now. Clients see that field instead of "' . Consent::OPT_OUT_NAME . '".'
                : 'Every client gets the texts that are turned on, unless they tick "' . Consent::OPT_OUT_NAME . '" in their profile.');
        }
        if (stripos($url, 'https://') !== 0) {
            $this->notice('warning', 'The SMSTunnel server address does not use https://: the API key would travel unencrypted. Use it only for a test server.');
        }
        $this->platform->logActivity('settings saved' . ($newKey !== '' ? ' (new API key ' . Settings::mask($newKey) . ')' : ''));
    }

    /**
     * @param array<string, mixed> $post
     */
    private function sendTest(array $post)
    {
        $typed = trim(self::field($post, 'test_number'));
        $message = trim(str_replace(array("\r\n", "\r"), "\n", self::field($post, 'test_message')));
        $this->testForm = array('number' => $typed, 'message' => $message);
        if (!$this->settings->hasApiKey()) {
            $this->notice('warning', 'Save your API key first.');

            return;
        }
        $callingCode = CallingCodes::forCountry($this->platform->defaultCountry());
        list($number, $problem) = PhoneNumber::analyse($typed, $callingCode);
        if ($problem !== PhoneNumber::OK) {
            $this->notice('danger', $problem === PhoneNumber::PREMIUM
                ? 'That is a premium-rate or short number: the module never texts those.'
                : 'Type the number in international format, for example +14155550123.');

            return;
        }
        if ($message === '') {
            $message = $this->defaultTestText();
        }
        $text = new OutgoingText(Events::TEST, 'test');
        $text->to = $number;
        $text->message = Template::cut($message, Template::MAX_LENGTH);
        $text->countryCode = $callingCode;
        $result = $this->outbox->sendOnce($text);
        if ($result['status'] === TextLog::SENT) {
            $this->notice('success', 'The test text went to your phone for ' . $number . ' (message ID ' . $result['messageId'] . '). It should arrive in a few seconds.');
        } elseif ($result['status'] === TextLog::QUEUED) {
            $this->notice('info', 'SMSTunnel has the test text (message ID ' . $result['messageId'] . '), but your phone is offline: it goes out as soon as the phone is back online. Nothing else to do.');
        } else {
            $this->notice('danger', 'The test text did not go out. ' . self::hint($result['exception']) . ' (' . $result['error'] . ')');
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    private function saveTexts(array $post, string $audience): bool
    {
        $texts = isset($post['text']) && is_array($post['text']) ? $post['text'] : array();
        $on = isset($post['on']) && is_array($post['on']) ? $post['on'] : array();
        $problems = array();
        foreach (Events::forAudience($audience) as $event => $definition) {
            $text = isset($texts[$event]) && is_string($texts[$event]) ? self::decoded($texts[$event]) : '';
            if (Template::length($text) > Template::MAX_LENGTH) {
                $problems[] = $definition['label'] . ': the text is longer than ' . Template::MAX_LENGTH . ' characters.';
                continue;
            }
            $unknown = Template::unknownFields($text, Events::fields($event));
            if ($unknown) {
                $this->notice('warning', $definition['label'] . ': {' . implode('}, {', $unknown) . '} is not a field of this text, so it stays as typed.');
            }
            $this->settings->setEvent($event, !empty($on[$event]), $text);
        }
        foreach ($problems as $problem) {
            $this->notice('danger', 'Not saved: ' . $problem);
        }
        if (!$problems) {
            $this->notice('success', 'Texts saved.');
        }

        return !$problems;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function saveStaff(array $post)
    {
        $callingCode = CallingCodes::forCountry($this->platform->defaultCountry());
        $numbers = array();
        $bad = array();
        foreach ((array) preg_split('/[\r\n,;]+/', self::field($post, 'staff_numbers')) as $typed) {
            $typed = trim((string) $typed);
            if ($typed === '') {
                continue;
            }
            list($number, $problem) = PhoneNumber::analyse($typed, $callingCode);
            if ($problem !== PhoneNumber::OK) {
                $bad[] = $typed;
            } elseif (!in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }
        if ($bad) {
            $this->notice('danger', 'Not saved: these are not numbers the module can text (use the international format, +14155550123): ' . implode(', ', $bad));

            return;
        }
        if (count($numbers) > Settings::MAX_STAFF_NUMBERS) {
            $this->notice('danger', 'Not saved: up to ' . Settings::MAX_STAFF_NUMBERS . ' staff numbers.');

            return;
        }
        $this->settings->set(Settings::STAFF_NUMBERS, $numbers);
        $this->saveTexts($post, Events::STAFF);
    }

    // ---- Tabs ------------------------------------------------------------------------------------

    private function summary(string $link): string
    {
        $items = array();
        if (!$this->settings->hasApiKey()) {
            $items[] = array('warning', 'No API key yet: no texts go out. Add one under Setup.');
        }
        $counts = $this->log->counts();
        if ($counts[TextLog::RETRY] > 0) {
            $items[] = array('warning', $counts[TextLog::RETRY] . ' text(s) wait for another try: the WHMCS cron retries them.');
        }
        $field = $this->consent->activeField();
        if ($field['id'] <= 0) {
            $items[] = array('warning', 'The client field "' . $field['name'] . '" is missing (it may have been deleted under Custom Client Fields).');
        }
        if (!$items) {
            return '';
        }
        $html = '';
        foreach ($items as $item) {
            $html .= Html::alert($item[0], $item[1]);
        }
        if ($field['id'] <= 0) {
            $html .= '<form method="post" action="' . Html::e($link) . '" style="margin:-10px 0 15px">' . $this->formToken('create_field')
                . '<button type="submit" class="btn btn-default btn-sm">Create the field "' . Html::e($field['name']) . '" again</button></form>';
        }

        return $html;
    }

    private function setupTab(string $link): string
    {
        $phones = null;
        $phonesError = '';
        if ($this->settings->hasApiKey()) {
            try {
                $phones = $this->outbox->client()->getDevices();
            } catch (\Throwable $e) {
                $phonesError = self::hint($e) . ' (' . Outbox::describe($e) . ')';
            }
        }
        $deviceId = $this->settings->deviceId();

        $html = '<form method="post" action="' . Html::e(Html::url($link, array('tab' => 'setup'))) . '" autocomplete="off">' . $this->formToken('save_setup');

        // The key.
        $html .= '<div class="panel panel-default"><div class="panel-heading"><strong>1. Your SMSTunnel key</strong></div><div class="panel-body">';
        $html .= '<ol class="smstunnel-steps">'
            . '<li>Install the <a href="' . Html::e(Version::GOOGLE_PLAY_URL) . '" target="_blank" rel="noopener">SMSTunnel app from Google Play</a> on the Android phone that should send your texts, and sign in.</li>'
            . '<li>In your <a href="' . Html::e(Version::DASHBOARD_URL) . '" target="_blank" rel="noopener">SMSTunnel dashboard</a>, open <strong>Apps → Add an app</strong> and copy its key.</li>'
            . '<li>Paste the key below and save. Your phones then show under <strong>2. Phone</strong>.</li></ol>';
        $masked = $this->settings->maskedApiKey();
        $html .= '<div class="form-group"><label for="smstunnelApiKey">API key</label>'
            . '<input type="password" class="form-control" id="smstunnelApiKey" name="api_key" value="" autocomplete="new-password" spellcheck="false" placeholder="' . Html::e($masked !== '' ? 'Saved: ' . $masked . ' (type a new key to replace it)' : 'sk_live_...') . '">'
            . '<p class="help-block">' . Html::e($masked !== '' ? 'A key is saved (' . $masked . '). It is stored encrypted and never shown in full. Leave the box empty to keep it.' : 'The key is stored encrypted with WHMCS\'s own encryption and never shown back in full.') . '</p>';
        if ($masked !== '') {
            $html .= '<div class="checkbox"><label><input type="checkbox" name="remove_key" value="1"> Remove the saved key (no texts go out until a new one is saved)</label></div>';
        }
        $html .= '</div></div></div>';

        // The phone.
        $html .= '<div class="panel panel-default"><div class="panel-heading"><strong>2. Phone</strong></div><div class="panel-body">';
        if ($phonesError !== '') {
            $html .= Html::alert('danger', 'Your phones could not be listed. ' . $phonesError);
        }
        $html .= '<div class="form-group"><label for="smstunnelDevice">Which phone sends</label><select class="form-control" id="smstunnelDevice" name="device_id">';
        $html .= '<option value=""' . Html::selected($deviceId === '') . '>Any of my phones (the one that is online)</option>';
        $known = false;
        foreach (is_array($phones) ? $phones : array() as $phone) {
            $known = $known || $phone['id'] === $deviceId;
            $state = $phone['isOnline'] ? 'online' : 'offline' . ($phone['lastSeenAt'] !== '' ? ', last seen ' . self::when($phone['lastSeenAt']) : '');
            $html .= '<option value="' . Html::e($phone['id']) . '"' . Html::selected($phone['id'] === $deviceId) . '>' . Html::e(($phone['name'] !== '' ? $phone['name'] : 'Phone') . ' (' . $state . ')') . '</option>';
        }
        if ($deviceId !== '' && !$known) {
            $html .= '<option value="' . Html::e($deviceId) . '" selected="selected">' . Html::e('Phone ' . $deviceId . ($phones !== null ? ' (not in your account any more)' : '')) . '</option>';
        }
        $html .= '</select>';
        if (is_array($phones)) {
            $online = count(array_filter($phones, function ($phone) {
                return $phone['isOnline'];
            }));
            $html .= '<p class="help-block">' . Html::e(count($phones) === 0
                ? 'No phone is linked to this account yet: in the SMSTunnel dashboard, open Phones → Add phone.'
                : count($phones) . ' phone(s), ' . $online . ' online. When the phone is offline, texts wait in your SMSTunnel account and go out as soon as it is back online.') . '</p>';
        } elseif ($phonesError === '') {
            $html .= '<p class="help-block">Save your API key to list your phones.</p>';
        }
        $html .= '</div></div></div>';

        // The client's choice.
        $field = $this->consent->activeField();
        $html .= '<div class="panel panel-default"><div class="panel-heading"><strong>3. Clients\' choice</strong></div><div class="panel-body">';
        $html .= '<p>Clients see a tickbox in their profile and at sign-up. These are service texts about their own account; the module never texts many clients at once.</p>';
        $html .= '<div class="checkbox"><label><input type="checkbox" name="opt_in" value="1"' . Html::checked($this->settings->optIn()) . '> Text only clients who opted in</label></div>';
        $html .= '<p class="help-block">Off: every client gets the texts that are turned on, unless they tick "' . Html::e(Consent::OPT_OUT_NAME) . '". '
            . 'On: only clients who tick "' . Html::e(Consent::OPT_IN_NAME) . '" get texts. Each choice keeps its own answers when you switch. '
            . 'Field now: <strong>' . Html::e($field['name']) . '</strong>' . ($field['id'] > 0 ? ' (System Settings → Custom Client Fields).' : ' (missing).') . '</p>';
        $html .= '</div></div>';

        // Log and deactivation.
        $html .= '<div class="panel panel-default"><div class="panel-heading"><strong>4. Log and removal</strong></div><div class="panel-body">';
        $html .= '<div class="form-group"><label for="smstunnelLogDays">Keep the log for</label>'
            . '<div class="input-group" style="max-width:220px"><input type="number" min="1" max="3650" class="form-control" id="smstunnelLogDays" name="log_days" value="' . Html::e((string) $this->settings->logDays()) . '"><span class="input-group-addon">days</span></div>'
            . '<p class="help-block">The daily cron removes older rows.</p></div>';
        $html .= '<div class="checkbox"><label><input type="checkbox" name="delete_on_deactivate" value="1"' . Html::checked($this->settings->deleteOnDeactivate()) . '> Delete all data on deactivate</label></div>'
            . '<p class="help-block">When ticked, deactivating the addon deletes its settings, the key, the log, saved sign-in numbers and the client field with what clients ticked. Otherwise everything stays for when you activate it again.</p>';
        $html .= '</div></div>';

        // Advanced.
        $html .= '<details class="smstunnel-advanced"><summary>Advanced</summary><div class="form-group" style="margin-top:10px"><label for="smstunnelApiUrl">SMSTunnel server</label>'
            . '<input type="url" class="form-control" id="smstunnelApiUrl" name="api_url" value="' . Html::e($this->settings->apiUrl()) . '" spellcheck="false">'
            . '<p class="help-block">Keep https://smstunnel.io. Another address is only for a test server.</p></div></details>';

        $html .= '<p><button type="submit" class="btn btn-primary">Save settings</button></p></form>';

        // The test text.
        $staff = $this->settings->staffNumbers();
        $number = isset($this->testForm['number']) ? $this->testForm['number'] : (isset($staff[0]) ? $staff[0] : '');
        $message = isset($this->testForm['message']) && $this->testForm['message'] !== '' ? $this->testForm['message'] : $this->defaultTestText();
        $html .= '<div class="panel panel-default"><div class="panel-heading"><strong>Send a test text</strong></div><div class="panel-body">';
        $html .= '<form method="post" action="' . Html::e(Html::url($link, array('tab' => 'setup'))) . '">' . $this->formToken('send_test');
        $html .= '<div class="form-group"><label for="smstunnelTestNumber">To</label><input type="tel" class="form-control" id="smstunnelTestNumber" name="test_number" value="' . Html::e($number) . '" placeholder="+14155550123" style="max-width:300px"></div>';
        $html .= '<div class="form-group"><label for="smstunnelTestMessage">Text</label><textarea class="form-control" id="smstunnelTestMessage" name="test_message" rows="2" maxlength="1600" data-smstunnel-text="1" data-samples="{}" data-fields="[]" data-counter="smstunnelTestCounter">' . Html::e($message) . '</textarea>'
            . '<p class="help-block smstunnel-counter" id="smstunnelTestCounter">' . Html::e(self::countLine($message)) . '</p></div>';
        $html .= '<button type="submit" class="btn btn-default"' . ($this->settings->hasApiKey() ? '' : ' disabled="disabled"') . '>Send a test text</button>';
        $html .= '</form></div></div>';

        return $html;
    }

    private function textsTab(string $link, string $audience): string
    {
        $tab = $audience === Events::STAFF ? 'staff' : 'texts';
        $company = $this->platform->companyName();
        $html = '<form method="post" action="' . Html::e(Html::url($link, array('tab' => $tab))) . '">' . $this->formToken($audience === Events::STAFF ? 'save_staff' : 'save_texts');

        if ($audience === Events::STAFF) {
            $html .= '<div class="panel panel-default"><div class="panel-heading"><strong>Staff numbers</strong></div><div class="panel-body">'
                . '<div class="form-group"><label for="smstunnelStaffNumbers">Numbers that get the alerts below</label>'
                . '<textarea class="form-control" id="smstunnelStaffNumbers" name="staff_numbers" rows="3" placeholder="+14155550123" style="max-width:400px">' . Html::e(implode("\n", $this->settings->staffNumbers())) . '</textarea>'
                . '<p class="help-block">One per line, in international format, up to ' . Settings::MAX_STAFF_NUMBERS . '. Each number gets its own text.</p></div></div></div>';
        } else {
            $html .= '<p>Each text goes to the client\'s own phone number from their profile, one text per event. Clients who ticked "'
                . Html::e(Consent::OPT_OUT_NAME) . '" get none' . ($this->settings->optIn() ? ' (opt-in mode: only clients who ticked "' . Html::e(Consent::OPT_IN_NAME) . '" get texts)' : '') . '. '
                . 'Keep them short: the counter shows how many SMS parts a text takes with example values.</p>';
        }

        foreach (Events::forAudience($audience) as $event => $definition) {
            $text = $this->settings->eventText($event);
            $fields = Events::fields($event);
            $samples = Events::samples($event, $company);
            $id = 'smstunnelText_' . $event;
            $html .= '<div class="panel panel-default"><div class="panel-heading">'
                . '<label style="margin:0;font-weight:bold"><input type="checkbox" name="on[' . Html::e($event) . ']" value="1"' . Html::checked($this->settings->eventOn($event)) . '> ' . Html::e($definition['label']) . '</label>'
                . ' <small class="text-muted">' . Html::e($definition['hook']) . '</small></div><div class="panel-body">';
            $html .= '<p class="help-block" style="margin-top:0">' . Html::e($definition['when']) . '</p>';
            $html .= '<textarea class="form-control" id="' . Html::e($id) . '" name="text[' . Html::e($event) . ']" rows="3" maxlength="1600" data-smstunnel-text="1"'
                . ' data-samples="' . Html::json($samples) . '" data-fields="' . Html::json($fields) . '" data-default="' . Html::e(Events::defaultText($event)) . '" data-counter="' . Html::e($id . '_count') . '">' . Html::e($text) . '</textarea>';
            $html .= '<p class="help-block smstunnel-counter" id="' . Html::e($id . '_count') . '">' . Html::e(self::countLine(Template::render($text, $samples))) . '</p>';
            $html .= '<p class="smstunnel-fields">';
            foreach ($fields as $field) {
                $help = isset(Events::FIELD_HELP[$field]) ? Events::FIELD_HELP[$field] : '';
                $html .= '<button type="button" class="btn btn-default btn-xs" data-insert="{' . Html::e($field) . '}" data-target="' . Html::e($id) . '" title="' . Html::e($help) . '">{' . Html::e($field) . '}</button> ';
            }
            $html .= '<button type="button" class="btn btn-link btn-xs" data-reset="' . Html::e($id) . '">Use the suggested text</button></p>';
            $html .= '</div></div>';
        }
        $html .= '<p><button type="submit" class="btn btn-primary">Save</button></p></form>';

        return $html;
    }

    /**
     * @param array<string, mixed> $get
     */
    private function logTab(string $link, array $get): string
    {
        $status = isset($get['status']) && is_string($get['status']) && in_array($get['status'], TextLog::STATUSES, true) ? $get['status'] : '';
        $event = isset($get['event']) && is_string($get['event']) && (Events::exists($get['event']) || in_array($get['event'], array(Events::TEST, Events::SIGN_IN_CODE), true)) ? $get['event'] : '';
        $page = isset($get['page']) && is_numeric($get['page']) ? max(1, (int) $get['page']) : 1;
        $result = $this->log->page(array('status' => $status, 'event' => $event), $page, self::PER_PAGE);

        $html = '<form method="get" action="addonmodules.php" class="form-inline" style="margin-bottom:10px">'
            . '<input type="hidden" name="module" value="' . Html::e(Version::MODULE) . '"><input type="hidden" name="tab" value="log">'
            . '<select name="status" class="form-control input-sm"><option value="">Every state</option>';
        foreach (TextLog::STATUSES as $option) {
            $html .= '<option value="' . Html::e($option) . '"' . Html::selected($option === $status) . '>' . Html::e(self::statusLabel($option)) . '</option>';
        }
        $html .= '</select> <select name="event" class="form-control input-sm"><option value="">Every event</option>';
        foreach (array_merge(Events::keys(), array(Events::TEST, Events::SIGN_IN_CODE)) as $option) {
            $html .= '<option value="' . Html::e($option) . '"' . Html::selected($option === $event) . '>' . Html::e(Events::label($option)) . '</option>';
        }
        $html .= '</select> <button type="submit" class="btn btn-default btn-sm">Show</button>'
            . ' <span class="text-muted">' . Html::e($result['total'] . ' text(s); kept ' . $this->settings->logDays() . ' days.') . '</span></form>';

        if (!$result['rows']) {
            return $html . '<p class="text-muted">No texts yet.</p>';
        }
        $names = $this->clientNames(array_map(function ($row) {
            return (int) $row['client_id'];
        }, $result['rows']));

        $html .= '<div class="table-responsive"><table class="table table-condensed table-striped smstunnel-log"><thead><tr>'
            . '<th>Time</th><th>Event</th><th>Client</th><th>Number</th><th>State</th><th>Message ID</th><th>Error</th></tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
            $clientId = (int) $row['client_id'];
            $client = $clientId > 0
                ? '<a href="clientssummary.php?userid=' . $clientId . '">' . Html::e(isset($names[$clientId]) ? $names[$clientId] : '#' . $clientId) . '</a>'
                : '<span class="text-muted">' . Html::e($row['audience'] === Events::STAFF ? 'staff' : '-') . '</span>';
            $html .= '<tr>'
                . '<td style="white-space:nowrap">' . Html::e((string) $row['created_at']) . '</td>'
                . '<td>' . $this->eventCell($row) . '</td>'
                . '<td>' . $client . '</td>'
                . '<td style="white-space:nowrap">' . Html::e((string) $row['recipient']) . '</td>'
                . '<td>' . $this->statusBadge($row) . '</td>'
                . '<td>' . ((string) $row['message_id'] !== '' ? '<code>' . Html::e((string) $row['message_id']) . '</code>' : '') . '</td>'
                . '<td>' . Html::e((string) $row['error']) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></div>';

        $pages = (int) ceil($result['total'] / self::PER_PAGE);
        if ($pages > 1) {
            $html .= '<ul class="pagination pagination-sm">';
            for ($i = max(1, $page - 5); $i <= min($pages, $page + 5); ++$i) {
                $html .= '<li' . ($i === $page ? ' class="active"' : '') . '><a href="' . Html::e(Html::url($link, array('tab' => 'log', 'status' => $status, 'event' => $event, 'page' => $i))) . '">' . $i . '</a></li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    // ---- Pieces ----------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function statusBadge(array $row): string
    {
        $status = (string) $row['status'];
        $kinds = array(
            TextLog::SENT => 'success',
            TextLog::QUEUED => 'info',
            TextLog::RETRY => 'warning',
            TextLog::FAILED => 'danger',
            TextLog::SKIPPED => 'default',
            TextLog::SENDING => 'info',
        );
        $label = self::statusLabel($status);
        if ($status === TextLog::RETRY && $row['next_attempt_at'] !== null) {
            $label .= ' at ' . substr((string) $row['next_attempt_at'], 11, 5);
        }
        $tries = (int) $row['attempts'] > 1 ? ' <small class="text-muted">' . (int) $row['attempts'] . ' tries</small>' : '';

        return '<span class="label label-' . (isset($kinds[$status]) ? $kinds[$status] : 'default') . '">' . Html::e($label) . '</span>' . $tries;
    }

    private static function statusLabel(string $status): string
    {
        $labels = array(
            TextLog::SENDING => 'Sending',
            TextLog::SENT => 'Sent',
            TextLog::QUEUED => 'Waiting for your phone',
            TextLog::RETRY => 'Will retry',
            TextLog::FAILED => 'Not sent',
            TextLog::SKIPPED => 'Skipped',
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * The log's Event cell: the event's name, linked to what it was about. An invoice shows its number after
     * the name (Invoice paid #1041); a ticket, order, service or domain links the name itself, so no word is
     * said twice (it read "Staff reply on a ticket ticket" in 1.0.0).
     *
     * @param array<string, mixed> $row
     */
    private function eventCell(array $row): string
    {
        $label = Html::e(Events::label((string) $row['event']));
        $id = (int) $row['related_id'];
        $client = (int) $row['client_id'];
        if ($id <= 0) {
            return $label;
        }
        switch ((string) $row['related_type']) {
            case 'invoice':
                return $label . ' <a href="invoices.php?action=edit&amp;id=' . $id . '">#' . $id . '</a>';
            case 'ticket':
                $href = 'supporttickets.php?action=view&amp;id=' . $id;
                break;
            case 'service':
                $href = 'clientsservices.php?userid=' . $client . '&amp;id=' . $id;
                break;
            case 'domain':
                $href = 'clientsdomains.php?userid=' . $client . '&amp;id=' . $id;
                break;
            case 'order':
                $href = 'orders.php?action=view&amp;id=' . $id;
                break;
            default:
                return $label;
        }

        return '<a href="' . $href . '">' . $label . '</a>';
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, string>
     */
    private function clientNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return array();
        }
        $names = array();
        try {
            foreach (Capsule::table('tblclients')->whereIn('id', $ids)->get(array('id', 'firstname', 'lastname', 'companyname')) as $client) {
                $name = trim(Template::value($client->firstname, 0) . ' ' . Template::value($client->lastname, 0));
                $company = Template::value($client->companyname, 0);
                $names[(int) $client->id] = $name !== '' ? $name . ($company !== '' ? ' (' . $company . ')' : '') : $company;
            }
        } catch (\Throwable $e) {
            // The IDs alone.
        }

        return $names;
    }

    private function formToken(string $action): string
    {
        return '<input type="hidden" name="token" value="' . Html::e($this->platform->csrfToken()) . '">'
            . '<input type="hidden" name="smstunnel_action" value="' . Html::e($action) . '">';
    }

    private function defaultTestText(): string
    {
        $company = $this->platform->companyName();

        return 'This is a test text from ' . ($company !== '' ? $company : 'WHMCS') . ' through SMSTunnel. Texts about invoices, services and tickets will come from this phone.';
    }

    private function notice(string $kind, string $text)
    {
        $this->notices[] = array($kind, $text);
    }

    /** "1 SMS · 98 characters · GSM 7-bit (160 per SMS)", the line under each text. */
    public static function countLine(string $text): string
    {
        $count = SmsCounter::count($text);
        if ($count['parts'] === 0) {
            return 'Empty: nothing is sent.';
        }
        $line = $count['parts'] . ' SMS · ' . $count['units'] . ' characters · '
            . ($count['encoding'] === 'GSM-7' ? 'GSM 7-bit' : 'Unicode') . ' (' . $count['perPart'] . ($count['parts'] > 1 ? ' per part' : ' per SMS') . ')';
        if ($count['nonGsm']) {
            $line .= ' · Unicode because of: ' . implode(' ', array_slice($count['nonGsm'], 0, 10));
        }

        return $line;
    }

    /** What to do about an error, in the dashboard's words. */
    public static function hint($error): string
    {
        if ($error instanceof ApiException) {
            $status = $error->getStatus();
            if ($status === 401) {
                return 'SMSTunnel refused the API key: it was deleted, mistyped, or its account is suspended. Create a new one under Apps → Add an app in your dashboard.';
            }
            if ($status === 403 && $error->getErrorCode() === 'PERMISSION_REQUIRED') {
                return 'This API key may not send texts. Create a new one under Apps → Add an app.';
            }
            if ($status === 403) {
                return 'Your SMSTunnel plan does not allow this.';
            }
            if ($status === 429) {
                return 'Too many texts at once, or your plan\'s daily limit is reached. Wait a moment and try again.';
            }
            if ($status >= 500) {
                return 'SMSTunnel had a problem. Try again in a few minutes.';
            }
            if ($status >= 300 && $status < 400) {
                return 'The SMSTunnel server address under Advanced is not right.';
            }

            return 'SMSTunnel refused the text.';
        }
        if ($error instanceof TransportException) {
            return 'SMSTunnel could not be reached from your server: check that it can open HTTPS connections to smstunnel.io (port 443).';
        }

        return 'Something went wrong.';
    }

    private static function when(string $iso): string
    {
        $time = strtotime($iso);

        return $time !== false ? date('Y-m-d H:i', $time) : $iso;
    }

    /**
     * A form value as typed. WHMCS HTML-encodes request values before modules see them ("Tom & Co"
     * arrives as "Tom &amp; Co"); WHMCS's own Sanitize::decode() undoes that.
     *
     * @param array<string, mixed> $post
     */
    private static function field(array $post, string $name): string
    {
        return isset($post[$name]) && is_string($post[$name]) ? self::decoded($post[$name]) : '';
    }

    public static function decoded(string $value): string
    {
        if (class_exists('WHMCS\\Input\\Sanitize') && method_exists('WHMCS\\Input\\Sanitize', 'decode')) {
            try {
                return (string) \WHMCS\Input\Sanitize::decode($value);
            } catch (\Throwable $e) {
                // The plain decoding below.
            }
        }

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function style(): string
    {
        $file = dirname(__DIR__, 2) . '/assets/admin.css';
        $css = is_file($file) ? (string) file_get_contents($file) : '';

        return '<style>' . str_replace('</', '<\/', $css) . '</style>';
    }

    private function script(): string
    {
        $file = dirname(__DIR__, 2) . '/assets/counter.js';
        $js = is_file($file) ? (string) file_get_contents($file) : '';

        return '<script>' . str_replace('</script', '<\/script', $js) . '</script>';
    }
}
