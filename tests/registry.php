<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The provider registry: what it accepts, what it drops, and what it says.
 *
 * The cases that matter are the refusals. A registry that quietly accepted a
 * half-built descriptor would produce an account form with no credential
 * fields, a sweep with no services, or — the one that actually costs
 * something — a second plugin silently answering for another's inventory
 * because both claimed the same provider key.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fixture-provider.php';

use GlpiPlugin\Glpicloud\Registry;

/** Register a set of descriptors and read back what survived. */
function registered(array $hooks): array
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['glpicloud_providers'] = $hooks;
    Registry::reset();

    Complaints::watch();
    $providers = Registry::providers();
    Complaints::stop();

    return $providers;
}

CloudFixture::load();

// ------------------------------------------------------------ the good case

$providers = registered(['glpicloudfixture' => [CloudFixture::class, 'describe']]);

T::is(array_keys($providers), ['fixture'], 'a well-formed descriptor registers under its own key');
T::is(Complaints::$lines, [], 'and says nothing');

$fixture = $providers['fixture'];

T::is($fixture->name(), 'Fixture cloud', 'name survives');
T::is($fixture->plugin(), 'glpicloudfixture', 'the registering plugin is recorded');
T::is($fixture->supplier(), 'Nobody', 'supplier survives, for the native Supplier link');
T::is(count($fixture->credentialFields()), 2, 'both credential fields survive');
T::is($fixture->credentialFields()[1]['secret'], true, 'a secret field is marked secret');
T::is($fixture->credentialFields()[0]['secret'], false, 'and a plain one is not');
T::is(array_column($fixture->services(), 'key'), ['compute', 'storage'], 'services keep their order');
T::ok($fixture->hasService('compute'), 'a declared service is found');
T::ok(!$fixture->hasService('nonsense'), 'an undeclared one is not');
T::ok($fixture->hasCosts(), 'the fixture offers costs');

// A service the provider never declared is a programming error on our side,
// not a silent empty sweep.
$threw = false;
try {
    $fixture->collect('nonsense', []);
} catch (Throwable $e) {
    $threw = true;
}
T::ok($threw, 'collecting an undeclared service throws');

// ------------------------------------------------------- scopes are cleaned

CloudFixture::$estate['scopes'] = [
    ['key' => 'sub-a'],
    ['key' => '', 'name' => 'nameless'],
    ['key' => 'sub-b', 'name' => 'Lapsed', 'is_active' => false],
];

$scopes = $fixture->scopes([]);

T::is(count($scopes), 2, 'a scope with no key is dropped');
T::is($scopes[0]['name'], 'sub-a', 'a scope with no name falls back to its key');
T::is($scopes[0]['is_active'], true, 'and is active unless it says otherwise');
T::is($scopes[1]['is_active'], false, 'an inactive scope stays inactive');

CloudFixture::load();

// ------------------------------------------------------------- the refusals

$cases = [
    'not callable' => [
        'hooks'  => ['badplugin' => 'this is not callable'],
        'says'   => 'not callable',
    ],
    'throwing describe' => [
        'hooks'  => ['badplugin' => static fn(): array => throw new RuntimeException('boom')],
        'says'   => 'could not describe itself',
    ],
    'no key' => [
        'hooks'  => ['badplugin' => static fn(): array => ['name' => 'Nameless']],
        'says'   => 'unusable provider key',
    ],
    'shouty key' => [
        'hooks'  => ['badplugin' => static fn(): array => ['key' => 'AWS!', 'name' => 'Shouty']],
        'says'   => 'unusable provider key',
    ],
    'no name' => [
        'hooks'  => ['badplugin' => static fn(): array => ['key' => 'aws']],
        'says'   => 'with no name',
    ],
    'no check callback' => [
        'hooks'  => ['badplugin' => static fn(): array => [
            'key' => 'aws', 'name' => 'AWS', 'scopes' => static fn(): array => [],
        ]],
        'says'   => 'no check callback',
    ],
    'no services' => [
        'hooks'  => ['badplugin' => static fn(): array => [
            'key'    => 'aws',
            'name'   => 'AWS',
            'check'  => static fn(): array => [],
            'scopes' => static fn(): array => [],
        ]],
        'says'   => 'no usable service',
    ],
    'service without collect' => [
        'hooks'  => ['badplugin' => static fn(): array => [
            'key'      => 'aws',
            'name'     => 'AWS',
            'check'    => static fn(): array => [],
            'scopes'   => static fn(): array => [],
            'services' => [['key' => 'ec2', 'name' => 'EC2']],
        ]],
        'says'   => 'no collect callback',
    ],
];

foreach ($cases as $label => $case) {
    $providers = registered($case['hooks']);

    T::is($providers, [], sprintf('%s: dropped', $label));
    T::ok(Complaints::mentions($case['says']), sprintf('%s: and complains about it', $label));
}

// ------------------------------------------------------- fields with choices

$providers = registered(['picky' => static fn(): array => [
    'key'      => 'picky',
    'name'     => 'Picky cloud',
    'check'    => static fn(): array => [],
    'scopes'   => static fn(): array => [],
    'services' => [['key' => 'all', 'name' => 'All', 'collect' => static fn(): iterable => []]],
    'credentials' => [
        ['key' => 'region', 'label' => 'Region', 'choices' => ['us' => 'United States', 'eu' => 'Europe']],
        ['key' => 'token', 'label' => 'Token', 'secret' => true],
        ['key' => 'broken', 'label' => 'Broken', 'choices' => 'not a map'],
        // A secret cannot also be a choice: a dropdown is nothing but rendered
        // values, and the point of a secret is that we never render its value.
        ['key' => 'confused', 'label' => 'Confused', 'secret' => true, 'choices' => ['a' => 'A']],
    ],
]]);

$fields = array_column($providers['picky']->credentialFields(), null, 'key');

T::is($fields['region']['choices'], ['us' => 'United States', 'eu' => 'Europe'], 'a fixed set of values survives as choices');
T::is($fields['region']['secret'], false, 'and is not a secret');
T::is($fields['token']['choices'], [], 'a plain field has no choices');
T::is($fields['token']['secret'], true, 'and stays secret');
T::is($fields['broken']['choices'], [], 'unusable choices fall back to free text');
T::ok(Complaints::mentions('unusable choices'), 'and are reported rather than rendered as an empty dropdown');
T::is($fields['confused']['secret'], false, 'a field with choices is never treated as a secret');

// ---------------------------------------------- one bad part, not one bad plugin

$providers = registered(['halfgood' => static fn(): array => [
    'key'      => 'aws',
    'name'     => 'AWS',
    'check'    => static fn(): array => [],
    'scopes'   => static fn(): array => [],
    'services' => [
        ['key' => 'ec2', 'name' => 'EC2', 'collect' => static fn(): iterable => []],
        ['key' => 'S3!', 'name' => 'S3', 'collect' => static fn(): iterable => []],
        ['key' => 'ec2', 'name' => 'EC2 again', 'collect' => static fn(): iterable => []],
    ],
    'credentials' => [
        ['key' => 'access_key', 'label' => 'Access key'],
        ['key' => 'Secret Key', 'label' => 'Secret'],
    ],
    'costs' => 'not callable either',
]]);

T::is(array_keys($providers), ['aws'], 'a provider with one bad service still registers');
T::is(array_column($providers['aws']->services(), 'key'), ['ec2'], 'the unusable and duplicate services are dropped');
T::is(count($providers['aws']->credentialFields()), 1, 'an unusable credential key is dropped');
T::ok(!$providers['aws']->hasCosts(), 'an uncallable cost callback disables cost, not the provider');
T::ok(Complaints::mentions('unusable service key'), 'and each drop is reported');
T::ok(Complaints::mentions('twice'), 'including the duplicate');

// --------------------------------------------------------------- collisions

$descriptor = static fn(): array => [
    'key'      => 'aws',
    'name'     => 'AWS',
    'check'    => static fn(): array => [],
    'scopes'   => static fn(): array => [],
    'services' => [['key' => 'ec2', 'name' => 'EC2', 'collect' => static fn(): iterable => []]],
];

$providers = registered(['firstplugin' => $descriptor, 'secondplugin' => $descriptor]);

T::is(count($providers), 1, 'two plugins claiming one provider key produce one provider');
T::is($providers['aws']->plugin(), 'firstplugin', 'and the first registration wins');
T::ok(Complaints::mentions('already registered'), 'the loser is named in the log');

// ------------------------------------------------------------------- cache

$PLUGIN_HOOKS['glpicloud_providers'] = [];
T::is(count(Registry::providers()), 1, 'the registry is cached within a request');
Registry::reset();
T::is(Registry::providers(), [], 'and reset forgets it, for a plugin enabled mid-request');

exit(T::done('registry'));
