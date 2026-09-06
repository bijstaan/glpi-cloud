<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use DBmysql;

/**
 * Where a sweep got to, per (account, scope, service).
 *
 * A GLPI cron tick will not enumerate a large estate, and a sweep that starts
 * from zero every tick never finishes one. So a provider hands back a cursor —
 * a `$skipToken`, a `NextToken`, a page number, whatever it uses — by calling
 * the `checkpoint` closure the core puts in its context, and the next run
 * resumes from there.
 *
 * The cursor is opaque to us on purpose. It is the provider's word for "carry
 * on from here" and the moment we parse it we have provider knowledge in the
 * core.
 */
final class Checkpoint
{
    public const TABLE = 'glpi_plugin_glpicloud_checkpoints';

    public static function get(int $accounts_id, string $scope, string $service): ?string
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => [
                    'plugin_glpicloud_accounts_id' => $accounts_id,
                    'scope'                        => $scope,
                    'service'                      => $service,
                ],
            ]) as $row
        ) {
            $cursor = (string) $row['cursor_value'];

            return $cursor === '' ? null : $cursor;
        }

        return null;
    }

    public static function set(int $accounts_id, string $scope, string $service, ?string $cursor): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $existing = null;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABLE,
                'WHERE'  => [
                    'plugin_glpicloud_accounts_id' => $accounts_id,
                    'scope'                        => $scope,
                    'service'                      => $service,
                ],
            ]) as $row
        ) {
            $existing = (int) $row['id'];
        }

        $values = [
            'cursor_value' => (string) ($cursor ?? ''),
            'date_mod'     => date('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            $DB->update(self::TABLE, $values, ['id' => $existing]);

            return;
        }

        $DB->insert(self::TABLE, $values + [
            'plugin_glpicloud_accounts_id' => $accounts_id,
            'scope'                        => $scope,
            'service'                      => $service,
        ]);
    }

    /**
     * Forget a unit's place, so the next sweep starts from the beginning.
     *
     * Deletes the row rather than blanking it. A completed unit has no place to
     * keep, and an account with twenty projects across seven services would
     * otherwise accumulate a hundred and forty permanent rows saying nothing.
     */
    public static function clear(int $accounts_id, string $scope, string $service): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, [
            'plugin_glpicloud_accounts_id' => $accounts_id,
            'scope'                        => $scope,
            'service'                      => $service,
        ]);
    }
}
