<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use CronTask;
use DBmysql;
use Throwable;

/**
 * The sweep: what turns a provider's rows into inventory.
 *
 * Fanned out over (account × scope × service). Each unit is attempted within a
 * wall-clock budget; when the budget runs out the cursor the provider last
 * handed back is already stored, and the cron returns. A GLPI cron tick will
 * not enumerate a large estate, and a sweep that starts from zero every tick
 * never finishes one.
 *
 * ### Deletion is inferred, never assumed
 *
 * A resource missing from a *complete and successful* sweep of its unit is
 * marked disappeared. A unit that threw, or that ran out of clock, marks
 * nothing — otherwise one throttled request deletes a customer's inventory, and
 * the next sweep "rediscovers" everything with a new first-seen date. This is
 * the single most important rule in the file.
 *
 * ### The core enforces the budget; the provider cooperates
 *
 * We cannot interrupt somebody else's HTTP call, so the deadline is checked
 * between rows: the loop stops consuming the generator, and whatever the
 * provider last checkpointed stands. `$ctx['request']()` lets a well-behaved
 * provider stop before making a call it has no budget for.
 */
final class Sync
{
    /** Rows whose last_seen is updated in one statement. */
    private const TOUCH_BATCH = 500;

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'cloudsync'  => ['description' => __('Sync cloud accounts', 'glpicloud')],
            'cloudprune' => ['description' => __('Drop expired cloud history and long-gone resources', 'glpicloud')],
            default      => [],
        };
    }

    public static function cronCloudsync(CronTask $task): int
    {
        if (!Settings::isEnabled()) {
            $task->log('glpicloud is switched off; no provider was contacted.');

            return 0;
        }

        $worked = 0;

        foreach (Account::due() as $account) {
            $summary = self::account($account);

            $task->log(sprintf(
                '%s: %s, %d seen, %d changed, %d new, %d gone',
                (string) ($account->fields['name'] ?? ''),
                $summary['status'],
                $summary['seen'],
                $summary['changed'],
                $summary['new'],
                $summary['gone']
            ));

            foreach ($summary['errors'] as $error) {
                $task->log('  ' . $error);
            }

            $worked++;
        }

        return $worked > 0 ? 1 : 0;
    }

    public static function cronCloudprune(CronTask $task): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $history = History::purge();

        $days = (int) Settings::get('keep_disappeared_days');
        $cut  = date('Y-m-d H:i:s', time() - ($days * 86400));

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Resource::getTable(),
                'WHERE'  => ['NOT' => ['disappeared_at' => null], 'disappeared_at' => ['<', $cut]],
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        if ($ids !== []) {
            $DB->delete(History::TABLE, ['plugin_glpicloud_resources_id' => $ids]);
            $DB->delete(Resource::getTable(), ['id' => $ids]);
        }

        $task->log(sprintf('%d history rows and %d long-gone resources removed.', $history, count($ids)));

        return ($history + count($ids)) > 0 ? 1 : 0;
    }

    /**
     * Sweep one account.
     *
     * @param array{now?:int,seconds?:int} $options test seams: a fixed clock and a
     *                                              shorter budget
     * @return array{status:string,seen:int,changed:int,new:int,gone:int,errors:array<int,string>}
     */
    public static function account(Account $account, array $options = []): array
    {
        $now     = $options['now'] ?? time();
        $seconds = (int) ($options['seconds'] ?? Settings::get('run_seconds'));

        $counts = ['seen' => 0, 'changed' => 0, 'new' => 0, 'gone' => 0, 'requests' => 0];
        $errors = [];

        $provider = $account->provider();

        if ($provider === null) {
            $message = sprintf(
                'No plugin provides "%s"; nothing was collected.',
                (string) ($account->fields['provider'] ?? '')
            );
            self::stamp($account, Run::FAILED, $message, $now);

            return ['status' => Run::FAILED, 'errors' => [$message]] + $counts;
        }

        $runs_id = Run::start($account, $now);

        if ($runs_id === null) {
            return ['status' => 'locked', 'errors' => []] + $counts;
        }

        $deadline  = $now + max(5, $seconds);
        $remaining = max(1, (int) Settings::get('request_budget'));
        $complete  = true;

        try {
            $scopes = $provider->scopes(self::accountContext($account));
        } catch (Throwable $e) {
            $message = 'Could not list scopes: ' . $e->getMessage();
            Run::finish($runs_id, Run::FAILED, $counts, [$message], $now);
            self::stamp($account, Run::FAILED, $message, $now);

            return ['status' => Run::FAILED, 'errors' => [$message]] + $counts;
        }

        foreach ($scopes as $scope) {
            if (!$scope['is_active']) {
                continue;
            }

            foreach ($provider->services() as $service) {
                if (time() >= $deadline || $remaining <= 0) {
                    $complete = false;
                    break 2;
                }

                $unit = self::unit(
                    $account,
                    $provider,
                    (string) $scope['key'],
                    (string) $service['key'],
                    $deadline,
                    $remaining,
                    $now
                );

                foreach (['seen', 'changed', 'new', 'gone', 'requests'] as $key) {
                    $counts[$key] += $unit[$key];
                }

                $remaining -= $unit['requests'];
                $errors     = array_merge($errors, $unit['errors']);
                $complete   = $complete && $unit['complete'];
            }
        }

        if (Settings::get('cost_enabled') && $provider->hasCosts()) {
            $errors = array_merge($errors, self::costs($account, $provider, $now));
        }

        $status = match (true) {
            $errors !== [] && $counts['seen'] === 0 => Run::FAILED,
            $errors !== [] || !$complete            => Run::PARTIAL,
            default                                 => Run::OK,
        };

        Run::finish($runs_id, $status, $counts, $errors, $now);
        self::stamp($account, $status, $errors[0] ?? '', $now);

        return ['status' => $status, 'errors' => $errors] + $counts;
    }

    /**
     * One (scope, service) unit.
     *
     * @return array{seen:int,changed:int,new:int,gone:int,requests:int,complete:bool,errors:array<int,string>}
     */
    private static function unit(
        Account $account,
        Provider $provider,
        string $scope,
        string $service,
        int $deadline,
        int $budget,
        int $now
    ): array {
        $accounts_id = (int) $account->getID();

        $result = [
            'seen' => 0, 'changed' => 0, 'new' => 0, 'gone' => 0,
            'requests' => 0, 'complete' => true, 'errors' => [],
        ];

        $known = self::known($accounts_id, $scope, $service);
        $seen  = [];
        $touch = [];

        $requests = 0;

        $ctx = self::accountContext($account) + [
            'scope'      => $scope,
            'service'    => $service,
            'cursor'     => Checkpoint::get($accounts_id, $scope, $service),
            'deadline'   => $deadline,
            'checkpoint' => static function (?string $cursor) use ($accounts_id, $scope, $service): void {
                Checkpoint::set($accounts_id, $scope, $service, $cursor);
            },
            'request'    => static function (int $count = 1) use (&$requests, $budget): bool {
                $requests += $count;

                return $requests <= $budget;
            },
        ];

        try {
            foreach ($provider->collect($service, $ctx) as $raw) {
                if (time() >= $deadline) {
                    $result['complete'] = false;
                    break;
                }

                $row = self::normalise((array) $raw, $provider, $scope, $service);

                if ($row === null) {
                    continue;
                }

                $result['seen']++;
                $seen[$row['native_id']] = true;

                $outcome = self::store($account, $row, $known[$row['native_id']] ?? null, $now);

                match ($outcome) {
                    'new'     => $result['new']++,
                    'changed' => $result['changed']++,
                    default   => $touch[] = $known[$row['native_id']]['id'],
                };

                if (count($touch) >= self::TOUCH_BATCH) {
                    self::touch($touch, $now);
                    $touch = [];
                }
            }
        } catch (Throwable $e) {
            $result['complete'] = false;
            $result['errors'][] = sprintf('%s/%s: %s', $scope, $service, $e->getMessage());
        }

        self::touch($touch, $now);

        $result['requests'] = $requests;

        if ($requests > $budget) {
            $result['complete'] = false;
            $result['errors'][] = sprintf('%s/%s: request budget exhausted.', $scope, $service);
        }

        // Only a clean, complete sweep is allowed to conclude anything about
        // what is *missing*. See the class docblock.
        if ($result['complete']) {
            Checkpoint::clear($accounts_id, $scope, $service);
            $result['gone'] = self::markGone($known, $seen, $now);
        }

        return $result;
    }

    /**
     * What we already hold for a unit, so the sweep is not one SELECT per row.
     *
     * @return array<string,array{id:int,checksum:string,gone:bool,entities_id:int}>
     */
    private static function known(int $accounts_id, string $scope, string $service): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $out = [];

        foreach (
            $DB->request([
                'SELECT' => ['id', 'native_id', 'checksum', 'disappeared_at', 'entities_id'],
                'FROM'   => Resource::getTable(),
                'WHERE'  => [
                    'plugin_glpicloud_accounts_id' => $accounts_id,
                    'scope'                        => $scope,
                    'service'                      => $service,
                ],
            ]) as $row
        ) {
            $out[(string) $row['native_id']] = [
                'id'          => (int) $row['id'],
                'checksum'    => (string) $row['checksum'],
                'gone'        => ((string) ($row['disappeared_at'] ?? '')) !== '',
                'entities_id' => (int) $row['entities_id'],
            ];
        }

        return $out;
    }

    /**
     * Insert, update, or leave alone.
     *
     * @param array<string,mixed>                                                     $row
     * @param array{id:int,checksum:string,gone:bool,entities_id:int}|null             $existing
     * @return string 'new', 'changed' or 'seen'
     */
    private static function store(Account $account, array $row, ?array $existing, int $now): string
    {
        /** @var DBmysql $DB */
        global $DB;

        $checksum = Resource::checksum($row);
        $entity   = Mapping::resolve($account, $row);
        $stamp    = date('Y-m-d H:i:s', $now);

        if ($existing === null) {
            $DB->insert(Resource::getTable(), [
                'plugin_glpicloud_accounts_id' => (int) $account->getID(),
                'provider'                     => $row['provider'],
                'scope'                        => $row['scope'],
                'service'                      => $row['service'],
                'type'                         => $row['type'],
                'native_id'                    => $row['native_id'],
                'name'                         => $row['name'],
                'state'                        => $row['state'],
                'tags'                         => (string) json_encode($row['tags']),
                'attributes'                   => (string) json_encode($row['attributes']),
                'entities_id'                  => $entity['entities_id'],
                'is_recursive'                 => $entity['is_recursive'],
                'checksum'                     => $checksum,
                'first_seen'                   => $stamp,
                'last_seen'                    => $stamp,
                'date_creation'                => $stamp,
                'date_mod'                     => $stamp,
            ]);

            return 'new';
        }

        $came_back = $existing['gone'];

        if ($existing['checksum'] === $checksum && !$came_back && $existing['entities_id'] === $entity['entities_id']) {
            return 'seen';
        }

        if ($existing['checksum'] !== $checksum) {
            $before = self::before($existing['id']);
            History::record($existing['id'], $before, $row, $now);
        }

        if ($came_back) {
            History::note($existing['id'], 'presence', 'gone', 'present', $now);
        }

        $DB->update(Resource::getTable(), [
            'provider'       => $row['provider'],
            'type'           => $row['type'],
            'name'           => $row['name'],
            'state'          => $row['state'],
            'tags'           => (string) json_encode($row['tags']),
            'attributes'     => (string) json_encode($row['attributes']),
            'entities_id'    => $entity['entities_id'],
            'is_recursive'   => $entity['is_recursive'],
            'checksum'       => $checksum,
            'last_seen'      => $stamp,
            'disappeared_at' => null,
            'date_mod'       => $stamp,
        ], ['id' => $existing['id']]);

        return $existing['checksum'] === $checksum ? 'seen' : 'changed';
    }

    /** @return array<string,mixed> */
    private static function before(int $resources_id): array
    {
        $resource = new Resource();

        if (!$resource->getFromDB($resources_id)) {
            return [];
        }

        return [
            'name'       => (string) $resource->fields['name'],
            'state'      => (string) $resource->fields['state'],
            'type'       => (string) $resource->fields['type'],
            'service'    => (string) $resource->fields['service'],
            'scope'      => (string) $resource->fields['scope'],
            'tags'       => $resource->tags(),
            'attributes' => $resource->attributes(),
        ];
    }

    /**
     * Say "still there" for rows nothing else changed, in batches.
     *
     * @param array<int,int> $ids
     */
    private static function touch(array $ids, int $now): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($ids === []) {
            return;
        }

        $DB->update(Resource::getTable(), ['last_seen' => date('Y-m-d H:i:s', $now)], ['id' => $ids]);
    }

    /**
     * @param array<string,array{id:int,checksum:string,gone:bool,entities_id:int}> $known
     * @param array<string,bool>                                                    $seen
     */
    private static function markGone(array $known, array $seen, int $now): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $ids = [];

        foreach ($known as $native_id => $row) {
            if (!isset($seen[$native_id]) && !$row['gone']) {
                $ids[] = $row['id'];
            }
        }

        if ($ids === []) {
            return 0;
        }

        $DB->update(
            Resource::getTable(),
            ['disappeared_at' => date('Y-m-d H:i:s', $now), 'date_mod' => date('Y-m-d H:i:s', $now)],
            ['id' => $ids]
        );

        foreach ($ids as $id) {
            History::note($id, 'presence', 'present', 'gone', $now);
        }

        return count($ids);
    }

    /**
     * Cost rows, and the native rollup of every closed period they touched.
     *
     * @return array<int,string> errors
     */
    private static function costs(Account $account, Provider $provider, int $now): array
    {
        $accounts_id = (int) $account->getID();
        $errors      = [];
        $periods     = [];

        try {
            foreach ($provider->costs(self::accountContext($account)) as $raw) {
                $raw = (array) $raw;

                $period = Costs::normalisePeriod((string) ($raw['period'] ?? ''));

                if ($period === null) {
                    continue;
                }

                $native_id = trim((string) ($raw['native_id'] ?? ''));

                Costs::record($accounts_id, [
                    'period'         => $period,
                    'currency'       => (string) ($raw['currency'] ?? ''),
                    'amount'         => (float) ($raw['amount'] ?? 0),
                    'source'         => (string) ($raw['source'] ?? ''),
                    'is_provisional' => (bool) ($raw['is_provisional'] ?? false),
                    // Unattributed spend — marketplace, support, reservations —
                    // is stored against the account with no resource rather
                    // than dropped, or the total stops matching the invoice.
                    'resources_id'   => $native_id === '' ? 0 : self::resourceIdFor($accounts_id, $native_id),
                ]);

                $periods[$period] = true;
            }
        } catch (Throwable $e) {
            $errors[] = 'Cost collection: ' . $e->getMessage();
        }

        foreach (array_keys($periods) as $period) {
            try {
                Costs::rollup($account, (string) $period);
            } catch (Throwable $e) {
                $errors[] = sprintf('Cost rollup for %s: %s', $period, $e->getMessage());
            }
        }

        return $errors;
    }

    private static function resourceIdFor(int $accounts_id, string $native_id): int
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Resource::getTable(),
                'WHERE'  => ['plugin_glpicloud_accounts_id' => $accounts_id, 'native_id' => $native_id],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            return (int) $row['id'];
        }

        return 0;
    }

    /**
     * What a provider is told about the account it is collecting for.
     *
     * Deliberately a plain array: nothing on the provider's side of the seam
     * may depend on a class of ours existing.
     *
     * @return array<string,mixed>
     */
    public static function accountContext(Account $account): array
    {
        return [
            'account' => [
                'id'          => (int) $account->getID(),
                'name'        => (string) ($account->fields['name'] ?? ''),
                'provider'    => (string) ($account->fields['provider'] ?? ''),
                'entities_id' => (int) ($account->fields['entities_id'] ?? 0),
            ],
            'credentials' => $account->credentials(),
        ];
    }

    /**
     * Normalise one provider row.
     *
     * The rules are in {@see Normalise}, which is free of GLPI so they can be
     * tested without one.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    public static function normalise(array $raw, Provider $provider, string $scope, string $service): ?array
    {
        return Normalise::row($raw, $provider->key(), $scope, $service);
    }

    private static function stamp(Account $account, string $status, string $error, int $now): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->update(Account::getTable(), [
            'last_sync'   => date('Y-m-d H:i:s', $now),
            'last_status' => $status,
            'last_error'  => mb_substr($error, 0, 255),
        ], ['id' => (int) $account->getID()]);
    }
}
