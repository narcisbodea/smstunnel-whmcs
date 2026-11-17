<?php
/**
 * A stand-in for WHMCS, so the module can be loaded and tested without a WHMCS licence.
 *
 * - WHMCS\Database\Capsule is Laravel's Capsule (illuminate/database) on SQLite.
 * - add_hook() records the callbacks of the module's hooks.php; Harness::fire() runs them the way
 *   WHMCS does.
 * - localAPI() answers GetClientsDetails, GetInvoice, GetTicket, GetClientsProducts and GetOrders
 *   from fixtures, in the shapes of WHMCS's API reference.
 * - encrypt()/decrypt(), logActivity(), generate_token(), getCurrency(), formatCurrency() and
 *   fromMySQLDate() behave like WHMCS's for what the module uses.
 *
 * Vendor code (illuminate/database) is installed per PHP version by tools/check.sh into
 * tests/.cache/vendor-php<major><minor>; SMSTUNNEL_VENDOR points at another one.
 *
 * @license MIT
 */

namespace {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    date_default_timezone_set('UTC');

    define('WHMCS', true);
    define('SMSTUNNEL_REPO', dirname(__DIR__, 2));
    define('ROOTDIR', SMSTUNNEL_REPO);

    // Warnings in the module's own code (or the tests) are failures; deprecations from the vendor's
    // Laravel on a newer PHP are not the module's concern. A deprecation from the WHMCS stand-in itself
    // is noted and goes on, the way WHMCS logs its own deprecations.
    $GLOBALS['smstunnel_test_notices'] = array();
    set_error_handler(function ($severity, $message, $file = '', $line = 0) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        $file = str_replace('\\', '/', (string) $file);
        if (in_array($severity, array(E_DEPRECATED, E_USER_DEPRECATED), true)) {
            if (strpos($file, '/vendor') !== false) {
                return true;
            }
            if (substr($file, -strlen('/tests/harness/bootstrap.php')) === '/tests/harness/bootstrap.php') {
                $GLOBALS['smstunnel_test_notices'][] = $message;

                return true;
            }
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    $vendor = getenv('SMSTUNNEL_VENDOR');
    if (!is_string($vendor) || $vendor === '') {
        $vendor = SMSTUNNEL_REPO . '/tests/.cache/vendor-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    }
    if (!is_file($vendor . '/autoload.php')) {
        fwrite(STDERR, "illuminate/database is missing in $vendor: run tools/check.sh (it installs it).\n");
        exit(2);
    }
    require $vendor . '/autoload.php';

    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = array();
    }
}

namespace WHMCS\Database {
    class Capsule extends \Illuminate\Database\Capsule\Manager
    {
    }
}

namespace WHMCS\Config {
    class Setting
    {
        public static function getValue($name)
        {
            $row = \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', $name)->first();

            return $row !== null ? $row->value : '';
        }
    }
}

namespace WHMCS {
    class Exception extends \Exception
    {
    }
}

namespace {
    use WHMCS\Database\Capsule;

    /** The hooks the module registered: hook point => list of callbacks. */
    $GLOBALS['smstunnel_test_hooks'] = array();

    function add_hook($hookPoint, $priority, $function)
    {
        $GLOBALS['smstunnel_test_hooks'][$hookPoint][] = array((int) $priority, $function);
    }

    function localAPI($command, $values = array(), $adminUser = '')
    {
        return Harness::api((string) $command, is_array($values) ? $values : array());
    }

    function encrypt($string)
    {
        $iv = random_bytes(16);
        $data = openssl_encrypt((string) $string, 'aes-256-cbc', Harness::ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $data);
    }

    function decrypt($string)
    {
        $raw = base64_decode((string) $string, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', Harness::ENCRYPTION_KEY, OPENSSL_RAW_DATA, substr($raw, 0, 16));

        return $plain === false ? '' : $plain;
    }

    function logActivity($message, $userId = 0)
    {
        Harness::$activity[] = array((string) $message, (int) $userId);
    }

    function generate_token($type = 'form')
    {
        if (!isset($_SESSION['tkval'])) {
            $_SESSION['tkval'] = 'csrf-' . bin2hex(random_bytes(10));
        }

        return $type === 'plain' ? $_SESSION['tkval'] : '<input type="hidden" name="token" value="' . $_SESSION['tkval'] . '" />';
    }

    function getCurrency($userId = 0, $currencyId = 0)
    {
        return array('id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => ' USD', 'format' => 1, 'rate' => 1);
    }

    function formatCurrency($amount, $currencyId = null)
    {
        // WHMCS returns a WHMCS\View\Formatter\Price; its string form is what the module uses.
        return new HarnessPrice('$' . number_format((float) $amount, 2, '.', ',') . ' USD');
    }

    function fromMySQLDate($date, $includeTime = false, $applyClientDateFormat = false)
    {
        $time = strtotime((string) $date);

        return $time === false ? (string) $date : date($includeTime ? 'm/d/Y H:i' : 'm/d/Y', $time);
    }

    final class HarnessPrice
    {
        private $text;

        public function __construct($text)
        {
            $this->text = $text;
        }

        public function __toString()
        {
            return $this->text;
        }
    }

    /**
     * The test WHMCS: database, fixtures, hooks, a fake SMSTunnel transport and a clock.
     */
    final class Harness
    {
        const ENCRYPTION_KEY = 'harness-encryption-key-0123456789abcdef';

        /** @var array<int, array{0: string, 1: int}> */
        public static $activity = array();

        /** @var array<int, array<string, mixed>> GetClientsDetails "client" arrays, by client id */
        public static $clients = array();

        /** @var array<int, array<string, mixed>> GetInvoice answers, by invoice id */
        public static $invoices = array();

        /** @var array<int, array<string, mixed>> GetTicket answers */
        public static $tickets = array();

        /** @var array<int, array<string, mixed>> GetClientsProducts product rows, by service id */
        public static $services = array();

        /** @var array<int, array<string, mixed>> GetOrders order rows */
        public static $orders = array();

        /** @var array<int, array{0: string, 1: array}> every localAPI call */
        public static $apiCalls = array();

        /** @var callable|null makes localAPI throw, for tests */
        public static $apiFailure;

        /** @var string a localAPI command that raises a deprecation inside "WHMCS" */
        public static $deprecationIn = '';

        /** @var int the clock (Unix time) the module sees */
        public static $now = 0;

        /** @var array<int, array<string, mixed>> requests the fake transport got */
        public static $requests = array();

        /** @var array<int, mixed> answers the fake transport gives next (array, callable or \Throwable) */
        public static $responses = array();

        /** @var string */
        private static $dbFile = '';

        /**
         * A fresh WHMCS: an empty SQLite database with the WHMCS tables the module touches.
         *
         * @param string $file a SQLite file, or '' for memory
         */
        public static function boot(string $file = '')
        {
            self::$dbFile = $file;
            $capsule = new Capsule();
            $capsule->addConnection(array(
                'driver' => 'sqlite',
                'database' => $file !== '' ? $file : ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ));
            $capsule->setAsGlobal();
            self::createWhmcsTables();
            self::reset();
        }

        /** Forgets fixtures, requests and the module's per-request state; the database stays. */
        public static function reset()
        {
            self::$activity = array();
            self::$apiCalls = array();
            self::$apiFailure = null;
            self::$deprecationIn = '';
            $GLOBALS['smstunnel_test_notices'] = array();
            self::$requests = array();
            self::$responses = array();
            self::$now = 1795000000; // 2026-11-18 (a fixed day keeps the tests' dates stable)
            $_SESSION = array('adminid' => 1);
            \SmsTunnel\Whmcs\Module::$clock = function () {
                return Harness::$now;
            };
            \SmsTunnel\Whmcs\Module::$clientOptions = array('transport' => array(__CLASS__, 'transport'));
            \SmsTunnel\Whmcs\Module::reset();
        }

        /** Drops the module's tables and the WHMCS rows the tests made. */
        public static function wipe()
        {
            foreach (array('mod_smstunnel_codes', 'mod_smstunnel_log', 'mod_smstunnel_settings') as $table) {
                Capsule::schema()->dropIfExists($table);
            }
            foreach (array('tblcustomfields', 'tblcustomfieldsvalues', 'tblclients') as $table) {
                Capsule::table($table)->delete();
            }
            self::defaultSettings();
            self::$clients = array();
            self::$invoices = array();
            self::$tickets = array();
            self::$services = array();
            self::$orders = array();
            self::reset();
        }

        public static function setting(string $name, string $value)
        {
            Capsule::table('tblconfiguration')->where('setting', $name)->delete();
            Capsule::table('tblconfiguration')->insert(array('setting' => $name, 'value' => $value));
        }

        private static function createWhmcsTables()
        {
            $schema = Capsule::schema();
            if (!$schema->hasTable('tblconfiguration')) {
                $schema->create('tblconfiguration', function ($table) {
                    $table->string('setting', 64);
                    $table->text('value');
                    $table->timestamp('created_at')->nullable();
                    $table->timestamp('updated_at')->nullable();
                });
            }
            if (!$schema->hasTable('tblclients')) {
                $schema->create('tblclients', function ($table) {
                    $table->increments('id');
                    $table->text('firstname');
                    $table->text('lastname');
                    $table->text('companyname');
                    $table->text('email');
                    $table->text('country');
                    $table->text('phonenumber');
                    $table->text('status');
                });
            }
            if (!$schema->hasTable('tblcustomfields')) {
                // The columns of WHMCS 8's tblcustomfields.
                $schema->create('tblcustomfields', function ($table) {
                    $table->increments('id');
                    $table->text('type');
                    $table->integer('relid')->default(0);
                    $table->text('fieldname');
                    $table->text('fieldtype');
                    $table->text('description');
                    $table->text('fieldoptions');
                    $table->text('regexpr');
                    $table->text('adminonly');
                    $table->text('required');
                    $table->text('showorder');
                    $table->text('showinvoice');
                    $table->integer('sortorder')->default(0);
                    $table->timestamp('created_at')->nullable();
                    $table->timestamp('updated_at')->nullable();
                });
            }
            if (!$schema->hasTable('tblcustomfieldsvalues')) {
                $schema->create('tblcustomfieldsvalues', function ($table) {
                    $table->increments('id');
                    $table->integer('fieldid');
                    $table->integer('relid');
                    $table->text('value');
                    $table->timestamp('created_at')->nullable();
                    $table->timestamp('updated_at')->nullable();
                });
            }
            self::defaultSettings();
        }

        /** WHMCS's General Settings the module reads. */
        private static function defaultSettings()
        {
            self::setting('CompanyName', 'Example Hosting');
            self::setting('SystemURL', 'https://billing.example.com/');
            self::setting('DefaultCountry', 'US');
            self::setting('Version', '8.13.1-release.1');
        }

        // ---- Fixtures --------------------------------------------------------------------------------

        /**
         * A client, as GetClientsDetails returns it, also written to tblclients.
         *
         * @param array<string, mixed> $client
         */
        public static function client(int $id, array $client)
        {
            $client = $client + array(
                'firstname' => 'Ana',
                'lastname' => 'Pop',
                'companyname' => '',
                'email' => 'client' . $id . '@example.com',
                'countrycode' => 'US',
                'phonenumber' => '',
                'phonecc' => '',
                'phonenumberformatted' => '',
                'language' => '',
                'currency' => 1,
                'currency_code' => 'USD',
                'status' => 'Active',
            );
            $client['client_id'] = $id;
            $client['userid'] = $id;
            $client['id'] = $id;
            $client['country'] = $client['countrycode'];
            $client['fullname'] = trim($client['firstname'] . ' ' . $client['lastname']);
            $client['telephoneNumber'] = $client['phonenumberformatted'];
            self::$clients[$id] = $client;
            Capsule::table('tblclients')->where('id', $id)->delete();
            Capsule::table('tblclients')->insert(array(
                'id' => $id,
                'firstname' => $client['firstname'],
                'lastname' => $client['lastname'],
                'companyname' => $client['companyname'],
                'email' => $client['email'],
                'country' => $client['countrycode'],
                'phonenumber' => $client['phonenumber'],
                'status' => $client['status'],
            ));
        }

        /** A US client with a number in WHMCS's formats. */
        public static function usClient(int $id, string $national = '4155550101', array $extra = array())
        {
            self::client($id, $extra + array(
                'firstname' => 'Alexandra',
                'lastname' => 'Popescu',
                'countrycode' => 'US',
                'phonenumber' => $national,
                'phonecc' => '1',
                'phonenumberformatted' => '+1.' . $national,
            ));
        }

        public static function invoice(int $id, int $clientId, array $extra = array())
        {
            self::$invoices[$id] = $extra + array(
                'result' => 'success',
                'invoiceid' => $id,
                'invoicenum' => '',
                'userid' => $clientId,
                'date' => '2026-11-18',
                'duedate' => '2026-11-25',
                'datepaid' => '0000-00-00 00:00:00',
                'subtotal' => '25.00',
                'credit' => '0.00',
                'tax' => '0.00',
                'total' => '25.00',
                'balance' => '25.00',
                'status' => 'Unpaid',
                'paymentmethod' => 'stripe',
            );
        }

        public static function ticket(int $id, int $clientId, array $extra = array())
        {
            self::$tickets[$id] = $extra + array(
                'result' => 'success',
                'ticketid' => $id,
                'tid' => '48291' . $id,
                'c' => 'KPqH7yG3',
                'deptid' => 1,
                'deptname' => 'Support',
                'userid' => $clientId,
                'name' => $clientId > 0 ? '' : 'Visitor Name',
                'requestor_name' => $clientId > 0 ? '' : 'Visitor Name',
                'subject' => 'Email not working',
                'status' => 'Open',
                'priority' => 'Medium',
            );
        }

        public static function service(int $id, int $clientId, array $extra = array())
        {
            self::$services[$id] = $extra + array(
                'id' => $id,
                'clientid' => $clientId,
                'pid' => 3,
                'name' => 'Starter Hosting',
                'translated_name' => 'Starter Hosting',
                'domain' => 'example.com',
                'status' => 'Active',
                'suspensionreason' => '',
            );
        }

        public static function order(int $id, int $clientId, array $extra = array())
        {
            self::$orders[$id] = $extra + array(
                'id' => $id,
                'ordernum' => 7858259149,
                'userid' => $clientId,
                'amount' => '95.40',
                'paymentmethodname' => 'Credit Card',
                'name' => 'Alexandra Popescu',
                'currencyprefix' => '$',
                'currencysuffix' => 'USD',
            );
        }

        /** Ticks a client custom field. */
        public static function tick(int $fieldId, int $clientId, string $value = 'on')
        {
            Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)->where('relid', $clientId)->delete();
            Capsule::table('tblcustomfieldsvalues')->insert(array('fieldid' => $fieldId, 'relid' => $clientId, 'value' => $value));
        }

        /**
         * WHMCS's Internal API, from the fixtures.
         *
         * @param array<string, mixed> $values
         *
         * @return array<string, mixed>
         */
        public static function api(string $command, array $values): array
        {
            self::$apiCalls[] = array($command, $values);
            if (self::$deprecationIn === $command) {
                // As WHMCS's own code can on a newer PHP: a deprecation raised inside WHMCS.
                trigger_error('Optional parameter declared before required parameter', E_USER_DEPRECATED);
            }
            if (self::$apiFailure !== null) {
                $failure = self::$apiFailure;
                $failure($command, $values);
            }
            $error = array('result' => 'error', 'message' => 'Not found');
            switch ($command) {
                case 'GetClientsDetails':
                    $id = (int) (isset($values['clientid']) ? $values['clientid'] : 0);
                    if (!isset(self::$clients[$id])) {
                        return array('result' => 'error', 'message' => 'Client Not Found');
                    }

                    return array('result' => 'success', 'client' => self::$clients[$id]) + self::$clients[$id];
                case 'GetInvoice':
                    $id = (int) (isset($values['invoiceid']) ? $values['invoiceid'] : 0);

                    return isset(self::$invoices[$id]) ? self::$invoices[$id] : array('result' => 'error', 'message' => 'Invoice ID Not Found');
                case 'GetTicket':
                    $id = (int) (isset($values['ticketid']) ? $values['ticketid'] : 0);

                    return isset(self::$tickets[$id]) ? self::$tickets[$id] : array('result' => 'error', 'message' => 'Ticket ID Not Found');
                case 'GetClientsProducts':
                    $id = (int) (isset($values['serviceid']) ? $values['serviceid'] : 0);
                    $rows = isset(self::$services[$id]) ? array(self::$services[$id]) : array();

                    return array('result' => 'success', 'totalresults' => count($rows), 'products' => array('product' => $rows));
                case 'GetOrders':
                    $id = (int) (isset($values['id']) ? $values['id'] : 0);
                    $rows = isset(self::$orders[$id]) ? array(self::$orders[$id]) : array();

                    return array('result' => 'success', 'totalresults' => count($rows), 'orders' => array('order' => $rows));
                default:
                    return $error;
            }
        }

        // ---- Hooks -------------------------------------------------------------------------------------

        /** Loads the module's hooks.php, as WHMCS does on every page while the addon is active. */
        public static function loadHooks()
        {
            if (empty($GLOBALS['smstunnel_test_hooks'])) {
                require SMSTUNNEL_REPO . '/modules/addons/smstunnel/hooks.php';
            }
        }

        /**
         * Runs a hook point's callbacks in priority order, the way WHMCS does.
         *
         * @param array<string, mixed> $vars
         */
        public static function fire(string $hookPoint, array $vars = array())
        {
            self::loadHooks();
            $hooks = isset($GLOBALS['smstunnel_test_hooks'][$hookPoint]) ? $GLOBALS['smstunnel_test_hooks'][$hookPoint] : array();
            usort($hooks, function ($a, $b) {
                return $a[0] - $b[0];
            });
            foreach ($hooks as $hook) {
                call_user_func($hook[1], $vars);
            }
        }

        /**
         * A new PHP request: the module forgets what it learned in this one (circuit, objects), like
         * the next WHMCS page or cron run.
         */
        public static function nextRequest()
        {
            \SmsTunnel\Whmcs\Module::reset();
        }

        // ---- The fake SMSTunnel transport ---------------------------------------------------------------

        /**
         * Queues what the next call answers: an array (status, headers, body), a \Throwable to throw,
         * or a callable fn(array $request): array.
         *
         * @param mixed $response
         */
        public static function respond($response)
        {
            self::$responses[] = $response;
        }

        /** A successful send answer, the way SMSTunnel writes it. */
        public static function sent(string $messageId = '', bool $queued = false, array $headers = array()): array
        {
            $messageId = $messageId !== '' ? $messageId : bin2hex(random_bytes(12));
            $body = array('success' => true, 'messageId' => $messageId, 'data' => array('messageId' => $messageId, 'branded' => false, 'remaining' => array('clean' => -1)));
            if ($queued) {
                $body['queued'] = true;
                $body['data']['queued'] = true;
            }

            return array('status' => 200, 'headers' => $headers, 'body' => json_encode($body));
        }

        /**
         * An error answer: a JSON body (array) or a raw one (a proxy's HTML page).
         *
         * @param array<string, mixed>|string $body
         */
        public static function error(int $status, $body, array $headers = array()): array
        {
            return array('status' => $status, 'headers' => $headers, 'body' => is_array($body) ? json_encode($body + array('statusCode' => $status)) : (string) $body);
        }

        /**
         * The transport the module's Api\Client uses in the tests: records the request and answers
         * with the next queued response (a success when none is queued).
         *
         * @param string[]    $headers
         * @param string|null $payload
         */
        public static function transport(string $method, string $url, array $headers, $payload, float $timeout, float $connectTimeout): array
        {
            $named = array();
            foreach ($headers as $header) {
                if (strpos($header, ':') !== false) {
                    list($name, $value) = explode(':', $header, 2);
                    $named[strtolower(trim($name))] = trim($value);
                }
            }
            $request = array(
                'method' => $method,
                'url' => $url,
                'path' => (string) parse_url($url, PHP_URL_PATH),
                'headers' => $named,
                'body' => $payload !== null ? json_decode($payload, true) : null,
                'raw' => $payload,
                'timeout' => $timeout,
                'connectTimeout' => $connectTimeout,
            );
            self::$requests[] = $request;
            $response = self::$responses ? array_shift(self::$responses) : null;
            if ($response === null) {
                if ($request['path'] === '/api/v1/devices') {
                    return array('status' => 200, 'headers' => array(), 'body' => json_encode(array('success' => true, 'data' => array('devices' => array(
                        array('id' => '6650c0ffee0000000000a001', 'name' => 'Store phone', 'isOnline' => true, 'lastSeenAt' => '2026-11-18T10:00:00Z'),
                    )))));
                }

                return self::sent();
            }
            if ($response instanceof \Throwable) {
                throw $response;
            }
            if (is_callable($response)) {
                return $response($request);
            }

            return $response;
        }

        /**
         * The requests sent to one endpoint.
         *
         * @return array<int, array<string, mixed>>
         */
        public static function sends(string $path = '/api/v1/sms/send'): array
        {
            return array_values(array_filter(self::$requests, function ($request) use ($path) {
                return $request['path'] === $path;
            }));
        }

        /**
         * The module's log rows, oldest first.
         *
         * @return array<int, array<string, mixed>>
         */
        public static function logRows(): array
        {
            $rows = array();
            foreach (Capsule::table('mod_smstunnel_log')->orderBy('id')->get() as $row) {
                $rows[] = (array) $row;
            }

            return $rows;
        }

        /** Activates the addon (as System Settings → Addon Modules → Activate does) and saves a key. */
        public static function activate(string $apiKey = '')
        {
            require_once SMSTUNNEL_REPO . '/modules/addons/smstunnel/smstunnel.php';
            $result = smstunnel_activate();
            if ($result['status'] !== 'success') {
                throw new \RuntimeException('activate: ' . $result['description']);
            }
            if ($apiKey !== '') {
                \SmsTunnel\Whmcs\Module::settings()->setApiKey($apiKey);
            }
            self::loadHooks();
        }

        /** A test API key, built from pieces so no file holds something a secret scanner takes for a real key. */
        public static function testKey(): string
        {
            return 'sk_' . 'live_' . 'harness0123456789abcdef0123456789';
        }
    }
}
