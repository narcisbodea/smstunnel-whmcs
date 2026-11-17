<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Store;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * The module's own tables, created with WHMCS's database layer (Capsule):
 * - mod_smstunnel_settings: the settings (the API key encrypted with WHMCS's encrypt());
 * - mod_smstunnel_log: every text, its state, and while it may be retried, its request;
 * - mod_smstunnel_codes: sign-in codes waiting to be typed, as keyed hashes only.
 *
 * Text that may hold characters outside MySQL's 3-byte utf8 (emoji) is stored JSON-escaped, so the
 * tables work whatever character set the WHMCS database uses.
 */
final class Schema
{
    const SETTINGS = 'mod_smstunnel_settings';
    const LOG = 'mod_smstunnel_log';
    const CODES = 'mod_smstunnel_codes';

    /** Bumped when a table changes; install() brings older tables up to it. */
    const VERSION = 1;

    /**
     * Creates the tables that are missing (activation, upgrade). Existing tables and rows stay.
     */
    public static function install()
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::SETTINGS)) {
            $schema->create(self::SETTINGS, function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->string('setting', 100);
                $table->mediumText('value')->nullable();
                $table->primary('setting');
            });
        }
        if (!$schema->hasTable(self::LOG)) {
            $schema->create(self::LOG, function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->string('event', 40);
                $table->string('audience', 10);
                $table->unsignedInteger('client_id')->default(0);
                $table->string('related_type', 20)->default('');
                $table->unsignedInteger('related_id')->default(0);
                $table->string('recipient', 40)->default('');
                $table->string('status', 16);
                $table->string('message_id', 64)->nullable();
                $table->string('error', 255)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->dateTime('first_attempt_at')->nullable();
                $table->dateTime('next_attempt_at')->nullable();
                $table->string('endpoint', 16)->default('send');
                $table->string('idempotency_key', 64)->nullable();
                // Which API key and server the key belongs to (SMSTunnel scopes Idempotency-Keys per API key),
                // and whether an attempt may have reached SMSTunnel (then only that key and server may retry).
                $table->string('scope', 16)->default('');
                $table->unsignedTinyInteger('reached')->default(0);
                $table->mediumText('request')->nullable();
                $table->string('dedupe_key', 120)->nullable();
                $table->unique('dedupe_key');
                $table->index(array('status', 'next_attempt_at'));
                $table->index('created_at');
                $table->index('client_id');
            });
        }
        self::installCodes();
    }

    /** The sign-in code table (the sign-in code module also creates it on first use). */
    public static function installCodes()
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::CODES)) {
            $schema->create(self::CODES, function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->string('user_key', 64);
                $table->string('session_key', 64);
                $table->string('purpose', 10);
                $table->string('phone_hash', 64);
                $table->string('code_hash', 64);
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->dateTime('expires_at');
                $table->dateTime('created_at');
                $table->dateTime('used_at')->nullable();
                $table->index(array('user_key', 'created_at'));
            });
        }
    }

    public static function installed(): bool
    {
        try {
            return Capsule::schema()->hasTable(self::SETTINGS) && Capsule::schema()->hasTable(self::LOG);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Drops the module's tables ("Delete all data on deactivate"). */
    public static function drop()
    {
        $schema = Capsule::schema();
        foreach (array(self::CODES, self::LOG, self::SETTINGS) as $table) {
            $schema->dropIfExists($table);
        }
    }

    /**
     * A string safe for a utf8 (3-byte) MySQL column: valid UTF-8, characters outside the Basic
     * Multilingual Plane replaced, at most $length characters.
     */
    public static function safeText(string $text, int $length): string
    {
        if (function_exists('mb_convert_encoding')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $text);

        return function_exists('mb_substr') ? (string) mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }
}
