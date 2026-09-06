<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpicloud\Resource;

Session::checkRight(Resource::$rightname, READ);

$item = new Resource();
$id   = (int) ($_GET['id'] ?? 0);

// No add, no update: a resource exists because a provider reported it, and a
// hand-edited field would be overwritten by the next sweep — silently, which is
// worse than not offering the edit. Purge is allowed, for the case where an
// account was removed and its inventory is being cleaned up by hand.
if (!empty($_POST['purge'])) {
    Session::checkRight(Resource::$rightname, PURGE);
    $item->check((int) $_POST['id'], PURGE);
    $item->delete($_POST, 1);
    Html::redirect(Resource::getSearchURL());
}

Html::header(
    Resource::getTypeName(1),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector('glpicloud_resource', 'assets')
        : 'assets',
    'glpicloud_resource'
);

// Scope wrapper: everything below is core-rendered markup, which the plugin's
// own stylesheet could otherwise never reach.
echo "<div class='glpicloud-surface'>";

if ($id > 0) {
    $item->display(['id' => $id]);
} else {
    Html::displayErrorAndDie(__('Cloud resources are not created by hand.', 'glpicloud'));
}

echo '</div>';

Html::footer();
