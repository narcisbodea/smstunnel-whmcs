<?php
/**
 * The unit tests of SMSTunnel for WHMCS, on the test WHMCS of tests/harness (SQLite, stubs).
 *
 *   php tests/unit/run.php [filter]
 *   SMSTUNNEL_FAKE_URL=http://host.docker.internal:PORT php tests/unit/run.php   (with the HTTP tests)
 *
 * tools/check.sh runs them on every PHP version WHMCS 8.10 to 9.1 supports, with the fake API.
 * Nothing here talks to smstunnel.io.
 *
 * @license MIT
 */

require __DIR__ . '/../harness/bootstrap.php';
require __DIR__ . '/../harness/Tests.php';
require SMSTUNNEL_REPO . '/modules/addons/smstunnel/lib/autoload.php';

Harness::boot();

foreach (array('PhoneTest', 'TextTest', 'StoreTest', 'HooksTest', 'RetryTest', 'AdminTest', 'TwoFactorTest', 'HttpTest') as $file) {
    require __DIR__ . '/' . $file . '.php';
}

exit(Tests::run(isset($argv[1]) ? (string) $argv[1] : '') > 0 ? 1 : 0);
