<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;

/**
 * The gate the read-only auto-allow suites decide against: a mode, optional
 * rules, and `withReadOnlyAutoAllow(true)`. One copy, so the three suites
 * (plain lines, sed and loops, awk) cannot drift apart on how it is built.
 */
trait ReadOnlyGateTrait
{
    /** @param list<PermissionRule> $rules */
    private function gate(PermissionMode $mode = PermissionMode::Default, array $rules = []): PermissionGate
    {
        return (new PermissionGate($mode, $rules))->withReadOnlyAutoAllow(true);
    }
}
