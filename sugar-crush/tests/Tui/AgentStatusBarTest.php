<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentStatusBar;

/**
 * @see AgentDisplayState
 * @see AgentStatusBar
 */
final class AgentStatusBarTest extends TestCase
{
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

    public function testStatusColorWorkingIsGreen(): void
    {
        $color = AgentStatusBar::statusColor('working');
        $this->assertSame('#9ece6a', $color->toHex());
    }

    public function testStatusColorWaitingIsYellow(): void
    {
        $color = AgentStatusBar::statusColor('waiting');
        $this->assertSame('#e0af68', $color->toHex());
    }

    public function testStatusColorStreamingIsBlue(): void
    {
        $color = AgentStatusBar::statusColor('streaming');
        $this->assertSame('#7aa2f7', $color->toHex());
    }

    public function testStatusColorFailedIsRed(): void
    {
        $color = AgentStatusBar::statusColor('failed');
        $this->assertSame('#f7768e', $color->toHex());
    }

    public function testStatusColorCompletedIsGray(): void
    {
        $color = AgentStatusBar::statusColor('completed');
        $this->assertSame('#7d6e98', $color->toHex());
    }

    public function testStatusColorStoppedIsGray(): void
    {
        // Stopped also maps to gray (same as completed).
        $color = AgentStatusBar::statusColor('stopped');
        $this->assertSame('#7d6e98', $color->toHex());
    }

    public function testStatusColorUnknownDefaultsToGray(): void
    {
        $color = AgentStatusBar::statusColor('unknown-nonsense');
        $this->assertSame('#7d6e98', $color->toHex());
    }

    public function testStatusColorIsCaseInsensitive(): void
    {
        $this->assertSame('#9ece6a', AgentStatusBar::statusColor('WORKING')->toHex());
        $this->assertSame('#9ece6a', AgentStatusBar::statusColor('Working')->toHex());
        $this->assertSame('#f7768e', AgentStatusBar::statusColor('FAILED')->toHex());
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

        $line = AgentStatusBar::renderAgentLine($agent);

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

        $line = AgentStatusBar::renderAgentLine($agent);

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

        $line = AgentStatusBar::renderAgentLine($agent);

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

        $line = AgentStatusBar::renderAgentLine($agent);

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

        $line = AgentStatusBar::renderAgentLine($agent);

        $this->assertStringContainsString('1,234 tok', $line);
        $this->assertStringContainsString('$0.0042', $line);
    }

    // =========================================================================
    // AgentStatusBar::render — multi-agent output
    // =========================================================================

    public function testRenderEmptyListReturnsEmptyString(): void
    {
        $result = AgentStatusBar::render([]);
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

        $output = AgentStatusBar::render($agents);

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

        $output = AgentStatusBar::render($agents);

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

        $output = AgentStatusBar::render($agents);

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

        $line = AgentStatusBar::renderAgentLine($agent);

        // Green SGR sequence (38;2;158;206;106).
        $this->assertStringContainsString("\e[38;2;158;206;106m", $line);
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

        $line = AgentStatusBar::renderAgentLine($agent);

        // Red SGR sequence (38;2;247;118;142).
        $this->assertStringContainsString("\e[38;2;247;118;142m", $line);
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

        $line = AgentStatusBar::renderAgentLine($agent);

        // Gray SGR sequence (38;2;125;110;152).
        $this->assertStringContainsString("\e[38;2;125;110;152m", $line);
    }
}
