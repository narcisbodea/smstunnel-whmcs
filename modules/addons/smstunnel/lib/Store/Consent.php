<?php
/**
 * SMSTunnel for WHMCS.
 *
 * @author    NARBOWEB SRL (https://smstunnel.io)
 * @copyright 2026 NARBOWEB SRL
 * @license   MIT
 */

namespace SmsTunnel\Whmcs\Store;

use SmsTunnel\Whmcs\Whmcs\Platform;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * The client's choice, kept in a client custom field (a tickbox) that clients see in their profile and
 * at sign-up.
 *
 * - Normally every client gets the texts that are turned on, unless they tick "No text messages".
 * - With "Text only clients who opted in", clients see "Send me text messages" instead, and only those
 *   who ticked it get texts.
 *
 * The two choices are two separate fields, so a "No" never turns into a "Yes" when the setting
 * changes: the field of the other mode is hidden from clients (Admin Only) but keeps its values.
 */
class Consent
{
    const OPT_OUT_NAME = 'No text messages';
    const OPT_OUT_HELP = 'Tick to stop text messages about your account (invoices, services and support tickets).';

    const OPT_IN_NAME = 'Send me text messages';
    const OPT_IN_HELP = 'Tick to get text messages about your account (invoices, services and support tickets).';

    const OPTED_OUT = 'opted out (No text messages)';
    const NOT_OPTED_IN = 'not opted in (Send me text messages)';

    /** @var Settings */
    private $settings;

    /** @var Platform */
    private $platform;

    public function __construct(Settings $settings, Platform $platform)
    {
        $this->settings = $settings;
        $this->platform = $platform;
    }

    /**
     * Creates the fields that are missing and shows clients the one of the current mode.
     */
    public function sync()
    {
        $optIn = $this->settings->optIn();
        $this->ensureField(Settings::FIELD_OPT_OUT, self::OPT_OUT_NAME, self::OPT_OUT_HELP, !$optIn);
        if ($optIn || $this->fieldId(Settings::FIELD_OPT_IN) > 0) {
            $this->ensureField(Settings::FIELD_OPT_IN, self::OPT_IN_NAME, self::OPT_IN_HELP, $optIn);
        }
    }

    /**
     * May this client get texts? The reason is set when not.
     *
     * @return array{0: bool, 1: string}
     */
    public function check(int $clientId): array
    {
        if ($this->settings->optIn()) {
            $field = $this->fieldId(Settings::FIELD_OPT_IN);

            return $field > 0 && $this->ticked($field, $clientId) ? array(true, '') : array(false, self::NOT_OPTED_IN);
        }
        $field = $this->fieldId(Settings::FIELD_OPT_OUT);

        return $field > 0 && $this->ticked($field, $clientId) ? array(false, self::OPTED_OUT) : array(true, '');
    }

    /** True when the client ticked the field. */
    public function ticked(int $fieldId, int $clientId): bool
    {
        if ($fieldId <= 0 || $clientId <= 0) {
            return false;
        }
        $row = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)->where('relid', $clientId)->orderBy('id', 'desc')->first();
        if ($row === null) {
            return false;
        }

        return in_array(strtolower(trim((string) $row->value)), array('on', '1', 'yes', 'true'), true);
    }

    /**
     * The custom field kept in a setting, when it still exists in WHMCS (0 otherwise).
     */
    public function fieldId(string $settingKey): int
    {
        $id = (int) $this->settings->get($settingKey, 0);
        if ($id <= 0) {
            return 0;
        }

        return Capsule::table('tblcustomfields')->where('id', $id)->where('type', 'client')->exists() ? $id : 0;
    }

    /**
     * The field clients see now, and whether it exists (an admin may have deleted it).
     *
     * @return array{name: string, id: int}
     */
    public function activeField(): array
    {
        return $this->settings->optIn()
            ? array('name' => self::OPT_IN_NAME, 'id' => $this->fieldId(Settings::FIELD_OPT_IN))
            : array('name' => self::OPT_OUT_NAME, 'id' => $this->fieldId(Settings::FIELD_OPT_OUT));
    }

    /** Deletes the module's fields and what clients ticked in them ("Delete all data on deactivate"). */
    public function remove()
    {
        foreach (array(Settings::FIELD_OPT_OUT, Settings::FIELD_OPT_IN) as $key) {
            $id = (int) $this->settings->get($key, 0);
            if ($id > 0) {
                Capsule::table('tblcustomfieldsvalues')->where('fieldid', $id)->delete();
                Capsule::table('tblcustomfields')->where('id', $id)->where('type', 'client')->delete();
            }
        }
    }

    private function ensureField(string $settingKey, string $name, string $help, bool $visible): int
    {
        $id = $this->fieldId($settingKey);
        if ($id <= 0) {
            // Reactivated after the settings were deleted, or the setting is gone: reuse a field of that name.
            $row = Capsule::table('tblcustomfields')->where('type', 'client')->where('fieldname', $name)->where('fieldtype', 'tickbox')->orderBy('id')->first();
            $id = $row !== null ? (int) $row->id : 0;
        }
        $now = $this->platform->nowSql();
        if ($id <= 0) {
            $row = $this->onlyExistingColumns(array(
                'type' => 'client',
                'relid' => 0,
                'fieldname' => $name,
                'fieldtype' => 'tickbox',
                'description' => $help,
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => $visible ? '' : 'on',
                'required' => '',
                'showorder' => $visible ? 'on' : '',
                'showinvoice' => '',
                'sortorder' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $id = (int) Capsule::table('tblcustomfields')->insertGetId($row);
        } else {
            Capsule::table('tblcustomfields')->where('id', $id)->update($this->onlyExistingColumns(array(
                'adminonly' => $visible ? '' : 'on',
                'showorder' => $visible ? 'on' : '',
                'updated_at' => $now,
            )));
        }
        $this->settings->set($settingKey, $id);

        return $id;
    }

    /**
     * Leaves out columns this WHMCS version's tblcustomfields does not have.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(array $row): array
    {
        try {
            $columns = Capsule::schema()->getColumnListing('tblcustomfields');
        } catch (\Throwable $e) {
            $columns = array();
        }
        if (!$columns) {
            return $row;
        }

        return array_intersect_key($row, array_flip($columns));
    }
}
