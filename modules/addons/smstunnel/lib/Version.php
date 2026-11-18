<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

final class Version
{
    /** The module's version (also in CHANGELOG.md, whmcs.json and the release zip's name). */
    const NUMBER = '1.0.1';

    /** The addon's system name: the folder modules/addons/smstunnel and the prefix of its functions. */
    const MODULE = 'smstunnel';

    const NAME = 'SMSTunnel for WHMCS';

    const SITE_URL = 'https://smstunnel.io';

    const DASHBOARD_URL = 'https://dashboard.smstunnel.io/apps';

    const GOOGLE_PLAY_URL = 'https://play.google.com/store/apps/details?id=io.smstunnel.app';

    /**
     * The User-Agent of every call to SMSTunnel: the module, WHMCS and PHP versions, nothing else.
     *
     * @param string $whmcsVersion e.g. "8.13.1-release.1", or '' when unknown
     */
    public static function userAgent(string $whmcsVersion = ''): string
    {
        $whmcs = preg_match('/^[0-9][0-9A-Za-z.\-]{0,40}$/', trim($whmcsVersion)) ? 'WHMCS ' . trim($whmcsVersion) : 'WHMCS';

        return 'SMSTunnel-WHMCS/' . self::NUMBER . ' (' . $whmcs . '; PHP ' . PHP_VERSION . ')';
    }
}
