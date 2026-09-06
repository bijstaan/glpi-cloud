<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

/**
 * URLs for this plugin's own pages and assets.
 *
 * GLPI 11 deprecated Plugin::getWebDir in favour of the plain `/plugins/` path,
 * but that path still needs root_doc in front of it or an installation in a
 * subdirectory breaks every link. Files under `public/` are served without it.
 */
final class Url
{
    public const KEY = 'glpicloud';

    /** Root-relative, WITHOUT root_doc — what Html::css()/script() expect. */
    public static function path(string $path): string
    {
        return '/plugins/' . self::KEY . '/' . ltrim($path, '/');
    }

    /** Absolute, including root_doc — for links and anything fetched by JS. */
    public static function to(string $path): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . self::path($path);
    }
}
