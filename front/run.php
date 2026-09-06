<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpicloud\Run;

Session::checkRight(Run::$rightname, READ);

Html::header(
    Run::getTypeName(2),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector('glpicloud_run', 'assets')
        : 'assets',
    'glpicloud_run'
);

Search::show(Run::class);

Html::footer();
