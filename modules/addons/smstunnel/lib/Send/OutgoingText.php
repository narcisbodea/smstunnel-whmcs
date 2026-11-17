<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Send;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * One text to one number, and what the log should say about it.
 */
final class OutgoingText
{
    const SEND = 'send';
    const SIGN_IN_CODE = 'send-2fa';

    /** @var string an event of Text\Events, or test / sign_in_code */
    public $event;

    /** @var string client, staff, test or user */
    public $audience;

    /** @var int */
    public $clientId = 0;

    /** @var string invoice, service, ticket, domain, order or '' */
    public $relatedType = '';

    /** @var int */
    public $relatedId = 0;

    /** @var string E.164 */
    public $to = '';

    /** @var string */
    public $message = '';

    /** @var string the calling code of the recipient's country ("40"), sent as defaultCountryCode */
    public $countryCode = '';

    /** @var string|null unique per event, so a hook WHMCS runs twice sends one text */
    public $dedupeKey;

    /** @var string send or send-2fa */
    public $endpoint = self::SEND;

    /** @var string what the log shows as the number ('' for the number itself) */
    public $loggedRecipient = '';

    public function __construct(string $event, string $audience)
    {
        $this->event = $event;
        $this->audience = $audience;
    }

    /**
     * The JSON body of POST /api/v1/sms/send (or send-2fa): one number, one text.
     *
     * @return array<string, string>
     */
    public function body(string $deviceId): array
    {
        // Valid UTF-8, so the request stored for a retry and the one sent are the same text.
        $body = array('to' => self::utf8($this->to), 'message' => self::utf8($this->message));
        if (trim($deviceId) !== '') {
            $body['deviceId'] = trim($deviceId);
        }
        if (preg_match('/^[1-9]\d{0,3}$/', $this->countryCode)) {
            $body['defaultCountryCode'] = '+' . $this->countryCode;
        }

        return $body;
    }

    /** The text with any byte that is not valid UTF-8 replaced (old databases may hold Latin-1). */
    public static function utf8(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text);
    }
}
