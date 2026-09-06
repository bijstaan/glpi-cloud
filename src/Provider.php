<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use RuntimeException;

/**
 * One registered cloud provider, validated.
 *
 * A provider plugin hands the registry a plain array of scalars and callables
 * (see {@see Registry}); this is what the core passes around afterwards, so
 * that the rest of the plugin talks to a small typed surface instead of poking
 * at a hash somebody else assembled.
 *
 * **Nothing here is a base class.** A provider plugin must never extend or
 * implement anything of ours: a class whose parent lives in a plugin that has
 * been deactivated — or is mid-upgrade — is a fatal at autoload time, and it
 * takes out every page that touches it rather than just this one. Parameter
 * type hints resolve lazily and are fine; the hierarchy is not. So providers
 * describe themselves in data, and this object is built on our side of the
 * seam.
 */
final class Provider
{
    /**
     * @param array<int,array{key:string,label:string,secret:bool,required:bool,help:string,choices:array<string,string>}> $credentials
     * @param array<string,array{key:string,name:string,types:array<int,string>,collect:callable}> $services
     */
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly string $plugin,
        private readonly string $icon,
        private readonly string $supplier,
        private readonly array $credentials,
        private readonly array $services,
        private readonly mixed $check,
        private readonly mixed $scopes,
        private readonly mixed $costs,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** The plugin key that registered this provider. Shown when it misbehaves. */
    public function plugin(): string
    {
        return $this->plugin;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    /** Seeds the native Supplier a cloud account's contract points at. */
    public function supplier(): string
    {
        return $this->supplier;
    }

    /**
     * What the account form asks for. Rendered by us, validated by them.
     *
     * A field carrying `choices` is a fixed set and is rendered as a dropdown;
     * everything else is a text input, and a `secret` one is never rendered
     * with its value.
     *
     * @return array<int,array{key:string,label:string,secret:bool,required:bool,help:string,choices:array<string,string>}>
     */
    public function credentialFields(): array
    {
        return $this->credentials;
    }

    /** @return array<int,array{key:string,name:string,types:array<int,string>}> */
    public function services(): array
    {
        return array_values(array_map(
            static fn(array $s): array => [
                'key'   => $s['key'],
                'name'  => $s['name'],
                'types' => $s['types'],
            ],
            $this->services
        ));
    }

    public function hasService(string $service): bool
    {
        return isset($this->services[$service]);
    }

    public function hasCosts(): bool
    {
        return $this->costs !== null;
    }

    /**
     * Prove the credentials work, before anything is stored.
     *
     * @param array<string,mixed> $account account fields plus decrypted credentials
     * @return array{ok:bool,message:string,identity:string}
     */
    public function check(array $account): array
    {
        $result = (array) ($this->check)($account);

        return [
            'ok'       => (bool) ($result['ok'] ?? false),
            'message'  => (string) ($result['message'] ?? ''),
            'identity' => (string) ($result['identity'] ?? ''),
        ];
    }

    /**
     * The units a sweep fans out over: subscriptions, projects, accounts,
     * regions — whatever this provider partitions enumeration by.
     *
     * @param array<string,mixed> $account
     * @return array<int,array{key:string,name:string,is_active:bool}>
     */
    public function scopes(array $account): array
    {
        $out = [];

        foreach ((array) ($this->scopes)($account) as $scope) {
            $scope = (array) $scope;
            $key   = trim((string) ($scope['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $out[] = [
                'key'       => $key,
                'name'      => trim((string) ($scope['name'] ?? $key)),
                'is_active' => (bool) ($scope['is_active'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * Enumerate one service within one scope.
     *
     * Returns whatever the provider yields — a generator, in practice, because
     * a subscription with forty thousand resources must not become a
     * forty-thousand-element array on the way here.
     *
     * @param array<string,mixed> $ctx see Sync::context()
     */
    public function collect(string $service, array $ctx): iterable
    {
        if (!isset($this->services[$service])) {
            throw new RuntimeException(sprintf('unknown service "%s"', $service));
        }

        $rows = ($this->services[$service]['collect'])($ctx);

        if (!is_iterable($rows)) {
            throw new RuntimeException(sprintf('service "%s" did not return anything iterable', $service));
        }

        return $rows;
    }

    /**
     * Cost rows for a billing period.
     *
     * @param array<string,mixed> $ctx
     */
    public function costs(array $ctx): iterable
    {
        if ($this->costs === null) {
            return [];
        }

        $rows = ($this->costs)($ctx);

        if (!is_iterable($rows)) {
            throw new RuntimeException('cost collection did not return anything iterable');
        }

        return $rows;
    }
}
