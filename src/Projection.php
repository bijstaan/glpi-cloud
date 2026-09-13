<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use Cluster;
use CommonDBTM;
use Computer;
use DatabaseInstance;
use DBmysql;
use Plugin;
use Throwable;

/**
 * Cloud resources as native GLPI assets.
 *
 * Milestone 4. Until now every resource lived only in this plugin's own generic
 * table, which is the right *canonical* home and the wrong place to be found
 * from: an entity's file server is an Azure VM, and the technician looking for
 * it opens the Computer list.
 *
 * ## Per-type, because projecting everything would wreck the asset list
 *
 * {@see MAP} is deliberately short. A cloud estate's long tail — public IPs,
 * snapshots, DNS zones, app service plans — has no native equivalent, and
 * inventing one by pushing it all into `Computer` would turn the asset list
 * into something nobody could use for its original purpose. Three families
 * project, because three families genuinely *are* the native thing:
 *
 *  - a VM, a VPS or a dedicated server is a {@see Computer};
 *  - a managed database is a {@see DatabaseInstance};
 *  - a Kubernetes cluster is a {@see Cluster}.
 *
 * Everything else keeps its generic row and is reached through this plugin's
 * own pages, which is what that table is for.
 *
 * ## Adopted, not duplicated
 *
 * The failure this class exists to avoid: a VM that also runs a glpi-osquery
 * agent becoming *two* assets, so half the estate's facts hang off one and half
 * off the other. Before creating anything, {@see adopt()} looks for the asset
 * GLPI already has — through an osquery agent's hardware UUID first, because
 * that is the strongest identifier either side holds, then the native `uuid`
 * column, then an exact name within the entity. What matched is recorded on the
 * resource, so a wrong adoption is diagnosable rather than mysterious.
 *
 * An asset that another resource has already claimed is never adopted twice.
 *
 * ## Reversible, and the asymmetry that makes it safe
 *
 * Turning projection off must not delete somebody's inventory. So the plugin
 * records **whether it created the asset or adopted an existing one**
 * (`projected_owned`), and retraction treats the two differently:
 *
 *  - an asset this plugin created is put in the trash — recoverable, not purged;
 *  - an asset it merely adopted is only *unlinked*. It was GLPI's before this
 *    plugin arrived and it stays GLPI's afterwards.
 *
 * Getting that backwards once would be unrecoverable, which is why it is a
 * stored column rather than a heuristic applied at deletion time.
 *
 * ## Nothing here is a source of truth
 *
 * Projection writes name, state and a back-reference, and nothing else. It does
 * not own serials, models, users or locations — a technician who corrects a
 * projected Computer must not have that correction overwritten on the next
 * sweep. The provider is authoritative about the resource; GLPI is
 * authoritative about the asset.
 */
final class Projection
{
    /**
     * Core resource type => the native itemtype it projects onto.
     *
     * Keyed on this plugin's own vocabulary ({@see Normalise}), not on any
     * provider's type strings, so a new provider that yields `virtualmachine`
     * projects without touching this file.
     *
     * @var array<string,class-string<CommonDBTM>>
     */
    public const MAP = [
        'virtualmachine'    => Computer::class,
        'vps'               => Computer::class,
        'dedicatedserver'   => Computer::class,

        'sqlserver'         => DatabaseInstance::class,
        'sqldatabase'       => DatabaseInstance::class,
        'mysqlserver'       => DatabaseInstance::class,
        'postgresqlserver'  => DatabaseInstance::class,
        'cosmosaccount'     => DatabaseInstance::class,
        'redis'             => DatabaseInstance::class,
        'databasecluster'   => DatabaseInstance::class,

        'kubernetescluster' => Cluster::class,
    ];

    /** How the asset was found, stored for diagnosis. */
    public const BY_OSQUERY = 'osquery';
    public const BY_UUID    = 'uuid';
    public const BY_NAME    = 'name';
    public const CREATED    = 'created';

    private const OSQUERY_TABLE = 'glpi_plugin_glpiosquery_agents';

    /** Stamped into an asset this plugin created, so a human reading it knows. */
    public const MARKER = 'Created by glpi-cloud from a cloud resource.';

    /** The types projection is switched on for right now. @return string[] */
    public static function enabledTypes(): array
    {
        if ((int) Settings::get('projection_enabled') !== 1) {
            return [];
        }

        $configured = array_filter(array_map(
            'trim',
            explode(',', (string) Settings::get('projection_types'))
        ));

        // An empty list means "every type that has a mapping" rather than
        // "none": a setting nobody has touched should do the thing the feature
        // was switched on for.
        return $configured === []
            ? array_keys(self::MAP)
            : array_values(array_intersect($configured, array_keys(self::MAP)));
    }

    /**
     * Project every projectable resource of one account.
     *
     * Run once at the end of a sweep rather than inside {@see Sync::store()},
     * for two reasons. It keeps the store path — the hot one, run per resource
     * per provider request — free of native asset writes. And it means turning
     * projection *on* back-fills the whole account on the next sweep, instead
     * of only ever projecting resources that happened to change afterwards,
     * which is the shape of bug somebody discovers six months later with half
     * an estate projected.
     *
     * Disappeared resources are skipped but not retracted: a resource gone for
     * an hour is usually a provider hiccup, and the plugin already keeps them
     * for `keep_disappeared_days`. Retraction happens at prune time, when the
     * resource is actually being removed.
     *
     * `created` and `adopted` count what *this sweep did*, not what the estate
     * looks like afterwards — a steady-state sweep reports three projected and
     * nothing created, which is the number somebody reading a cron log needs.
     * Counting by how the link was originally made would report "created: 3"
     * forever, on a run that created nothing.
     *
     * @return array{projected:int,adopted:int,created:int,linked:int}
     */
    public static function sweep(int $accounts_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $counts = ['projected' => 0, 'adopted' => 0, 'created' => 0, 'linked' => 0];
        $types  = self::enabledTypes();

        if ($types === []) {
            return $counts;
        }

        foreach (
            $DB->request([
                'FROM'  => Resource::getTable(),
                'WHERE' => [
                    'plugin_glpicloud_accounts_id' => $accounts_id,
                    'type'                         => $types,
                    'disappeared_at'               => null,
                ],
            ]) as $row
        ) {
            $result = self::apply((array) $row);

            if ($result === null) {
                continue;
            }

            $counts['projected']++;

            if (!$result['fresh']) {
                $counts['linked']++;
                continue;
            }

            $counts[$result['how'] === self::CREATED ? 'created' : 'adopted']++;
        }

        return $counts;
    }

    public static function itemtypeFor(string $type): ?string
    {
        return self::MAP[$type] ?? null;
    }

    /**
     * Bring one resource's native asset in step.
     *
     * Called from the sweep for every resource that was seen. Returns the pair
     * now stored on the resource row, or nulls when this type does not project.
     *
     * Never throws: a projection failure must cost the projection, not the
     * sweep. A sweep that abandons an account because one VM had a name GLPI
     * would not accept loses the other four hundred resources too.
     *
     * @param array<string,mixed> $resource a row from the resources table
     * @return array{itemtype:string,items_id:int,how:string,fresh:bool}|null
     *         `fresh` is whether the link was established by *this* call
     */
    public static function apply(array $resource): ?array
    {
        $type     = (string) ($resource['type'] ?? '');
        $itemtype = self::itemtypeFor($type);

        if ($itemtype === null || !in_array($type, self::enabledTypes(), true)) {
            return null;
        }

        try {
            return self::project($resource, $itemtype);
        } catch (Throwable $e) {
            trigger_error(
                sprintf(
                    'glpicloud: could not project resource #%d (%s): %s',
                    (int) ($resource['id'] ?? 0),
                    $type,
                    $e->getMessage()
                ),
                E_USER_WARNING
            );

            return null;
        }
    }

    /**
     * @param array<string,mixed> $resource
     * @return array{itemtype:string,items_id:int,how:string,fresh:bool}|null
     */
    private static function project(array $resource, string $itemtype): ?array
    {
        $resources_id = (int) $resource['id'];
        $entities_id  = (int) $resource['entities_id'];
        $name         = self::assetName($resource);

        // Already linked, and the asset still exists: update in place. The
        // existence check is not paranoia — a technician may have purged the
        // asset since, and re-creating it silently would undo their decision
        // every six hours until somebody worked out why.
        $existing = self::linkedItem($resource, $itemtype);

        if ($existing !== null) {
            self::refresh($existing, $resource, $name);

            return [
                'itemtype' => $itemtype,
                'items_id' => (int) $existing->getID(),
                // An older row written before `projected_how` existed reports
                // what it is: this plugin's own asset. Empty would be counted
                // as an adoption and understate what retraction will trash.
                'how'      => ((string) ($resource['projected_how'] ?? '')) ?: self::CREATED,
                'fresh'    => false,
            ];
        }

        $adopted = self::adopt($itemtype, $resource, $entities_id, $name);

        if ($adopted !== null) {
            [$items_id, $how] = $adopted;

            $item = new $itemtype();
            if ($item->getFromDB($items_id)) {
                self::refresh($item, $resource, $name);
            }

            self::stamp($resources_id, $itemtype, $items_id, $how, false);

            return ['itemtype' => $itemtype, 'items_id' => $items_id, 'how' => $how, 'fresh' => true];
        }

        $items_id = self::create($itemtype, $resource, $entities_id, $name);

        if ($items_id <= 0) {
            return null;
        }

        self::stamp($resources_id, $itemtype, $items_id, self::CREATED, true);

        return ['itemtype' => $itemtype, 'items_id' => $items_id, 'how' => self::CREATED, 'fresh' => true];
    }

    /**
     * Undo the projection of one resource.
     *
     * The asymmetry described in the class docblock lives here. `$purge` is
     * never offered: an asset this plugin created goes to the trash so somebody
     * can get it back, and an adopted one is not this plugin's to delete at all.
     */
    public static function retract(int $resources_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'FROM'  => Resource::getTable(),
            'WHERE' => ['id' => $resources_id],
        ])->current();

        if (!is_array($row)) {
            return;
        }

        $itemtype = (string) ($row['projected_itemtype'] ?? '');
        $items_id = (int) ($row['projected_items_id'] ?? 0);

        if ($itemtype !== '' && $items_id > 0 && (int) ($row['projected_owned'] ?? 0) === 1) {
            if (is_a($itemtype, CommonDBTM::class, true)) {
                $item = new $itemtype();
                if ($item->getFromDB($items_id) && (int) ($item->fields['is_deleted'] ?? 0) === 0) {
                    $item->delete(['id' => $items_id]);
                }
            }
        }

        $DB->update(
            Resource::getTable(),
            ['projected_itemtype' => '', 'projected_items_id' => 0, 'projected_how' => '', 'projected_owned' => 0],
            ['id' => $resources_id]
        );
    }

    /**
     * The asset a resource is already linked to, if it is still there.
     *
     * @param array<string,mixed> $resource
     */
    private static function linkedItem(array $resource, string $itemtype): ?CommonDBTM
    {
        if ((string) ($resource['projected_itemtype'] ?? '') !== $itemtype) {
            return null;
        }

        $items_id = (int) ($resource['projected_items_id'] ?? 0);
        if ($items_id <= 0) {
            return null;
        }

        $item = new $itemtype();

        return $item->getFromDB($items_id) ? $item : null;
    }

    /**
     * Find the asset GLPI already has for this resource.
     *
     * Strongest identifier first. Each candidate is rejected if another
     * resource has already claimed it, so two cloud VMs that happen to share a
     * name cannot collapse onto one Computer.
     *
     * @param array<string,mixed> $resource
     * @return array{0:int,1:string}|null [items_id, how]
     */
    private static function adopt(string $itemtype, array $resource, int $entities_id, string $name): ?array
    {
        $uuid = self::hardwareUuid($resource);

        if ($itemtype === Computer::class && $uuid !== '') {
            $items_id = self::osqueryComputer($uuid);
            if ($items_id > 0 && !self::claimed($itemtype, $items_id, (int) $resource['id'])) {
                return [$items_id, self::BY_OSQUERY];
            }

            $items_id = self::byColumn($itemtype, 'uuid', $uuid, $entities_id);
            if ($items_id > 0 && !self::claimed($itemtype, $items_id, (int) $resource['id'])) {
                return [$items_id, self::BY_UUID];
            }
        }

        // Name matching is the weakest rule and the one most likely to be
        // wanted: an estate that already created "web-prod-01" by hand does
        // not want a second one. Exact, within the entity, and only when exactly
        // one asset matches — two candidates mean the answer is genuinely
        // ambiguous and guessing would merge two entities' records.
        if ($name !== '') {
            $items_id = self::byColumn($itemtype, 'name', $name, $entities_id);
            if ($items_id > 0 && !self::claimed($itemtype, $items_id, (int) $resource['id'])) {
                return [$items_id, self::BY_NAME];
            }
        }

        return null;
    }

    /**
     * The Computer an osquery agent with this hardware UUID is linked to.
     *
     * Guarded on the plugin being active *and* the table existing: this is a
     * read of another plugin's schema, exactly like glpi-signal's netscan
     * bridge, and it must degrade to "no match" rather than to a fatal on an
     * instance where glpi-osquery was never installed.
     */
    private static function osqueryComputer(string $uuid): int
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!Plugin::isPluginActive('glpiosquery') || !$DB->tableExists(self::OSQUERY_TABLE)) {
            return 0;
        }

        foreach (
            $DB->request([
                'SELECT' => ['items_id'],
                'FROM'   => self::OSQUERY_TABLE,
                'WHERE'  => [
                    'hardware_uuid' => $uuid,
                    'itemtype'      => Computer::class,
                    'is_deleted'    => 0,
                ],
                'LIMIT'  => 2,
            ]) as $row
        ) {
            return (int) $row['items_id'];
        }

        return 0;
    }

    /** One asset matching a column exactly within an entity, or 0 when 0 or 2+ do. */
    private static function byColumn(string $itemtype, string $column, string $value, int $entities_id): int
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($value === '') {
            return 0;
        }

        $table = $itemtype::getTable();

        if (!$DB->fieldExists($table, $column)) {
            return 0;
        }

        $found = [];

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => $table,
                'WHERE'  => [$column => $value, 'entities_id' => $entities_id, 'is_deleted' => 0],
                'LIMIT'  => 2,
            ]) as $row
        ) {
            $found[] = (int) $row['id'];
        }

        return count($found) === 1 ? $found[0] : 0;
    }

    /** Has another resource already projected onto this asset? */
    private static function claimed(string $itemtype, int $items_id, int $except_resources_id): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Resource::getTable(),
                'WHERE'  => [
                    'projected_itemtype' => $itemtype,
                    'projected_items_id' => $items_id,
                    ['NOT' => ['id' => $except_resources_id]],
                ],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $resource
     * @return int the new asset's id, or 0
     */
    private static function create(string $itemtype, array $resource, int $entities_id, string $name): int
    {
        $item = new $itemtype();

        $input = [
            'name'         => $name,
            'entities_id'  => $entities_id,
            'is_recursive' => (int) ($resource['is_recursive'] ?? 0),
            'comment'      => self::comment($resource),
            // Core's word for "an inventory tool maintains this", which is what
            // stops a technician being surprised when a field is overwritten
            // and what keeps it out of manual-entry workflows.
            'is_dynamic'   => 1,
        ];

        if ($itemtype === Computer::class) {
            $uuid = self::hardwareUuid($resource);
            if ($uuid !== '') {
                $input['uuid'] = $uuid;
            }
        }

        $items_id = $item->add($input);

        return is_int($items_id) ? $items_id : 0;
    }

    /**
     * Bring the fields projection owns — and only those — in step.
     *
     * A no-op update is skipped rather than written, because every write here
     * lands in the asset's history, and a resource whose name never changes
     * would otherwise accumulate one history row per sweep per asset until the
     * history is useless.
     *
     * @param array<string,mixed> $resource
     */
    private static function refresh(CommonDBTM $item, array $resource, string $name): void
    {
        $input = [];

        if ($name !== '' && (string) ($item->fields['name'] ?? '') !== $name) {
            $input['name'] = $name;
        }

        $comment = self::comment($resource);
        if ((string) ($item->fields['comment'] ?? '') !== $comment) {
            $input['comment'] = $comment;
        }

        if ($input === []) {
            return;
        }

        $item->update($input + ['id' => $item->getID()]);
    }

    /**
     * What the asset is called.
     *
     * The provider's name, or the native id when a provider gave none — an
     * asset called "" is unfindable, and the native id at least matches what
     * the provider's own console shows.
     *
     * @param array<string,mixed> $resource
     */
    private static function assetName(array $resource): string
    {
        $name = trim((string) ($resource['name'] ?? ''));

        return $name !== '' ? $name : trim((string) ($resource['native_id'] ?? ''));
    }

    /**
     * The provenance note written onto the asset.
     *
     * Deliberately prose rather than a machine-readable tag: the machine-
     * readable link is the `projected_*` columns on the resource row, and a
     * person who finds this Computer in the asset list without knowing this
     * plugin exists needs a sentence, not a marker they cannot look up.
     *
     * @param array<string,mixed> $resource
     */
    private static function comment(array $resource): string
    {
        return sprintf(
            "%s\n%s: %s\n%s",
            self::MARKER,
            ucfirst((string) ($resource['provider'] ?? 'cloud')),
            (string) ($resource['type'] ?? ''),
            (string) ($resource['native_id'] ?? '')
        );
    }

    /**
     * A hardware UUID for this resource, if the provider gave one.
     *
     * Azure exposes `properties.vmId`, which is the same UUID the guest reports
     * to osquery as `system_info.uuid` — that identity is the entire reason the
     * osquery dedupe works at all. Other providers may put it elsewhere or
     * nowhere; a resource with none simply falls through to the weaker rules.
     *
     * @param array<string,mixed> $resource
     */
    private static function hardwareUuid(array $resource): string
    {
        $attributes = $resource['attributes'] ?? null;

        if (is_string($attributes)) {
            $attributes = json_decode($attributes, true);
        }

        if (!is_array($attributes)) {
            return '';
        }

        $candidates = [
            $attributes['properties']['vmId'] ?? null,
            $attributes['vm_id'] ?? null,
            $attributes['uuid'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            // Shaped like a UUID or not at all. A provider id that happens to
            // live under `uuid` and is not one would match nothing, but it
            // would also be written into a Computer's `uuid` column on create,
            // which is worse than leaving it empty.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate)) {
                return strtolower($candidate);
            }
        }

        return '';
    }

    /** Record the link on the resource row. */
    private static function stamp(int $resources_id, string $itemtype, int $items_id, string $how, bool $owned): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->update(
            Resource::getTable(),
            [
                'projected_itemtype' => $itemtype,
                'projected_items_id' => $items_id,
                'projected_how'      => $how,
                'projected_owned'    => $owned ? 1 : 0,
            ],
            ['id' => $resources_id]
        );
    }
}
