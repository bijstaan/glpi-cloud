<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use GlpiPlugin\Glpiai\Tool;

/**
 * Cloud inventory, offered to glpi-ai's assistant as tools.
 *
 * The estate a model could reach before this stopped at the edge of the
 * building. Every native tool reads the CMDB, and for a customer whose file
 * server is an Azure VM and whose backups are a storage account, the CMDB is
 * half the answer — "no asset matches" is a true statement about GLPI and a
 * false one about their estate.
 *
 * Three tools, and the last two are the ones nobody expects to need until
 * they do:
 *
 *  - **`cloud_resources`** — what is running in this customer's accounts, what
 *    state it is in, and which GLPI asset it is projected onto. The last part
 *    matters: a resource that projects onto a Computer is reachable by every
 *    other tool here, and one that does not is invisible to all of them.
 *  - **`cloud_spend`** — what it cost, per account and period, and whether the
 *    figure is still provisional. Support questions turn into commercial ones
 *    faster in the cloud than anywhere else: "can we just spin up another one"
 *    has an answer, and it is a number.
 *  - **`cloud_changes`** — what moved. A cloud estate changes under you
 *    without a change request: somebody resizes a VM at four in the afternoon,
 *    a resource is deleted by a pipeline, a machine quietly stops. Every sync
 *    records the difference, and this is the only place in the suite that can
 *    answer "did anything change on their side" for an estate nobody here
 *    administers. It is the cloud half of `item_history`, and the first thing
 *    to ask when something worked yesterday.
 *
 * **Nothing here acts on the cloud, and nothing ever should from a tool.**
 * Starting, stopping, resizing or deleting somebody's VM is not a read that
 * went wrong, it is an outage; the credentials this plugin holds are read-only
 * by design, and a write tool would be an argument for widening them. If a
 * technician needs a resource stopped, the answer is the provider's own
 * console with their own name against it.
 *
 * Costs are gated on the *account* right rather than the resource one, which
 * mirrors this plugin's own split: seeing that a VM exists and seeing what the
 * customer pays for it are different permissions, and an MSP's first-line
 * profile usually holds only the first.
 */
final class AiTools
{
    /** Resources returned by one call. */
    private const MAX_RESOURCES = 20;

    /** Change rows returned by one call. */
    private const MAX_CHANGES = 30;

    /** @return Tool[] */
    public static function all(): array
    {
        return [self::resources(), self::spend(), self::changes()];
    }

    // -------------------------------------------------------------- changes

    private static function changes(): Tool
    {
        return new Tool(
            name: 'cloud_changes',
            description: 'What has changed in a customer\'s cloud estate recently: resources '
                . 'resized, stopped, started, renamed, retagged, appearing or disappearing, '
                . 'with the old and new value and when the sync saw it. Ask this whenever '
                . 'something worked yesterday and does not today, before blaming an on-premises '
                . 'change for a fault in a hybrid estate, and when a bill jumps. Nobody raises '
                . 'a change request for a resize somebody made in a portal — this is the only '
                . 'record that it happened.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'days'        => [
                        'type'        => 'integer',
                        'description' => 'How far back to look. Defaults to 7, maximum 90.',
                    ],
                    'resource_id' => [
                        'type'        => 'integer',
                        'description' => 'One resource\'s history instead of the whole estate.',
                    ],
                    'limit'       => [
                        'type'        => 'integer',
                        'description' => 'Rows, 1-30. Defaults to 20.',
                    ],
                ],
            ],
            handler: [self::class, 'runChanges'],
            right: Resource::$rightname,
            source: 'glpicloud',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runChanges(array $arguments = [], mixed $context = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $days  = max(1, min(90, (int) ($arguments['days'] ?? 7)));
        $limit = max(1, min(self::MAX_CHANGES, (int) ($arguments['limit'] ?? 20)));
        $one   = (int) ($arguments['resource_id'] ?? 0);
        $since = date('Y-m-d H:i:s', strtotime("-$days days"));

        $entities_id = $context instanceof \GlpiPlugin\Glpiai\ToolContext
            ? $context->entities_id
            : 0;

        $where = [
            History::TABLE . '.date' => ['>=', $since],
        ];

        if ($one > 0) {
            $where[History::TABLE . '.plugin_glpicloud_resources_id'] = $one;
        }

        // Joined to the resource and restricted there, not here: the history
        // table has no entity of its own, and filtering it alone would hand
        // one customer's resizes to another's conversation.
        $where[] = getEntitiesRestrictCriteria(
            Resource::getTable(),
            'entities_id',
            $entities_id,
            true
        );

        $rows = [];

        foreach (
            $DB->request([
                'SELECT'     => [
                    History::TABLE . '.date',
                    History::TABLE . '.field',
                    History::TABLE . '.old_value',
                    History::TABLE . '.new_value',
                    Resource::getTable() . '.id AS resources_id',
                    Resource::getTable() . '.name AS resource',
                    Resource::getTable() . '.provider',
                    Resource::getTable() . '.type',
                ],
                'FROM'       => History::TABLE,
                'INNER JOIN' => [
                    Resource::getTable() => [
                        'ON' => [
                            History::TABLE       => 'plugin_glpicloud_resources_id',
                            Resource::getTable() => 'id',
                        ],
                    ],
                ],
                'WHERE'      => $where,
                'ORDER'      => [History::TABLE . '.date DESC', History::TABLE . '.id DESC'],
                'LIMIT'      => $limit,
            ]) as $row
        ) {
            $rows[] = array_filter([
                'when'     => (string) $row['date'],
                'resource' => (string) $row['resource'],
                'id'       => (int) $row['resources_id'],
                'provider' => (string) $row['provider'],
                'type'     => (string) $row['type'],
                'changed'  => (string) $row['field'],
                'from'     => (string) $row['old_value'],
                'to'       => (string) $row['new_value'],
            ], static fn($v): bool => $v !== '' && $v !== 0);
        }

        return [
            'window'  => sprintf('the last %d days', $days),
            'changes' => $rows,
            'note'    => $rows === []
                ? 'Nothing changed in that window — or nothing has synced. Cloud history is '
                    . 'written by the sync, so an estate whose last sync failed looks perfectly '
                    . 'stable. Check cloud_resources for when things were last seen.'
                : 'Newest first. "attributes" rows name the keys that changed rather than '
                    . 'quoting the whole payload; the current values are on the resource '
                    . 'itself.',
        ];
    }

    // ------------------------------------------------------------ resources

    private static function resources(): Tool
    {
        return new Tool(
            name: 'cloud_resources',
            description: 'What virtual machines, VMs, servers, databases and storage are '
                . 'running in this customer\'s cloud accounts — Azure, AWS, OVH or whichever '
                . 'providers are connected — with each resource\'s state (running, stopped), the '
                . 'account and region it lives in, when it was last seen, and the GLPI asset it '
                . 'is projected onto if there is one. Reach for it whenever a fault might be '
                . 'about something that is not in the CMDB: "no asset matches" is a statement '
                . 'about GLPI, not about their estate.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'query'    => [
                        'type'        => 'string',
                        'description' => 'Words from the resource name, its native id, its type '
                            . 'or its service. Empty lists what there is.',
                    ],
                    'state'    => [
                        'type'        => 'string',
                        'description' => 'Only resources in this state, as the provider spells it '
                            . '— running, stopped, deallocated. Omit for all of them.',
                    ],
                    'provider' => [
                        'type'        => 'string',
                        'description' => 'Restrict to one provider, e.g. azure. Omit for all '
                            . 'connected ones.',
                    ],
                    'limit'    => [
                        'type'        => 'integer',
                        'description' => 'Results, 1-20. Defaults to 10.',
                    ],
                ],
            ],
            handler: [self::class, 'runResources'],
            right: Resource::$rightname,
            source: 'glpicloud',
            // Not declared on every request. A cloud question is a question
            // about the estate rather than about the ticket in front of
            // somebody, and most instances have no connected account at all —
            // find_tools ranks this first for "what VMs do they have".
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runResources(array $arguments = [], mixed $context = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $query    = mb_strtolower(trim((string) ($arguments['query'] ?? '')));
        $state    = mb_strtolower(trim((string) ($arguments['state'] ?? '')));
        $provider = mb_strtolower(trim((string) ($arguments['provider'] ?? '')));
        $limit    = max(1, min(self::MAX_RESOURCES, (int) ($arguments['limit'] ?? 10)));

        $entities_id = $context instanceof \GlpiPlugin\Glpiai\ToolContext
            ? $context->entities_id
            : (int) ($_SESSION['glpiactive_entity'] ?? 0);

        $where = [
            'is_deleted' => 0,
        ] + getEntitiesRestrictCriteria(Resource::getTable(), 'entities_id', $entities_id, true);

        if ($provider !== '') {
            $where['provider'] = $provider;
        }

        $out     = [];
        $skipped = 0;

        foreach (
            $DB->request([
                'FROM'  => Resource::getTable(),
                'WHERE' => $where,
                'ORDER' => 'last_seen DESC',
            ]) as $row
        ) {
            if ($state !== '' && mb_strtolower((string) $row['state']) !== $state) {
                continue;
            }

            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string) $row['name'],
                    (string) $row['native_id'],
                    (string) $row['type'],
                    (string) $row['service'],
                    (string) $row['tags'],
                ]));

                if (!str_contains($haystack, $query)) {
                    continue;
                }
            }

            if (count($out) >= $limit) {
                $skipped++;
                continue;
            }

            $out[] = array_filter([
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'provider'  => (string) $row['provider'],
                'service'   => (string) $row['service'],
                'type'      => (string) $row['type'],
                'state'     => (string) $row['state'],
                'region'    => (string) $row['scope'],
                'native_id' => (string) $row['native_id'],
                'last_seen' => (string) $row['last_seen'],
                // Said plainly: a resource the provider has stopped reporting
                // has not necessarily gone, and a model that reads last_seen as
                // "deleted" will tell somebody their server is gone.
                'gone_since' => (string) ($row['disappeared_at'] ?? ''),
                'orphaned'  => (bool) $row['is_orphaned'],
                'glpi_asset' => self::projection($row),
                'tags'      => self::tags((string) $row['tags']),
            ], static fn($v): bool => $v !== null && $v !== '' && $v !== [] && $v !== false);
        }

        return array_filter([
            'count'     => count($out),
            'resources' => $out,
            'more'      => $skipped > 0 ? $skipped : null,
            'note'      => $out === []
                ? 'Nothing matches in this customer\'s connected cloud accounts. That is not the '
                  . 'same as them having none — a provider may simply not be connected here.'
                : 'A resource with a glpi_asset is the same machine the other tools can read; '
                  . 'one without exists only in the cloud inventory.',
        ], static fn($v): bool => $v !== null);
    }

    /**
     * The GLPI asset this resource is projected onto, when there is one.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private static function projection(array $row): ?array
    {
        $itemtype = (string) ($row['projected_itemtype'] ?? '');
        $items_id = (int) ($row['projected_items_id'] ?? 0);

        if ($itemtype === '' || $items_id <= 0 || !class_exists($itemtype)) {
            return null;
        }

        $item = new $itemtype();
        if (!$item->getFromDB($items_id) || !$item->canViewItem()) {
            return null;
        }

        return [
            'itemtype' => $itemtype,
            'id'       => $items_id,
            'name'     => (string) ($item->fields['name'] ?? ''),
        ];
    }

    /**
     * Provider tags, which are where the ownership usually is.
     *
     * Capped: a well-governed account tags everything with a cost centre, an
     * owner, an environment and six other things, and all of it would arrive
     * on every row.
     *
     * @return array<string,string>
     */
    private static function tags(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_slice(array_map(
            static fn($v): string => mb_substr((string) $v, 0, 80),
            array_filter($decoded, 'is_scalar')
        ), 0, 8, true);
    }

    // ---------------------------------------------------------------- spend

    private static function spend(): Tool
    {
        return new Tool(
            name: 'cloud_spend',
            description: 'What this customer\'s cloud accounts cost in a given month, per '
                . 'account and currency, and whether the figure is still provisional. Use it '
                . 'when somebody asks what something is costing, before agreeing to leave a '
                . 'test environment running, and when a bill is the actual subject of the '
                . 'ticket. Never quote a provisional figure as final.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'period' => [
                        'type'        => 'string',
                        'description' => 'The month as YYYY-MM. Defaults to the current one.',
                    ],
                ],
            ],
            handler: [self::class, 'runSpend'],
            // The account right, not the resource one: seeing that a VM exists
            // and seeing what the customer pays for it are different
            // permissions, and this plugin already separates them.
            right: Account::$rightname,
            source: 'glpicloud',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runSpend(array $arguments = [], mixed $context = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $period = Costs::normalisePeriod(trim((string) ($arguments['period'] ?? '')))
            ?? date('Y-m');

        $entities_id = $context instanceof \GlpiPlugin\Glpiai\ToolContext
            ? $context->entities_id
            : (int) ($_SESSION['glpiactive_entity'] ?? 0);

        $accounts = [];
        foreach (
            $DB->request([
                'FROM'  => Account::getTable(),
                'WHERE' => ['is_deleted' => 0]
                    + getEntitiesRestrictCriteria(Account::getTable(), 'entities_id', $entities_id, true),
                'ORDER' => 'name',
            ]) as $row
        ) {
            $account = new Account();
            $account->getFromResultSet($row);
            if (!$account->canViewItem()) {
                continue;
            }

            $accounts_id = (int) $row['id'];
            $totals      = Costs::totals($accounts_id, $period);

            if ($totals === []) {
                continue;
            }

            $accounts[] = [
                'account'     => (string) $row['name'],
                'provider'    => (string) ($row['provider'] ?? ''),
                'totals'      => array_map(
                    static fn(float $amount): float => round($amount, 2),
                    $totals
                ),
                // The distinction the whole answer turns on. A provisional
                // month is the provider's running estimate and moves; quoting
                // it as the bill is how a customer is told the wrong number.
                'provisional' => Costs::isProvisional($accounts_id, $period),
            ];
        }

        return [
            'period'   => $period,
            'accounts' => $accounts,
            'note'     => $accounts === []
                ? sprintf('No cost has been collected for %s. Either no account is connected '
                    . 'here, or the provider has not reported that period yet.', $period)
                : 'Any account marked provisional is the provider\'s running estimate for a '
                  . 'month that has not closed. Say so when you quote it.',
        ];
    }
}
