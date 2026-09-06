<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Normalisation, the checksum, and the two small rules money depends on.
 *
 * The checksum is the single decision that says whether a six-hourly sweep of
 * forty thousand resources writes anything at all. If it is unstable, every
 * sweep rewrites the estate and the change history becomes noise; if it is too
 * stable, a resource can go public and nobody is told. Both failures are
 * silent, which is why they are tested here rather than noticed later.
 */

require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Glpicloud\Costs;
use GlpiPlugin\Glpicloud\History;
use GlpiPlugin\Glpicloud\Normalise;

// ------------------------------------------------------------- normalisation

T::is(
    Normalise::row(['name' => 'nameless'], 'fixture', 'sub-a', 'compute'),
    null,
    'a row with no provider identifier is not a resource'
);

T::is(
    Normalise::row(['native_id' => '   '], 'fixture', 'sub-a', 'compute'),
    null,
    'and whitespace is not an identifier either'
);

$row = Normalise::row(['native_id' => '/subs/sub-a/vm/web-01'], 'fixture', 'sub-a', 'compute');

T::is($row['name'], '/subs/sub-a/vm/web-01', 'a nameless resource is named by its identifier');
T::is($row['type'], 'unknown', 'an untyped resource is stored as unknown, not dropped');
T::is($row['state'], '', 'a stateless resource has no state');
T::is($row['scope'], 'sub-a', 'the sweep supplies the scope');
T::is($row['service'], 'compute', 'and the service');
T::is($row['provider'], 'fixture', 'and the provider key');

$row = Normalise::row([
    'native_id' => 'x',
    'type'      => '  VirtualMachine ',
    'state'     => 'Running',
    'scope'     => 'sub-declared',
], 'fixture', 'sub-a', 'compute');

T::is($row['type'], 'virtualmachine', 'type is lowercased and trimmed');
T::is($row['state'], 'running', 'state is lowercased');
T::is($row['scope'], 'sub-declared', 'a row may name its own scope');

$row = Normalise::row([
    'native_id' => 'x',
    'tags'      => ['client' => 'acme', 'count' => 3, 'flag' => true, 'nested' => ['a' => 1], 'nothing' => null],
], 'fixture', 'sub-a', 'compute');

T::is($row['tags'], ['client' => 'acme', 'count' => '3', 'flag' => '1', 'nothing' => ''], 'tag values are flattened to strings and a structure is dropped');

$long = str_repeat('a', 400);
$row  = Normalise::row(['native_id' => $long, 'name' => $long], 'fixture', 'sub-a', 'compute');

T::is(mb_strlen($row['native_id']), 255, 'an over-long identifier is cut to the column');
T::is(mb_strlen($row['name']), 255, 'and so is the name');

$row = Normalise::row([
    'native_id'  => 'x',
    'attributes' => ['blob' => str_repeat('b', Normalise::MAX_ATTRIBUTES + 10)],
], 'fixture', 'sub-a', 'compute');

T::is($row['attributes'], ['_truncated' => true], 'an absurd payload is recorded as truncated rather than silently trimmed');

// ------------------------------------------------------------------ checksum

$a = Normalise::row([
    'native_id'  => 'x',
    'name'       => 'web-01',
    'state'      => 'running',
    'tags'       => ['env' => 'prod', 'client' => 'acme'],
    'attributes' => ['location' => 'uksouth', 'size' => 'D2s'],
], 'fixture', 'sub-a', 'compute');

$b = Normalise::row([
    'native_id'  => 'x',
    'name'       => 'web-01',
    'state'      => 'running',
    'tags'       => ['client' => 'acme', 'env' => 'prod'],
    'attributes' => ['size' => 'D2s', 'location' => 'uksouth'],
], 'fixture', 'sub-a', 'compute');

T::is(
    Normalise::checksum($a),
    Normalise::checksum($b),
    'key order out of a provider does not count as a change'
);

$nested_a = Normalise::row(['native_id' => 'x', 'attributes' => ['net' => ['ip' => '10.0.0.1', 'vnet' => 'core']]], 'fixture', 'sub-a', 'compute');
$nested_b = Normalise::row(['native_id' => 'x', 'attributes' => ['net' => ['vnet' => 'core', 'ip' => '10.0.0.1']]], 'fixture', 'sub-a', 'compute');

T::is(
    Normalise::checksum($nested_a),
    Normalise::checksum($nested_b),
    'nor does key order inside a nested object'
);

$stopped = $a;
$stopped['state'] = 'stopped';

T::ok(Normalise::checksum($a) !== Normalise::checksum($stopped), 'a state change is a change');

$retagged = $a;
$retagged['tags']['client'] = 'other';

T::ok(Normalise::checksum($a) !== Normalise::checksum($retagged), 'a tag change is a change — it is what decides the entity');

$repayloaded = $a;
$repayloaded['attributes']['public'] = true;

T::ok(Normalise::checksum($a) !== Normalise::checksum($repayloaded), 'a payload change is a change — it is where "this bucket is public" lives');

$renamed_id = $a;
$renamed_id['native_id'] = 'y';

T::is(
    Normalise::checksum($a),
    Normalise::checksum($renamed_id),
    'the identifier is the key, not part of the body: it cannot change under a resource'
);

// ------------------------------------------------------------ what changed

T::is(
    History::changedKeys(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3]),
    ['b'],
    'a changed value is named'
);

T::is(
    History::changedKeys(['a' => 1], ['a' => 1, 'b' => 2]),
    ['b'],
    'an added key is named'
);

T::is(
    History::changedKeys(['a' => 1, 'b' => 2], ['a' => 1]),
    ['b'],
    'a removed key is named'
);

T::is(
    History::changedKeys(
        ['net' => ['ip' => '10.0.0.1', 'vnet' => 'core']],
        ['net' => ['vnet' => 'core', 'ip' => '10.0.0.1']]
    ),
    [],
    'and a reordered nested object is not a change here either — history must agree with the checksum'
);

// -------------------------------------------------------------- the period

T::is(Costs::normalisePeriod('2026-08'), '2026-08', 'a billing period is a month');
T::is(Costs::normalisePeriod(' 2026-08 '), '2026-08', 'and is trimmed');
T::is(Costs::normalisePeriod('2026-13'), null, 'month 13 is not a month');
T::is(Costs::normalisePeriod('2026-8'), null, 'nor is an unpadded one — the column is CHAR(7)');
T::is(Costs::normalisePeriod('2026-08-01'), null, 'nor is a day');
T::is(Costs::normalisePeriod(''), null, 'nor is nothing');

exit(T::done('normalise'));
