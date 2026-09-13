<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpicloud\Account;
use GlpiPlugin\Glpicloud\Resource;
use GlpiPlugin\Glpicloud\Run;
use GlpiPlugin\Glpicloud\Settings;
use GlpiPlugin\Glpicloud\Statement;
use GlpiPlugin\Glpicloud\Sync;

/**
 * Install.
 *
 * Six tables, and the shape of them is the plan's §4. Two conventions worth
 * naming because core enforces them quietly:
 *
 * - Date columns are `TIMESTAMP NULL DEFAULT NULL`. Core's schema checker
 *   rejects `DATETIME` on plugin tables.
 * - Every entity-assigned table carries `is_recursive` alongside `entities_id`,
 *   because `getEntitiesRestrictCriteria(..., true)`'s fourth argument means
 *   "this table has is_recursive" — not "walk the tree" — and a table without
 *   it is invisible from the root.
 */
function plugin_glpicloud_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    // One set of credentials, one entity, one schedule. `credentials` is a
    // GLPIKey-encrypted JSON blob rather than columns, because the field list
    // belongs to the provider — see Account.
    if (!$DB->tableExists('glpi_plugin_glpicloud_accounts')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpicloud_accounts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `provider` VARCHAR(32) NOT NULL DEFAULT '',
                `credentials` TEXT NULL,
                `identity` VARCHAR(255) NOT NULL DEFAULT '',
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 0,
                `contracts_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `budgets_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `sync_interval` INT UNSIGNED NOT NULL DEFAULT 0,
                `last_sync` TIMESTAMP NULL DEFAULT NULL,
                `last_status` VARCHAR(32) NOT NULL DEFAULT '',
                `last_error` VARCHAR(255) NOT NULL DEFAULT '',
                `is_active` TINYINT NOT NULL DEFAULT 1,
                `is_deleted` TINYINT NOT NULL DEFAULT 0,
                `comment` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `provider` (`provider`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`),
                KEY `last_sync` (`last_sync`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // The canonical resource row. Unique on (account, native_id): a provider's
    // own identifier is the only key that survives a rename, and re-collecting
    // the same resource must update it rather than fork it.
    if (!$DB->tableExists('glpi_plugin_glpicloud_resources')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpicloud_resources` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpicloud_accounts_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `provider` VARCHAR(32) NOT NULL DEFAULT '',
                `scope` VARCHAR(128) NOT NULL DEFAULT '',
                `service` VARCHAR(32) NOT NULL DEFAULT '',
                `type` VARCHAR(64) NOT NULL DEFAULT '',
                `native_id` VARCHAR(255) NOT NULL DEFAULT '',
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `state` VARCHAR(64) NOT NULL DEFAULT '',
                `tags` MEDIUMTEXT NULL,
                `attributes` MEDIUMTEXT NULL,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 0,
                `projected_itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `projected_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
                -- How the asset was found (created | osquery | uuid | name),
                -- and whether this plugin created it. `projected_owned` is what
                -- makes retraction safe: an asset we created is ours to trash,
                -- an adopted one is only ever unlinked. See Projection.
                `projected_how` VARCHAR(16) NOT NULL DEFAULT '',
                `projected_owned` TINYINT NOT NULL DEFAULT 0,
                `checksum` CHAR(64) NOT NULL DEFAULT '',
                `first_seen` TIMESTAMP NULL DEFAULT NULL,
                `last_seen` TIMESTAMP NULL DEFAULT NULL,
                `disappeared_at` TIMESTAMP NULL DEFAULT NULL,
                `is_orphaned` TINYINT NOT NULL DEFAULT 0,
                `is_deleted` TINYINT NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `native` (`plugin_glpicloud_accounts_id`, `native_id`),
                KEY `sweep` (`plugin_glpicloud_accounts_id`, `scope`, `service`),
                KEY `entities_id` (`entities_id`),
                KEY `type` (`type`),
                KEY `state` (`state`),
                KEY `disappeared_at` (`disappeared_at`),
                KEY `projected` (`projected_itemtype`, `projected_items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Written only when a resource's checksum moves. This is what answers "when
    // did this become public", so it deliberately outlives the resource.
    if (!$DB->tableExists('glpi_plugin_glpicloud_resourcehistories')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpicloud_resourcehistories` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpicloud_resources_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date` TIMESTAMP NULL DEFAULT NULL,
                `field` VARCHAR(64) NOT NULL DEFAULT '',
                `old_value` TEXT NULL,
                `new_value` TEXT NULL,
                PRIMARY KEY (`id`),
                KEY `resource` (`plugin_glpicloud_resources_id`, `date`),
                KEY `date` (`date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Money as the provider billed it. Unique on the natural key so re-querying
    // a month — which every provider restates for days after it closes — cannot
    // double-count. A row with resources_id = 0 is unattributed spend, kept so
    // the account total reconciles with the invoice.
    if (!$DB->tableExists('glpi_plugin_glpicloud_costs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpicloud_costs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpicloud_accounts_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `plugin_glpicloud_resources_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `period` CHAR(7) NOT NULL DEFAULT '',
                `currency` CHAR(3) NOT NULL DEFAULT '',
                `amount` DECIMAL(20,4) NOT NULL DEFAULT 0.0000,
                `source` VARCHAR(64) NOT NULL DEFAULT '',
                `is_provisional` TINYINT NOT NULL DEFAULT 0,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `natural` (`plugin_glpicloud_accounts_id`, `plugin_glpicloud_resources_id`, `period`, `currency`),
                KEY `period` (`period`),
                KEY `resource` (`plugin_glpicloud_resources_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // One row per sweep. Also the lock: see Run.
    if (!$DB->tableExists('glpi_plugin_glpicloud_runs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpicloud_runs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpicloud_accounts_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 0,
                `status` VARCHAR(32) NOT NULL DEFAULT '',
                `started_at` TIMESTAMP NULL DEFAULT NULL,
                `finished_at` TIMESTAMP NULL DEFAULT NULL,
                `resources_seen` INT UNSIGNED NOT NULL DEFAULT 0,
                `resources_changed` INT UNSIGNED NOT NULL DEFAULT 0,
                `resources_new` INT UNSIGNED NOT NULL DEFAULT 0,
                `resources_gone` INT UNSIGNED NOT NULL DEFAULT 0,
                `requests` INT UNSIGNED NOT NULL DEFAULT 0,
                `errors` TEXT NULL,
                PRIMARY KEY (`id`),
                KEY `account` (`plugin_glpicloud_accounts_id`, `started_at`),
                KEY `status` (`status`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Where a sweep got to. The cursor is the provider's own word for "carry on
    // from here" and is opaque to us on purpose.
    if (!$DB->tableExists('glpi_plugin_glpicloud_checkpoints')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpicloud_checkpoints` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpicloud_accounts_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `scope` VARCHAR(128) NOT NULL DEFAULT '',
                `service` VARCHAR(32) NOT NULL DEFAULT '',
                `cursor_value` TEXT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unit` (`plugin_glpicloud_accounts_id`, `scope`, `service`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    plugin_glpicloud_install_rights();
    plugin_glpicloud_install_displayprefs();

    Config::setConfigurationValues(
        PLUGIN_GLPICLOUD_CONFIG_CONTEXT,
        array_diff_key(
            Settings::DEFAULTS,
            Config::getConfigurationValues(PLUGIN_GLPICLOUD_CONFIG_CONTEXT)
        )
    );

    // Six hours, and every account decides for itself whether it is due — the
    // cron only wakes the ones whose interval has elapsed.
    CronTask::register(
        Sync::class,
        'cloudsync',
        HOUR_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Sync cloud accounts that are due',
        ]
    );

    // Separate from the sweep on purpose: retention is a policy decision that
    // must keep working when every provider is failing.
    CronTask::register(
        Sync::class,
        'cloudprune',
        DAY_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Drop expired cloud history and long-gone resources',
        ]
    );

    plugin_glpicloud_install_columns();

    // The cost statement's notification template. Idempotent: it is seeded only
    // when no template for Account exists, so an administrator's edits survive
    // every upgrade.
    Statement::install();

    return true;
}

/**
 * Columns added after the first release.
 *
 * Install runs on upgrade too, and the table guards above are `tableExists`, so
 * a column added to a CREATE TABLE never reaches an instance that already has
 * the table. Each one is added here as well, guarded on its own absence.
 */
function plugin_glpicloud_install_columns()
{
    /** @var DBmysql $DB */
    global $DB;

    $table = Resource::getTable();

    $added = [
        'projected_how'   => "VARCHAR(16) NOT NULL DEFAULT '' AFTER `projected_items_id`",
        'projected_owned' => "TINYINT NOT NULL DEFAULT 0 AFTER `projected_how`",
    ];

    $altered = false;

    foreach ($added as $column => $definition) {
        // `$usecache = false`, and it is load-bearing. DBmysql::fieldExists()
        // serves a per-request field cache, and by the time an install hook
        // runs, something earlier in the request has usually already read this
        // table and warmed it. A cache that predates a previous upgrade says a
        // column is absent when it is not, and the ALTER then fails with a
        // duplicate-column error that aborts the whole upgrade — the same trap
        // the rights block below documents for ProfileRight.
        if (!$DB->fieldExists($table, $column, false)) {
            $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            $altered = true;
        }
    }

    // And clear it on the way out, so anything later in this same request —
    // the display preferences below, a first sweep — sees the new columns
    // rather than the shape the table had when the request started.
    if ($altered) {
        $DB->clearSchemaCache();
    }
}

/**
 * What each list shows before anybody configures it.
 *
 * GLPI opens a plugin list with one column — the item's name — and for a table
 * with no `name` field, with *none at all*. A resource list showing only a name
 * cannot be read: the questions are which entity, what kind of thing, is it
 * running, and when was it last seen. So the defaults are seeded here, as
 * global preferences (`users_id = 0`), which any user is still free to change
 * for themselves.
 *
 * Idempotent: install runs on upgrade too, and a column somebody deliberately
 * removed should not come back every time they update the plugin.
 */
function plugin_glpicloud_install_displayprefs()
{
    /** @var DBmysql $DB */
    global $DB;

    $defaults = [
        // provider, service, type, state, account, last seen
        Resource::class => [3, 4, 5, 6, 11, 9],
        // provider, active, last sync, status
        Account::class  => [3, 4, 5, 6],
        // account, status, finished, seen, changed, gone
        Run::class      => [2, 3, 5, 6, 7, 8],
    ];

    foreach ($defaults as $itemtype => $columns) {
        $rank = 1;

        foreach ($columns as $num) {
            $exists = countElementsInTable('glpi_displaypreferences', [
                'itemtype' => $itemtype,
                'num'      => $num,
                'users_id' => 0,
            ]);

            if ($exists === 0) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype' => $itemtype,
                    'num'      => $num,
                    'rank'     => $rank,
                    'users_id' => 0,
                ]);
            }

            $rank++;
        }
    }
}

/**
 * Register this plugin's rights and grant them to the profiles that already
 * administer the instance.
 *
 * Only rights that are not already registered are added: GLPI runs the install
 * hook on upgrade as well as on first install, and
 * ProfileRight::addProfileRights() inserts unconditionally — so calling it for
 * an existing right raises a duplicate-key error that aborts the upgrade.
 */
function plugin_glpicloud_install_rights()
{
    /** @var DBmysql $DB */
    global $DB;

    $rights = [
        // Reading inventory is the ordinary case; creating one by hand is not
        // possible at all (Resource::canCreate()).
        'plugin_glpicloud_resource' => ALLSTANDARDRIGHT,
        // Holding an entity's cloud credentials is the serious right here,
        // even read-only ones. It is granted with the others by default only to
        // profiles that can already administer configuration.
        'plugin_glpicloud_account'  => ALLSTANDARDRIGHT,
        'plugin_glpicloud_config'   => READ | UPDATE,
    ];

    $existing = [];
    foreach (
        $DB->request([
            'SELECT'   => ['name'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_profilerights',
            'WHERE'    => ['name' => array_keys($rights)],
        ]) as $row
    ) {
        $existing[(string) $row['name']] = true;
    }

    foreach (array_keys($rights) as $right) {
        if (!isset($existing[$right])) {
            ProfileRight::addProfileRights([$right]);
        }
    }

    // Keying off the installing user's session does not work: plugins are
    // routinely installed from the console, where there is no active profile,
    // and the plugin would then be installed but usable by nobody.
    $targets = [];
    foreach (
        $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
        ]) as $row
    ) {
        $targets[] = (int) $row['profiles_id'];
    }

    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $targets[] = (int) $_SESSION['glpiactiveprofile']['id'];
    }

    foreach (array_unique($targets) as $profiles_id) {
        foreach ($rights as $right => $value) {
            ProfileRight::updateProfileRights($profiles_id, [$right => $value]);
        }
    }
}

/**
 * Uninstall.
 *
 * The inventory goes with the plugin — it is this plugin's own tables and
 * nothing else can read them. What deliberately does *not* go is anything
 * written into core: `ContractCost` rows stay, because they are money that has
 * been reported on, and quietly reversing an entity's contract history because
 * somebody uninstalled a plugin would be the wrong kind of tidy.
 */
function plugin_glpicloud_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (['checkpoints', 'runs', 'costs', 'resourcehistories', 'resources', 'accounts'] as $suffix) {
        $table = "glpi_plugin_glpicloud_$suffix";

        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $DB->delete('glpi_displaypreferences', [
        'itemtype' => [Account::class, Resource::class, Run::class],
    ]);

    Config::deleteConfigurationValues(
        PLUGIN_GLPICLOUD_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    foreach (['cloudsync', 'cloudprune'] as $task) {
        $cron = new CronTask();
        if ($cron->getFromDBbyName(Sync::class, $task)) {
            $cron->delete(['id' => $cron->getID()]);
        }
    }

    Statement::uninstall();

    foreach (['plugin_glpicloud_resource', 'plugin_glpicloud_account', 'plugin_glpicloud_config'] as $right) {
        ProfileRight::deleteProfileRights([$right]);
    }

    return true;
}
