<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Shared harness for the pure suites.
 *
 * These deliberately do not boot GLPI. The provider registry, the
 * normalisation rules and the checksum have no GLPI base class, no database
 * access and read no configuration — precisely so the part of this plugin most
 * likely to be subtly wrong can be exercised with nothing but `php file.php`.
 *
 * Anything that needs the instance lives in tests/sync.php, which is run
 * deliberately inside the container.
 */

const CLOUD_SRC = __DIR__ . '/../src';

require_once CLOUD_SRC . '/Provider.php';
require_once CLOUD_SRC . '/Registry.php';
require_once CLOUD_SRC . '/Normalise.php';
require_once CLOUD_SRC . '/History.php';
require_once CLOUD_SRC . '/Costs.php';

final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var string[] */
    public static array $failures = [];

    public static function ok(bool $condition, string $what): void
    {
        if ($condition) {
            self::$passed++;

            return;
        }

        self::$failed++;
        self::$failures[] = $what;
    }

    public static function is(mixed $actual, mixed $expected, string $what): void
    {
        if ($actual === $expected) {
            self::$passed++;

            return;
        }

        self::$failed++;
        self::$failures[] = sprintf(
            "%s\n      expected: %s\n      actual:   %s",
            $what,
            self::show($expected),
            self::show($actual)
        );
    }

    private static function show(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . $value . "'";
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    public static function done(string $suite): int
    {
        if (self::$failed === 0) {
            printf("%-14s %d passed\n", $suite, self::$passed);

            return 0;
        }

        printf("%-14s %d passed, %d FAILED\n", $suite, self::$passed, self::$failed);

        foreach (self::$failures as $failure) {
            echo '  - ' . $failure . "\n";
        }

        return 1;
    }
}

/**
 * Collect the warnings the registry raises when a provider misbehaves.
 *
 * They are the product, not noise: a dropped provider that says nothing is a
 * provider nobody knows to fix.
 */
final class Complaints
{
    /** @var string[] */
    public static array $lines = [];

    public static function watch(): void
    {
        self::$lines = [];

        set_error_handler(static function (int $level, string $message): bool {
            if ($level === E_USER_WARNING) {
                self::$lines[] = $message;

                return true;
            }

            return false;
        });
    }

    public static function stop(): void
    {
        restore_error_handler();
    }

    public static function mentions(string $needle): bool
    {
        foreach (self::$lines as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }
}
