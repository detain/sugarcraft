<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentViewPane;
use SugarCraft\Crush\Theme;

/**
 * @see AgentViewPane
 */
final class AgentViewPaneTest extends TestCase
{
    /** The palette every colour assertion below is stated against. */
    private static function theme(): Theme
    {
        return Theme::byName('dark');
    }

    // =========================================================================
    // AgentViewPane::render — empty list → placeholder with border
    // =========================================================================

    public function testRenderEmptyArrayReturnsPlaceholderWithBorder(): void
    {
        $output = AgentViewPane::render([], -1, 80, 20, self::theme());

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

        $output = AgentViewPane::render($agents, -1, 80, 20, self::theme());

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

        $output = AgentViewPane::render($agents, -1, 80, 20, self::theme());

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

        // The highlight FILL, read off the palette: the literal #232338 this
        // replaced was a dark band, which on a light terminal was painted under
        // text picked to be read on light.
        $bg = self::theme()->shellSeparator->toBg(ColorProfile::TrueColor);

        // When index 1 (r2) is selected, r2 line has background, c1 line does not.
        $output = AgentViewPane::render($agents, 1, 80, 20, self::theme());
        $lines = explode("\n", $output);
        // lines[0] = top border, lines[1] = c1 (not selected), lines[2] = r2 (selected), lines[3] = bottom border
        $this->assertStringNotContainsString($bg, $lines[1]); // c1 not selected
        $this->assertStringContainsString($bg, $lines[2]);     // r2 selected

        // When index 0 (c1) is selected, c1 line has background, r2 line does not.
        $output2 = AgentViewPane::render($agents, 0, 80, 20, self::theme());
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

        $output = AgentViewPane::render($agents, -1, 80, 20, self::theme());

        // The operation should be truncated (contain the ellipsis character)
        $this->assertStringContainsString("\u{2026}", $output);
        // The original long operation should NOT appear in full
        $this->assertStringNotContainsString('This is a very long operation name that should be truncated because it exceeds the budget', $output);
    }

    // =========================================================================
    // AgentViewPane::statusColor — all 6 statuses
    // =========================================================================

    public function testStatusColorMapsEachStatusToItsSemanticThemeToken(): void
    {
        $t = self::theme();

        $this->assertSame($t->shellSuccess->toHex(), AgentViewPane::statusColor('working', $t)->toHex());
        $this->assertSame($t->shellWarning->toHex(), AgentViewPane::statusColor('waiting', $t)->toHex());
        $this->assertSame($t->shellInfo->toHex(), AgentViewPane::statusColor('streaming', $t)->toHex());
        $this->assertSame($t->shellError->toHex(), AgentViewPane::statusColor('failed', $t)->toHex());
        $this->assertSame($t->shellMuted->toHex(), AgentViewPane::statusColor('completed', $t)->toHex());
        $this->assertSame($t->shellMuted->toHex(), AgentViewPane::statusColor('stopped', $t)->toHex());
    }

    /**
     * The two panes are documented as sharing one mapping
     * (AgentViewPane::STATUS_TOKEN "matched to AgentStatusBar's"); assert it
     * rather than trusting the comment, in every palette.
     */
    public function testStatusColoursAgreeWithAgentStatusBarInEveryPalette(): void
    {
        foreach (Theme::names() as $name) {
            $t = Theme::byName($name);
            foreach (['working', 'waiting', 'streaming', 'failed', 'completed', 'stopped', 'nonsense'] as $status) {
                $this->assertSame(
                    \SugarCraft\Crush\Tui\AgentStatusBar::statusColor($status, $t)->toHex(),
                    AgentViewPane::statusColor($status, $t)->toHex(),
                    "{$name}/{$status}",
                );
            }
        }
    }

    // =========================================================================
    // AgentViewPane::statusColor — unknown status defaults to the completed token
    // =========================================================================

    public function testStatusColorUnknownDefaultsToTheCompletedToken(): void
    {
        $t = self::theme();
        $this->assertSame(
            AgentViewPane::statusColor('completed', $t)->toHex(),
            AgentViewPane::statusColor('unknown-nonsense', $t)->toHex(),
        );
    }

    // =========================================================================
    // AgentViewPane::statusColor — case insensitivity
    // =========================================================================

    public function testStatusColorIsCaseInsensitive(): void
    {
        $t = self::theme();
        $this->assertSame($t->shellSuccess->toHex(), AgentViewPane::statusColor('WORKING', $t)->toHex());
        $this->assertSame($t->shellSuccess->toHex(), AgentViewPane::statusColor('Working', $t)->toHex());
        $this->assertSame($t->shellError->toHex(), AgentViewPane::statusColor('FAILED', $t)->toHex());
        $this->assertSame($t->shellMuted->toHex(), AgentViewPane::statusColor('COMPLETED', $t)->toHex());
    }
}
