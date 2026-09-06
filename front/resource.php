<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpicloud\Resource;

Session::checkRight(Resource::$rightname, READ);

Html::header(
    Resource::getTypeName(2),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector('glpicloud_resource', 'assets')
        : 'assets',
    'glpicloud_resource'
);

Search::show(Resource::class);

Html::footer();
