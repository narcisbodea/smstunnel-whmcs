<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs;

use SmsTunnel\Whmcs\Send\Outbox;
use SmsTunnel\Whmcs\Store\Consent;
use SmsTunnel\Whmcs\Store\Settings;
use SmsTunnel\Whmcs\Store\TextLog;
use SmsTunnel\Whmcs\TwoFactor\SignInCodes;
use SmsTunnel\Whmcs\Whmcs\Platform;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * The module's objects, made once per PHP request.
 */
final class Module
{
    /** @var array<string, object> */
    private static $made = array();

    /**
     * Options for every Api\Client (tests set a transport and shorter timeouts here).
     *
     * @var array<string, mixed>
     */
    public static $clientOptions = array();

    /** @var callable|null fn(): int, the clock of tests */
    public static $clock;

    public static function platform(): Platform
    {
        return self::make('platform', function () {
            return new Platform(Module::$clock);
        });
    }

    public static function settings(): Settings
    {
        return self::make('settings', function () {
            return new Settings(Module::platform());
        });
    }

    public static function log(): TextLog
    {
        return self::make('log', function () {
            return new TextLog(Module::platform());
        });
    }

    public static function consent(): Consent
    {
        return self::make('consent', function () {
            return new Consent(Module::settings(), Module::platform());
        });
    }

    public static function outbox(): Outbox
    {
        return self::make('outbox', function () {
            return new Outbox(Module::settings(), Module::log(), Module::platform(), Module::$clientOptions);
        });
    }

    public static function notifier(): Notifier
    {
        return self::make('notifier', function () {
            return new Notifier(Module::settings(), Module::platform(), Module::outbox(), Module::consent());
        });
    }

    public static function signInCodes(): SignInCodes
    {
        return self::make('codes', function () {
            return new SignInCodes(Module::settings(), Module::platform(), Module::outbox());
        });
    }

    /** Forgets everything made in this request (tests, and after the settings change). */
    public static function reset()
    {
        self::$made = array();
        Outbox::reset();
    }

    /**
     * @return mixed
     */
    private static function make(string $name, callable $factory)
    {
        if (!isset(self::$made[$name])) {
            self::$made[$name] = $factory();
        }

        return self::$made[$name];
    }
}
