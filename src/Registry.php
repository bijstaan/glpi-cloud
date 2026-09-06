<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

use Throwable;

/**
 * Which plugins speak which cloud, and how.
 *
 * A provider plugin registers one callable, the same shape the suite already
 * uses for `glpimail_letters`, `glpipdf_documents` and `glpiai_tools`:
 *
 * ```php
 * $PLUGIN_HOOKS['glpicloud_providers']['glpicloudazure'] = [Provider::class, 'describe'];
 * ```
 *
 * and it returns a flat descriptor:
 *
 * ```php
 * [
 *     'key'         => 'azure',
 *     'name'        => 'Microsoft Azure',
 *     'supplier'    => 'Microsoft',
 *     'credentials' => [
 *         ['key' => 'tenant_id', 'label' => 'Directory (tenant) ID', 'required' => true],
 *         ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true],
 *     ],
 *     'check'    => static fn(array $account): array => Auth::verify($account),
 *     'scopes'   => static fn(array $account): array => Subscriptions::list($account),
 *     'services' => [
 *         ['key' => 'compute', 'name' => 'Compute', 'types' => ['virtualmachine'],
 *          'collect' => static fn(array $ctx): iterable => Compute::walk($ctx)],
 *     ],
 *     'costs'    => static fn(array $ctx): iterable => Cost::walk($ctx),
 * ]
 * ```
 *
 * ### Why data and callables rather than an interface
 *
 * Because an interface is a load-time dependency across a plugin boundary, and
 * those are fatal. A provider class implementing one of ours dies at autoload
 * the moment this plugin is deactivated or half-upgraded, and it takes the page
 * with it. Descriptors cross the boundary as arrays; nothing on the provider's
 * side has to exist for this plugin's own pages to render.
 *
 * ### Failure is per-provider
 *
 * A descriptor that throws, or is malformed, is dropped and logged; every other
 * provider still works. An expired Azure secret must not stop an AWS estate
 * from syncing, and it must not take down the settings page — that page is
 * where somebody goes to fix it.
 *
 * ### A provider cannot claim another's key
 *
 * Two plugins offering `aws` is not a merge, it is a collision: rows are
 * stamped with the provider key, so the second registration would silently
 * start answering for the first one's inventory. The first wins and the second
 * is logged.
 */
final class Registry
{
    /** @var array<string,Provider>|null */
    private static ?array $cache = null;

    /**
     * Every registered provider, validated, keyed by provider key.
     *
     * @return array<string,Provider>
     */
    public static function providers(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        /** @var array<string,mixed> $PLUGIN_HOOKS */
        global $PLUGIN_HOOKS;

        $out = [];

        foreach ((array) ($PLUGIN_HOOKS['glpicloud_providers'] ?? []) as $plugin => $callback) {
            $plugin = (string) $plugin;

            if (!is_callable($callback)) {
                self::complain($plugin, 'registered something that is not callable', '');
                continue;
            }

            try {
                $descriptor = (array) $callback();
            } catch (Throwable $e) {
                self::complain($plugin, 'could not describe itself', $e->getMessage());
                continue;
            }

            $provider = self::normalise($descriptor, $plugin);

            if ($provider === null) {
                continue;
            }

            if (isset($out[$provider->key()])) {
                self::complain(
                    $plugin,
                    sprintf('offered provider "%s", which', $provider->key()),
                    sprintf('%s already registered. Ignored.', $out[$provider->key()]->plugin())
                );
                continue;
            }

            $out[$provider->key()] = $provider;
        }

        return self::$cache = $out;
    }

    public static function get(string $key): ?Provider
    {
        return self::providers()[$key] ?? null;
    }

    /** Forget what the hooks said. For tests, and for a plugin enabled mid-request. */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * @param array<string,mixed> $descriptor
     */
    private static function normalise(array $descriptor, string $plugin): ?Provider
    {
        $key  = strtolower(trim((string) ($descriptor['key'] ?? '')));
        $name = trim((string) ($descriptor['name'] ?? ''));

        // The key is stored on every resource row and appears in URLs, so it is
        // held to a shape that cannot surprise either.
        if (preg_match('/^[a-z][a-z0-9]{1,15}$/', $key) !== 1) {
            self::complain($plugin, 'offered an unusable provider key', sprintf('"%s"', $key));

            return null;
        }

        if ($name === '') {
            self::complain($plugin, sprintf('offered provider "%s" with no name', $key), '');

            return null;
        }

        foreach (['check', 'scopes'] as $required) {
            if (!is_callable($descriptor[$required] ?? null)) {
                self::complain($plugin, sprintf('offered provider "%s" with no %s callback', $key, $required), '');

                return null;
            }
        }

        $credentials = self::credentials($descriptor['credentials'] ?? [], $plugin, $key);
        $services    = self::services($descriptor['services'] ?? [], $plugin, $key);

        if ($services === []) {
            self::complain($plugin, sprintf('offered provider "%s" with no usable service', $key), '');

            return null;
        }

        $costs = $descriptor['costs'] ?? null;

        if ($costs !== null && !is_callable($costs)) {
            self::complain($plugin, sprintf('offered provider "%s" with an uncallable cost callback', $key), 'Cost collection disabled for it.');
            $costs = null;
        }

        return new Provider(
            $key,
            $name,
            $plugin,
            trim((string) ($descriptor['icon'] ?? 'ti ti-cloud')),
            trim((string) ($descriptor['supplier'] ?? '')),
            $credentials,
            $services,
            $descriptor['check'],
            $descriptor['scopes'],
            $costs,
        );
    }

    /**
     * @return array<int,array{key:string,label:string,secret:bool,required:bool,help:string,choices:array<string,string>}>
     */
    private static function credentials(mixed $fields, string $plugin, string $key): array
    {
        $out  = [];
        $seen = [];

        foreach ((array) $fields as $field) {
            $field     = (array) $field;
            $field_key = trim((string) ($field['key'] ?? ''));

            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $field_key) !== 1 || isset($seen[$field_key])) {
                self::complain($plugin, sprintf('offered provider "%s" with an unusable credential field', $key), sprintf('"%s"', $field_key));
                continue;
            }

            $seen[$field_key] = true;

            // A field with `choices` is a fixed set — OVH's API region, say,
            // which is part of what identifies an account and is wrong as free
            // text. Anything unusable in there is dropped back to a text field
            // rather than rendering an empty dropdown nobody can get past.
            $offered = $field['choices'] ?? null;
            $choices = [];

            // A map of value => label, and nothing else. A *list* is rejected
            // rather than accepted with its indexes as values: `['us','eu']`
            // would silently become the choices "0" and "1", which is a stored
            // credential that cannot work and looks fine on screen.
            if (is_array($offered)) {
                foreach ($offered as $value => $label) {
                    if (is_string($value) && $value !== '' && is_scalar($label)) {
                        $choices[$value] = (string) $label;
                    } else {
                        $choices = [];
                        break;
                    }
                }
            }

            if ($choices === [] && $offered !== null) {
                self::complain($plugin, sprintf('offered provider "%s" field "%s" with unusable choices', $key, $field_key), 'Rendered as free text.');
            }

            $out[] = [
                'key'      => $field_key,
                // A secret cannot also be a choice: the point of a secret is
                // that we never render its value, and a dropdown is nothing but
                // rendered values.
                'secret'   => $choices === [] && (bool) ($field['secret'] ?? false),
                'label'    => trim((string) ($field['label'] ?? $field_key)),
                'required' => (bool) ($field['required'] ?? false),
                'help'     => trim((string) ($field['help'] ?? '')),
                'choices'  => $choices,
            ];
        }

        return $out;
    }

    /**
     * @return array<string,array{key:string,name:string,types:array<int,string>,collect:callable}>
     */
    private static function services(mixed $services, string $plugin, string $key): array
    {
        $out = [];

        foreach ((array) $services as $service) {
            $service     = (array) $service;
            $service_key = strtolower(trim((string) ($service['key'] ?? '')));

            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $service_key) !== 1) {
                self::complain($plugin, sprintf('offered provider "%s" with an unusable service key', $key), sprintf('"%s"', $service_key));
                continue;
            }

            if (isset($out[$service_key])) {
                self::complain($plugin, sprintf('offered provider "%s" service "%s" twice', $key, $service_key), 'The second is ignored.');
                continue;
            }

            if (!is_callable($service['collect'] ?? null)) {
                self::complain($plugin, sprintf('offered provider "%s" service "%s" with no collect callback', $key, $service_key), '');
                continue;
            }

            $types = [];
            foreach ((array) ($service['types'] ?? []) as $type) {
                $type = strtolower(trim((string) $type));
                if ($type !== '') {
                    $types[] = $type;
                }
            }

            $out[$service_key] = [
                'key'     => $service_key,
                'name'    => trim((string) ($service['name'] ?? $service_key)),
                'types'   => $types,
                'collect' => $service['collect'],
            ];
        }

        return $out;
    }

    private static function complain(string $plugin, string $what, string $detail): void
    {
        trigger_error(
            rtrim(sprintf('glpicloud: %s %s. %s', $plugin, $what, $detail)),
            E_USER_WARNING
        );
    }
}
