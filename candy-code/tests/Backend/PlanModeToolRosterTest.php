<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;

/**
 * C-SEC-7.c — while the session's permission gate is in `plan` mode,
 * {@see \SugarCraft\Code\Backend\EngineBackend::turnTools()} drops every tool
 * whose DECLARATION the gate itself refuses
 * ({@see \SugarCraft\Code\Permissions\PermissionGate::refuses()}), so the
 * advertised roster cannot drift from the enforcement. `Write`, `Bash` and
 * `PlanExit` stay: plan mode allows a `.md` write into `.candy-code/plans/`
 * and read-only `Bash`, and leaving the mode is the plan's approval door.
 *
 * STUB — implementation in progress; skeleton commit first per lane law.
 */
final class PlanModeToolRosterTest extends TestCase
{
    public function testSkeletonPlaceholder(): void
    {
        $this->markTestIncomplete('C-SEC-7.c skeleton commit');
    }
}
