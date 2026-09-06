<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use GlpiPlugin\Glpimail\Letter;

/**
 * What the cost statement looks like when glpi-mail is installed.
 *
 * Registered under `glpimail_letters`. Without it the statement is still a real
 * notification — {@see Statement} seeds a template and GLPI renders it — but
 * the body is the plain `<ul>` a template editor can express, and glpi-mail can
 * only *wrap* that. Describing it here means a customer's cloud bill arrives as
 * the same card as every other message the estate sends, with the figures as
 * labelled rows rather than as a bulleted list of colons.
 *
 * That matters more for this message than for most: it is the one that contains
 * a number somebody will be asked to justify, and the one most likely to be
 * forwarded to whoever approves the spend.
 *
 * ### The provisional pill is the whole design
 *
 * Everything else here is layout. A cloud month is restated for days after it
 * closes, so a statement for an open period is a *forecast* being read as an
 * invoice unless the message says otherwise — and a caveat in the last
 * paragraph is a caveat nobody read. It goes at the top, as a pill, in the
 * colour the reader already associates with "wait".
 *
 * ### Nothing here is required for the plugin to work
 *
 * glpi-mail is optional and this file is only loaded when it asks. The
 * `class_exists()` guard is not defensive habit: `$PLUGIN_HOOKS` is walked by
 * anything that reads it, and returning an offer referencing a class from an
 * uninstalled plugin turns a missing optional dependency into a fatal.
 */
final class MailLetters
{
    /** @return array<int,array<string,mixed>> */
    public static function offers(): array
    {
        if (!class_exists(Letter::class)) {
            return [];
        }

        return [
            [
                'name'     => Statement::NAME,
                'itemtype' => Account::class,
                'summary'  => __('What one cloud account cost for one billing period, by service. '
                    . 'Says plainly when the period is still provisional.', 'glpicloud'),
                'build'    => [self::class, 'statement'],
            ],
        ];
    }

    public static function statement(): Letter
    {
        return Letter::make()
            ->eyebrow('##cloud.provider##')
            ->title('##cloud.account##')
            ->pill('##cloud.period##', Letter::INFO)
            // Guarded on the flag rather than printed unconditionally: a closed
            // period carrying a "this may change" line teaches people to ignore
            // the line, and then it is not there when it matters.
            ->when(
                'cloud.isprovisional',
                static fn(Letter $letter) => $letter->note('This period is still provisional. '
                    . 'Providers restate a month for days after it closes, so this figure will '
                    . 'change and is not a bill.')
            )
            ->row('##lang.cloud.entity##', '##cloud.entity##')
            ->row('##lang.cloud.period##', '##cloud.period##')
            ->row('##lang.cloud.total##', '##cloud.total##')
            ->row('##lang.cloud.resources##', '##cloud.resourcecount##')
            // Guarded on the count, not on the list: GLPI strips arrays out of
            // the data handed to processIf(), so `##IFservices##` is always
            // false, and a "By service" heading over nothing reads as a fault
            // in the mail rather than as an account with no attributed spend.
            ->when('cloud.servicecount', static fn(Letter $letter) => $letter
                ->section('By service')
                ->loop('services', null, static fn(Letter $inner) => $inner->row(
                    '##service.name##',
                    '##service.amount##'
                )))
            ->note('Amounts are as billed by the provider, in the currency billed. Nothing here is '
                . 'converted between currencies.');
    }
}
