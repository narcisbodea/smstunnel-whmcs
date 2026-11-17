<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Text;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * How many SMS parts a text takes. The same rules as assets/counter.js, which the text editor uses.
 *
 * - Only characters of the GSM 7-bit alphabet: 160 characters in one SMS, 153 per part when longer.
 *   ^ { } \ [ ] ~ | € and the form feed take two places.
 * - Any other character (ă, ș, ç, emoji...): the whole text is sent as Unicode, 70 in one SMS, 67 per
 *   part. Characters outside the Basic Multilingual Plane, such as most emoji, take two places.
 * A two-place character is never split across two parts.
 */
final class SmsCounter
{
    const GSM_SINGLE = 160;
    const GSM_PART = 153;
    const UNICODE_SINGLE = 70;
    const UNICODE_PART = 67;

    /** @var array<string, int>|null character → places in GSM 7-bit */
    private static $gsm;

    /**
     * @return array{encoding: string, characters: int, units: int, parts: int, perPart: int, nonGsm: string[]}
     *         encoding "GSM-7" or "Unicode"; units is the places the text takes (septets or UTF-16
     *         code units); nonGsm lists the characters that made it Unicode (each once)
     */
    public static function count(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            // Not valid UTF-8: count bytes as Unicode places, the worst case.
            $chars = str_split($text);
        }
        $map = self::gsmMap();
        $nonGsm = array();
        foreach ($chars as $char) {
            if (!isset($map[$char])) {
                $nonGsm[$char] = true;
            }
        }
        $gsm = !$nonGsm;
        $weights = array();
        foreach ($chars as $char) {
            $weights[] = $gsm ? $map[$char] : (strlen($char) === 4 ? 2 : 1);
        }
        $units = array_sum($weights);
        $single = $gsm ? self::GSM_SINGLE : self::UNICODE_SINGLE;
        $part = $gsm ? self::GSM_PART : self::UNICODE_PART;

        if ($units === 0) {
            $parts = 0;
        } elseif ($units <= $single) {
            $parts = 1;
        } else {
            $parts = 1;
            $used = 0;
            foreach ($weights as $weight) {
                if ($used + $weight > $part) {
                    ++$parts;
                    $used = $weight;
                } else {
                    $used += $weight;
                }
            }
        }

        return array(
            'encoding' => $gsm ? 'GSM-7' : 'Unicode',
            'characters' => count($chars),
            'units' => $units,
            'parts' => $parts,
            'perPart' => $parts > 1 ? $part : $single,
            'nonGsm' => array_keys($nonGsm),
        );
    }

    /** True when every character of the text is in the GSM 7-bit alphabet. */
    public static function isGsm(string $text): bool
    {
        $count = self::count($text);

        return $count['encoding'] === 'GSM-7';
    }

    /**
     * @return array<string, int>
     */
    private static function gsmMap(): array
    {
        if (self::$gsm === null) {
            $basic = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
                . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
            $extended = "\f" . '^{}\\[~]|€';
            self::$gsm = array();
            foreach ((array) preg_split('//u', $basic, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                self::$gsm[$char] = 1;
            }
            foreach ((array) preg_split('//u', $extended, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                self::$gsm[$char] = 2;
            }
        }

        return self::$gsm;
    }
}
