<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpicloud\Account;

Session::checkRight(Account::$rightname, READ);

Html::header(
    Account::getTypeName(2),
    $_SERVER['PHP_SELF'],
    class_exists(\GlpiPlugin\Glpinav\Nav::class)
        ? \GlpiPlugin\Glpinav\Nav::sector('glpicloud_account', 'assets')
        : 'assets',
    'glpicloud_account'
);

Search::show(Account::class);

Html::footer();
