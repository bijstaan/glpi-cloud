<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use ContractCost;
use DBmysql;

/**
 * What the provider billed, and how it reaches GLPI's own financial model.
 *
 * Two layers, on purpose.
 *
 * **The record** is this plugin's `costs` table: one row per (account,
 * resource, period, currency), upserted, so re-querying a month cannot
 * double-count it. Cloud bills are restated for days after a month closes, and
 * a store that could only append would need a reconciliation pass nobody would
 * ever write. Per-resource monthly history also runs to tens of thousands of
 * rows a month, which is not what core's financial tables are for.
 *
 * **The rollup** is native: one `ContractCost` row per (account, period,
 * currency) against the account's contract, attributed to its budget. That is
 * the money GLPI itself reports on — budget consumption, contract cost screens,
 * anything downstream — so cloud spend arrives there without a line of
 * reporting code of ours.
 *
 * **Unattributed spend is a first-class row.** Marketplace charges, support
 * plans and reservations carry no resource id; they are stored with
 * `plugin_glpicloud_resources_id = 0` rather than dropped, because an account
 * total that does not reconcile with the invoice will not be trusted twice.
 *
 * **Currency is recorded as billed and never converted.** An account billed in
 * two currencies produces two rollup rows, not one wrong one.
 */
final class Costs
{
    public const TABLE = 'glpi_plugin_glpicloud_costs';

    /** Marks a ContractCost row as ours, so the rollup can find it again. */
    public const MARKER = 'glpicloud';

    /**
     * Store one cost row.
     *
     * @param array{period:string,currency:string,amount:float|string,resources_id?:int,source?:string,is_provisional?:bool} $row
     */
    public static function record(int $accounts_id, array $row): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $period = self::normalisePeriod((string) ($row['period'] ?? ''));

        if ($period === null) {
            return false;
        }

        $currency     = strtoupper(substr(trim((string) ($row['currency'] ?? '')), 0, 3));
        $resources_id = (int) ($row['resources_id'] ?? 0);

        if ($currency === '') {
            return false;
        }

        $values = [
            'amount'         => (float) ($row['amount'] ?? 0),
            'source'         => substr((string) ($row['source'] ?? ''), 0, 64),
            'is_provisional' => (int) (bool) ($row['is_provisional'] ?? false),
            'date_mod'       => date('Y-m-d H:i:s'),
        ];

        $where = [
            'plugin_glpicloud_accounts_id'  => $accounts_id,
            'plugin_glpicloud_resources_id' => $resources_id,
            'period'                        => $period,
            'currency'                      => $currency,
        ];

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => $where]) as $existing) {
            $DB->update(self::TABLE, $values, ['id' => (int) $existing['id']]);

            return true;
        }

        $DB->insert(self::TABLE, $values + $where);

        return true;
    }

    /**
     * Totals for a period, per currency.
     *
     * @return array<string,float>
     */
    public static function totals(int $accounts_id, string $period): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $out = [];

        foreach (
            $DB->request([
                'SELECT' => ['currency', 'amount', 'is_provisional'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['plugin_glpicloud_accounts_id' => $accounts_id, 'period' => $period],
            ]) as $row
        ) {
            $currency        = (string) $row['currency'];
            $out[$currency]   = ($out[$currency] ?? 0.0) + (float) $row['amount'];
        }

        return $out;
    }

    /**
     * What a period cost, broken down by service and by resource.
     *
     * The statement's body. Two groupings rather than one because they answer
     * different questions and a reader needs both: *what kind of thing* is the
     * money going on (the service split, which is where a decision gets made)
     * and *which specific thing* is the most expensive (the resource list,
     * which is where somebody looks when the split surprises them).
     *
     * Unattributed spend — marketplace charges, support plans, reservations,
     * stored with `plugin_glpicloud_resources_id = 0` — is its own row in both,
     * never folded in and never dropped. An account total that does not
     * reconcile with the provider's invoice will not be trusted twice, and the
     * commonest way to break that reconciliation is to quietly discard the
     * lines with no resource behind them.
     *
     * @return array{
     *     services:array<int,array{service:string,currency:string,amount:float}>,
     *     resources:array<int,array{name:string,type:string,service:string,currency:string,amount:float}>
     * }
     */
    public static function breakdown(int $accounts_id, string $period, int $top = 20): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $services  = [];
        $resources = [];

        $iterator = $DB->request([
            'SELECT' => [
                'c.currency AS currency',
                'c.amount AS amount',
                'r.service AS service',
                'r.type AS type',
                'r.name AS name',
                'c.plugin_glpicloud_resources_id AS resources_id',
            ],
            'FROM'      => self::TABLE . ' AS c',
            'LEFT JOIN' => [
                Resource::getTable() . ' AS r' => [
                    'ON' => ['c' => 'plugin_glpicloud_resources_id', 'r' => 'id'],
                ],
            ],
            'WHERE'     => ['c.plugin_glpicloud_accounts_id' => $accounts_id, 'c.period' => $period],
        ]);

        $unattributed = __('Unattributed', 'glpicloud');

        foreach ($iterator as $row) {
            $currency = (string) $row['currency'];
            $amount   = (float) $row['amount'];

            $service = (int) $row['resources_id'] === 0
                ? $unattributed
                : ((string) ($row['service'] ?? '') ?: $unattributed);

            $key = $service . '|' . $currency;
            $services[$key] ??= ['service' => $service, 'currency' => $currency, 'amount' => 0.0];
            $services[$key]['amount'] += $amount;

            $resources[] = [
                'name'     => (int) $row['resources_id'] === 0
                    ? $unattributed
                    : ((string) ($row['name'] ?? '') ?: '#' . (int) $row['resources_id']),
                'type'     => (string) ($row['type'] ?? ''),
                'service'  => $service,
                'currency' => $currency,
                'amount'   => $amount,
            ];
        }

        usort($services, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
        usort($resources, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return [
            'services'  => array_values($services),
            'resources' => array_slice($resources, 0, max(1, $top)),
        ];
    }

    /** True while any row of the period is still marked provisional. */
    public static function isProvisional(int $accounts_id, string $period): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABLE,
                'WHERE'  => [
                    'plugin_glpicloud_accounts_id' => $accounts_id,
                    'period'                       => $period,
                    'is_provisional'               => 1,
                ],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            return true;
        }

        return false;
    }

    /**
     * Put a closed period on the account's contract, natively.
     *
     * Idempotent: the row is found by its marker comment rather than by name,
     * so an administrator who renames a cost line does not get a duplicate on
     * the next sync.
     *
     * A provisional period is deliberately *not* rolled up. A mid-month number
     * on a contract is read as a bill by everything downstream of it.
     *
     * @return array<string,int> ContractCost ids written, keyed by currency
     */
    public static function rollup(Account $account, string $period): array
    {
        $written = [];

        if ((int) Settings::get('cost_rollup') !== 1) {
            return $written;
        }

        $contracts_id = (int) ($account->fields['contracts_id'] ?? 0);
        $accounts_id  = (int) $account->getID();

        if ($contracts_id <= 0 || $accounts_id <= 0) {
            return $written;
        }

        $period = self::normalisePeriod($period);

        if ($period === null || self::isProvisional($accounts_id, $period)) {
            return $written;
        }

        foreach (self::totals($accounts_id, $period) as $currency => $amount) {
            $id = self::writeContractCost($account, $period, (string) $currency, (float) $amount);

            if ($id > 0) {
                $written[(string) $currency] = $id;
            }
        }

        return $written;
    }

    private static function writeContractCost(Account $account, string $period, string $currency, float $amount): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $accounts_id = (int) $account->getID();
        $marker      = sprintf('%s#%d#%s#%s', self::MARKER, $accounts_id, $period, $currency);

        $values = [
            'contracts_id' => (int) ($account->fields['contracts_id'] ?? 0),
            'name'         => sprintf(
                __('Cloud spend — %1$s %2$s', 'glpicloud'),
                (string) ($account->fields['name'] ?? ''),
                $period
            ),
            'comment'      => $marker,
            'begin_date'   => $period . '-01',
            'end_date'     => date('Y-m-t', (int) strtotime($period . '-01')),
            'cost'         => $amount,
            'budgets_id'   => (int) ($account->fields['budgets_id'] ?? 0),
            'entities_id'  => (int) ($account->fields['entities_id'] ?? 0),
        ];

        $cost = new ContractCost();

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => ContractCost::getTable(),
                'WHERE'  => ['comment' => $marker],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            $id = (int) $row['id'];
            $cost->update($values + ['id' => $id]);

            return $id;
        }

        $id = $cost->add($values);

        return is_int($id) ? $id : 0;
    }

    /** `2026-08`, or null if that is not what it is. */
    public static function normalisePeriod(string $period): ?string
    {
        $period = trim($period);

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1 ? $period : null;
    }
}
