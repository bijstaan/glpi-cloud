<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use Budget;
use CommonDBTM;
use Contract;
use DBmysql;
use Dropdown;
use GLPIKey;
use Html;
use Session;

/**
 * A cloud account: one set of credentials, one customer, one sync schedule.
 *
 * "Account" is the neutral word for what Azure calls a tenant, AWS an account
 * and GCP an organisation — the thing a credential authenticates against. What
 * that credential can then *see* is a list of scopes (subscriptions, projects,
 * regions), which the provider enumerates and which is deliberately not
 * modelled here: it changes without anybody telling GLPI.
 *
 * ### Credentials
 *
 * One JSON blob, encrypted with GLPIKey, because the field list is the
 * provider's and a fixed set of columns would either be Azure's or nobody's.
 * The column is also declared in `secured_fields` (setup.php) — that hook does
 * *not* encrypt anything on write, whatever its name suggests; it tells
 * `glpi:security:changekey` which columns to re-encrypt when the instance key
 * is rotated. Encrypting is ours to do, on the way in, here.
 *
 * A stored secret is never sent back to the browser. The form shows a
 * placeholder, and a placeholder posted back means "unchanged" — so an
 * administrator editing the sync interval cannot accidentally overwrite a
 * client secret with eight bullet characters.
 *
 * ### Native Management links
 *
 * `contracts_id` and `budgets_id` are the whole cost design:
 * closed billing periods become native `ContractCost` rows against that
 * contract, attributed to that budget, and core's own financial screens then
 * count cloud spend without a line of reporting code of ours.
 */
final class Account extends CommonDBTM
{
    public static $rightname = 'plugin_glpicloud_account';

    public $dohistory = true;

    /** Shown in place of a stored secret, and posted back unchanged to keep it. */
    public const SECRET_PLACEHOLDER = '••••••••';

    public static function getTypeName($nb = 0)
    {
        return _n('Cloud account', 'Cloud accounts', $nb, 'glpicloud');
    }

    public static function getIcon()
    {
        return 'ti ti-cloud-cog';
    }

    /**
     * What a new account starts as.
     *
     * `getEmpty()` fills every field with the empty string rather than the
     * column default, so without this the Active dropdown opens on **No** — and
     * an account created through the form would sit there being skipped by
     * every cron tick, with nothing on screen to say why.
     */
    public function post_getEmpty()
    {
        $this->fields['is_active']     = 1;
        // Zero is meaningful here: "use the instance default".
        $this->fields['sync_interval'] = 0;
        $this->fields['contracts_id']  = 0;
        $this->fields['budgets_id']    = 0;
    }

    /** The provider that owns this account, or null if its plugin is gone. */
    public function provider(): ?Provider
    {
        return Registry::get((string) ($this->fields['provider'] ?? ''));
    }

    /**
     * The decrypted credentials.
     *
     * @return array<string,string>
     */
    public function credentials(): array
    {
        $stored = (string) ($this->fields['credentials'] ?? '');

        if ($stored === '') {
            return [];
        }

        $json = (new GLPIKey())->decrypt($stored);
        $data = json_decode((string) $json, true);

        return is_array($data) ? array_map(static fn($v): string => (string) $v, $data) : [];
    }

    /** Seconds between syncs of this account: its own, or the instance default. */
    public function syncInterval(): int
    {
        $own = (int) ($this->fields['sync_interval'] ?? 0);

        return $own > 0 ? $own : (int) Settings::get('sync_interval');
    }

    /**
     * Accounts due a sync, oldest first.
     *
     * Ordering by `last_sync` rather than by id matters on an instance with
     * more accounts than one cron tick can serve: by id, the same first few
     * accounts would be synced every tick and the tail would never run.
     *
     * @return array<int,self>
     */
    public static function due(?int $now = null): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $now  = $now ?? time();
        $out  = [];
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER' => ['last_sync ASC', 'id ASC'],
        ]);

        foreach ($rows as $row) {
            $account = new self();
            $account->fields = $row;

            $last = (int) strtotime((string) ($row['last_sync'] ?? '')) ?: 0;

            if ($last + $account->syncInterval() <= $now) {
                $out[] = $account;
            }
        }

        return $out;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->prepareCommon((array) $input);

        if ($input === false) {
            return false;
        }

        $provider = Registry::get((string) ($input['provider'] ?? ''));

        if ($provider === null) {
            Session::addMessageAfterRedirect(
                __s('That cloud provider is not installed.', 'glpicloud'),
                false,
                ERROR
            );

            return false;
        }

        $input['credentials'] = $this->encodeCredentials(
            self::validated((array) ($input['cred'] ?? []), $provider),
            []
        );
        unset($input['cred']);

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->prepareCommon((array) $input);

        if ($input === false) {
            return false;
        }

        // The provider is fixed at creation. Changing it would leave every
        // resource row stamped with a provider that no longer describes how it
        // was collected, and there is no migration that makes sense.
        unset($input['provider']);

        if (array_key_exists('cred', $input)) {
            $input['credentials'] = $this->encodeCredentials(
                self::validated((array) $input['cred'], $this->provider()),
                $this->credentials()
            );
            unset($input['cred']);
        }

        return $input;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|false
     */
    private function prepareCommon(array $input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__s('A cloud account needs a name.', 'glpicloud'), false, ERROR);

            return false;
        }

        if (array_key_exists('sync_interval', $input)) {
            $interval = (int) $input['sync_interval'];
            // Zero is meaningful: "use the instance default". Anything else is
            // clamped to the same bounds the global setting uses.
            $input['sync_interval'] = $interval <= 0 ? 0 : (int) Settings::clamp('sync_interval', $interval);
        }

        return $input;
    }

    /**
     * Drop posted values a provider's field list does not allow.
     *
     * Only matters for a field with `choices`: the dropdown offers three API
     * regions, and a hand-crafted POST offering a fourth would be stored and
     * then signed against an endpoint that does not exist. Unknown *fields* are
     * left alone — a provider may add one between versions, and an account
     * mid-upgrade should keep what it has.
     *
     * @param array<string,mixed> $posted
     * @return array<string,mixed>
     */
    private static function validated(array $posted, ?Provider $provider): array
    {
        if ($provider === null) {
            return $posted;
        }

        foreach ($provider->credentialFields() as $field) {
            $choices = $field['choices'] ?? [];

            if ($choices === [] || !array_key_exists($field['key'], $posted)) {
                continue;
            }

            if (!array_key_exists((string) $posted[$field['key']], $choices)) {
                unset($posted[$field['key']]);
            }
        }

        return $posted;
    }

    /**
     * Merge posted credentials over the stored ones.
     *
     * @param array<string,mixed>  $posted
     * @param array<string,string> $existing
     */
    private function encodeCredentials(array $posted, array $existing): string
    {
        $merged = $existing;

        foreach ($posted as $key => $value) {
            $key   = (string) $key;
            $value = (string) $value;

            if ($value === self::SECRET_PLACEHOLDER) {
                continue;   // "unchanged"
            }

            if ($value === '') {
                unset($merged[$key]);
                continue;
            }

            $merged[$key] = $value;
        }

        return (new GLPIKey())->encrypt((string) json_encode($merged));
    }

    /**
     * Purging an account takes its inventory with it.
     *
     * Deliberate, and deliberately only on *purge*: a deleted account is in the
     * bin and recoverable, and the resources are still the answer to "what did
     * this customer have last month". Purge is the button that means it.
     */
    public function cleanDBonPurge()
    {
        /** @var DBmysql $DB */
        global $DB;

        $id = (int) $this->getID();

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Resource::getTable(),
                'WHERE'  => ['plugin_glpicloud_accounts_id' => $id],
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        if ($ids !== []) {
            $DB->delete('glpi_plugin_glpicloud_resourcehistories', ['plugin_glpicloud_resources_id' => $ids]);
        }

        foreach ([Resource::getTable(), Run::getTable(), 'glpi_plugin_glpicloud_costs', 'glpi_plugin_glpicloud_checkpoints'] as $table) {
            $DB->delete($table, ['plugin_glpicloud_accounts_id' => $id]);
        }
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $providers = Registry::providers();
        $provider  = $this->provider();

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . __('Name') . '</td><td>';
        echo Html::input('name', ['value' => $this->fields['name'] ?? '']);
        echo '</td>';
        echo '<td>' . __('Active') . '</td><td>';
        Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . __('Provider', 'glpicloud') . '</td><td>';

        if ($this->isNewID($ID)) {
            if ($providers === []) {
                echo "<span class='text-danger'>"
                   . __s('No cloud provider plugin is installed. Install glpi-cloud-azure (or another provider) first.', 'glpicloud')
                   . '</span>';
            } else {
                Dropdown::showFromArray('provider', array_map(
                    static fn(Provider $p): string => $p->name(),
                    $providers
                ), ['value' => $this->fields['provider'] ?? '']);
            }
        } else {
            echo htmlspecialchars($provider?->name() ?? sprintf(
                /* the plugin that collected this inventory has gone away */
                __('%s (plugin not installed)', 'glpicloud'),
                (string) ($this->fields['provider'] ?? '')
            ));
        }

        echo '</td>';
        echo '<td>' . __('Sync interval (seconds)', 'glpicloud') . '</td><td>';
        echo Html::input('sync_interval', [
            'type'  => 'number',
            'min'   => 0,
            'value' => $this->fields['sync_interval'] ?? 0,
        ]);
        echo "<div class='form-text'>"
           . __s('Zero uses the instance default from Setup > Cloud.', 'glpicloud')
           . '</div>';
        echo '</td></tr>';

        // Credentials.
        //
        // On a *new* account every installed provider's fields are rendered and
        // all but the selected one are hidden, because the alternative — the
        // one this form had first — is that you pick a provider, save, and only
        // then get somewhere to type the credentials. A second round trip to
        // fill in the only fields that matter is not a form, it is a puzzle.
        //
        // Nothing is fetched to do this: there are as many providers as there
        // are installed plugins, and rendering three fieldsets costs less than
        // an AJAX endpoint would.
        $render_for = [];

        if ($this->isNewID($ID)) {
            $render_for = $providers;
        } elseif ($provider !== null) {
            $render_for = [$provider->key() => $provider];
        }

        $selected = (string) ($this->fields['provider'] ?? '');

        if ($selected === '' && $providers !== []) {
            // What the dropdown shows before anybody touches it.
            $selected = (string) array_key_first($providers);
        }

        $stored = $this->credentials();

        foreach ($render_for as $key => $each) {
            $hidden = ((string) $key !== $selected) ? " style='display:none'" : '';
            $row    = sprintf("class='glpicloud-cred' data-provider='%s'%s", htmlspecialchars((string) $key), $hidden);

            echo "<tr {$row}><th colspan='4' class='tab_bg_2'>"
               . sprintf(__s('%s credentials', 'glpicloud'), htmlspecialchars($each->name()))
               . '</th></tr>';

            foreach ($each->credentialFields() as $field) {
                $value = (string) ($stored[$field['key']] ?? '');

                echo "<tr {$row}>";
                echo '<td>' . htmlspecialchars($field['label']);
                if ($field['required']) {
                    echo " <span class='text-danger'>*</span>";
                }
                echo '</td><td colspan="3">';

                if (($field['choices'] ?? []) !== []) {
                    Dropdown::showFromArray('cred[' . $field['key'] . ']', $field['choices'], [
                        'value' => $value !== '' ? $value : array_key_first($field['choices']),
                    ]);
                } else {
                    echo Html::input('cred[' . $field['key'] . ']', [
                        'value'        => $field['secret'] ? ($value === '' ? '' : self::SECRET_PLACEHOLDER) : $value,
                        'autocomplete' => 'off',
                    ]);
                }
                if ($field['help'] !== '') {
                    echo "<div class='form-text'>" . htmlspecialchars($field['help']) . '</div>';
                }
                echo '</td></tr>';
            }

            echo "<tr {$row}><td colspan='4' class='text-muted'>"
               . __s('Credentials are stored encrypted and are never sent back to the browser. Use read-only credentials: this plugin never writes to a cloud account.', 'glpicloud')
               . '</td></tr>';
        }

        if ($this->isNewID($ID) && count($providers) > 1) {
            // GLPI renders the provider dropdown as select2, which fires its
            // change event through jQuery — a listener added with
            // addEventListener never hears it. jQuery is always present here;
            // the fallback is for the day it is not.
            echo <<<'JS'
<script>
(function () {
    var apply = function () {
        var select = document.querySelector('select[name="provider"]');
        if (!select) {
            return;
        }
        var sync = function () {
            document.querySelectorAll('tr.glpicloud-cred').forEach(function (row) {
                row.style.display = row.dataset.provider === select.value ? '' : 'none';
            });
        };
        if (window.jQuery) {
            window.jQuery(select).on('change', sync);
        } else {
            select.addEventListener('change', sync);
        }
        sync();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
</script>
JS;
        }

        echo "<tr class='tab_bg_2'><th colspan='4'>" . __s('Financials', 'glpicloud') . '</th></tr>';

        // The entity these dropdowns are restricted to. A saved account has
        // one; a new one has not been given an entity yet, so the session's is
        // the only honest guess — and the alternative, an empty restriction,
        // offers contracts from entities the account will not be able to use.
        $entity = $this->fields['entities_id'] ?? '';
        $entity = $entity === '' ? (int) ($_SESSION['glpiactive_entity'] ?? 0) : (int) $entity;

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . Contract::getTypeName(1) . '</td><td>';
        Contract::dropdown([
            'name'                => 'contracts_id',
            'value'               => $this->fields['contracts_id'] ?? 0,
            'entity'              => $entity,
            'display_emptychoice' => true,
            // Core hides expired contracts by default, and computes "expired"
            // from begin_date + duration — so a contract with **no dates**, the
            // ordinary shape of a cloud subscription, is NULL in that
            // arithmetic and disappears from the list entirely. Ended contracts
            // are wanted here too: a closed billing period is posted to
            // whatever contract covered it, which may well have finished since.
            'expired'             => true,
        ]);
        echo "<div class='form-text'>"
           . __s('Each closed billing period becomes one cost line on this contract. Contracts that have ended are listed too, because a closed period belongs to whatever covered it.', 'glpicloud')
           . '</div>';

        if (self::countIn(Contract::getTable(), $entity, true) === 0) {
            echo self::emptyHint(
                __s('No contract exists in this entity yet.', 'glpicloud'),
                Contract::getSearchURL(),
                __s('Create one under Management > Contracts', 'glpicloud')
            );
        }

        echo '</td>';
        echo '<td>' . Budget::getTypeName(1) . '</td><td>';
        Budget::dropdown([
            'name'                => 'budgets_id',
            'value'               => $this->fields['budgets_id'] ?? 0,
            'entity'              => $entity,
            'display_emptychoice' => true,
        ]);
        echo "<div class='form-text'>"
           . __s("Cost lines are attributed to this budget, so GLPI's own budget screens count cloud spend.", 'glpicloud')
           . '</div>';

        if (self::countIn(Budget::getTable(), $entity, false) === 0) {
            echo self::emptyHint(
                __s('No budget exists in this entity yet.', 'glpicloud'),
                Budget::getSearchURL(),
                __s('Create one under Management > Budgets', 'glpicloud')
            );
        }

        echo '</td></tr>';

        if (!$this->isNewID($ID)) {
            echo "<tr class='tab_bg_1'>";
            echo '<td>' . __('Last sync', 'glpicloud') . '</td><td>';
            echo htmlspecialchars((string) ($this->fields['last_sync'] ?? '')) ?: __s('Never', 'glpicloud');
            echo '</td>';
            echo '<td>' . __('Status', 'glpicloud') . '</td><td>';
            $status = (string) ($this->fields['last_status'] ?? '');
            $error  = (string) ($this->fields['last_error'] ?? '');
            echo htmlspecialchars($status === '' ? __('Never run', 'glpicloud') : $status);
            if ($error !== '') {
                echo "<div class='text-danger'>" . htmlspecialchars($error) . '</div>';
            }
            echo '</td></tr>';
        }

        $this->showFormButtons($options);

        return true;
    }

    /**
     * How many of something this entity can actually choose from.
     *
     * Only used to decide whether to say so. An empty dropdown with no
     * explanation is the worst of both: it looks broken, and the thing that
     * would fix it is two menus away.
     */
    private static function countIn(string $table, int $entity, bool $has_template): int
    {
        $criteria = ['is_deleted' => 0] + getEntitiesRestrictCriteria($table, 'entities_id', $entity, true);

        if ($has_template) {
            $criteria['is_template'] = 0;
        }

        return (int) countElementsInTable($table, $criteria);
    }

    private static function emptyHint(string $said, string $url, string $link): string
    {
        return "<div class='form-text text-warning'>"
            . $said . ' <a href="' . htmlspecialchars($url) . '">' . $link . '</a>'
            . '</div>';
    }

    public function rawSearchOptions()
    {
        $options = parent::rawSearchOptions();

        $options[] = [
            'id'    => '2',
            'table' => self::getTable(),
            'field' => 'id',
            'name'  => __('ID'),
        ];

        $options[] = [
            'id'            => '3',
            'table'         => self::getTable(),
            'field'         => 'provider',
            'name'          => __('Provider', 'glpicloud'),
            'datatype'      => 'string',
        ];

        $options[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];

        $options[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'last_sync',
            'name'     => __('Last sync', 'glpicloud'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '6',
            'table'    => self::getTable(),
            'field'    => 'last_status',
            'name'     => __('Status', 'glpicloud'),
            'datatype' => 'string',
        ];

        return $options;
    }
}
