<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI Cloud — cloud resources as first-class inventory.
 *
 * This plugin makes exactly zero calls to a cloud API. Every provider — Azure,
 * AWS, GCP — is a separate plugin that registers itself through the
 * `glpicloud_providers` hook and hands back normalised rows; everything
 * downstream of a normalised row is here: storage, identity, entity, lifecycle,
 * money and the UI.
 *
 * The seam is deliberate and it is testable: `tests/` exercises the whole of
 * this plugin against a fixture provider that reads JSON off disk. If a feature
 * here cannot be demonstrated without a real cloud account, provider knowledge
 * has leaked into the core.
 */

use GlpiPlugin\Glpicloud\Account;
use GlpiPlugin\Glpicloud\Menu;
use GlpiPlugin\Glpicloud\Resource;

define('PLUGIN_GLPICLOUD_VERSION', '0.1.0');
define('PLUGIN_GLPICLOUD_MIN_GLPI', '11.0');
define('PLUGIN_GLPICLOUD_CONFIG_CONTEXT', 'plugin:glpicloud');

function plugin_init_glpicloud()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpicloud'] = true;

    // `notificationtemplates_types` puts Account in the itemtype dropdown on
    // Setup > Notifications > Templates, which is what makes the cost
    // statement's wording editable and lets glpi-mail give it the house design.
    // Deliberately no Notification record uses it — see Statement.
    Plugin::registerClass(Account::class, ['notificationtemplates_types' => true]);

    // A cloud resource joins core's Management model rather than growing a
    // parallel one. `Plugin::registerClass()` splices an itemtype into any
    // `*_types` array present in $CFG_GLPI (src/Plugin.php, the
    // preg_grep('/.+_types/') loop), so this is all it takes for a resource to
    // carry a native Infocom, hang off a Contract, hold documents and links,
    // and be the subject of a ticket.
    //
    // Money then lives where GLPI already reports on it — see Costs —
    // instead of in a cost model of our own that would have
    // to re-earn budgets, suppliers and every financial screen core has.
    Plugin::registerClass(Resource::class, [
        'infocom_types'  => true,
        'contract_types' => true,
        'document_types' => true,
        'link_types'     => true,
        'ticket_types'   => true,
    ]);

    // Assets, because that is what these are. The settings page is reached from
    // Setup > Plugins, which already links it; a second entry would be a second
    // link to the same page.
    $PLUGIN_HOOKS['menu_toadd']['glpicloud'] = ['assets' => Menu::class];
    $PLUGIN_HOOKS['config_page']['glpicloud'] = 'front/config.php';

    // Dark-palette conformance for this plugin's own containers.
    $PLUGIN_HOOKS['add_css']['glpicloud'] = 'css/glpicloud.css';

    // Credentials are provider-shaped, so the column holding them is one JSON
    // blob rather than a fixed set of fields. Declaring it secured means core
    // encrypts it, masks it in the history log, and — the part that matters on
    // a long-lived instance — re-encrypts it when somebody runs
    // glpi:security:changekey.
    $PLUGIN_HOOKS['secured_fields']['glpicloud'] = [
        Account::getTable() . '.credentials',
    ];

    // Offered to glpi-ai's assistant as tools. Registered unconditionally:
    // only glpi-ai reads this hook, so an instance without it pays one array
    // assignment and never loads the class — while guarding on
    // Plugin::isPluginActive('glpiai') would run a database lookup on every
    // request to avoid exactly that.
    $PLUGIN_HOOKS['glpiai_tools']['glpicloud'] = [\GlpiPlugin\Glpicloud\AiTools::class, 'all'];

    // The other three suite seams. All three are registered unconditionally and
    // all three return [] when the receiving plugin is absent: the hook array is
    // read by anything that walks $PLUGIN_HOOKS, and guarding these on
    // Plugin::isPluginActive() would put a database lookup on every request to
    // save three array assignments.
    //
    // Arrays and callables in both directions, never inheritance across a plugin
    // boundary — a class here extending one of theirs is a fatal at autoload the
    // moment they are deactivated or mid-upgrade.
    $PLUGIN_HOOKS['glpimail_letters']['glpicloud']   = [\GlpiPlugin\Glpicloud\MailLetters::class, 'offers'];
    $PLUGIN_HOOKS['glpipdf_documents']['glpicloud']  = [\GlpiPlugin\Glpicloud\PdfDocument::class, 'offers'];

}

function plugin_version_glpicloud()
{
    return [
        'name'         => 'GLPI Cloud',
        'version'      => PLUGIN_GLPICLOUD_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-cloud',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPICLOUD_MIN_GLPI]],
    ];
}

function plugin_glpicloud_check_prerequisites()
{
    return true;
}

function plugin_glpicloud_check_config($verbose = false)
{
    return true;
}
