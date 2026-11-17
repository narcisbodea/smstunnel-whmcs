<?php
/**
 * Phone numbers: the shared rules (PhoneNumber, CallingCodes) and a WHMCS client's number (ClientNumber).
 *
 * @license MIT
 */

use SmsTunnel\Whmcs\Phone\CallingCodes;
use SmsTunnel\Whmcs\Phone\ClientNumber;
use SmsTunnel\Whmcs\Phone\PhoneNumber;

group('phone numbers');

test('numbers are turned into E.164, bad and premium numbers refused', function () {
    // [typed, calling code, E.164, problem]
    $cases = array(
        array('+40 721 123 456', '40', '+40721123456', ''),
        array('0721 123 456', '40', '+40721123456', ''),
        array('721123456', '40', '+40721123456', ''),
        array('0040 721 123 456', '1', '+40721123456', ''),
        array('(415) 555-0123', '1', '+14155550123', ''),
        array('415.555.0123', '1', '+14155550123', ''),
        array('14155550123', '1', '+14155550123', ''),
        array('+1 415 555 0123', '', '+14155550123', ''),
        array('06 12 34 56 78', '33', '+33612345678', ''),
        array('06 1234 5678', '39', '+390612345678', ''),
        array('8 916 123-45-67', '7', '+79161234567', ''),
        array('07700 900123', '44', '+447700900123', ''),
        array('+49 (0) 170 1234567', '1', '+491701234567', ''),
        array('&#43;40 721 123 456', '1', '+40721123456', ''),
        array('', '40', '', 'missing'),
        array(null, '40', '', 'missing'),
        array('abc', '40', '', 'invalid'),
        array('+40 7', '', '', 'invalid'),
        array('+1234567890123456', '', '', 'invalid'),
        array('+1 900 555 0123', '', '', 'premium'),
        array('0900 123 456', '40', '', 'premium'),
        array('+44 909 879 0000', '', '', 'premium'),
        array('+881 6 12345678', '', '', 'premium'),
        array('+3361234', '', '', 'premium'),
    );
    foreach ($cases as $case) {
        eq(PhoneNumber::analyse($case[0], $case[1]), array($case[2], $case[3]), var_export($case[0], true) . ' / ' . $case[1]);
    }
    eq(CallingCodes::forCountry('ro'), '40');
    eq(CallingCodes::forCountry('GB'), '44');
    eq(CallingCodes::forCountry('ZZ'), '');
});

test("a client's number: typed international, WHMCS's formatted number, or built from the country", function () {
    $client = function (array $fields) {
        return $fields + array('phoneRaw' => '', 'phoneCc' => '', 'phoneFormatted' => '', 'countryCode' => 'US');
    };
    // [client, E.164, problem, calling code]
    $cases = array(
        'typed with +' => array($client(array('phoneRaw' => '+44 7700 900123', 'phoneCc' => '1', 'phoneFormatted' => '+1.447700900123')), '+447700900123', '', '1'),
        'typed with 00' => array($client(array('phoneRaw' => '0040 721 123 456', 'countryCode' => 'US')), '+40721123456', '', '1'),
        'formatted by WHMCS' => array($client(array('phoneRaw' => '07700 900123', 'phoneCc' => '44', 'phoneFormatted' => '+44.7700900123', 'countryCode' => 'GB')), '+447700900123', '', '44'),
        'US national' => array($client(array('phoneRaw' => '(415) 555-0123', 'phoneCc' => '1', 'phoneFormatted' => '+1.4155550123')), '+14155550123', '', '1'),
        'no formatted number: from the country' => array($client(array('phoneRaw' => '0721 123 456', 'countryCode' => 'RO')), '+40721123456', '', '40'),
        'formatted only' => array($client(array('phoneFormatted' => '+44.7700900123', 'countryCode' => 'GB')), '+447700900123', '', '44'),
        'Italy keeps its leading 0' => array($client(array('phoneRaw' => '06 1234 5678', 'phoneCc' => '39', 'phoneFormatted' => '+39.612345678', 'countryCode' => 'IT')), '+390612345678', '', '39'),
        'HTML-escaped' => array($client(array('phoneRaw' => '&#43;40 721 123 456')), '+40721123456', '', '1'),
        'no number' => array($client(array()), '', 'missing', '1'),
        'premium' => array($client(array('phoneRaw' => '+1 900 555 0123')), '', 'premium', '1'),
        'short code' => array($client(array('phoneRaw' => '+3361234', 'countryCode' => 'FR')), '', 'premium', '33'),
        'satellite' => array($client(array('phoneRaw' => '+881 6 12345678')), '', 'premium', '1'),
        'too short' => array($client(array('phoneRaw' => '12345')), '', 'invalid', '1'),
    );
    foreach ($cases as $name => $case) {
        $result = ClientNumber::analyse($case[0]);
        eq(array($result[0], $result[1], $result[3]), array($case[1], $case[2], $case[3]), $name);
    }
    eq(ClientNumber::callingCode(array('phoneCc' => '', 'countryCode' => 'DE')), '49', 'from the country');
    eq(ClientNumber::callingCode(array('phoneCc' => '+353', 'countryCode' => 'GB')), '353', 'phonecc wins');
    eq(ClientNumber::callingCode(array('phoneCc' => '', 'countryCode' => '')), '', 'unknown');
});
