<?php
/**
 * Activation, settings, the client field, the log, deactivation.
 *
 * @license MIT
 */

use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Store\Consent;
use SmsTunnel\Whmcs\Store\Schema;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;
use SmsTunnel\Whmcs\Text\Events;
use WHMCS\Database\Capsule;

group('activation and settings');

test('activation creates the tables, the defaults and the visible "No text messages" field', function () {
    Harness::activate();
    ok(Schema::installed(), 'tables');
    ok(Capsule::schema()->hasTable('mod_smstunnel_codes'), 'codes table');
    $settings = Module::settings();
    eq($settings->apiUrl(), 'https://smstunnel.io');
    eq($settings->logDays(), 90);
    eq($settings->deleteOnDeactivate(), false);
    eq($settings->optIn(), false);
    eq($settings->eventOn('invoice_created'), true);
    eq($settings->eventOn('payment_reminder'), false, 'pre-due reminders start off');
    eq($settings->eventText('invoice_created'), Events::defaultText('invoice_created'));
    $field = Capsule::table('tblcustomfields')->where('fieldname', Consent::OPT_OUT_NAME)->first();
    ok($field !== null, 'the client field exists');
    eq(array($field->type, $field->fieldtype, $field->adminonly, $field->showorder), array('client', 'tickbox', '', 'on'), 'a client tickbox, visible to clients and at sign-up');
    eq(Capsule::table('tblcustomfields')->where('fieldname', Consent::OPT_IN_NAME)->count(), 0, 'no opt-in field until opt-in mode is used');
    $config = smstunnel_config();
    eq($config['name'], 'SMSTunnel for WHMCS');
    eq($config['version'], \SmsTunnel\Whmcs\Version::NUMBER);
    eq($config['fields'], array());
    // Activating again keeps everything (one field, the same settings).
    $settings->set(Settings::LOG_DAYS, 30);
    Harness::activate();
    eq(Module::settings()->logDays(), 30, 'kept on reactivation');
    eq(Capsule::table('tblcustomfields')->where('fieldname', Consent::OPT_OUT_NAME)->count(), 1, 'one field');
});

test('the API key is stored encrypted and never shown in full', function () {
    Harness::activate();
    $key = Harness::testKey();
    Module::settings()->setApiKey(" $key \n");
    $stored = Capsule::table('mod_smstunnel_settings')->where('setting', Settings::API_KEY)->value('value');
    lacks((string) $stored, $key, 'not stored in clear');
    lacks((string) $stored, substr($key, 8, 12), 'no part of it in clear');
    Module::reset();
    eq(Module::settings()->apiKey(), $key, 'decrypted back, spaces dropped');
    eq(Module::settings()->maskedApiKey(), 'sk_live_••••••••' . substr($key, -4));
    eq(Settings::mask('short'), '••••••••', 'a short value shows nothing');
    Module::settings()->setApiKey('');
    eq(Module::settings()->hasApiKey(), false, 'removed');
});

test('texts: the suggested text is kept as "suggested", a custom one is stored', function () {
    Harness::activate();
    $settings = Module::settings();
    $settings->setEvent('invoice_paid', false, Events::defaultText('invoice_paid'));
    eq($settings->customText('invoice_paid'), '', 'the suggestion itself is not stored');
    eq($settings->eventOn('invoice_paid'), false);
    $settings->setEvent('invoice_paid', true, "  Thanks {client_first_name}! 🎉\r\n");
    Module::reset();
    eq(Module::settings()->eventText('invoice_paid'), 'Thanks {client_first_name}! 🎉', 'emoji survive (stored JSON-escaped)');
    $raw = (string) Capsule::table('mod_smstunnel_settings')->where('setting', 'event.invoice_paid')->value('value');
    ok(preg_match('/^[\x20-\x7E]*$/', $raw) === 1, 'the stored value is plain ASCII, fit for any MySQL character set');
    $settings = Module::settings();
    $settings->set(Settings::STAFF_NUMBERS, array('+14155550199', 'not a number', '+40721123456'));
    eq($settings->staffNumbers(), array('+14155550199', '+40721123456'));
    $settings->set(Settings::LOG_DAYS, 0);
    eq($settings->logDays(), 90, 'a wrong value falls back to 90 days');
    $secret = $settings->twoFactorSecret();
    eq(strlen($secret), 64);
    Module::reset();
    eq(Module::settings()->twoFactorSecret(), $secret, 'the sign-in code secret is stable');
});

group("clients' choice");

test('opt-out by default; opt-in mode uses its own field; switching never turns a No into a Yes', function () {
    Harness::activate(Harness::testKey());
    $consent = Module::consent();
    $optOut = (int) Module::settings()->get(Settings::FIELD_OPT_OUT);
    ok($optOut > 0, 'opt-out field id');
    eq($consent->check(1), array(true, ''), 'nobody ticked anything: texts go');
    Harness::tick($optOut, 2);
    eq($consent->check(2), array(false, Consent::OPTED_OUT), 'ticked No text messages');
    Harness::tick($optOut, 3, '');
    eq($consent->check(3), array(true, ''), 'unticked (empty value)');

    Module::settings()->set(Settings::OPT_IN, true);
    $consent->sync();
    $optIn = (int) Module::settings()->get(Settings::FIELD_OPT_IN);
    ok($optIn > 0 && $optIn !== $optOut, 'a separate opt-in field');
    $fields = Capsule::table('tblcustomfields')->whereIn('id', array($optOut, $optIn))->get()->keyBy('id');
    eq(array($fields[$optIn]->adminonly, $fields[$optIn]->showorder), array('', 'on'), 'clients see "Send me text messages"');
    eq(array($fields[$optOut]->adminonly, $fields[$optOut]->showorder), array('on', ''), '"No text messages" is hidden from clients');
    eq($consent->check(1), array(false, Consent::NOT_OPTED_IN), 'opt-in mode: not ticked, no texts');
    eq($consent->check(2), array(false, Consent::NOT_OPTED_IN), 'the old No stays a No');
    Harness::tick($optIn, 1);
    eq($consent->check(1), array(true, ''), 'ticked Send me text messages');

    Module::settings()->set(Settings::OPT_IN, false);
    $consent->sync();
    eq($consent->check(2), array(false, Consent::OPTED_OUT), 'back to opt-out: client 2 is still opted out');
    eq($consent->check(1), array(true, ''), 'client 1 gets texts');
    eq(Capsule::table('tblcustomfieldsvalues')->where('fieldid', $optIn)->count(), 1, 'the opt-in answers are kept');
    $hidden = Capsule::table('tblcustomfields')->where('id', $optIn)->first();
    eq($hidden->adminonly, 'on', 'the opt-in field is hidden again');
});

test('a deleted field is reported and can be created again', function () {
    Harness::activate();
    $id = (int) Module::settings()->get(Settings::FIELD_OPT_OUT);
    Capsule::table('tblcustomfields')->where('id', $id)->delete();
    eq(Module::consent()->activeField()['id'], 0, 'missing');
    eq(Module::consent()->check(5), array(false, Consent::FIELD_MISSING), 'without the field nobody could say no: no texts until it is back');
    Module::consent()->sync();
    ok(Module::consent()->activeField()['id'] > 0, 'created again');
});

group('log and deactivation');

test('the daily cron removes rows older than the days set (a day after it was written a text is past its retries)', function () {
    Harness::activate(Harness::testKey());
    $log = Module::log();
    $day = 86400;
    foreach (array(100, 91, 89, 1) as $daysAgo) {
        Harness::$now -= $daysAgo * $day;
        $log->create(array('event' => 'invoice_paid', 'audience' => 'client', 'status' => TextLog::SENT, 'recipient' => '+14155550101'));
        Harness::$now += $daysAgo * $day;
    }
    Harness::$now -= 95 * $day;
    $stuck = $log->create(array('event' => 'invoice_paid', 'audience' => 'client', 'status' => TextLog::SENDING, 'recipient' => '+14155550102', 'request' => '{"to":"+14155550102"}'));
    $log->retryAt($stuck, Harness::$now + 300, 'network');
    Harness::$now += 95 * $day;
    $recent = $log->create(array('event' => 'invoice_paid', 'audience' => 'client', 'status' => TextLog::SENDING, 'recipient' => '+14155550103'));
    $log->retryAt($recent, Harness::$now + 300, 'network');
    Harness::fire('DailyCronJob');
    eq(count(Harness::logRows()), 3, 'the rows older than 90 days went, a stuck retry with its number and words too');
    Module::settings()->set(Settings::LOG_DAYS, 2);
    Harness::fire('DailyCronJob');
    eq(array_column(Harness::logRows(), 'recipient'), array('+14155550101', '+14155550103'), 'with 2 days: the newest row and the text waiting since a moment ago');
});

test('deactivation keeps everything unless "Delete all data on deactivate" is ticked', function () {
    Harness::activate(Harness::testKey());
    $field = (int) Module::settings()->get(Settings::FIELD_OPT_OUT);
    Harness::tick($field, 7);
    Module::log()->create(array('event' => 'test', 'audience' => 'test', 'status' => TextLog::SENT));
    $result = smstunnel_deactivate();
    eq($result['status'], 'success');
    has($result['description'], 'kept');
    ok(Schema::installed(), 'tables kept');
    eq(Module::settings()->apiKey(), Harness::testKey(), 'key kept');
    eq(count(Harness::logRows()), 1, 'log kept');
    eq(Capsule::table('tblcustomfieldsvalues')->where('fieldid', $field)->count(), 1, "clients' choices kept");

    Harness::activate();
    eq(Module::settings()->apiKey(), Harness::testKey(), 'the key is still there after activating again');
    Module::settings()->set(Settings::DELETE_ON_DEACTIVATE, true);
    $result = smstunnel_deactivate();
    eq($result['status'], 'success');
    has($result['description'], 'deleted');
    ok(!Capsule::schema()->hasTable('mod_smstunnel_settings'), 'settings table dropped');
    ok(!Capsule::schema()->hasTable('mod_smstunnel_log'), 'log dropped');
    ok(!Capsule::schema()->hasTable('mod_smstunnel_codes'), 'codes dropped');
    eq(Capsule::table('tblcustomfields')->where('id', $field)->count(), 0, 'field deleted');
    eq(Capsule::table('tblcustomfieldsvalues')->where('fieldid', $field)->count(), 0, 'its values deleted');
});

test('upgrade brings the tables and defaults up to date without losing settings', function () {
    Harness::activate(Harness::testKey());
    Capsule::schema()->drop('mod_smstunnel_codes');
    Module::settings()->set(Settings::LOG_DAYS, 45);
    smstunnel_upgrade(array('version' => '0.9.0'));
    ok(Capsule::schema()->hasTable('mod_smstunnel_codes'), 'missing table created');
    eq(Module::settings()->logDays(), 45, 'settings kept');
    has(Harness::$activity[count(Harness::$activity) - 1][0], 'upgraded from 0.9.0');
});
