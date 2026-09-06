<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use DBmysql;

/**
 * What changed on a resource, and when.
 *
 * Written only when {@see Resource::checksum()} moves, which is what makes a
 * six-hourly sweep of forty thousand resources cost nothing when nothing
 * happened.
 *
 * **`attributes` is recorded as the list of keys that changed, not as the two
 * blobs.** A provider's payload for one VM is kilobytes; storing before-and-
 * after copies on every resize would make this table larger than the inventory
 * within a month, to answer a question ("what changed") that the key list
 * answers just as well. The current payload is always on the resource itself.
 */
final class History
{
    public const TABLE = 'glpi_plugin_glpicloud_resourcehistories';

    /** Longest value stored for a scalar change. */
    private const MAX = 1000;

    /**
     * @param array<string,mixed> $before what the row held
     * @param array<string,mixed> $after  what the provider now reports
     * @return int number of history rows written
     */
    public static function record(int $resources_id, array $before, array $after, ?int $now = null): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $date    = date('Y-m-d H:i:s', $now ?? time());
        $written = 0;

        foreach (Resource::TRACKED as $field) {
            $old = $before[$field] ?? '';
            $new = $after[$field] ?? '';

            if ($field === 'attributes') {
                $changed = self::changedKeys(self::asArray($old), self::asArray($new));

                if ($changed === []) {
                    continue;
                }

                $DB->insert(self::TABLE, [
                    'plugin_glpicloud_resources_id' => $resources_id,
                    'date'                          => $date,
                    'field'                         => $field,
                    'old_value'                     => '',
                    'new_value'                     => implode(', ', $changed),
                ]);
                $written++;

                continue;
            }

            $old = self::scalar($old);
            $new = self::scalar($new);

            if ($old === $new) {
                continue;
            }

            $DB->insert(self::TABLE, [
                'plugin_glpicloud_resources_id' => $resources_id,
                'date'                          => $date,
                'field'                         => $field,
                'old_value'                     => $old,
                'new_value'                     => $new,
            ]);
            $written++;
        }

        return $written;
    }

    /** A resource that vanished, and one that came back, are both events. */
    public static function note(int $resources_id, string $field, string $old, string $new, ?int $now = null): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->insert(self::TABLE, [
            'plugin_glpicloud_resources_id' => $resources_id,
            'date'                          => date('Y-m-d H:i:s', $now ?? time()),
            'field'                         => $field,
            'old_value'                     => mb_substr($old, 0, self::MAX),
            'new_value'                     => mb_substr($new, 0, self::MAX),
        ]);
    }

    /** Drop history older than the retention setting. Returns rows removed. */
    public static function purge(?int $days = null, ?int $now = null): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $days = $days ?? (int) Settings::get('history_days');
        $cut  = date('Y-m-d H:i:s', ($now ?? time()) - ($days * 86400));

        $DB->delete(self::TABLE, ['date' => ['<', $cut]]);

        return (int) $DB->affectedRows();
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<int,string>
     */
    public static function changedKeys(array $before, array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($keys);

        $changed = [];

        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if (is_array($old) || is_array($new)) {
                // Compared through the same key-ordering the checksum uses, or
                // a provider that reordered a nested object would be reported
                // as a change the checksum had already decided was none.
                if (Normalise::stable((array) $old) !== Normalise::stable((array) $new)) {
                    $changed[] = (string) $key;
                }

                continue;
            }

            if ((string) $old !== (string) $new) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }

    /** @return array<string,mixed> */
    private static function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function scalar(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            $value = json_encode($value);
        }

        return mb_substr((string) $value, 0, self::MAX);
    }
}
