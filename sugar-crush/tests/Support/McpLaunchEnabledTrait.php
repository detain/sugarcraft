<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use SugarCraft\Crush\Cli\Bootstrap;

/**
 * E737: the `SUGARCRUSH_MCP_DISABLE` gate is deliberately GLOBAL —
 * {@see Bootstrap::mcpConfigDecision()} answers the config-less miss before
 * anything else, so a shard runner (scripts/parallel-tests.sh sets the
 * variable on the phpunit launch) and a trusting operator shell cannot turn
 * the suite into a spawner of arbitrary servers.
 *
 * That is the correct production reading of the hatch, but a suite that
 * ASSERTS LAUNCHES — the client really builds, the server child really
 * starts, the memo really fills — is by definition a suite that needs its own
 * launch preconditions under its own control, exactly like its temp HOME and
 * its temp tree. Arm it FIRST in `setUp()` (right after `parent::setUp()`)
 * and restore LAST before `parent::tearDown()`; the snapshot/restore shape
 * keeps every other test in the same shard process gated, so the scrub is
 * bounded to each launch-asserting test instead of leaking back into the
 * root-less funnel the E737 leak runs through.
 */
trait McpLaunchEnabledTrait
{
    /** @var string|false the gate exactly as this process found it, false = unset */
    private string|false $mcpLaunchGateBefore = false;

    protected function armMcpLaunchEnabled(): void
    {
        $this->mcpLaunchGateBefore = getenv(Bootstrap::MCP_DISABLE_ENV);
        putenv(Bootstrap::MCP_DISABLE_ENV);
    }

    protected function restoreMcpLaunchEnabled(): void
    {
        if ($this->mcpLaunchGateBefore !== false) {
            putenv(Bootstrap::MCP_DISABLE_ENV . '=' . $this->mcpLaunchGateBefore);
        }
    }
}
