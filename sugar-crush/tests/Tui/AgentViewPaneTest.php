<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentViewPane;

/**
 * @see AgentViewPane
 */
final class AgentViewPaneTest extends TestCase
{
    // =========================================================================
    // AgentViewPane::render — empty list → placeholder with border
    // =========================================================================

    public function testRenderEmptyArrayReturnsPlaceholderWithBorder(): void
    {
        $output = AgentViewPane::render([], -1, 80, 20);

        $this->assertStringContainsString('(no active agents)', $output);
        $this->assertStringContainsString('agents', $output);
    }

    // =========================================================================
    // AgentViewPane::render — single agent
    // =========================================================================

    public function testRenderSingleAgentRendersCorrectly(): void
    {
        $agents = [
            new AgentDisplayState(
                name: 'c1',
                status: 'working',
                operation: 'Reading',
                elapsedSeconds: 45,
                tokensUsed: 500,
                costUsd: 0.0012,
            ),
        ];

        $output = AgentViewPane::render($agents, -1, 80, 20);

        $this->assertStringContainsString('c1', $output);
        $this->assertStringContainsString('Reading', $output);
        $this->assertStringContainsString('[working]', $output);
        $this->assertStringContainsString('45s', $output);
        $this->assertStringContainsString('500 tok', $output);
    }

    // =========================================================================
    // AgentViewPane::render — multiple agents
    // =========================================================================

    public function testRenderMultipleAgentsRenderWithCorrectSeparator(): void
    {
        $agents = [
            new AgentDisplayState(
                name: 'c1',
                status: 'working',
                operation: 'Reading',
                elapsedSeconds: 30,
                tokensUsed: 500,
                costUsd: 0.0012,
            ),
            new AgentDisplayState(
                name: 'r2',
                status: 'waiting',
                operation: 'Waiting',
                elapsedSeconds: 10,
                tokensUsed: 100,
                costUsd: 0.0003,
            ),
        ];

        $output = AgentViewPane::render($agents, -1, 80, 20);

        $this->assertStringContainsString('c1', $output);
        $this->assertStringContainsString('r2', $output);
        // c1 should appear before r2 in the output
        $this->assertLessThan(strpos($output, 'r2'), strpos($output, 'c1'));
    }

    // =========================================================================
    // AgentViewPane::render — selected index
    // =========================================================================

    public function testRenderSelectedIndexGetsDifferentStyling(): void
    {
        $agents = [
            new AgentDisplayState(
                name: 'c1',
                status: 'working',
                operation: 'Working',
                elapsedSeconds: 30,
                tokensUsed: 500,
                costUsd: 0.0012,
            ),
            new AgentDisplayState(
                name: 'r2',
                status: 'waiting',
                operation: 'Waiting',
                elapsedSeconds: 10,
                tokensUsed: 100,
                costUsd: 0.0003,
            ),
        ];

        $bg = "\e[48;2;35;35;56m";

        // When index 1 (r2) is selected, r2 line has background, c1 line does not.
        $output = AgentViewPane::render($agents, 1, 80, 20);
        $lines = explode("\n", $output);
        // lines[0] = top border, lines[1] = c1 (not selected), lines[2] = r2 (selected), lines[3] = bottom border
        $this->assertStringNotContainsString($bg, $lines[1]); // c1 not selected
        $this->assertStringContainsString($bg, $lines[2]);     // r2 selected

        // When index 0 (c1) is selected, c1 line has background, r2 line does not.
        $output2 = AgentViewPane::render($agents, 0, 80, 20);
        $lines2 = explode("\n", $output2);
        $this->assertStringContainsString($bg, $lines2[1]);     // c1 selected
        $this->assertStringNotContainsString($bg, $lines2[2]); // r2 not selected
    }

    // =========================================================================
    // AgentViewPane::render — truncation
    // =========================================================================

    public function testRenderTruncationWorksForLongOperationNames(): void
    {
        $agents = [
            new AgentDisplayState(
                name: 'c1',
                status: 'working',
                operation: 'This is a very long operation name that should be truncated because it exceeds the budget',
                elapsedSeconds: 30,
                tokensUsed: 500,
                costUsd: 0.0012,
            ),
        ];

        $output = AgentViewPane::render($agents, -1, 80, 20);

        // The operation should be truncated (contain the ellipsis character)
        $this->assertStringContainsString("\u{2026}", $output);
        // The original long operation should NOT appear in full
        $this->assertStringNotContainsString('This is a very long operation name that should be truncated because it exceeds the budget', $output);
    }

    // =========================================================================
    // AgentViewPane::statusColor — all 6 statuses
    // =========================================================================

    public function testStatusColorWorkingReturnsGreen(): void
    {
        $color = AgentViewPane::statusColor('working');
        $this->assertSame('#9ece6a', $color->toHex());
    }

    public function testStatusColorWaitingReturnsYellow(): void
    {
        $color = AgentViewPane::statusColor('waiting');
        $this->assertSame('#e0af68', $color->toHex());
    }

    public function testStatusColorStreamingReturnsBlue(): void
    {
        $color = AgentViewPane::statusColor('streaming');
        $this->assertSame('#7aa2f7', $color->toHex());
    }

    public function testStatusColorFailedReturnsRed(): void
    {
        $color = AgentViewPane::statusColor('failed');
        $this->assertSame('#f7768e', $color->toHex());
    }

    public function testStatusColorCompletedReturnsGray(): void
    {
        $color = AgentViewPane::statusColor('completed');
        $this->assertSame('#7d6e98', $color->toHex());
    }

    public function testStatusColorStoppedReturnsGray(): void
    {
        $color = AgentViewPane::statusColor('stopped');
        $this->assertSame('#7d6e98', $color->toHex());
    }

    // =========================================================================
    // AgentViewPane::statusColor — unknown status defaults to gray
    // =========================================================================

    public function testStatusColorUnknownDefaultsToGray(): void
    {
        $color = AgentViewPane::statusColor('unknown-nonsense');
        $this->assertSame('#7d6e98', $color->toHex());
    }

    // =========================================================================
    // AgentViewPane::statusColor — case insensitivity
    // =========================================================================

    public function testStatusColorIsCaseInsensitive(): void
    {
        $this->assertSame('#9ece6a', AgentViewPane::statusColor('WORKING')->toHex());
        $this->assertSame('#9ece6a', AgentViewPane::statusColor('Working')->toHex());
        $this->assertSame('#f7768e', AgentViewPane::statusColor('FAILED')->toHex());
        $this->assertSame('#7d6e98', AgentViewPane::statusColor('COMPLETED')->toHex());
    }
}
