<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;

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
