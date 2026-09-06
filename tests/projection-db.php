<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Projection: adoption, creation, and the retraction asymmetry.
 *
 * **Not run by `tests/run.sh`.** It boots GLPI and writes native assets, so it
 * is left for the maintainer to run deliberately, on an instance they are
 * willing to have it touch:
 *
 *   docker compose -p glpi exec glpi php /var/www/glpi/plugins/glpicloud/tests/projection-db.php
 *
 * tests/projection.php already covers the map and the settings narrowing, which
 * are pure. What is here is everything that writes, and the reason it is worth
 * a suite of its own is that **two of these failures are unrecoverable**:
 *
 *  - adopting an asset another resource already owns merges two customers'
 *    records onto one Computer;
 *  - retracting an *adopted* asset as though this plugin had created it puts
 *    somebody's real, hand-maintained inventory in the trash because they
 *    turned a setting off.
 *
 * Neither is visible in a unit test of the map, and neither is something to
 * find out about from a customer.
 *
 * ## Cleaning up
 *
 * Everything it writes is named `ZZTEST-` and removed at the end, native assets
 * included — purged, not trashed, so a re-run does not adopt last run's
 * leftovers and quietly pass for the wrong reason. Settings are snapshotted and
 * restored as **raw `glpi_configs` rows through $DB**, never through the Config
 * API, which encrypts declared secrets on the way in.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();

if (!defined('PLUGIN_GLPICLOUD_CONFIG_CONTEXT')) {
    require_once dirname(__DIR__) . '/setup.php';
}

foreach (
    ['Normalise', 'Provider', 'Registry', 'Settings', 'Account', 'Resource', 'Run',
        'Checkpoint', 'History', 'Costs', 'Mapping', 'Sync', 'Projection'] as $class
) {
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}

use GlpiPlugin\Glpicloud\Account;
use GlpiPlugin\Glpicloud\Projection;
use GlpiPlugin\Glpicloud\Resource;

/** @var DBmysql $DB */
global $DB;

if (!$DB->tableExists(Resource::getTable())) {
    fwrite(STDERR, "glpicloud is not installed on this instance; install the plugin first.\n");
    exit(2);
}

if (!$DB->fieldExists(Resource::getTable(), 'projected_owned', false)) {
    fwrite(STDERR, "the resources table predates projection; re-run the plugin install.\n");
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

$_SESSION['glpiactive_entity']         = 0;
$_SESSION['glpiactiveentities']        = [0];
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpi_currenttime']          = date('Y-m-d H:i:s');
$_SESSION['glpiname']                  = 'glpicloud-tests';

// ------------------------------------------------------------ settings, raw

$config_before = [];
foreach (
    $DB->request([
        'FROM'  => 'glpi_configs',
        'WHERE' => ['context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT],
    ]) as $row
) {
    $config_before[(string) $row['name']] = ['id' => (int) $row['id'], 'value' => $row['value']];
}

// Looks the row up each time rather than trusting the opening snapshot: this
// suite sets the same key twice, and a closure that remembers "it was absent"
// inserts a second row and hits the unicity key on the second call.
$set = static function (string $name, string $value) use ($DB): void {
    foreach (
        $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_configs',
            'WHERE'  => ['context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT, 'name' => $name],
            'LIMIT'  => 1,
        ]) as $existing
    ) {
        $DB->update('glpi_configs', ['value' => $value], ['id' => (int) $existing['id']]);

        return;
    }

    $DB->insert('glpi_configs', [
        'context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT,
        'name'    => $name,
        'value'   => $value,
    ]);
};

$set('enabled', '1');
$set('projection_enabled', '1');
$set('projection_types', '');

// ------------------------------------------------------------- the account

$DB->insert(Account::getTable(), [
    'name'          => 'ZZTEST-projection-account',
    'provider'      => 'fixture',
    'entities_id'   => 0,
    'is_active'     => 1,
    'date_creation' => date('Y-m-d H:i:s'),
]);

$accounts_id = (int) $DB->insertId();
$account     = new Account();
$account->getFromDB($accounts_id);

/** Insert a resource row directly — the sweep is sync.php's subject, not this one. */
$resource = static function (array $fields) use ($DB, $accounts_id): int {
    $DB->insert(Resource::getTable(), [
        'plugin_glpicloud_accounts_id' => $accounts_id,
        'provider'                     => 'fixture',
        'scope'                        => 'sub-a',
        'service'                      => 'compute',
        'entities_id'                  => 0,
        'first_seen'                   => date('Y-m-d H:i:s'),
        'last_seen'                    => date('Y-m-d H:i:s'),
    ] + $fields);

    return (int) $DB->insertId();
};

$row = static function (int $id) use ($DB): array {
    foreach ($DB->request(['FROM' => Resource::getTable(), 'WHERE' => ['id' => $id]]) as $r) {
        return (array) $r;
    }

    return [];
};

$made = ['Computer' => [], 'DatabaseInstance' => [], 'Cluster' => []];

try {
    // ==================================================== creation, per type

    $vm = $resource([
        'type'      => 'virtualmachine',
        'native_id' => '/subs/sub-a/vm/ZZTEST-web-01',
        'name'      => 'ZZTEST-web-01',
        'state'     => 'running',
        'attributes' => (string) json_encode(['properties' => ['vmId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']]),
    ]);

    $db = $resource([
        'type'      => 'postgresqlserver',
        'service'   => 'database',
        'native_id' => '/subs/sub-a/pg/ZZTEST-pg-01',
        'name'      => 'ZZTEST-pg-01',
    ]);

    $k8s = $resource([
        'type'      => 'kubernetescluster',
        'service'   => 'containers',
        'native_id' => '/subs/sub-a/aks/ZZTEST-k8s-01',
        'name'      => 'ZZTEST-k8s-01',
    ]);

    $disk = $resource([
        'type'      => 'disk',
        'native_id' => '/subs/sub-a/disk/ZZTEST-os',
        'name'      => 'ZZTEST-os-disk',
    ]);

    $counts = Projection::sweep($accounts_id);

    is_same($counts['projected'], 3, 'three of the four resources project');
    is_same($counts['created'], 3, 'and all three were created, nothing to adopt yet');

    foreach ([[$vm, 'Computer'], [$db, 'DatabaseInstance'], [$k8s, 'Cluster']] as [$id, $expected]) {
        $r = $row($id);
        is_same((string) $r['projected_itemtype'], $expected, "a $expected was created for resource #$id");
        is_same((int) $r['projected_owned'], 1, "and this plugin records that it owns it");
        $made[$expected][] = (int) $r['projected_items_id'];
    }

    is_same((string) $row($disk)['projected_itemtype'], '', 'a disk projects onto nothing');

    // The VM's hardware UUID reaches the native column, which is what makes a
    // later inventory of the same machine line up with it rather than duplicate.
    $computer = new Computer();
    $computer->getFromDB($made['Computer'][0]);
    is_same(
        strtolower((string) $computer->fields['uuid']),
        'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        "the provider's VM id is written to the Computer's uuid"
    );

    // ======================================================= idempotence

    $again = Projection::sweep($accounts_id);
    is_same($again['created'], 0, 'a second sweep creates nothing');
    is_same($again['adopted'], 0, 'and adopts nothing');
    is_same($again['linked'], 3, 'the three are reported as already linked');
    is_same($again['projected'], 3, 'and still reports the same three as projected');

    $computers_now = countElementsInTable('glpi_computers', ['name' => 'ZZTEST-web-01', 'is_deleted' => 0]);
    is_same($computers_now, 1, 'one Computer, not one per sweep');

    // =================================================== adoption, by name
    //
    // The case an MSP actually hits: the asset was created by hand before the
    // cloud account was connected.

    $hand = new Computer();
    $hand_id = (int) $hand->add(['name' => 'ZZTEST-adopted-01', 'entities_id' => 0]);
    $made['Computer'][] = $hand_id;

    $adopt = $resource([
        'type'      => 'virtualmachine',
        'native_id' => '/subs/sub-a/vm/ZZTEST-adopted-01',
        'name'      => 'ZZTEST-adopted-01',
    ]);

    Projection::sweep($accounts_id);
    $r = $row($adopt);

    is_same((int) $r['projected_items_id'], $hand_id, 'an existing Computer of the same name is adopted');
    is_same((string) $r['projected_how'], Projection::BY_NAME, 'and how it was matched is recorded');
    is_same((int) $r['projected_owned'], 0, 'and it is NOT recorded as this plugin\'s to delete');

    is_same(
        countElementsInTable('glpi_computers', ['name' => 'ZZTEST-adopted-01', 'is_deleted' => 0]),
        1,
        'adoption does not leave a duplicate behind'
    );

    // ============================================= adoption, via glpi-osquery
    //
    // The case the issue names: a VM that also runs an agent must be one asset,
    // not two. The agent's hardware UUID is the strongest identifier either
    // side holds, and it is checked before name or native uuid.

    $osq_table = 'glpi_plugin_glpiosquery_agents';

    if (Plugin::isPluginActive('glpiosquery') && $DB->tableExists($osq_table)) {
        $agent_host = new Computer();
        $agent_host_id = (int) $agent_host->add([
            'name'        => 'ZZTEST-inventoried-by-osquery',
            'entities_id' => 0,
        ]);
        $made['Computer'][] = $agent_host_id;

        $DB->insert($osq_table, [
            'name'          => 'ZZTEST-osq-agent',
            'node_key_hash' => str_repeat('a', 64),
            'deviceid'      => 'ZZTEST-osq-agent',
            'hardware_uuid' => '11111111-2222-3333-4444-555555555555',
            'itemtype'      => 'Computer',
            'items_id'      => $agent_host_id,
            'entities_id'   => 0,
            'is_active'     => 1,
            'is_deleted'    => 0,
        ]);
        $osq_agent_id = (int) $DB->insertId();

        // The cloud side calls it something else entirely — which is the point.
        // Nothing but the UUID connects these two records.
        $dual = $resource([
            'type'       => 'virtualmachine',
            'native_id'  => '/subs/sub-a/vm/ZZTEST-dual-homed',
            'name'       => 'ZZTEST-vm-named-differently',
            'attributes' => (string) json_encode([
                'properties' => ['vmId' => '11111111-2222-3333-4444-555555555555'],
            ]),
        ]);

        Projection::sweep($accounts_id);
        $r = $row($dual);

        is_same(
            (int) $r['projected_items_id'],
            $agent_host_id,
            'a VM running an osquery agent adopts that agent\'s Computer'
        );
        is_same((string) $r['projected_how'], Projection::BY_OSQUERY, 'and records that the agent matched it');
        is_same((int) $r['projected_owned'], 0, 'an inventoried machine is never this plugin\'s to delete');

        is_same(
            countElementsInTable('glpi_computers', [
                'name'       => ['LIKE', 'ZZTEST-%'],
                'is_deleted' => 0,
                'uuid'       => '11111111-2222-3333-4444-555555555555',
            ]),
            0,
            'and no second Computer was created for the same machine'
        );

        $DB->delete($osq_table, ['id' => $osq_agent_id]);
    } else {
        $notes[] = 'SKIPPED: glpi-osquery is not active, so the dedupe path was not exercised';
    }

    // ============================================ an asset is claimed once

    $rival = $resource([
        'type'      => 'virtualmachine',
        'native_id' => '/subs/sub-b/vm/ZZTEST-adopted-01',
        'name'      => 'ZZTEST-adopted-01',
        'scope'     => 'sub-b',
    ]);

    Projection::sweep($accounts_id);
    $r = $row($rival);

    is_true(
        (int) $r['projected_items_id'] !== $hand_id,
        'a second resource with the same name does not adopt an already-claimed asset'
    );
    is_same((string) $r['projected_how'], Projection::CREATED, 'it gets its own asset instead');
    $made['Computer'][] = (int) $r['projected_items_id'];

    // ================================================ the retraction asymmetry
    //
    // The two rules that must never swap. Everything above is a convenience;
    // this is the part that damages an estate if it is wrong.

    $created_id = (int) $row($vm)['projected_items_id'];

    Projection::retract($vm);
    Projection::retract($adopt);

    $created = new Computer();
    $created->getFromDB($created_id);
    is_same((int) $created->fields['is_deleted'], 1, 'an asset this plugin created is trashed on retraction');
    is_true(
        countElementsInTable('glpi_computers', ['id' => $created_id]) === 1,
        'trashed, not purged — it is recoverable'
    );

    $kept = new Computer();
    is_true($kept->getFromDB($hand_id), 'an adopted asset still exists after retraction');
    is_same((int) $kept->fields['is_deleted'], 0, 'and is NOT trashed — it was never this plugin\'s');

    is_same((string) $row($adopt)['projected_itemtype'], '', 'the link is cleared either way');
    is_same((int) $row($adopt)['projected_owned'], 0, 'and so is the ownership flag');

    // ================================================== the master switch

    $set('projection_enabled', '0');
    is_same(Projection::enabledTypes(), [], 'nothing projects while the switch is off');
    is_same(Projection::sweep($accounts_id)['projected'], 0, 'and a sweep is a no-op');

    $set('projection_enabled', '1');
    $set('projection_types', 'kubernetescluster');
    is_same(Projection::enabledTypes(), ['kubernetescluster'], 'the narrowing is honoured');
    is_same(
        Projection::sweep($accounts_id)['projected'],
        1,
        'and a narrowed sweep touches only the narrowed type'
    );
} finally {
    // ------------------------------------------------------------- cleanup

    $ids = [];
    foreach (
        $DB->request([
            'SELECT' => ['id', 'projected_itemtype', 'projected_items_id'],
            'FROM'   => Resource::getTable(),
            'WHERE'  => ['plugin_glpicloud_accounts_id' => $accounts_id],
        ]) as $r
    ) {
        $ids[] = (int) $r['id'];

        $itemtype = (string) $r['projected_itemtype'];
        if ($itemtype !== '' && isset($made[$itemtype])) {
            $made[$itemtype][] = (int) $r['projected_items_id'];
        }
    }

    if ($ids !== []) {
        $DB->delete(\GlpiPlugin\Glpicloud\History::TABLE, ['plugin_glpicloud_resources_id' => $ids]);
    }

    $DB->delete(Resource::getTable(), ['plugin_glpicloud_accounts_id' => $accounts_id]);
    $DB->delete(Account::getTable(), ['id' => $accounts_id]);

    // Purged rather than trashed: a re-run must not adopt what the last one
    // left behind and pass for the wrong reason.
    foreach ($made as $itemtype => $item_ids) {
        foreach (array_unique(array_filter($item_ids)) as $item_id) {
            $item = new $itemtype();
            if ($item->getFromDB($item_id)) {
                $item->delete(['id' => $item_id], true);
            }
        }
    }

    $DB->delete('glpi_configs', ['context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT]);

    foreach ($config_before as $name => $r) {
        $DB->insert('glpi_configs', [
            'context' => PLUGIN_GLPICLOUD_CONFIG_CONTEXT,
            'name'    => $name,
            'value'   => $r['value'],
        ]);
    }
}

if ($failed === 0) {
    printf("%-14s %d passed\n", 'projection-db', $passed);
    exit(0);
}

printf("%-14s %d passed, %d FAILED\n", 'projection-db', $passed, $failed);

foreach ($notes as $note) {
    echo '  - ' . $note . "\n";
}

exit(1);
