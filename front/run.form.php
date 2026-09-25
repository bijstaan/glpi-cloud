<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpicloud\Run;

Session::checkRight(Run::$rightname, READ);

$item = new Run();
$id   = (int) ($_GET['id'] ?? 0);

Html::header(
    Run::getTypeName(1),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector('glpicloud_run', 'assets')
        : 'assets',
    'glpicloud_run'
);

echo "<div class='glpicloud-surface'>";

if ($id > 0) {
    $item->display(['id' => $id]);
} else {
    $error = new \Glpi\Exception\Http\BadRequestHttpException();
    $error->setMessageToDisplay(__('A sync run is recorded by a sync, not created by hand.', 'glpicloud'));
    throw $error;
}

echo '</div>';

Html::footer();
