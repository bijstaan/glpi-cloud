<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The projection map, and the setting that narrows it.
 *
 * Small on purpose: almost all of {@see GlpiPlugin\Glpicloud\Projection} writes
 * native assets and belongs in tests/sync.php. What is here is the part that
 * fails *silently*, which is why it is worth a test at all.
 *
 * A type key that does not match what the providers actually yield — a stray
 * capital, a plural, a provider's own word instead of the core vocabulary —
 * produces a projection that never runs and never complains. The same is true
 * of the settings clamp: a narrowing list containing an unknown type would
 * quietly project nothing at all. Both are indistinguishable from "projection
 * is switched off", which is the hardest kind of bug to be told about.
 *
 * So the map is checked against the type strings the shipped providers really
 * emit, taken from their own tables rather than restated here — restating them
 * would let both copies drift together and prove nothing.
 */

require_once __DIR__ . '/bootstrap.php';

require_once CLOUD_SRC . '/Projection.php';
require_once CLOUD_SRC . '/Settings.php';

use GlpiPlugin\Glpicloud\Projection;
use GlpiPlugin\Glpicloud\Settings;

// ------------------------------------------------------------------- the map

T::ok(Projection::MAP !== [], 'the map is not empty');

$itemtypes = array_values(array_unique(array_values(Projection::MAP)));
sort($itemtypes);

T::is(
    $itemtypes,
    ['Cluster', 'Computer', 'DatabaseInstance'],
    'three native itemtypes, and only three — anything else means a type was pointed at a class '
        . 'core does not have, which fails at write time on a customer instance rather than here'
);

foreach (array_keys(Projection::MAP) as $type) {
    T::is(
        $type,
        strtolower($type),
        sprintf('"%s" is lowercase, as Normalise stores types', $type)
    );
}

T::is(
    Projection::itemtypeFor('virtualmachine'),
    'Computer',
    'a VM is a Computer'
);

T::is(
    Projection::itemtypeFor('kubernetescluster'),
    'Cluster',
    'a Kubernetes cluster is a Cluster'
);

T::is(
    Projection::itemtypeFor('storageaccount'),
    null,
    'and the long tail projects onto nothing — a storage account is not an asset core models'
);

T::is(
    Projection::itemtypeFor(''),
    null,
    'an untyped resource projects onto nothing rather than onto the first entry'
);

// --------------------------------------- the map against what providers emit
//
// Read out of the provider plugins' own type tables. If a provider renames a
// type, this fails here rather than by quietly projecting nothing.

$emitted = [];

$azure = dirname(__DIR__, 2) . '/glpi-cloud-azure/src/Types.php';
if (is_file($azure)) {
    require_once $azure;

    foreach (GlpiPlugin\Glpicloudazure\Types::SERVICES as $service) {
        foreach ($service['types'] as $core_type) {
            $emitted[$core_type] = true;
        }
    }
}

$ovh = dirname(__DIR__, 2) . '/glpi-cloud-ovh/src/Types.php';
if (is_file($ovh)) {
    require_once $ovh;

    foreach (GlpiPlugin\Glpicloudovh\Types::KINDS as $kind) {
        $emitted[$kind['type']] = true;
    }
}

if ($emitted !== []) {
    $unknown = array_diff(array_keys(Projection::MAP), array_keys($emitted));

    T::is(
        array_values($unknown),
        [],
        'every type in the map is one a shipped provider actually emits — a key no provider '
            . 'produces is a projection that can never fire'
    );

    // Not the reverse: most emitted types deliberately have no mapping, which
    // is the whole argument of the class. Only the ones that plainly are a
    // native asset are named, so that a new provider type does not start
    // creating Computers because somebody added it to a table.
    T::ok(
        !isset(Projection::MAP['storageaccount']),
        'and the reverse does not hold: an emitted type without a mapping stays generic'
    );
}

// -------------------------------------------------------------- the narrowing

T::is(
    Settings::clamp('projection_types', 'virtualmachine,kubernetescluster'),
    'virtualmachine,kubernetescluster',
    'a list of known types survives the clamp'
);

T::is(
    Settings::clamp('projection_types', ' virtualmachine , vps '),
    'virtualmachine,vps',
    'and is trimmed'
);

T::is(
    Settings::clamp('projection_types', 'virtualmachine,nonsense'),
    'virtualmachine',
    'an unknown type is dropped rather than stored — stored, it would narrow the list to nothing '
        . 'and look exactly like the feature being off'
);

T::is(
    Settings::clamp('projection_types', ['virtualmachine', 'redis']),
    'virtualmachine,redis',
    'a checkbox group posts an array, and that is accepted'
);

T::is(
    Settings::clamp('projection_types', ''),
    '',
    'and an empty narrowing stays empty, which enabledTypes() reads as every mapped type'
);

exit(T::done('projection'));
