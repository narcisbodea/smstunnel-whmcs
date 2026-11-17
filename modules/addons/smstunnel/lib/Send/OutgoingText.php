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
        $body = array('to' => $this->to, 'message' => $this->message);
        if (trim($deviceId) !== '') {
            $body['deviceId'] = trim($deviceId);
        }
        if (preg_match('/^[1-9]\d{0,3}$/', $this->countryCode)) {
            $body['defaultCountryCode'] = '+' . $this->countryCode;
        }

        return $body;
    }
}
