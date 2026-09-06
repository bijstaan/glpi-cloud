<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The reference provider: JSON off disk, no network, no cloud account.
 *
 * This exists to keep the seam honest. Everything the core does — enumeration,
 * checkpointing, change detection, disappearance, cost, the rollup — is
 * exercised through a provider that knows nothing about any real cloud. If a
 * core feature cannot be demonstrated with this, provider knowledge has leaked
 * into the core and that is the bug.
 *
 * It is also the worked example a provider author should read first: the whole
 * contract is a descriptor of scalars and callables, and nothing here extends
 * or implements anything of the plugin's.
 */
final class CloudFixture
{
    /** @var array<string,mixed> */
    public static array $estate = [];

    /** Services whose collect() throws, to prove failure stays local. */
    public static array $failing = [];

    /** Rows yielded per checkpoint, so paging and resumption are real. */
    public static int $page = 2;

    /** Recorded contexts, so a test can assert what the core handed over. */
    public static array $seen_cursors = [];

    /** What check() should answer. */
    public static bool $credentials_ok = true;

    public static function load(?string $path = null): void
    {
        $path = $path ?? __DIR__ . '/fixtures/estate.json';

        self::$estate       = (array) json_decode((string) file_get_contents($path), true);
        self::$failing      = [];
        self::$seen_cursors = [];
    }

    /** Drop a resource, as a provider does when somebody deletes one. */
    public static function remove(string $service, string $scope, string $native_id): void
    {
        self::$estate['resources'][$service][$scope] = array_values(array_filter(
            self::$estate['resources'][$service][$scope] ?? [],
            static fn(array $row): bool => $row['native_id'] !== $native_id
        ));
    }

    /** Change a field on a resource, as a provider does when somebody edits one. */
    public static function set(string $service, string $scope, string $native_id, string $field, mixed $value): void
    {
        foreach (self::$estate['resources'][$service][$scope] ?? [] as $i => $row) {
            if ($row['native_id'] === $native_id) {
                self::$estate['resources'][$service][$scope][$i][$field] = $value;
            }
        }
    }

    /** @return array<string,mixed> the descriptor the registry validates */
    public static function describe(): array
    {
        return [
            'key'         => 'fixture',
            'name'        => 'Fixture cloud',
            'icon'        => 'ti ti-test-pipe',
            'supplier'    => 'Nobody',
            'credentials' => [
                ['key' => 'account_id', 'label' => 'Account id', 'required' => true],
                ['key' => 'secret', 'label' => 'Secret', 'secret' => true, 'required' => true],
            ],
            'check'  => [self::class, 'check'],
            'scopes' => [self::class, 'scopes'],
            'services' => [
                [
                    'key'     => 'compute',
                    'name'    => 'Compute',
                    'types'   => ['virtualmachine', 'disk'],
                    'collect' => static fn(array $ctx): iterable => self::collect('compute', $ctx),
                ],
                [
                    'key'     => 'storage',
                    'name'    => 'Storage',
                    'types'   => ['storageaccount'],
                    'collect' => static fn(array $ctx): iterable => self::collect('storage', $ctx),
                ],
            ],
            'costs' => [self::class, 'costs'],
        ];
    }

    /** @return array{ok:bool,message:string,identity:string} */
    public static function check(array $account): array
    {
        $credentials = (array) ($account['credentials'] ?? []);

        if (!self::$credentials_ok || ($credentials['secret'] ?? '') === '') {
            return ['ok' => false, 'message' => 'no secret', 'identity' => ''];
        }

        return ['ok' => true, 'message' => 'ok', 'identity' => 'fixture:' . ($credentials['account_id'] ?? '')];
    }

    /** @return array<int,array{key:string,name:string,is_active:bool}> */
    public static function scopes(array $account): array
    {
        return (array) (self::$estate['scopes'] ?? []);
    }

    /**
     * Enumerate one service, in pages, checkpointing as it goes.
     *
     * The cursor is this provider's own word for "carry on from here" — here an
     * offset, elsewhere a `$skipToken` — and the core never looks inside it.
     */
    public static function collect(string $service, array $ctx): iterable
    {
        if (in_array($service, self::$failing, true)) {
            throw new RuntimeException('the fixture was told to fail ' . $service);
        }

        $scope  = (string) $ctx['scope'];
        $cursor = $ctx['cursor'];

        self::$seen_cursors[] = [$service, $scope, $cursor];

        $rows   = (array) (self::$estate['resources'][$service][$scope] ?? []);
        $offset = $cursor === null ? 0 : (int) $cursor;
        $rows   = array_slice($rows, $offset);

        $emitted = 0;

        foreach ($rows as $row) {
            if (!($ctx['request'])()) {
                return;
            }

            yield $row;

            $emitted++;
            $offset++;

            if ($emitted % self::$page === 0) {
                ($ctx['checkpoint'])((string) $offset);
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function costs(array $ctx): iterable
    {
        return (array) (self::$estate['costs'] ?? []);
    }
}
