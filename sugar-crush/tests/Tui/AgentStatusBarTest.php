<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentStatusBar;
use SugarCraft\Crush\Theme;
use SugarCraft\Core\Util\ColorProfile;

/**
 * @see AgentDisplayState
 * @see AgentStatusBar
 */
final class AgentStatusBarTest extends TestCase
{
    /**
     * The palette every colour assertion below is stated against. Named once,
     * because a status colour is now a THEME LOOKUP: what `working` resolves to
     * is `dark`'s `success`, not a constant, and the property worth asserting
     * is the mapping plus its distinctness - not a hex the user can change with
     * `/theme`.
     */
    private static function theme(): Theme
    {
        return Theme::byName('dark');
    }

    // =========================================================================
    // AgentDisplayState — elapsed / usage display helpers
    // =========================================================================

    public function testElapsedDisplaySecondsOnly(): void
    {
        $state = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading auth.php',
            elapsedSeconds: 45,
            tokensUsed: 500,
            costUsd: 0.0012,
        );

        $this->assertSame('45s', $state->elapsedDisplay());
    }

    public function testElapsedDisplayMinutesOnly(): void
    {
        $state = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading auth.php',
            elapsedSeconds: 120,
            tokensUsed: 500,
            costUsd: 0.0012,
        );

        $this->assertSame('2m', $state->elapsedDisplay());
    }

    public function testElapsedDisplayMinutesAndSeconds(): void
    {
        $state = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading auth.php',
            elapsedSeconds: 90,
            tokensUsed: 500,
            costUsd: 0.0012,
        );

        $this->assertSame('1m 30s', $state->elapsedDisplay());
    }

    public function testUsageDisplayFormat(): void
    {
        $state = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading auth.php',
            elapsedSeconds: 60,
            tokensUsed: 1234,
            costUsd: 0.0042,
        );

        $this->assertSame('1,234 tok | $0.0042', $state->usageDisplay());
    }

    // =========================================================================
    // AgentStatusBar::statusColor — colour mapping
    // =========================================================================

    public function testStatusColorMapsEachStatusToItsSemanticThemeToken(): void
    {
        $t = self::theme();

        $this->assertSame($t->shellSuccess->toHex(), AgentStatusBar::statusColor('working', $t)->toHex());
        $this->assertSame($t->shellWarning->toHex(), AgentStatusBar::statusColor('waiting', $t)->toHex());
        $this->assertSame($t->shellInfo->toHex(), AgentStatusBar::statusColor('streaming', $t)->toHex());
        $this->assertSame($t->shellError->toHex(), AgentStatusBar::statusColor('failed', $t)->toHex());
        $this->assertSame($t->shellMuted->toHex(), AgentStatusBar::statusColor('completed', $t)->toHex());
        // Stopped shares `completed`'s token, as the two literals it replaced
        // were both the same grey.
        $this->assertSame($t->shellMuted->toHex(), AgentStatusBar::statusColor('stopped', $t)->toHex());
    }

    /**
     * The dot exists to be told apart at a glance, so the four LIVE states must
     * not collide. Asserted for every offered palette, because a mapping that
     * is distinct in `dark` and degenerate in `light` is still a broken dot.
     */
    public function testStatusColoursAreMutuallyDistinctInEveryPalette(): void
    {
        foreach (Theme::names() as $name) {
            $t = Theme::byName($name);
            $hexes = array_map(
                static fn(string $s): string => AgentStatusBar::statusColor($s, $t)->toHex(),
                ['working', 'waiting', 'streaming', 'failed', 'completed'],
            );

            $this->assertSame($hexes, array_values(array_unique($hexes)), "collision under theme {$name}");
        }
    }

    public function testStatusColorUnknownDefaultsToTheCompletedToken(): void
    {
        $t = self::theme();
        $this->assertSame(
            AgentStatusBar::statusColor('completed', $t)->toHex(),
            AgentStatusBar::statusColor('unknown-nonsense', $t)->toHex(),
        );
    }

    public function testStatusColorIsCaseInsensitive(): void
    {
        $t = self::theme();
        $this->assertSame($t->shellSuccess->toHex(), AgentStatusBar::statusColor('WORKING', $t)->toHex());
        $this->assertSame($t->shellSuccess->toHex(), AgentStatusBar::statusColor('Working', $t)->toHex());
        $this->assertSame($t->shellError->toHex(), AgentStatusBar::statusColor('FAILED', $t)->toHex());
    }

    // =========================================================================
    // AgentStatusBar::renderAgentLine — single-agent output
    // =========================================================================

    public function testRenderAgentLineContainsName(): void
    {
        $agent = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading auth.php',
            elapsedSeconds: 30,
            tokensUsed: 999,
            costUsd: 0.0031,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        $this->assertStringContainsString('coder-1', $line);
    }

    public function testRenderAgentLineContainsStatus(): void
    {
        $agent = new AgentDisplayState(
            name: 'coder-1',
            status: 'waiting',
            operation: 'Waiting for input',
            elapsedSeconds: 30,
            tokensUsed: 999,
            costUsd: 0.0031,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        $this->assertStringContainsString('[waiting]', $line);
    }

    public function testRenderAgentLineContainsOperation(): void
    {
        $agent = new AgentDisplayState(
            name: 'reviewer-1',
            status: 'streaming',
            operation: 'Generating API tests',
            elapsedSeconds: 15,
            tokensUsed: 2500,
            costUsd: 0.0089,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        $this->assertStringContainsString('Generating API tests', $line);
    }

    public function testRenderAgentLineContainsElapsedTime(): void
    {
        $agent = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading auth.php',
            elapsedSeconds: 90,
            tokensUsed: 500,
            costUsd: 0.0012,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        $this->assertStringContainsString('1m 30s', $line);
    }

    public function testRenderAgentLineContainsUsage(): void
    {
        $agent = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading auth.php',
            elapsedSeconds: 60,
            tokensUsed: 1234,
            costUsd: 0.0042,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        $this->assertStringContainsString('1,234 tok', $line);
        $this->assertStringContainsString('$0.0042', $line);
    }

    // =========================================================================
    // AgentStatusBar::render — multi-agent output
    // =========================================================================

    public function testRenderEmptyListReturnsEmptyString(): void
    {
        $result = AgentStatusBar::render([], self::theme());
        $this->assertSame('', $result);
    }

    public function testRenderSingleAgentReturnsOneLine(): void
    {
        $agents = [
            new AgentDisplayState(
                name: 'coder-1',
                status: 'working',
                operation: 'Reading auth.php',
                elapsedSeconds: 30,
                tokensUsed: 500,
                costUsd: 0.0012,
            ),
        ];

        $output = AgentStatusBar::render($agents, self::theme());

        // A single agent produces a single line with no internal newline.
        $this->assertSame(0, substr_count($output, "\n"));
        $this->assertStringContainsString('coder-1', $output);
    }

    public function testRenderMultipleAgentsReturnsMultipleLines(): void
    {
        $agents = [
            new AgentDisplayState(
                name: 'coder-1',
                status: 'working',
                operation: 'Reading auth.php',
                elapsedSeconds: 30,
                tokensUsed: 500,
                costUsd: 0.0012,
            ),
            new AgentDisplayState(
                name: 'reviewer-2',
                status: 'waiting',
                operation: 'Waiting for coder',
                elapsedSeconds: 10,
                tokensUsed: 100,
                costUsd: 0.0003,
            ),
        ];

        $output = AgentStatusBar::render($agents, self::theme());

        $this->assertSame(1, substr_count($output, "\n")); // 2 agents → 1 newline
        $this->assertStringContainsString('coder-1', $output);
        $this->assertStringContainsString('reviewer-2', $output);
    }

    public function testRenderPreservesAgentOrder(): void
    {
        $agents = [
            new AgentDisplayState(
                name: 'agent-a',
                status: 'completed',
                operation: 'Done',
                elapsedSeconds: 5,
                tokensUsed: 50,
                costUsd: 0.0001,
            ),
            new AgentDisplayState(
                name: 'agent-b',
                status: 'failed',
                operation: 'Error',
                elapsedSeconds: 3,
                tokensUsed: 20,
                costUsd: 0.0001,
            ),
        ];

        $output = AgentStatusBar::render($agents, self::theme());

        $posA = strpos($output, 'agent-a');
        $posB = strpos($output, 'agent-b');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA);
    }

    // =========================================================================
    // Status colour coding — integration: each colour maps to the expected
    // ANSI escape in the rendered output (proves SGR bytes are present).
    // =========================================================================

    public function testRenderedWorkingLineContainsGreenSGR(): void
    {
        $agent = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: 'Compiling',
            elapsedSeconds: 10,
            tokensUsed: 100,
            costUsd: 0.0003,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        // The palette's `success`, as the SGR bytes a TrueColor Style emits.
        $this->assertStringContainsString(
            self::theme()->shellSuccess->toFg(ColorProfile::TrueColor),
            $line,
        );
    }

    public function testRenderedFailedLineContainsRedSGR(): void
    {
        $agent = new AgentDisplayState(
            name: 'coder-1',
            status: 'failed',
            operation: 'Crashed',
            elapsedSeconds: 5,
            tokensUsed: 50,
            costUsd: 0.0001,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        // The palette's `error`.
        $this->assertStringContainsString(
            self::theme()->shellError->toFg(ColorProfile::TrueColor),
            $line,
        );
    }

    public function testRenderedCompletedLineContainsGraySGR(): void
    {
        $agent = new AgentDisplayState(
            name: 'coder-1',
            status: 'completed',
            operation: 'Done',
            elapsedSeconds: 30,
            tokensUsed: 600,
            costUsd: 0.0020,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        // The palette's `muted`.
        $this->assertStringContainsString(
            self::theme()->shellMuted->toFg(ColorProfile::TrueColor),
            $line,
        );
    }
}
