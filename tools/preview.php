<?php
/**
 * Renders the module's admin page (each tab) on the test WHMCS into static HTML files with Bootstrap 3
 * (the base of WHMCS's Blend admin theme), to look at the layout without a WHMCS licence:
 *
 *   php tools/preview.php <output folder>
 *
 * The data is made up; nothing is sent anywhere (the fake transport answers).
 *
 * @license MIT
 */

require __DIR__ . '/../tests/harness/bootstrap.php';
require SMSTUNNEL_REPO . '/modules/addons/smstunnel/lib/autoload.php';

use SmsTunnel\Whmcs\Module;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;

$out = isset($argv[1]) ? rtrim($argv[1], '/') : SMSTUNNEL_REPO . '/build/preview';
if (!is_dir($out)) {
    mkdir($out, 0777, true);
}

Harness::boot();
Harness::activate('sk_' . 'live_' . 'preview0123456789abcdef01234567');
Module::settings()->set(Settings::STAFF_NUMBERS, array('+14155550190', '+14155550191'));
Harness::usClient(1, '4155550101');
Harness::usClient(2, '4155550102', array('firstname' => 'Mihai', 'lastname' => 'Ionescu', 'companyname' => 'Ionescu Design'));
$log = Module::log();
$rows = array(
    array('invoice_created', 'client', 1, 'invoice', 1042, '+14155550101', TextLog::SENT, '6650c0ffee00000000000101', ''),
    array('overdue_reminder', 'client', 2, 'invoice', 1038, '+14155550102', TextLog::QUEUED, '6650c0ffee00000000000102', ''),
    array('staff_order_paid', 'staff', 1, 'order', 512, '+14155550190', TextLog::RETRY, '', 'HTTP 503: Service Unavailable'),
    array('ticket_reply', 'client', 2, 'ticket', 77, '+14155550102', TextLog::SKIPPED, '', 'opted out (No text messages)'),
    array('invoice_paid', 'client', 1, 'invoice', 1041, '+14155550101', TextLog::FAILED, '', 'HTTP 401: Invalid or expired API key'),
);
foreach ($rows as $row) {
    $id = $log->create(array('event' => $row[0], 'audience' => $row[1], 'client_id' => $row[2], 'related_type' => $row[3], 'related_id' => $row[4], 'recipient' => $row[5], 'status' => TextLog::SENDING));
    if ($row[6] === TextLog::RETRY) {
        $log->retryAt($id, Harness::$now + 300, $row[8]);
    } else {
        $log->finish($id, $row[6], $row[7], $row[8]);
    }
}

$devices = json_encode(array('success' => true, 'data' => array('devices' => array(
    array('id' => '6650c0ffee0000000000a001', 'name' => 'Office phone', 'isOnline' => true, 'lastSeenAt' => '2026-11-18T10:00:00Z'),
    array('id' => '6650c0ffee0000000000a002', 'name' => 'Spare phone', 'isOnline' => false, 'lastSeenAt' => '2026-11-16T08:30:00Z'),
))));

$head = '<!doctype html><html><head><meta charset="utf-8"><title>SMSTunnel for WHMCS preview</title>'
    . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css">'
    . '<style>body{background:#f3f5f7;padding:20px} .preview{background:#fff;padding:20px;max-width:1100px;margin:auto;border:1px solid #ddd}</style>'
    . '</head><body><div class="preview">';
foreach (array('setup', 'texts', 'staff', 'log') as $tab) {
    Harness::$responses = array();
    if ($tab === 'setup') {
        Harness::respond(array('status' => 200, 'headers' => array(), 'body' => $devices));
    }
    $_GET = array('module' => 'smstunnel', 'tab' => $tab);
    $_POST = array();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    smstunnel_output(array('modulelink' => 'addonmodules.php?module=smstunnel'));
    $html = (string) ob_get_clean();
    Module::reset();
    file_put_contents($out . '/' . $tab . '.html', $head . $html . '</div></body></html>');
    echo $out . '/' . $tab . ".html\n";
}
