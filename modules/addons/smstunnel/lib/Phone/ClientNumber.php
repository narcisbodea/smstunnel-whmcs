<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Phone;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * A WHMCS client's phone number in international format (E.164).
 *
 * GetClientsDetails gives the number as the client typed it (phonenumber), the calling code of the
 * client's country (phonecc) and WHMCS's own international form (phonenumberformatted, "+44.1234567890").
 * In order:
 * 1. a number typed with "+" or "00" is international already and is taken as typed;
 * 2. otherwise WHMCS's formatted number (calling code + national number);
 * 3. otherwise the typed number completed with the calling code of the client's country.
 * Countries whose numbers keep their leading 0 after the calling code (Italy...) skip step 2 when the
 * typed number starts with 0, because WHMCS drops that 0.
 */
final class ClientNumber
{
    /**
     * @param array<string, mixed> $client the client as Platform::client() gives it
     *
     * @return array{0: string, 1: string, 2: string, 3: string} the E.164 number (or ''), the problem
     *                                                           (PhoneNumber::OK, MISSING, INVALID or
     *                                                           PREMIUM), the number as typed, and the
     *                                                           calling code of the client's country
     */
    public static function analyse(array $client): array
    {
        $raw = self::text(isset($client['phoneRaw']) ? $client['phoneRaw'] : '');
        $formatted = self::text(isset($client['phoneFormatted']) ? $client['phoneFormatted'] : '');
        $callingCode = self::callingCode($client);

        if ($raw === '' && $formatted === '') {
            return array('', PhoneNumber::MISSING, '', $callingCode);
        }
        if ($raw !== '' && preg_match('/^[^0-9]*(\+|00)/', $raw)) {
            list($number, $problem) = PhoneNumber::analyse($raw, '');

            return array($number, $problem, $raw, $callingCode);
        }
        $keepsZero = in_array($callingCode, PhoneNumber::KEEP_LEADING_ZERO, true) && preg_match('/^\D*0/', $raw);
        if (!$keepsZero && preg_match('/^\+?(\d{1,4})\.(\d{4,14})$/', (string) preg_replace('/\s+/', '', $formatted), $parts)) {
            list($number, $problem) = PhoneNumber::analyse('+' . $parts[1] . $parts[2], '');

            return array($number, $problem, $raw !== '' ? $raw : $formatted, $callingCode);
        }
        list($number, $problem) = PhoneNumber::analyse($raw !== '' ? $raw : $formatted, $callingCode);

        return array($number, $problem, $raw !== '' ? $raw : $formatted, $callingCode);
    }

    /**
     * The calling code of the client's country: phonecc when WHMCS gives one, else from the country.
     *
     * @param array<string, mixed> $client
     */
    public static function callingCode(array $client): string
    {
        $cc = isset($client['phoneCc']) && is_scalar($client['phoneCc']) ? (string) preg_replace('/\D+/', '', (string) $client['phoneCc']) : '';
        if (preg_match('/^[1-9]\d{0,3}$/', $cc)) {
            return $cc;
        }

        return CallingCodes::forCountry(isset($client['countryCode']) ? (string) $client['countryCode'] : '');
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
    }
}
