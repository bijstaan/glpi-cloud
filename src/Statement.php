<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use NotificationTemplate;

/**
 * The cost statement: one account, one billing period, what it cost and on what.
 *
 * The artefact this plugin exists to produce for a person rather than for a
 * query. Everything else here answers "what is running"; this answers "what are
 * we paying for it", which is the question that arrives from the side of the
 * business that signs things.
 *
 * ## One statement, three renderings
 *
 * The figures are assembled once, by {@see data()}, and three surfaces consume
 * them — the notification template seeded here, glpi-mail's branded letter
 * ({@see MailLetters}), and glpi-pdf's document ({@see PdfDocument}). That is
 * deliberate: a PDF and an email that disagree about an entity's cloud bill is
 * not a formatting bug, it is an invoice dispute.
 *
 * ## Provisional is said, never smoothed
 *
 * A cloud month is restated for days after it closes. A statement for a period
 * that still carries provisional rows says so in every rendering, at the top,
 * because the number is going to change and somebody is going to quote it. The
 * plugin already refuses to roll a provisional period onto a contract
 * ({@see Costs::rollup()}) for the same reason; this is the same rule made
 * visible rather than merely enforced.
 *
 * ## Why there is a template but no Notification
 *
 * The same convention glpi-report and glpi-signal follow, and for the same
 * reason. Seeding a `NotificationTemplate` is what makes the wording editable
 * under Setup → Notifications → Templates, translatable through `##lang.*##`,
 * and available to glpi-mail's house design. Seeding a `Notification` would
 * additionally claim that this plugin sends the statement on a schedule to a
 * configured target list — it does not, and a switch that appears to control
 * delivery which is not happening is worse than no switch.
 */
final class Statement
{
    /** The NotificationTemplate name. Also the key glpi-mail's offer matches on. */
    public const NAME = 'Cloud cost statement';

    /**
     * Everything a rendering needs, assembled once.
     *
     * @return array{
     *     account:string, provider:string, entity:string, period:string,
     *     totals:array<string,float>, provisional:bool,
     *     services:array<int,array<string,mixed>>, resources:array<int,array<string,mixed>>,
     *     resource_count:int
     * }|null null when the account or period is not one we can speak about
     */
    public static function data(Account $account, string $period): ?array
    {
        $normalised = Costs::normalisePeriod($period);

        if ($normalised === null) {
            return null;
        }

        $accounts_id = (int) $account->getID();
        $breakdown   = Costs::breakdown($accounts_id, $normalised);

        return [
            'account'     => (string) $account->fields['name'],
            'provider'    => (string) $account->fields['provider'],
            'entity'      => \Dropdown::getDropdownName('glpi_entities', (int) $account->fields['entities_id']),
            'period'      => $normalised,
            'totals'      => Costs::totals($accounts_id, $normalised),
            'provisional' => Costs::isProvisional($accounts_id, $normalised),
            'services'    => $breakdown['services'],
            'resources'   => $breakdown['resources'],
            'resource_count' => self::resourceCount($accounts_id),
        ];
    }

    /**
     * Totals as one line of text, in every currency the period was billed in.
     *
     * Never converted, never summed across currencies. An account billed in two
     * currencies has two totals; producing one by applying a rate this plugin
     * invented would be a number nobody can reconcile against either invoice.
     *
     * @param array<string,float> $totals
     */
    public static function totalLine(array $totals): string
    {
        if ($totals === []) {
            return __('No cost recorded for this period.', 'glpicloud');
        }

        $parts = [];
        foreach ($totals as $currency => $amount) {
            $parts[] = sprintf('%s %s', number_format($amount, 2), $currency);
        }

        return implode(' + ', $parts);
    }

    /** How many resources the account currently has, for context on the total. */
    private static function resourceCount(int $accounts_id): int
    {
        return (int) countElementsInTable(Resource::getTable(), [
            'plugin_glpicloud_accounts_id' => $accounts_id,
            'disappeared_at'               => null,
        ]);
    }

    /**
     * The most recent period this account has any cost for.
     *
     * What a surface with no period in hand should show — an export button on
     * an account form has no month to offer, and defaulting to "this month"
     * produces an empty statement for the first few days of every month.
     */
    public static function latestPeriod(int $accounts_id): ?string
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['period'],
                'FROM'   => Costs::TABLE,
                'WHERE'  => ['plugin_glpicloud_accounts_id' => $accounts_id],
                'ORDER'  => ['period DESC'],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            return (string) $row['period'];
        }

        return null;
    }

    // ================================================================ template

    public static function templatesId(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_notificationtemplates')) {
            return 0;
        }

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notificationtemplates',
                'WHERE'  => ['itemtype' => Account::class],
                'ORDER'  => 'id',
                'LIMIT'  => 1,
            ]) as $row
        ) {
            return (int) $row['id'];
        }

        return 0;
    }

    public static function install(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (['glpi_notificationtemplates', 'glpi_notificationtemplatetranslations'] as $table) {
            if (!$DB->tableExists($table)) {
                return;
            }
        }

        if (self::templatesId() > 0) {
            return;
        }

        $template = new NotificationTemplate();
        $id       = $template->add([
            'name'     => self::NAME,
            'itemtype' => Account::class,
            'comment'  => 'Installed by the cloud plugin. Safe to edit; it is not overwritten. '
                . 'There is deliberately no Notification using it: this plugin does not send the '
                . 'statement on a schedule, and a switch that looks like it controls delivery that '
                . 'is not happening is worse than no switch.',
            'css'      => '',
        ]);

        if ($id === false) {
            return;
        }

        // Written with $DB rather than through the itemtype, so that
        // NotificationTemplateTranslation::cleanContentHtml() does not derive
        // the text part from the HTML — the same reason glpi-report does.
        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => (int) $id,
            'language'                 => '',
            'subject'                  => '##cloud.account## — ##cloud.period##',
            'content_text'             => self::text(),
            'content_html'             => self::html(),
        ]);
    }

    public static function uninstall(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_notificationtemplates')) {
            return;
        }

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notificationtemplates',
                'WHERE'  => ['itemtype' => Account::class],
            ]) as $row
        ) {
            $DB->delete('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => (int) $row['id'],
            ]);
            $DB->delete('glpi_notificationtemplates', ['id' => (int) $row['id']]);
        }
    }

    private static function text(): string
    {
        return <<<'TXT'
##cloud.account## (##cloud.provider##)
##lang.cloud.entity##: ##cloud.entity##
##lang.cloud.period##: ##cloud.period##

##lang.cloud.total##: ##cloud.total##
##cloud.provisionalnote##

##lang.cloud.services##
##FOREACHservices##
 - ##service.name##: ##service.amount##
##ENDFOREACHservices##
TXT;
    }

    private static function html(): string
    {
        return <<<'HTML'
<p><strong>##cloud.account##</strong> (##cloud.provider##)</p>
<ul>
  <li>##lang.cloud.entity##: ##cloud.entity##</li>
  <li>##lang.cloud.period##: ##cloud.period##</li>
  <li>##lang.cloud.total##: <strong>##cloud.total##</strong></li>
</ul>
<p>##cloud.provisionalnote##</p>
<p>##lang.cloud.services##</p>
<ul>
##FOREACHservices##
  <li>##service.name##: ##service.amount##</li>
##ENDFOREACHservices##
</ul>
HTML;
    }
}
