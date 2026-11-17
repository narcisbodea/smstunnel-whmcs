<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Api;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Calls the SMSTunnel public API the way the SMSTunnel SDKs do (same endpoints, headers, JSON and
 * error reading), with PHP's cURL extension only. One text to one number per call.
 *
 * Timeouts are short on purpose (5 seconds to connect, 10 in all): texts are sent while WHMCS answers
 * a page, a payment callback or its cron, and none of them may wait long for SMSTunnel.
 */
class Client
{
    const DEFAULT_URL = 'https://smstunnel.io';

    const CONNECT_TIMEOUT = 5.0;

    const TIMEOUT = 10.0;

    /** The fields POST /api/v1/sms/send and /sms/send-2fa take from this module (the API refuses unknown ones). */
    const SEND_FIELDS = array('to', 'message', 'deviceId', 'defaultCountryCode');

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var float */
    private $timeout;

    /** @var float */
    private $connectTimeout;

    /** @var string */
    private $userAgent;

    /** @var callable */
    private $transport;

    /**
     * @param string               $apiKey  a key from Apps → Add an app in the SMSTunnel dashboard
     * @param string               $baseUrl https://smstunnel.io; another address only for a test server
     * @param array<string, mixed> $options timeout, connectTimeout (seconds), userAgent, transport
     *                                      (a callable, for tests: see curlTransport() for its contract)
     */
    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_URL, array $options = array())
    {
        // A key never holds spaces or line breaks; dropping them keeps the header intact whatever was saved.
        $this->apiKey = (string) preg_replace('/\s+/', '', $apiKey);
        $baseUrl = rtrim(trim($baseUrl), '/');
        $this->baseUrl = $baseUrl !== '' ? $baseUrl : self::DEFAULT_URL;
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : self::TIMEOUT;
        $connect = isset($options['connectTimeout']) ? (float) $options['connectTimeout'] : self::CONNECT_TIMEOUT;
        $this->timeout = max(0.5, $timeout);
        $this->connectTimeout = max(0.5, min($connect, $this->timeout));
        $this->userAgent = isset($options['userAgent']) && is_string($options['userAgent']) ? $options['userAgent'] : 'SMSTunnel-WHMCS';
        $this->transport = isset($options['transport']) && is_callable($options['transport'])
            ? $options['transport']
            : array(__CLASS__, 'curlTransport');
    }

    /** True for an address the client can call: http(s)://host[:port][/path]. */
    public static function isValidBaseUrl(string $url): bool
    {
        return preg_match('~^https?://[^/\s?#@]+(/[^\s?#]*)?$~i', rtrim(trim($url), '/')) === 1;
    }

    /** True for a value the server takes as an Idempotency-Key: 1 to 255 printable ASCII characters. */
    public static function isValidIdempotencyKey(string $key): bool
    {
        return preg_match('/^[\x21-\x7E][\x20-\x7E]{0,254}$/', $key) === 1 && trim($key) === $key;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Sends one text to one number (POST /api/v1/sms/send).
     *
     * @param array<string, string> $body           to (E.164), message (up to 1600 characters), and
     *                                              optionally deviceId and defaultCountryCode ("+40")
     * @param string                $idempotencyKey the same key for every attempt of the same text: a
     *                                              retry within 24 hours gets the first answer back
     *                                              instead of a second text
     *
     * @throws ApiException       when SMSTunnel refuses the text
     * @throws TransportException when SMSTunnel cannot be reached
     */
    public function sendSms(array $body, string $idempotencyKey = ''): SendResult
    {
        return $this->send('/api/v1/sms/send', $body, $idempotencyKey);
    }

    /**
     * Sends a sign-in code (POST /api/v1/sms/send-2fa): the text must hold a code of 4 to 10 digits
     * standing on its own, at most 320 characters. It counts against the plan's sign-in code allowance.
     *
     * @param array<string, string> $body
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function sendSignInCode(array $body, string $idempotencyKey = ''): SendResult
    {
        return $this->send('/api/v1/sms/send-2fa', $body, $idempotencyKey);
    }

    /**
     * The phones linked to the key's account, and which are online (GET /api/v1/devices).
     *
     * @return array<int, array{id: string, name: string, isOnline: bool, lastSeenAt: string}>
     *
     * @throws ApiException
     * @throws TransportException
     */
    public function getDevices(): array
    {
        $answer = $this->request('GET', '/api/v1/devices', null);
        $decoded = $answer['decoded'];
        $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();
        $devices = isset($data['devices']) && is_array($data['devices']) ? $data['devices'] : array();

        $phones = array();
        foreach ($devices as $device) {
            if (!is_array($device) || !isset($device['id']) || !(is_string($device['id']) || is_int($device['id']))) {
                continue;
            }
            $phones[] = array(
                'id' => (string) $device['id'],
                'name' => isset($device['name']) && is_string($device['name']) ? $device['name'] : '',
                'isOnline' => isset($device['isOnline']) && $device['isOnline'] === true,
                'lastSeenAt' => isset($device['lastSeenAt']) && is_string($device['lastSeenAt']) ? $device['lastSeenAt'] : '',
            );
        }

        return $phones;
    }

    /**
     * @param array<string, string> $body
     */
    private function send(string $path, array $body, string $idempotencyKey): SendResult
    {
        $clean = array();
        foreach (self::SEND_FIELDS as $field) {
            if (isset($body[$field]) && is_string($body[$field]) && $body[$field] !== '') {
                $clean[$field] = $body[$field];
            }
        }
        if (!isset($clean['to'], $clean['message'])) {
            throw new \InvalidArgumentException('A text needs a number and words.');
        }
        $headers = array();
        if ($idempotencyKey !== '') {
            if (!self::isValidIdempotencyKey($idempotencyKey)) {
                throw new \InvalidArgumentException('The Idempotency-Key must be 1 to 255 printable ASCII characters.');
            }
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $answer = $this->request('POST', $path, $clean, $headers);
        $decoded = $answer['decoded'];
        $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();

        $messageId = '';
        foreach (array($decoded, $data) as $source) {
            if ($messageId === '' && isset($source['messageId']) && (is_string($source['messageId']) || is_int($source['messageId']))) {
                $messageId = (string) $source['messageId'];
            }
        }
        $queued = (isset($decoded['queued']) && $decoded['queued'] === true) || (isset($data['queued']) && $data['queued'] === true);
        $replayed = isset($answer['headers']['idempotent-replayed']) && strtolower(trim($answer['headers']['idempotent-replayed'])) === 'true';

        return new SendResult($messageId, $queued, isset($data['branded']) && $data['branded'] === true, $replayed);
    }

    /**
     * @param array<string, string>|null $body
     * @param string[]                   $extraHeaders
     *
     * @return array{status: int, headers: array<string, string>, decoded: array<string, mixed>}
     *
     * @throws ApiException
     * @throws TransportException
     */
    private function request(string $method, string $path, $body, array $extraHeaders = array()): array
    {
        if ($this->apiKey === '') {
            throw new ApiException(401, null, array(), 'No API key is saved.');
        }
        if (!self::isValidBaseUrl($this->baseUrl)) {
            throw new TransportException(sprintf('The SMSTunnel address must look like https://smstunnel.io (got "%s").', $this->baseUrl));
        }

        $headers = array_merge(array(
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        ), $extraHeaders);
        $payload = null;
        if ($body !== null) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $payload = json_encode($body, $flags);
            if (!is_string($payload)) {
                throw new ApiException(400, null, array(), 'The text could not be encoded.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $response = call_user_func($this->transport, $method, $this->baseUrl . $path, $headers, $payload, $this->timeout, $this->connectTimeout);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $responseHeaders = isset($response['headers']) && is_array($response['headers']) ? array_change_key_case($response['headers'], CASE_LOWER) : array();
        $raw = isset($response['body']) ? (string) $response['body'] : '';

        $decoded = self::decode($raw);
        if ($status >= 300 && $status < 400) {
            $location = isset($responseHeaders['location']) ? $responseHeaders['location'] : '';
            throw new ApiException($status, $decoded, $responseHeaders, $location !== ''
                ? sprintf('SMSTunnel answered with a redirect to %s. Set the server address to that address.', $location)
                : sprintf('SMSTunnel answered with a redirect (%d).', $status));
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException($status, $decoded, $responseHeaders);
        }
        if (!is_array($decoded)) {
            throw new ApiException($status, $decoded, $responseHeaders, 'SMSTunnel answered with something that is not JSON.');
        }
        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            throw new ApiException($status, $decoded, $responseHeaders);
        }

        return array('status' => $status, 'headers' => $responseHeaders, 'decoded' => $decoded);
    }

    /**
     * The default transport: one HTTP request with cURL.
     *
     * @param string[]    $headers
     * @param string|null $payload
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws TransportException
     */
    public static function curlTransport(string $method, string $url, array $headers, $payload, float $timeout, float $connectTimeout): array
    {
        if (!function_exists('curl_init')) {
            throw new TransportException('The PHP cURL extension is missing.');
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('cURL could not start.');
        }
        $responseHeaders = array();
        $headers[] = 'Expect:';
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            // Redirects are not followed, so the API key never goes to another address.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($connectTimeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $trimmed = trim($line);
                if (strpos($trimmed, 'HTTP/') === 0) {
                    // A new status line (after "100 Continue", for instance): start over.
                    $responseHeaders = array();
                } elseif (strpos($trimmed, ':') !== false) {
                    list($name, $value) = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        );
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        // Whether the request left: a body uploaded, or a connection made before the transfer began.
        $requestSent = (float) curl_getinfo($handle, CURLINFO_SIZE_UPLOAD) > 0 || (float) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME) > 0;
        if (PHP_VERSION_ID < 80000) {
            // A no-op since PHP 8.0, and deprecated in PHP 8.5.
            curl_close($handle);
        }

        if (!is_string($raw) || $errno !== 0) {
            // 28 timeout, 52 empty answer, 55 send error, 56 receive error: after the request left,
            // SMSTunnel may have taken the text even though no answer came back.
            $mayHaveArrived = $method === 'POST' && $requestSent && in_array($errno, array(28, 52, 55, 56), true);
            throw new TransportException(sprintf(
                $mayHaveArrived ? 'No answer from SMSTunnel at %s: %s' : 'Could not reach SMSTunnel at %s: %s',
                self::origin($url),
                $error !== '' ? $error : 'cURL error ' . $errno
            ), $errno, $mayHaveArrived);
        }

        return array('status' => $status, 'headers' => $responseHeaders, 'body' => $raw);
    }

    /**
     * The JSON body as an array, the raw text when it is not JSON, or null when it is empty.
     *
     * @return mixed
     */
    private static function decode(string $raw)
    {
        if (trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $url;
        }

        return (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
