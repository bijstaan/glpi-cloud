<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use CommonDBTM;
use DBmysql;

/**
 * One sweep of one account: when, how long, what it saw, and what went wrong.
 *
 * Also the lock. A run row with status `running` is what stops a second cron
 * tick from sweeping an account the first one is still working through — GLPI's
 * cron will happily overlap, and two concurrent sweeps of the same account race
 * each other's disappearance marking.
 *
 * A `running` row older than {@see STALE_AFTER} is not a lock, it is wreckage:
 * PHP was killed mid-sweep, or the container went away. It is closed as
 * `interrupted` and the next run proceeds, because the alternative is an
 * account that never syncs again and says nothing about why.
 */
final class Run extends CommonDBTM
{
    public static $rightname = 'plugin_glpicloud_resource';

    public const RUNNING     = 'running';
    public const OK          = 'ok';
    public const PARTIAL     = 'partial';
    public const FAILED      = 'failed';
    public const INTERRUPTED = 'interrupted';

    /** How long a `running` row is believed. */
    public const STALE_AFTER = 3600;

    public static function getTypeName($nb = 0)
    {
        return _n('Sync run', 'Sync runs', $nb, 'glpicloud');
    }

    public static function getIcon()
    {
        return 'ti ti-refresh';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Open a run, or refuse because one is already going.
     *
     * @return int|null the run id, or null when the account is locked
     */
    public static function start(Account $account, ?int $now = null): ?int
    {
        /** @var DBmysql $DB */
        global $DB;

        $now = $now ?? time();
        $id  = (int) $account->getID();

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_glpicloud_accounts_id' => $id, 'status' => self::RUNNING],
            ]) as $row
        ) {
            $started = (int) strtotime((string) $row['started_at']);

            if ($started > 0 && ($now - $started) < self::STALE_AFTER) {
                return null;
            }

            $DB->update(self::getTable(), [
                'status'      => self::INTERRUPTED,
                'finished_at' => date('Y-m-d H:i:s', $now),
                'errors'      => (string) json_encode(['The previous run stopped without finishing.']),
            ], ['id' => (int) $row['id']]);
        }

        $DB->insert(self::getTable(), [
            'plugin_glpicloud_accounts_id' => $id,
            'entities_id'                  => (int) ($account->fields['entities_id'] ?? 0),
            'status'                       => self::RUNNING,
            'started_at'                   => date('Y-m-d H:i:s', $now),
        ]);

        // DBmysql::insert() returns a bool. Casting it gives 1 for every row
        // ever inserted, which is a lock on run #1 forever.
        return (int) $DB->insertId();
    }

    /**
     * Close a run.
     *
     * @param array<int,string> $errors
     * @param array<string,int> $counts
     */
    public static function finish(int $runs_id, string $status, array $counts, array $errors, ?int $now = null): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->update(self::getTable(), [
            'status'             => $status,
            'finished_at'        => date('Y-m-d H:i:s', $now ?? time()),
            'resources_seen'     => (int) ($counts['seen'] ?? 0),
            'resources_changed'  => (int) ($counts['changed'] ?? 0),
            'resources_new'      => (int) ($counts['new'] ?? 0),
            'resources_gone'     => (int) ($counts['gone'] ?? 0),
            'requests'           => (int) ($counts['requests'] ?? 0),
            'errors'             => (string) json_encode(array_values($errors)),
        ], ['id' => $runs_id]);
    }

    /**
     * What one sweep did, and what went wrong.
     *
     * "Why is this customer's inventory empty" is the question this page
     * exists for, so the errors are the body of it rather than a field on it.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $account = new Account();
        $account->getFromDB((int) ($this->fields['plugin_glpicloud_accounts_id'] ?? 0));

        $rows = [
            Account::getTypeName(1)          => (string) ($account->fields['name'] ?? ''),
            __('Status', 'glpicloud')        => (string) ($this->fields['status'] ?? ''),
            __('Started', 'glpicloud')       => (string) ($this->fields['started_at'] ?? ''),
            __('Finished', 'glpicloud')      => (string) ($this->fields['finished_at'] ?? ''),
            __('Resources seen', 'glpicloud')   => (string) ($this->fields['resources_seen'] ?? 0),
            __('New', 'glpicloud')              => (string) ($this->fields['resources_new'] ?? 0),
            __('Changed', 'glpicloud')          => (string) ($this->fields['resources_changed'] ?? 0),
            __('Disappeared', 'glpicloud')      => (string) ($this->fields['resources_gone'] ?? 0),
            __('Provider requests', 'glpicloud') => (string) ($this->fields['requests'] ?? 0),
        ];

        foreach ($rows as $label => $value) {
            echo "<tr class='tab_bg_1'><td>" . $label . "</td>";
            echo "<td colspan='3'>" . htmlspecialchars($value) . '</td></tr>';
        }

        $errors = $this->errors();

        if ($errors !== []) {
            echo "<tr class='tab_bg_2'><th colspan='4'>" . __s('What went wrong', 'glpicloud') . '</th></tr>';

            foreach ($errors as $error) {
                echo "<tr class='tab_bg_1'><td colspan='4' class='text-danger'>"
                   . htmlspecialchars($error) . '</td></tr>';
            }
        } elseif ((string) ($this->fields['status'] ?? '') === self::PARTIAL) {
            // Partial with no error means the clock ran out, not a failure.
            echo "<tr class='tab_bg_1'><td colspan='4'>"
               . __s('This run stopped when it reached its time budget. It kept its place and the next run continues from there.', 'glpicloud')
               . '</td></tr>';
        }

        // Nothing on this page is editable — a resource is what the provider
        // reported and a run is what happened — so it gets no buttons. A Save
        // button that silently does nothing is worse than no button: it reads
        // as "your change was kept".
        $options['candel']  = false;
        $options['canedit'] = false;
        $this->showFormButtons($options);

        return true;
    }

    /** @return array<int,string> */
    public function errors(): array
    {
        $data = json_decode((string) ($this->fields['errors'] ?? ''), true);

        return is_array($data) ? array_map(static fn($v): string => (string) $v, $data) : [];
    }

    public function rawSearchOptions()
    {
        $options = parent::rawSearchOptions();

        // Search option 1 is the column GLPI links the item from, and core only
        // creates it for a table with a `name` field. A run has no name — so
        // without this the list renders *no columns at all*, which is what it
        // did until somebody opened the page.
        $options[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'started_at',
            'name'          => __('Started', 'glpicloud'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $options[] = [
            'id'            => '2',
            'table'         => Account::getTable(),
            'field'         => 'name',
            'name'          => Account::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        foreach ([
            ['3', 'status', __('Status', 'glpicloud'), 'string'],
            ['5', 'finished_at', __('Finished', 'glpicloud'), 'datetime'],
            ['6', 'resources_seen', __('Resources seen', 'glpicloud'), 'number'],
            ['7', 'resources_changed', __('Changed', 'glpicloud'), 'number'],
            ['8', 'resources_gone', __('Disappeared', 'glpicloud'), 'number'],
        ] as [$id, $field, $name, $datatype]) {
            $options[] = [
                'id'       => $id,
                'table'    => self::getTable(),
                'field'    => $field,
                'name'     => $name,
                'datatype' => $datatype,
            ];
        }

        return $options;
    }
}
