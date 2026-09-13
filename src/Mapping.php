<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloud;

/**
 * Which entity a resource belongs to.
 *
 * The answer is rarely "whoever owns the account" — it is "whoever owns the
 * account, except the rows tagged `client=…`". That is a rule set, and
 * a rule set is milestone work: criteria over account,
 * provider, scope, type, name and any tag, actions setting the entity.
 *
 * This is the default those rules will fall through to, and it is deliberately
 * the account's own entity rather than entity 0. A resource that lands at the
 * root because nothing matched is visible to every technician in the instance,
 * which is a disclosure, not an inconvenience.
 */
final class Mapping
{
    /**
     * @param array<string,mixed> $row the normalised resource
     * @return array{entities_id:int,is_recursive:int}
     */
    public static function resolve(Account $account, array $row): array
    {
        return [
            'entities_id'  => (int) ($account->fields['entities_id'] ?? 0),
            'is_recursive' => (int) ($account->fields['is_recursive'] ?? 0),
        ];
    }
}
