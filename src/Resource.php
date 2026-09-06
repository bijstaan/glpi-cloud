<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use CommonDBTM;
use DBmysql;

/**
 * One cloud resource, whatever it is.
 *
 * A single generic row for buckets, roles, security groups, functions, queues,
 * key vaults and virtual machines alike. That is not a shortcut around
 * modelling: the long tail of cloud resource types has no equivalent anywhere
 * in GLPI and never will, and a schema that only stored the types we had
 * mappings for would be quietly wrong precisely where somebody is looking —
 * the odd resource nobody remembered creating.
 *
 * The typed model is the *projection*, applied per type,
 * opt-in, to the handful of types core genuinely models better than we would:
 * VM → Computer, managed database → DatabaseInstance, cluster → Cluster.
 *
 * ### Why `dohistory` is off
 *
 * Core's history writes one row per changed field per item, and a sync touches
 * every resource in an estate. `glpi_plugin_glpicloud_resourcehistories` holds
 * the same information for the fields that matter, written only when the
 * checksum moves — see {@see History}. Core's log would be the same data at
 * ten times the volume, mixed in with everything else the instance did.
 *
 * ### Nothing here is created by hand
 *
 * A resource exists because a provider reported it. `canCreate()` is false so
 * the UI never offers a form that would produce a row no sync can maintain and
 * the next sweep would mark as disappeared.
 */
final class Resource extends CommonDBTM
{
    public static $rightname = 'plugin_glpicloud_resource';

    public $dohistory = false;

    /** Fields whose change is worth a history row, and worth a re-checksum. */
    public const TRACKED = Normalise::TRACKED;

    public static function getTypeName($nb = 0)
    {
        return _n('Cloud resource', 'Cloud resources', $nb, 'glpicloud');
    }

    public static function getIcon()
    {
        return 'ti ti-cloud';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string,string> */
    public function tags(): array
    {
        return self::decode((string) ($this->fields['tags'] ?? ''));
    }

    /** @return array<string,mixed> */
    public function attributes(): array
    {
        return self::decode((string) ($this->fields['attributes'] ?? ''));
    }

    /** @return array<string,mixed> */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * What a resource's identity boils down to for change detection.
     *
     * The arithmetic is in {@see Normalise}, which has no GLPI base class, so
     * the rule that decides whether a sweep writes anything at all can be
     * tested with `php tests/normalise.php`.
     *
     * @param array<string,mixed> $row
     */
    public static function checksum(array $row): string
    {
        return Normalise::checksum($row);
    }

    /** True while the provider still reports this resource. */
    public function isPresent(): bool
    {
        return ((string) ($this->fields['disappeared_at'] ?? '')) === '';
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $account = new Account();
        $account->getFromDB((int) ($this->fields['plugin_glpicloud_accounts_id'] ?? 0));

        $rows = [
            __('Name')                        => (string) ($this->fields['name'] ?? ''),
            Account::getTypeName(1)           => (string) ($account->fields['name'] ?? ''),
            __('Provider', 'glpicloud')       => (string) ($this->fields['provider'] ?? ''),
            __('Scope', 'glpicloud')          => (string) ($this->fields['scope'] ?? ''),
            __('Service', 'glpicloud')        => (string) ($this->fields['service'] ?? ''),
            __('Type', 'glpicloud')           => (string) ($this->fields['type'] ?? ''),
            __('State', 'glpicloud')          => (string) ($this->fields['state'] ?? ''),
            __('Provider identifier', 'glpicloud') => (string) ($this->fields['native_id'] ?? ''),
            __('First seen', 'glpicloud')     => (string) ($this->fields['first_seen'] ?? ''),
            __('Last seen', 'glpicloud')      => (string) ($this->fields['last_seen'] ?? ''),
        ];

        foreach ($rows as $label => $value) {
            echo "<tr class='tab_bg_1'><td>" . $label . "</td>";
            echo "<td colspan='3'>" . htmlspecialchars($value) . '</td></tr>';
        }

        if (!$this->isPresent()) {
            echo "<tr class='tab_bg_1'><td colspan='4'>";
            echo "<div class='alert alert-warning mb-0'>"
               . sprintf(
                   __s('This resource was last reported on %s and is gone from the provider. It is kept for the retention period set under Setup > Cloud.', 'glpicloud'),
                   htmlspecialchars((string) $this->fields['disappeared_at'])
               )
               . '</div></td></tr>';
        }

        $tags = $this->tags();

        if ($tags !== []) {
            echo "<tr class='tab_bg_1'><td>" . __s('Tags', 'glpicloud') . "</td><td colspan='3'>";
            foreach ($tags as $key => $value) {
                echo "<span class='badge bg-secondary-lt me-1'>"
                   . htmlspecialchars((string) $key) . '=' . htmlspecialchars((string) $value)
                   . '</span>';
            }
            echo '</td></tr>';
        }

        $attributes = $this->attributes();

        if ($attributes !== []) {
            echo "<tr class='tab_bg_1'><td colspan='4'>";
            echo '<details><summary>' . __s('What the provider reported', 'glpicloud') . '</summary>';
            echo "<pre class='mt-2 mb-0 small'>"
               . htmlspecialchars((string) json_encode($attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
               . '</pre></details></td></tr>';
        }

        $this->showRecentHistory();

        // Nothing on this page is editable — a resource is what the provider
        // reported and a run is what happened — so it gets no buttons. A Save
        // button that silently does nothing is worse than no button: it reads
        // as "your change was kept".
        $options['candel']  = false;
        $options['canedit'] = false;
        $this->showFormButtons($options);

        return true;
    }

    /**
     * The last few changes, on the page rather than behind a tab.
     *
     * "When did this change, and to what" is most of why somebody opens a cloud
     * resource in a ticketing system at all.
     */
    private function showRecentHistory(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $id = (int) $this->getID();

        if ($id <= 0) {
            return;
        }

        $rows = $DB->request([
            'FROM'  => 'glpi_plugin_glpicloud_resourcehistories',
            'WHERE' => ['plugin_glpicloud_resources_id' => $id],
            'ORDER' => ['date DESC', 'id DESC'],
            'LIMIT' => 20,
        ]);

        $lines = iterator_to_array($rows);

        if ($lines === []) {
            return;
        }

        echo "<tr class='tab_bg_2'><th colspan='4'>" . __s('Recent changes', 'glpicloud') . '</th></tr>';

        foreach ($lines as $line) {
            echo "<tr class='tab_bg_1'><td>" . htmlspecialchars((string) $line['date']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $line['field']) . '</td>';
            echo "<td colspan='2'><span class='text-muted'>"
               . htmlspecialchars(self::truncate((string) $line['old_value']))
               . '</span> → '
               . htmlspecialchars(self::truncate((string) $line['new_value']))
               . '</td></tr>';
        }
    }

    private static function truncate(string $value, int $length = 120): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '…' : $value;
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
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'provider',
            'name'     => __('Provider', 'glpicloud'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'service',
            'name'     => __('Service', 'glpicloud'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'type',
            'name'     => __('Type', 'glpicloud'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '6',
            'table'    => self::getTable(),
            'field'    => 'state',
            'name'     => __('State', 'glpicloud'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '7',
            'table'    => self::getTable(),
            'field'    => 'scope',
            'name'     => __('Scope', 'glpicloud'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '8',
            'table'    => self::getTable(),
            'field'    => 'native_id',
            'name'     => __('Provider identifier', 'glpicloud'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '9',
            'table'    => self::getTable(),
            'field'    => 'last_seen',
            'name'     => __('Last seen', 'glpicloud'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '10',
            'table'    => self::getTable(),
            'field'    => 'disappeared_at',
            'name'     => __('Disappeared', 'glpicloud'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'            => '11',
            'table'         => Account::getTable(),
            'field'         => 'name',
            'name'          => Account::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $options;
    }

    /**
     * Resources the current session may see, for the pages that do their own
     * queries rather than going through the search engine.
     *
     * @return array<string,mixed>
     */
    public static function entityCriteria(): array
    {
        return getEntitiesRestrictCriteria(self::getTable(), 'entities_id', $_SESSION['glpiactiveentities'] ?? [], true);
    }
}
