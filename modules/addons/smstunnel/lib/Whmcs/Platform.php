<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Whmcs;

use SmsTunnel\Whmcs\Guard;
use SmsTunnel\Whmcs\Redact;
use SmsTunnel\Whmcs\Text\Template;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Everything the module asks WHMCS: the Internal API (localAPI), settings, money and date formats,
 * encryption, the CSRF token and the Activity Log. One place, so the rest of the module is plain PHP.
 */
class Platform
{
    /** @var callable|null fn(): int, for tests */
    private $clock;

    /** @var array<string, string> WHMCS settings read during this request */
    private $config = array();

    /**
     * @param callable|null $clock returns the current Unix time (tests move it forward)
     */
    public function __construct($clock = null)
    {
        $this->clock = is_callable($clock) ? $clock : null;
    }

    public function now(): int
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }

    /** The current time as WHMCS stores it in the database. */
    public function nowSql(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', $this->now() + $offsetSeconds);
    }

    /**
     * Calls WHMCS's Internal API. Null when the call fails or WHMCS answers with an error.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function api(string $command, array $params = array())
    {
        if (!function_exists('localAPI')) {
            return null;
        }
        try {
            $result = localAPI($command, $params);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($result) || (isset($result['result']) && $result['result'] !== 'success')) {
            return null;
        }

        return $result;
    }

    /**
     * A client, from GetClientsDetails.
     *
     * @return array<string, mixed>|null
     */
    public function client(int $clientId)
    {
        if ($clientId <= 0) {
            return null;
        }
        $result = $this->api('GetClientsDetails', array('clientid' => $clientId, 'stats' => false));
        if ($result === null) {
            return null;
        }
        // WHMCS answers with a "client" array; older versions also repeat it at the top level.
        $client = isset($result['client']) && is_array($result['client']) ? $result['client'] : $result;
        $id = (int) self::pick($client, array('client_id', 'userid', 'id'), $clientId);
        $first = self::pick($client, array('firstname'), '');
        $last = self::pick($client, array('lastname'), '');
        $full = self::pick($client, array('fullname'), '');

        return array(
            'id' => $id > 0 ? $id : $clientId,
            'firstName' => $first,
            'lastName' => $last,
            'fullName' => $full !== '' ? $full : trim($first . ' ' . $last),
            'companyName' => self::pick($client, array('companyname'), ''),
            'countryCode' => strtoupper(self::pick($client, array('countrycode', 'country'), '')),
            'phoneRaw' => self::pick($client, array('phonenumber'), ''),
            'phoneCc' => self::pick($client, array('phonecc'), ''),
            'phoneFormatted' => self::pick($client, array('phonenumberformatted', 'telephoneNumber'), ''),
            'language' => self::pick($client, array('language'), ''),
            'currencyId' => (int) self::pick($client, array('currency'), 0),
            'currencyCode' => self::pick($client, array('currency_code'), ''),
            'status' => self::pick($client, array('status'), ''),
        );
    }

    /**
     * An invoice, from GetInvoice.
     *
     * @return array<string, mixed>|null
     */
    public function invoice(int $invoiceId)
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $result = $this->api('GetInvoice', array('invoiceid' => $invoiceId));
        if ($result === null) {
            return null;
        }
        $number = self::pick($result, array('invoicenum'), '');

        return array(
            'id' => (int) self::pick($result, array('invoiceid', 'id'), $invoiceId),
            'number' => $number !== '' ? $number : (string) $invoiceId,
            'clientId' => (int) self::pick($result, array('userid'), 0),
            'status' => self::pick($result, array('status'), ''),
            'total' => (float) self::pick($result, array('total'), 0),
            'balance' => (float) self::pick($result, array('balance'), self::pick($result, array('total'), 0)),
            'dueDate' => self::pick($result, array('duedate'), ''),
        );
    }

    /**
     * A ticket, from GetTicket.
     *
     * @return array<string, mixed>|null
     */
    public function ticket(int $ticketId)
    {
        if ($ticketId <= 0) {
            return null;
        }
        $result = $this->api('GetTicket', array('ticketid' => $ticketId, 'repliessort' => 'DESC'));
        if ($result === null) {
            return null;
        }

        return array(
            'id' => (int) self::pick($result, array('ticketid', 'id'), $ticketId),
            'number' => self::pick($result, array('tid'), (string) $ticketId),
            'clientId' => (int) self::pick($result, array('userid'), 0),
            'name' => self::pick($result, array('requestor_name', 'name'), ''),
            'subject' => self::pick($result, array('subject'), ''),
            'department' => self::pick($result, array('deptname'), ''),
            'status' => self::pick($result, array('status'), ''),
            'priority' => self::pick($result, array('priority'), ''),
        );
    }

    /**
     * A service (product) of a client, from GetClientsProducts.
     *
     * @return array<string, mixed>|null
     */
    public function service(int $serviceId, int $clientId = 0)
    {
        if ($serviceId <= 0) {
            return null;
        }
        $params = array('serviceid' => $serviceId);
        if ($clientId > 0) {
            $params['clientid'] = $clientId;
        }
        $result = $this->api('GetClientsProducts', $params);
        $product = null;
        if ($result !== null && isset($result['products']['product']) && is_array($result['products']['product'])) {
            foreach ($result['products']['product'] as $row) {
                if (is_array($row) && (int) self::pick($row, array('id'), 0) === $serviceId) {
                    $product = $row;
                    break;
                }
            }
        }
        if ($product === null) {
            return null;
        }

        return array(
            'id' => $serviceId,
            'clientId' => (int) self::pick($product, array('clientid'), $clientId),
            'name' => self::pick($product, array('name', 'translated_name'), ''),
            'domain' => self::pick($product, array('domain'), ''),
            'status' => self::pick($product, array('status'), ''),
            'suspendReason' => self::pick($product, array('suspensionreason'), ''),
        );
    }

    /**
     * An order, from GetOrders.
     *
     * @return array<string, mixed>|null
     */
    public function order(int $orderId)
    {
        if ($orderId <= 0) {
            return null;
        }
        $result = $this->api('GetOrders', array('id' => $orderId));
        if ($result === null || !isset($result['orders']['order']) || !is_array($result['orders']['order'])) {
            return null;
        }
        foreach ($result['orders']['order'] as $row) {
            if (is_array($row) && (int) self::pick($row, array('id'), 0) === $orderId) {
                return array(
                    'id' => $orderId,
                    'number' => self::pick($row, array('ordernum'), (string) $orderId),
                    'clientId' => (int) self::pick($row, array('userid'), 0),
                    'clientName' => self::pick($row, array('name'), ''),
                    'amount' => (float) self::pick($row, array('amount'), 0),
                    'currencyPrefix' => self::pick($row, array('currencyprefix'), ''),
                    'currencySuffix' => self::pick($row, array('currencysuffix'), ''),
                    'paymentMethod' => self::pick($row, array('paymentmethodname', 'paymentmethod'), ''),
                );
            }
        }

        return null;
    }

    /**
     * An amount in the client's currency, the way WHMCS writes it ("$25.00 USD").
     */
    public function money(float $amount, int $clientId, string $prefix = '', string $suffix = ''): string
    {
        if ($clientId > 0 && function_exists('getCurrency') && function_exists('formatCurrency')) {
            try {
                $formatted = Guard::run(function () use ($amount, $clientId) {
                    $currency = getCurrency($clientId);
                    $id = is_array($currency) && isset($currency['id']) ? (int) $currency['id'] : 0;

                    return (string) formatCurrency($amount, $id > 0 ? $id : null);
                });
                $formatted = Template::value($formatted, 0);
                if ($formatted !== '') {
                    return $formatted;
                }
            } catch (\Throwable $e) {
                // The plain format below.
            }
        }

        return Template::value(trim($prefix . number_format($amount, 2, '.', ',') . ' ' . $suffix), 0);
    }

    /**
     * A date (YYYY-MM-DD) in the client area's date format.
     */
    public function date(string $ymd): string
    {
        $ymd = trim($ymd);
        if ($ymd === '' || strpos($ymd, '0000-00-00') === 0) {
            return '';
        }
        if (function_exists('fromMySQLDate')) {
            try {
                $date = Guard::run(function () use ($ymd) {
                    return (string) fromMySQLDate($ymd, false, true);
                });
                if (trim($date) !== '') {
                    return Template::value($date, 0);
                }
            } catch (\Throwable $e) {
                // The date as stored, below.
            }
        }

        return substr($ymd, 0, 10);
    }

    /** A WHMCS setting (General Settings), '' when missing. */
    public function config(string $name): string
    {
        if (array_key_exists($name, $this->config)) {
            return $this->config[$name];
        }
        $value = '';
        try {
            if (class_exists('WHMCS\\Config\\Setting')) {
                $value = (string) \WHMCS\Config\Setting::getValue($name);
            } else {
                $row = Capsule::table('tblconfiguration')->where('setting', $name)->first();
                $value = $row !== null && isset($row->value) ? (string) $row->value : '';
            }
        } catch (\Throwable $e) {
            $value = '';
        }

        return $this->config[$name] = $value;
    }

    public function companyName(): string
    {
        return Template::value($this->config('CompanyName'), 0);
    }

    /** The System URL, without a trailing slash. */
    public function systemUrl(): string
    {
        return rtrim(trim($this->config('SystemURL')), '/');
    }

    /** The default country of new clients (ISO code), from General Settings. */
    public function defaultCountry(): string
    {
        return strtoupper(trim($this->config('DefaultCountry')));
    }

    public function whmcsVersion(): string
    {
        return trim($this->config('Version'));
    }

    /**
     * Writes to WHMCS's Activity Log, without numbers in full and without keys.
     */
    public function logActivity(string $message, int $clientId = 0)
    {
        if (!function_exists('logActivity')) {
            return;
        }
        $line = 'SMSTunnel: ' . Redact::text($message);
        Guard::quietly(function () use ($line, $clientId) {
            logActivity($line, $clientId);
        });
    }

    /** WHMCS's encrypt() (the key in configuration.php). */
    public function encrypt(string $value): string
    {
        if (!function_exists('encrypt')) {
            throw new \RuntimeException('WHMCS encrypt() is not available.');
        }

        return (string) encrypt($value);
    }

    /** WHMCS's decrypt(); '' when the value cannot be decrypted. */
    public function decrypt(string $value): string
    {
        if ($value === '' || !function_exists('decrypt')) {
            return '';
        }
        try {
            return (string) Guard::run(function () use ($value) {
                return decrypt($value);
            });
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The admin area's CSRF token (generate_token('plain')), for the module's forms. */
    public function csrfToken(): string
    {
        if (function_exists('generate_token')) {
            try {
                $token = (string) generate_token('plain');
                if ($token !== '') {
                    return $token;
                }
            } catch (\Throwable $e) {
                // The session token below.
            }
        }
        if (!isset($_SESSION['smstunnel_csrf']) || !is_string($_SESSION['smstunnel_csrf'])) {
            $_SESSION['smstunnel_csrf'] = bin2hex(random_bytes(20));
        }

        return $_SESSION['smstunnel_csrf'];
    }

    /** True when $token is this session's CSRF token (compared in constant time). */
    public function csrfValid($token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($this->csrfToken(), $token);
    }

    /** The ID of the admin who is signed in, 0 for none. */
    public function adminId(): int
    {
        return isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0;
    }

    /**
     * The first non-empty scalar of $keys in $row, as a trimmed string (or $default).
     *
     * @param array<string, mixed> $row
     * @param string[]             $keys
     * @param mixed                $default
     *
     * @return mixed
     */
    public static function pick(array $row, array $keys, $default)
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return $default;
    }
}
