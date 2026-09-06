<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use CommonGLPI;
use Session;

/**
 * Assets menu entries.
 *
 * Three of them, as `is_multi_entries`: the inventory, the accounts it came
 * from, and the runs that collected it. Burying the second two behind the first
 * makes them unfindable, and "why is this empty" is answered by the third.
 *
 * Configuration is deliberately not here — it is reached from Setup > Plugins,
 * which already links it.
 *
 * The `: bool` return types on canView/canCreate are load-bearing: CommonGLPI
 * declares them, and a signature mismatch is a fatal inside menu generation,
 * which takes out every page in GLPI rather than just this one.
 */
final class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Cloud', 'glpicloud');
    }

    public static function getIcon()
    {
        return 'ti ti-cloud';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(Resource::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return (bool) Session::haveRight(Account::$rightname, CREATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        $entries = ['is_multi_entries' => true];

        // The inventory. The page somebody arrives looking for.
        $entries['glpicloud_resource'] = [
            'title' => Resource::getTypeName(2),
            'page'  => Resource::getSearchURL(false),
            'icon'  => Resource::getIcon(),
            // No 'add': a resource exists because a provider reported it. An
            // empty 'add' is not the same as none — GLPI renders the button
            // either way and an empty target sends the operator to the
            // dashboard.
            'links' => ['search' => Resource::getSearchURL(false)],
        ];

        if (Session::haveRight(Account::$rightname, READ)) {
            $entries['glpicloud_account'] = [
                'title' => Account::getTypeName(2),
                'page'  => Account::getSearchURL(false),
                'icon'  => Account::getIcon(),
                'links' => array_filter([
                    'search' => Account::getSearchURL(false),
                    'add'    => self::canCreate() ? Account::getFormURL(false) : '',
                ]),
            ];
        }

        $entries['glpicloud_run'] = [
            'title' => Run::getTypeName(2),
            'page'  => Run::getSearchURL(false),
            'icon'  => Run::getIcon(),
            'links' => ['search' => Run::getSearchURL(false)],
        ];

        return $entries;
    }
}
