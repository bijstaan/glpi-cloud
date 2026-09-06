<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Setup > Cloud.
 *
 * House style: one container at 960px, status first, one form, one Save, and
 * READ opens the page while UPDATE saves it — a page that renders an editable
 * form and then answers Save with access-denied is worse than one that says so
 * at the top.
 *
 * The form deliberately has no `action` attribute. `$_SERVER['PHP_SELF']` under
 * GLPI 11 is the front controller, so a form posting to it posts to
 * `/index.php`, the router answers 400, and the page comes back looking exactly
 * as though it had saved.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpicloud\Account;
use GlpiPlugin\Glpicloud\Registry;
use GlpiPlugin\Glpicloud\Provider;
use GlpiPlugin\Glpicloud\Settings;

Session::checkRight('plugin_glpicloud_config', READ);

$can_edit = (bool) Session::haveRight('plugin_glpicloud_config', UPDATE);

if (!empty($_POST['update'])) {
    Session::checkRight('plugin_glpicloud_config', UPDATE);

    // Only the keys this form owns. Checkboxes that are off post nothing, so
    // they are read as explicit zeroes here rather than left to a handler that
    // rebuilds every setting from $_POST — the trap that silently switched off
    // half of glpi-netscan's settings whenever any card was saved.
    $flags = ['enabled', 'cost_enabled', 'cost_rollup', 'projection_enabled'];
    $input = [];

    foreach ($flags as $flag) {
        $input[$flag] = isset($_POST[$flag]) ? 1 : 0;
    }

    foreach (['sync_interval', 'run_seconds', 'request_budget', 'history_days', 'keep_disappeared_days'] as $number) {
        if (isset($_POST[$number])) {
            $input[$number] = (int) $_POST[$number];
        }
    }

    Settings::save($input);
    Session::addMessageAfterRedirect(__s('Saved.', 'glpicloud'), true, INFO);
    Html::back();
}

Html::header(
    __('Cloud', 'glpicloud'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$settings  = Settings::all();
$providers = Registry::providers();
$e         = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$accounts = countElementsInTable(Account::getTable(), ['is_deleted' => 0]);
$active   = countElementsInTable(Account::getTable(), ['is_deleted' => 0, 'is_active' => 1]);

echo "<div class='container-fluid glpicloud-config' style='max-width:960px'>";

if (!$can_edit) {
    echo "<div class='alert alert-info'>"
       . __s('Read only: you can see this configuration but not change it.', 'glpicloud')
       . '</div>';
}

// ------------------------------------------------------------------ status
echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3 class='card-title'>" . __s('Status', 'glpicloud') . '</h3>';

if ($providers === []) {
    echo "<div class='alert alert-warning mb-2'>"
       . __s('No cloud provider plugin is installed, so nothing can be collected. Install glpi-cloud-azure (or another provider) and activate it.', 'glpicloud')
       . '</div>';
} else {
    echo "<div class='mb-2'>" . __s('Providers installed', 'glpicloud') . ': ';
    foreach ($providers as $provider) {
        /** @var Provider $provider */
        echo "<span class='badge bg-blue-lt me-1'>" . $e($provider->name()) . '</span>';
        // Deliberately outside the badge: muted text inside a tinted badge
        // composites one low-contrast colour over another, and measures under
        // the floor on GLPI's lighter dark palettes.
        echo "<span class='text-muted me-3'>" . $e($provider->plugin()) . '</span>';
    }
    echo '</div>';
}

if ((int) $settings['enabled'] !== 1) {
    echo "<div class='alert alert-warning mb-2'>"
       . __s('Cloud sync is switched off. No provider is contacted and the cron does nothing.', 'glpicloud')
       . '</div>';
} elseif ($active === 0) {
    echo "<div class='alert alert-warning mb-2'>"
       . __s('Sync is on, but no cloud account is active yet.', 'glpicloud')
       . '</div>';
}

echo "<div class='text-muted'>"
   . sprintf(
       __s('%1$d cloud account(s), %2$d active.', 'glpicloud'),
       $accounts,
       $active
   )
   . ' <a href="' . Account::getSearchURL() . '">' . __s('Manage accounts', 'glpicloud') . '</a>'
   . '</div>';

echo '</div></div>';

// ------------------------------------------------------------------- form
echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

$checkbox = static function (string $key, string $label, string $help) use ($settings, $can_edit, $e): void {
    echo "<label class='form-check form-switch'>";
    echo "<input class='form-check-input' type='checkbox' name='" . $e($key) . "' value='1'"
       . ((int) $settings[$key] === 1 ? ' checked' : '')
       . ($can_edit ? '' : ' disabled')
       . '>';
    echo "<span class='form-check-label'>" . $e($label) . '</span>';
    echo '</label>';

    if ($help !== '') {
        echo "<div class='form-text mb-3'>" . $e($help) . '</div>';
    }
};

$number = static function (string $key, string $label, string $help) use ($settings, $can_edit, $e): void {
    echo "<div class='mb-3'>";
    echo "<label class='form-label'>" . $e($label) . '</label>';
    echo "<input class='form-control' type='number' min='0' name='" . $e($key) . "' value='"
       . $e((string) $settings[$key]) . "'" . ($can_edit ? '' : ' disabled') . '>';
    if ($help !== '') {
        echo "<div class='form-text'>" . $e($help) . '</div>';
    }
    echo '</div>';
};

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3 class='card-title'>" . __s('Collection', 'glpicloud') . '</h3>';
$checkbox(
    'enabled',
    __('Sync cloud accounts', 'glpicloud'),
    __('Off by default. Nothing is contacted, and no cron does any work, until this is on. Every credential this plugin uses is read-only: it never writes to a cloud account.', 'glpicloud')
);
$number(
    'sync_interval',
    __('Default interval between syncs (seconds)', 'glpicloud'),
    __('Each account may override this. Six hours suits a control plane that is not changing minute to minute.', 'glpicloud')
);
$number(
    'run_seconds',
    __('Wall-clock budget per account per run (seconds)', 'glpicloud'),
    __('A sweep is checkpointed, so a small budget means a slower catch-up rather than a failure. A cron that never returns is worse than one that makes progress.', 'glpicloud')
);
$number(
    'request_budget',
    __('Provider requests per run', 'glpicloud'),
    __('The outer bound that stops a misbehaving provider spending an hour of PHP. The provider paces itself against its own rate limits.', 'glpicloud')
);
echo '</div></div>';

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3 class='card-title'>" . __s('Cost', 'glpicloud') . '</h3>';
$checkbox(
    'cost_enabled',
    __('Collect cost from providers that offer it', 'glpicloud'),
    ''
);
$checkbox(
    'cost_rollup',
    __('Write closed periods onto the account contract', 'glpicloud'),
    __("One native contract cost line per account, period and currency, attributed to the account's budget — so GLPI's own budget screens count cloud spend. A period that is still provisional is never written.", 'glpicloud')
);
echo '</div></div>';

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3 class='card-title'>" . __s('Retention', 'glpicloud') . '</h3>';
$number(
    'history_days',
    __('Keep resource change history for (days)', 'glpicloud'),
    __('History is what answers "when did this change", so it outlives the resource.', 'glpicloud')
);
$number(
    'keep_disappeared_days',
    __('Keep resources that have disappeared for (days)', 'glpicloud'),
    __('They stay visible in the meantime: "it is gone" is usually the answer somebody came looking for.', 'glpicloud')
);
echo '</div></div>';

echo "<div class='card mb-3'><div class='card-body'>";
echo "<h3 class='card-title'>" . __s('Native assets', 'glpicloud') . '</h3>';
$checkbox(
    'projection_enabled',
    __('Maintain native GLPI assets for selected resource types', 'glpicloud'),
    __('Off, and per type when on. Projecting a dev subscription of four thousand resources into Computers rewrites the asset list the service desk depends on.', 'glpicloud')
);
echo "<div class='text-muted'>" . __s('Per-type mapping arrives with the projection milestone; this switch does nothing on its own yet.', 'glpicloud') . '</div>';
echo '</div></div>';

if ($can_edit) {
    echo "<div class='text-end mb-4'>";
    echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . __s('Save') . '</button>';
    echo '</div>';
}

echo '</form>';
echo '</div>';

Html::footer();
