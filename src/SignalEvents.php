<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use GlpiPlugin\Glpisignal\Ingest;
use Plugin;
use Throwable;

/**
 * Cloud sync failures, into glpi-signal's alert pipeline.
 *
 * ## The failure worth waking somebody for
 *
 * Not a VM going down — the provider's own monitoring saw that first and this
 * plugin would be the slowest possible way to hear it. The failure that belongs
 * here is the one **only this plugin can see: its own sync stopping.**
 *
 * An expired Azure client secret does not break anything visibly. The pages
 * still render, the resources are still listed, the costs are still there. They
 * are simply *frozen*, and they stay frozen — quietly, for weeks — until
 * somebody notices that a decommissioned VM is still on the asset list or that
 * a month's spend never arrived. Every consumer downstream keeps reporting the
 * stale numbers with full confidence, which is worse than an outage because an
 * outage is at least visible.
 *
 * So: a failing account raises an alert, a recovering account resolves it, and
 * a *partial* sweep does neither.
 *
 * ## Why partial is not an alert
 *
 * A partial sweep is the checkpointing working as designed — the request budget
 * or the wall-clock budget ran out, the sweep kept its place, and the next tick
 * resumes. Paging for that would page for normal operation on any large estate,
 * and a channel that cries wolf during business hours is a channel somebody
 * mutes before the night it matters.
 *
 * ## The fingerprint is per account, deliberately
 *
 * One account failing every six hours must be one alert with a rising count,
 * not forty. glpi-signal collapses repeats onto a fingerprint, so the
 * fingerprint is derived from the account id alone — the message changes as the
 * provider's error changes, the identity does not.
 *
 * ## Off unless somebody chose a source
 *
 * No source configured, no events. This is not a switch with a sensible
 * default: events have to land in a glpi-signal source, that source decides
 * severity floors and routing, and inventing one would put cloud failures into
 * whatever stream happened to be first. The settings page asks.
 */
final class SignalEvents
{
    /** Never throws to a caller: emitting an event must not fail a sweep. */
    public static function syncOutcome(Account $account, string $status, string $error): void
    {
        try {
            self::emit($account, $status, $error);
        } catch (Throwable $e) {
            trigger_error(
                'glpicloud: could not raise a signal event: ' . $e->getMessage(),
                E_USER_WARNING
            );
        }
    }

    private static function emit(Account $account, string $status, string $error): void
    {
        $sources_id = (int) Settings::get('signal_sources_id');

        if ($sources_id <= 0 || !self::available()) {
            return;
        }

        // Partial is normal operation on a large estate; see the class
        // docblock. Anything else is either a failure or a recovery.
        if ($status === Run::PARTIAL) {
            return;
        }

        $failing = $status === Run::FAILED;

        $accounts_id = (int) $account->getID();
        $name        = (string) $account->fields['name'];
        $provider    = (string) $account->fields['provider'];

        Ingest::submit($sources_id, [
            'summary'  => $failing
                ? sprintf('Cloud sync failing for %s (%s)', $name, $provider)
                : sprintf('Cloud sync recovered for %s (%s)', $name, $provider),
            // High rather than critical: the estate is not down, it is going
            // stale. Critical is what an entity's production being unreachable
            // should mean, and spending it here devalues it there.
            'severity' => 'high',
            'status'   => $failing ? 'firing' : 'resolved',
            'host'     => $name,
            // Per account, so six-hourly repeats collapse onto one alert with a
            // count rather than filling the queue.
            'fingerprint' => 'glpicloud/account/' . $accounts_id,
            'labels'   => [
                'plugin'   => 'glpicloud',
                'provider' => $provider,
                'account'  => $name,
                'entity'   => (string) $account->fields['entities_id'],
            ],
            'raw'      => [
                'accounts_id' => $accounts_id,
                'status'      => $status,
                // The provider's own words. Truncated to what the account row
                // stores, because that is what an operator will see when they
                // open the account to check the alert against it, and two
                // different truncations of the same error read as two errors.
                'error'       => mb_substr($error, 0, 255),
            ],
        ]);
    }

    /**
     * Is glpi-signal there to receive anything?
     *
     * Both halves matter. `isPluginActive` is false for a plugin that is
     * installed but switched off, and `class_exists` is false for one that was
     * removed from disk without being uninstalled — a state that is common
     * enough during upgrades, and fatal if the call is made anyway.
     */
    public static function available(): bool
    {
        return Plugin::isPluginActive('glpisignal') && class_exists(Ingest::class);
    }
}
