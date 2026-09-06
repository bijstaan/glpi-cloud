<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use CommonDBTM;
use GlpiPlugin\Glpipdf\Doc;

/**
 * The cost statement as a document, for glpi-pdf.
 *
 * The one thing this plugin produces that leaves the building. An MSP's
 * customer does not read a GLPI list; they read the thing that arrives as a
 * file, and until now the answer to "what did our cloud cost last month" was a
 * screenshot of a table.
 *
 * ## Why the statement and not the inventory
 *
 * A resource list would be a longer and less useful document. What somebody
 * asks for in writing is money — and the inventory behind it is already
 * reachable, per resource, from the pages this plugin owns. So the document is
 * {@see Statement}, and the resource list appears only as the top spenders
 * inside it, which is the part of the inventory the money makes relevant.
 *
 * ## Registered as a guarded array of callables, never by inheritance
 *
 * `class_exists(Doc::class)` before anything is offered. A provider class that
 * `extends` or `implements` something from another plugin is a fatal at
 * autoload the moment that plugin is deactivated or half-upgraded — which is
 * exactly when somebody is looking at the page that would have told them why.
 */
final class PdfDocument
{
    /** @return array<int,array<string,mixed>> */
    public static function offers(): array
    {
        if (!class_exists(Doc::class)) {
            return [];
        }

        return [
            [
                'key'      => 'glpicloud.statement',
                'itemtype' => Account::class,
                'label'    => __('Cost statement', 'glpicloud'),
                'kind'     => 'document',
                'weight'   => 10,
                'parts'    => [
                    ['key' => 'cloud.services',  'label' => __('By service', 'glpicloud'), 'default' => true],
                    ['key' => 'cloud.resources', 'label' => __('Top spenders', 'glpicloud'), 'default' => true],
                ],
                'build'    => static fn(CommonDBTM $item, array $parts = []): ?Doc
                    => self::build($item, $parts),
            ],
        ];
    }

    /** @param string[] $parts */
    private static function build(CommonDBTM $item, array $parts = []): ?Doc
    {
        if (!($item instanceof Account)) {
            return null;
        }

        // No period in hand — an export button on a form has none to give — so
        // the most recent one with any cost. "This month" would produce an
        // empty statement for the first days of every month, which reads as a
        // broken export rather than as a month that has barely started.
        $period = Statement::latestPeriod((int) $item->getID());

        if ($period === null) {
            return null;
        }

        $data = Statement::data($item, $period);

        if ($data === null) {
            return null;
        }

        $doc = Doc::make(sprintf(__('%1$s — %2$s', 'glpicloud'), $data['account'], $data['period']))
            ->subtitle(__('Cloud cost statement', 'glpicloud'))
            ->reference(sprintf(__('Account #%d', 'glpicloud'), (int) $item->getID()))
            ->meta([
                __('Entity')                    => $data['entity'],
                __('Provider', 'glpicloud')     => $data['provider'],
                __('Period', 'glpicloud')       => $data['period'],
                __('Total', 'glpicloud')        => Statement::totalLine($data['totals']),
                __('Resources', 'glpicloud')    => (string) $data['resource_count'],
            ]);

        // Before the figures, not after them. A reader who has already taken
        // the number away has not been told, whatever the footnote says.
        if ($data['provisional']) {
            $doc->note(
                __('This period is still provisional. Providers restate a month for days after it '
                    . 'closes, so these figures will change and are not a bill.', 'glpicloud'),
                Doc::WARN
            );
        }

        $want = static fn(string $key): bool => $parts === [] || in_array($key, $parts, true);

        if ($want('cloud.services')) {
            self::services($doc, $data['services']);
        }

        if ($want('cloud.resources')) {
            self::resources($doc, $data['resources']);
        }

        $doc->footnote(__('Amounts are as billed by the provider, in the currency billed. Nothing '
            . 'here is converted between currencies.', 'glpicloud'));

        return $doc;
    }

    /** @param array<int,array<string,mixed>> $services */
    private static function services(Doc $doc, array $services): void
    {
        $doc->section(__('By service', 'glpicloud'));

        if ($services === []) {
            $doc->muted(__('No cost was recorded for this period.', 'glpicloud'));
            return;
        }

        $doc->table(
            [__('Service', 'glpicloud'), __('Currency', 'glpicloud'), __('Amount', 'glpicloud')],
            array_map(
                static fn(array $row): array => [
                    (string) $row['service'],
                    (string) $row['currency'],
                    number_format((float) $row['amount'], 2),
                ],
                $services
            ),
            [60, 15, 25]
        );
    }

    /** @param array<int,array<string,mixed>> $resources */
    private static function resources(Doc $doc, array $resources): void
    {
        $doc->section(__('Top spenders', 'glpicloud'));

        if ($resources === []) {
            $doc->muted(__('No cost was attributed to individual resources.', 'glpicloud'));
            return;
        }

        $doc->table(
            [
                __('Resource', 'glpicloud'),
                __('Type', 'glpicloud'),
                __('Currency', 'glpicloud'),
                __('Amount', 'glpicloud'),
            ],
            array_map(
                static fn(array $row): array => [
                    (string) $row['name'],
                    (string) $row['type'],
                    (string) $row['currency'],
                    number_format((float) $row['amount'], 2),
                ],
                $resources
            ),
            [45, 20, 12, 23]
        );
    }
}
