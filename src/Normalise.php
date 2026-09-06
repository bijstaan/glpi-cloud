<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

/**
 * Turning what a provider yielded into the row we store.
 *
 * Deliberately free of every GLPI base class, database call and setting, so
 * that the part of the core most likely to be subtly wrong can be exercised
 * with nothing but `php tests/normalise.php`. {@see Resource} and {@see Sync}
 * are thin wrappers over it.
 *
 * The rule: **normalise what we index and query, keep verbatim what we do
 * not.** A normalisation we get wrong has to be recoverable from what was
 * stored, without asking the provider again — which is why `attributes` keeps
 * the payload as it arrived, and why an unrecognised type is stored rather than
 * mapped to "other".
 */
final class Normalise
{
    /** Fields whose change is worth a history row, and worth a re-checksum. */
    public const TRACKED = ['name', 'state', 'type', 'service', 'scope', 'tags', 'attributes'];

    /** Biggest provider payload kept per resource, encoded. */
    public const MAX_ATTRIBUTES = 262144;

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null null when the row has no identity
     */
    public static function row(array $raw, string $provider, string $scope, string $service): ?array
    {
        $native_id = trim((string) ($raw['native_id'] ?? ''));

        if ($native_id === '') {
            return null;
        }

        $tags = [];
        foreach ((array) ($raw['tags'] ?? []) as $key => $value) {
            // A tag whose value is a structure is not a tag. Dropping it beats
            // storing "Array" and beats throwing away the whole resource.
            if (is_scalar($value) || $value === null) {
                $tags[(string) $key] = (string) $value;
            }
        }

        $attributes = (array) ($raw['attributes'] ?? []);

        if (strlen((string) json_encode($attributes)) > self::MAX_ATTRIBUTES) {
            // Recorded as truncated rather than silently trimmed: a payload
            // this size is a provider bug, and it should look like one.
            $attributes = ['_truncated' => true];
        }

        return [
            'native_id'  => mb_substr($native_id, 0, 255),
            'provider'   => $provider,
            'scope'      => mb_substr(trim((string) ($raw['scope'] ?? $scope)), 0, 128),
            'service'    => $service,
            'type'       => mb_substr(strtolower(trim((string) ($raw['type'] ?? 'unknown'))), 0, 64),
            'name'       => mb_substr(trim((string) ($raw['name'] ?? $native_id)), 0, 255),
            'state'      => mb_substr(strtolower(trim((string) ($raw['state'] ?? ''))), 0, 64),
            'tags'       => $tags,
            'attributes' => $attributes,
        ];
    }

    /**
     * What a resource's identity boils down to for change detection.
     *
     * Hashing the normalised body rather than comparing fields one by one means
     * a sweep that changed nothing writes nothing — no history, no date_mod, no
     * row churn on forty thousand resources every six hours. Keys are sorted
     * because key order out of a provider's JSON is not stable and ours has to
     * be, or every sweep would "change" everything.
     *
     * @param array<string,mixed> $row
     */
    public static function checksum(array $row): string
    {
        $material = [];

        foreach (self::TRACKED as $field) {
            $value = $row[$field] ?? '';

            if (is_array($value)) {
                $value = self::stable($value);
            }

            $material[$field] = (string) $value;
        }

        return hash('sha256', (string) json_encode($material));
    }

    /**
     * JSON with every level's keys in a fixed order.
     *
     * Public because change detection and change *description* have to agree:
     * if the checksum says nothing moved, the history must not claim a key did
     * because the provider serialised it in a different order.
     */
    public static function stable(array $value): string
    {
        array_walk_recursive($value, static function (&$leaf): void {
            $leaf = is_scalar($leaf) || $leaf === null ? $leaf : (string) json_encode($leaf);
        });

        $sort = static function (array &$node) use (&$sort): void {
            ksort($node);

            foreach ($node as &$child) {
                if (is_array($child)) {
                    $sort($child);
                }
            }
        };

        $sort($value);

        return (string) json_encode($value);
    }
}
