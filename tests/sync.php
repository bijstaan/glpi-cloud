<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The parts that need a database: the sweep, the lifecycle, and the money.
 *
 * **Not run by `tests/run.sh`.** It boots GLPI and writes to this plugin's own
 * tables, so it is left for the maintainer to run deliberately, on an instance
 * they are willing to have it touch:
 *
 *   docker compose -p glpi exec glpi php /var/www/glpi/plugins/glpicloud/tests/sync.php
 *
 * What it covers is the half that cannot be exercised from fixtures alone, and
 * the half where a mistake is expensive rather than merely wrong:
 *
 *  - a sweep that changed nothing writes nothing;
 *  - a resource that vanished is marked gone — but **only** by a sweep that
 *    completed, because the alternative is that one throttled request deletes a
 *    entity's inventory and the next sweep "rediscovers" it with today's
 *    first-seen date;
 *  - a truncated sweep keeps its place, and the next one resumes from it;
 *  - re-querying a billing month cannot double-count it;
 *  - a provisional month never reaches a contract.
 *
 * ## Cleaning up
 *
 * Everything it writes is named `ZZTEST-` and removed at the end, including the
 * native Contract and ContractCost rows the rollup produces. The plugin's
 * settings are snapshotted and restored as **raw `glpi_configs` rows through
 * $DB** — never through the Config API, which encrypts declared secrets on the
 * way in and hands back ciphertext on the way out, so a restore written the
 * obvious way re-encrypts what it restores.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

if (!defined('PLUGIN_GLPICLOUD_CONFIG_CONTEXT')) {
    require_once dirname(__DIR__) . '/setup.php';
}

// Required rather than autoloaded: GLPI registers a plugin's namespace only for
// an *active* plugin, and this is worth running before anybody activates it.
foreach (
    ['Normalise', 'Provider', 'Registry', 'Settings', 'Account', 'Resource', 'Run',
        'Checkpoint', 'History', 'Costs', 'Mapping', 'Sync'] as $class
) {
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}

require_once __DIR__ . '/fixture-provider.php';

use GlpiPlugin\Glpicloud\Account;
use GlpiPlugin\Glpicloud\Checkpoint;
use GlpiPlugin\Glpicloud\Costs;
use GlpiPlugin\Glpicloud\History;
use GlpiPlugin\Glpicloud\Registry;
use GlpiPlugin\Glpicloud\Resource;
use GlpiPlugin\Glpicloud\Run;
use GlpiPlugin\Glpicloud\Sync;

/** @var DBmysql $DB */
global $DB, $PLUGIN_HOOKS;

if (!$DB->tableExists(Resource::getTable())) {
    fwrite(STDERR, "glpicloud is not installed on this instance; install the plugin first.\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$notes  = [];

function is_same(mixed $actual, mixed $expected, string $what): void
{
    global $passed, $failed, $notes;

    if ($actual === $expected) {
        $passed++;

        return;
    }

    $failed++;
    $notes[] = sprintf(
        "%s\n      expected: %s\n      actual:   %s",
        $what,
        json_encode($expected, JSON_UNESCAPED_SLASHES),
        json_encode($actual, JSON_UNESCAPED_SLASHES)
    );
}

function is_true(bool $condition, string $what): void
{
    is_same($condition, true, $what);
}

// A session is needed only by ContractCost, which is core CommonDBTM. Nothing
// here creates an entity, which is the case a hand-built session breaks.
$_SESSION['glpiactive_entity']       = 0;
$_SESSION['glpiactiveentities']      = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpi_currenttime']        = date('Y-m-d H:i:s');
$_SESSION['glpiname']                = 'glpicloud-tests';

// ------------------------------------------------------- settings, raw

$config_before = [];
foreach (
    $DB->request([
        'FROM'  => 'glpi_configs',
        'WHERE' => ['context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT],
    ]) as $row
) {
    $config_before[(string) $row['name']] = ['id' => (int) $row['id'], 'value' => $row['value']];
}

$set = static function (string $name, string $value) use ($DB, $config_before): void {
    if (isset($config_before[$name])) {
        $DB->update('glpi_configs', ['value' => $value], ['id' => $config_before[$name]['id']]);

        return;
    }

    $DB->insert('glpi_configs', [
        'context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT,
        'name'    => $name,
        'value'   => $value,
    ]);
};

$set('enabled', '1');
$set('cost_enabled', '1');
$set('cost_rollup', '1');

// ------------------------------------------------------- fixtures in place

CloudFixture::load();

$PLUGIN_HOOKS['glpicloud_providers'] = ['glpicloudfixture' => [CloudFixture::class, 'describe']];
Registry::reset();

$contract = new Contract();
$contracts_id = (int) $contract->add([
    'name'        => 'ZZTEST-cloud-contract',
    'entities_id' => 0,
]);

$DB->insert(Account::getTable(), [
    'name'          => 'ZZTEST-cloud-account',
    'provider'      => 'fixture',
    'credentials'   => (new GLPIKey())->encrypt((string) json_encode(['account_id' => 'acme', 'secret' => 'shh'])),
    'entities_id'   => 0,
    'contracts_id'  => $contracts_id,
    'is_active'     => 1,
    'date_creation' => date('Y-m-d H:i:s'),
]);

$accounts_id = (int) $DB->insertId();
$account     = new Account();
$account->getFromDB($accounts_id);

$resources = static function () use ($DB, $accounts_id): array {
    $out = [];

    foreach (
        $DB->request([
            'FROM'  => Resource::getTable(),
            'WHERE' => ['plugin_glpicloud_accounts_id' => $accounts_id],
        ]) as $row
    ) {
        $out[(string) $row['native_id']] = $row;
    }

    return $out;
};

/**
 * When a resource was last reported gone.
 *
 * A cleared `disappeared_at` is SQL NULL, and a missing row is a different
 * failure entirely — `?? ''` would quietly report both as "present", which is
 * the assertion this file exists to make.
 */
$gone_at = static function (string $native_id) use (&$resources): string {
    $rows = $resources();

    if (!isset($rows[$native_id])) {
        return 'NO SUCH ROW';
    }

    return (string) ($rows[$native_id]['disappeared_at'] ?? '');
};

$history_count = static function () use ($DB, $accounts_id): int {
    $ids = [];

    foreach (
        $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Resource::getTable(),
            'WHERE'  => ['plugin_glpicloud_accounts_id' => $accounts_id],
        ]) as $row
    ) {
        $ids[] = (int) $row['id'];
    }

    return $ids === [] ? 0 : (int) countElementsInTable(History::TABLE, ['plugin_glpicloud_resources_id' => $ids]);
};

try {
    // ------------------------------------------------------- the first sweep

    $first = Sync::account($account);

    is_same($first['status'], Run::OK, 'a clean sweep reports ok');
    is_same($first['new'], 4, 'four resources are new');
    is_same($first['seen'], 4, 'four were seen');
    is_same($first['changed'], 0, 'nothing changed on a first sweep');
    is_same(count($resources()), 4, 'and four rows exist');

    $rows = $resources();
    is_same((string) $rows['/subs/sub-a/vm/web-01']['state'], 'running', 'state is stored');
    is_same((string) $rows['/subs/sub-a/vm/web-01']['service'], 'compute', 'service is stored');
    is_true((string) $rows['/subs/sub-a/vm/web-01']['first_seen'] !== '', 'first_seen is stamped');
    is_same((int) $rows['/subs/sub-a/sa/acmebackups']['entities_id'], 0, "the account's entity is inherited");

    // The lapsed subscription in the fixture is inactive; nothing from it is
    // collected, and the sweep does not treat that as an error.
    is_same($first['errors'], [], 'an inactive scope is skipped quietly');

    // ------------------------------------------ a sweep that changed nothing

    $before_history = $history_count();
    $second = Sync::account($account);

    is_same($second['new'], 0, 'a repeat sweep creates nothing');
    is_same($second['changed'], 0, 'and changes nothing');
    is_same($second['seen'], 4, 'while still seeing everything');
    is_same($history_count(), $before_history, 'and writes no history at all');

    // --------------------------------------------------------- a real change

    CloudFixture::set('compute', 'sub-a', '/subs/sub-a/vm/web-01', 'state', 'stopped');

    $third = Sync::account($account);

    is_same($third['changed'], 1, 'one resource changed');
    is_same((string) $resources()['/subs/sub-a/vm/web-01']['state'], 'stopped', 'and the new state is stored');
    is_same($history_count(), $before_history + 1, 'with exactly one history row');

    // ------------------------------------------------------- disappearance

    CloudFixture::remove('compute', 'sub-a', '/subs/sub-a/vm/web-02');

    $fourth = Sync::account($account);

    is_same($fourth['gone'], 1, 'a resource missing from a complete sweep is marked gone');
    is_true($gone_at('/subs/sub-a/vm/web-02') !== '', 'with a date');
    is_same(count($resources()), 4, 'and it is kept, not deleted');

    // ---------------------------------------------------------- and back

    CloudFixture::load();
    CloudFixture::set('compute', 'sub-a', '/subs/sub-a/vm/web-01', 'state', 'stopped');

    $fifth = Sync::account($account);

    is_same($gone_at('/subs/sub-a/vm/web-02'), '', 'a resource that comes back is present again');

    // ------------------------------------- a failed sweep concludes nothing
    //
    // The most important assertion in the file: storage fails, so the storage
    // account must NOT be marked gone even though the sweep did not see it.

    CloudFixture::$failing = ['storage'];
    CloudFixture::remove('compute', 'sub-a', '/subs/sub-a/disk/web-01-os');

    $sixth = Sync::account($account);

    is_same($sixth['status'], Run::PARTIAL, 'a sweep with a failing service is partial');
    is_true($sixth['errors'] !== [], 'and says what failed');
    is_same($gone_at('/subs/sub-a/sa/acmebackups'), '', 'the failed service marks nothing gone');
    is_true($gone_at('/subs/sub-a/disk/web-01-os') !== '', 'while the service that did complete still does');

    CloudFixture::load();
    CloudFixture::$failing = [];

    // ------------------------------------------- out of clock, keeping place
    //
    // A deadline already in the past stops the loop on the first row. Nothing
    // may be concluded from that sweep, and the cursor must survive it.

    $out_of_time = Sync::account($account, ['now' => time() - 3600, 'seconds' => 5]);

    is_true(in_array($out_of_time['status'], [Run::PARTIAL, Run::FAILED], true), 'a sweep that runs out of clock is not reported as ok');
    is_same($out_of_time['gone'], 0, 'and concludes nothing about what is missing');

    // ------------------------------------------------------------ the lock

    $runs_id = Run::start($account);
    is_true($runs_id !== null, 'a run can be opened');
    is_same(Run::start($account), null, 'and a second one is refused while it is open');
    Run::finish((int) $runs_id, Run::OK, [], []);
    is_true(Run::start($account) !== null, 'once it is closed, another may start');

    // -------------------------------------------------------------- money

    $costs = static fn(): array => iterator_to_array($DB->request([
        'FROM'  => Costs::TABLE,
        'WHERE' => ['plugin_glpicloud_accounts_id' => $accounts_id],
    ]));

    Sync::account($account);
    $after_first = count($costs());

    is_same($after_first, 4, 'every cost row is stored, including the unattributed one');

    $unattributed = 0;
    foreach ($costs() as $row) {
        if ((int) $row['plugin_glpicloud_resources_id'] === 0) {
            $unattributed++;
        }
    }
    is_same($unattributed, 1, 'marketplace spend with no resource is kept against the account');

    Sync::account($account);
    is_same(count($costs()), $after_first, 're-querying a month cannot double-count it');

    is_same(Costs::totals($accounts_id, '2026-07'), ['GBP' => 63.74], 'the period total is the sum as billed');
    is_true(Costs::isProvisional($accounts_id, '2026-08'), 'a mid-month figure is flagged provisional');

    $rollups = iterator_to_array($DB->request([
        'FROM'  => ContractCost::getTable(),
        'WHERE' => ['contracts_id' => $contracts_id],
    ]));

    is_same(count($rollups), 1, 'one native contract cost line, for the closed month only');

    $line = reset($rollups);
    is_same((float) $line['cost'], 63.74, 'carrying the period total');
    is_same((string) $line['begin_date'], '2026-07-01', 'from the first of the month');
    is_same((string) $line['end_date'], '2026-07-31', 'to the last');
} finally {
    // ----------------------------------------------------------- cleanup

    $ids = [];
    foreach (
        $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Resource::getTable(),
            'WHERE'  => ['plugin_glpicloud_accounts_id' => $accounts_id],
        ]) as $row
    ) {
        $ids[] = (int) $row['id'];
    }

    if ($ids !== []) {
        $DB->delete(History::TABLE, ['plugin_glpicloud_resources_id' => $ids]);
    }

    foreach ([Resource::getTable(), Run::getTable(), Costs::TABLE, Checkpoint::TABLE] as $table) {
        $DB->delete($table, ['plugin_glpicloud_accounts_id' => $accounts_id]);
    }

    $DB->delete(Account::getTable(), ['id' => $accounts_id]);
    $DB->delete(ContractCost::getTable(), ['contracts_id' => $contracts_id]);
    $DB->delete(Contract::getTable(), ['id' => $contracts_id]);

    // Settings back exactly as they were, raw.
    $DB->delete('glpi_configs', ['context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT]);

    foreach ($config_before as $name => $row) {
        $DB->insert('glpi_configs', [
            'context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT,
            'name'    => $name,
            'value'   => $row['value'],
        ]);
    }
}

if ($failed === 0) {
    printf("%-14s %d passed\n", 'sync', $passed);
    exit(0);
}

printf("%-14s %d passed, %d FAILED\n", 'sync', $passed, $failed);

foreach ($notes as $note) {
    echo '  - ' . $note . "\n";
}

exit(1);
