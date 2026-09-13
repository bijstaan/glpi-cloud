<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use Config;

/**
 * Plugin configuration, with defaults.
 *
 * Two of these are load-bearing rather than routine.
 *
 * **`enabled` is off.** A plugin that started talking to three cloud control
 * planes the moment somebody activated it would be a nasty surprise,
 * and not every organisation permits it. Nothing is contacted, and no
 * cron does any work, until an administrator says so.
 *
 * **`project_*` are off.** Projection creates native GLPI assets (see
 * Projection, milestone 4). Turning it on for a dev subscription with four
 * thousand resources rewrites the asset list the service desk depends on, and
 * that is not recoverable by anything short of a purge. It is per-type and
 * deliberate.
 */
final class Settings
{
    public const DEFAULTS = [
        // Master switch. Off, no provider is called and the cron does nothing.
        'enabled'               => 0,

        // ------------------------------------------------------- sync engine
        // Default seconds between syncs of one account. Overridable per
        // account; six hours is a control plane that is not changing minute to
        // minute against an API that bills nobody for being polled politely.
        'sync_interval'         => 21600,

        // Wall-clock seconds one account may spend inside a single cron tick.
        // The sweep is checkpointed, so a small budget means a slower catch-up
        // rather than a failure — and a cron that never returns is worse than
        // one that makes progress.
        'run_seconds'           => 120,

        // Hard ceiling on provider HTTP requests per run. The provider paces
        // itself against its own rate limits; this is the outer bound that
        // stops a misbehaving one spending an hour of PHP.
        'request_budget'        => 2000,

        // ------------------------------------------------------- retention
        // Days of per-resource change history kept. History is what answers
        // "when did this become public", so it outlives the resource.
        'history_days'          => 365,

        // Days a disappeared resource is kept before it is purged. It stays
        // visible in the meantime: "it is gone" is usually the answer somebody
        // came looking for.
        'keep_disappeared_days' => 90,

        // --------------------------------------------------------- money
        // Collect cost from providers that offer it.
        'cost_enabled'          => 1,

        // Roll closed periods up into native ContractCost rows against the
        // account's contract, so core's own budget screens count cloud spend.
        'cost_rollup'           => 1,

        // --------------------------------------------------------- projection
        // Maintain native GLPI assets for selected resource types. Off; see
        // the class docblock.
        'projection_enabled'    => 0,

        // --------------------------------------------------------- signalling
        // The glpi-signal source cloud sync failures are submitted to. Zero
        // means no events at all — there is no sensible default, because the
        // source decides severity floors and routing. See SignalEvents.
        'signal_sources_id'     => 0,

        // Which of Projection::MAP's types actually project. Empty means all of
        // them, which is what somebody who switched the feature on asked for;
        // the setting exists so an estate that wants VMs as Computers but not
        // managed databases as DatabaseInstances can say so without turning the
        // whole thing off.
        'projection_types'      => '',
    ];

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(PLUGIN_GLPICLOUD_CONFIG_CONTEXT);

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** True when the master switch is on. */
    public static function isEnabled(): bool
    {
        return (int) self::get('enabled') === 1;
    }

    /**
     * Store a set of settings, clamped.
     *
     * Only keys we know are written. A settings page posts what its form
     * contains, and a handler that rebuilt every setting from $_POST would
     * switch off any checkbox that was not on the submitted form — the trap
     * that cost glpi-netscan its TLS requirement.
     *
     * @param array<string,mixed> $input
     */
    public static function save(array $input): void
    {
        $values = [];

        foreach ($input as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $values[$key] = self::clamp($key, $value);
        }

        if ($values !== []) {
            Config::setConfigurationValues(PLUGIN_GLPICLOUD_CONFIG_CONTEXT, $values);
        }
    }

    /** Bounds that keep a typo from producing a cron that never returns. */
    public static function clamp(string $key, mixed $value): mixed
    {
        return match ($key) {
            'enabled', 'cost_enabled', 'cost_rollup', 'projection_enabled'
                => (int) ((int) $value === 1),
            // Stored as a comma list, filtered to types that actually have a
            // mapping — an unknown type here would silently do nothing, and a
            // setting that silently does nothing is a support call.
            'projection_types'      => implode(',', array_values(array_intersect(
                array_filter(array_map('trim', explode(',', is_array($value) ? implode(',', $value) : (string) $value))),
                array_keys(Projection::MAP)
            ))),
            'signal_sources_id'     => max(0, (int) $value),
            'sync_interval'         => max(300, min(7 * 86400, (int) $value)),
            'run_seconds'           => max(10, min(900, (int) $value)),
            'request_budget'        => max(10, min(100000, (int) $value)),
            'history_days'          => max(7, min(3650, (int) $value)),
            'keep_disappeared_days' => max(1, min(3650, (int) $value)),
            default                 => $value,
        };
    }
}
