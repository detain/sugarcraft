<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\MultiplexerSplitPane;
use SugarCraft\Crush\Tui\MultiplexerType;
use SugarCraft\Crush\Tui\SplitDirection;
use SugarCraft\Crush\Tui\SplitLayout;

/**
 * @see MultiplexerSplitPane
 * @see MultiplexerType
 * @see MultiplexerSplitPane::isActive()
 * @see MultiplexerSplitPane::getMultiplexerType()
 * @see MultiplexerSplitPane::renderWithMultiplexer()
 */
final class MultiplexerSplitPaneTest extends TestCase
{
    // =========================================================================
    // MultiplexerType Enum Tests
    // =========================================================================

    public function testMultiplexerTypeNoneIsNotActive(): void
    {
        $type = MultiplexerType::None;

        $this->assertFalse($type->isActive());
        $this->assertSame('none', $type->value);
    }

    public function testMultiplexerTypeTmuxIsActive(): void
    {
        $type = MultiplexerType::Tmux;

        $this->assertTrue($type->isActive());
        $this->assertSame('tmux', $type->value);
    }

    public function testMultiplexerTypeITerm2IsActive(): void
    {
        $type = MultiplexerType::ITerm2;

        $this->assertTrue($type->isActive());
        $this->assertSame('iterm2', $type->value);
    }

    public function testMultiplexerTypeDescriptionForNone(): void
    {
        $description = MultiplexerType::None->description();

        $this->assertSame('No multiplexer (in-process rendering)', $description);
    }

    public function testMultiplexerTypeDescriptionForTmux(): void
    {
        $description = MultiplexerType::Tmux->description();

        $this->assertSame('tmux multiplexer', $description);
    }

    public function testMultiplexerTypeDescriptionForITerm2(): void
    {
        $description = MultiplexerType::ITerm2->description();

        $this->assertSame('iTerm2 (macOS)', $description);
    }

    // =========================================================================
    // No Multiplexer Detection Tests
    // =========================================================================

    public function testIsActiveReturnsFalseWhenNoMultiplexerDetected(): void
    {
        // TMUX not set, TERM_PROGRAM not set
        $detector = new MultiplexerSplitPane('', '');

        $this->assertFalse($detector->isActive());
    }

    public function testGetMultiplexerTypeReturnsNoneWhenNoMultiplexerDetected(): void
    {
        $detector = new MultiplexerSplitPane('', '');

        $this->assertSame(MultiplexerType::None, $detector->getMultiplexerType());
    }

    public function testIsActiveReturnsFalseWhenTmuxEnvIsEmptyString(): void
    {
        // Empty string is treated as not set
        $detector = new MultiplexerSplitPane('', '');

        $this->assertFalse($detector->isActive());
    }

    public function testIsActiveReturnsFalseWhenOnlyTermProgramSetToOtherValue(): void
    {
        // TERM_PROGRAM set to something other than iTerm.app
        $detector = new MultiplexerSplitPane('', 'Apple_Terminal');

        $this->assertFalse($detector->isActive());
    }

    public function testGetMultiplexerTypeReturnsNoneWhenOnlyTermProgramSetToOtherValue(): void
    {
        $detector = new MultiplexerSplitPane('', 'Apple_Terminal');

        $this->assertSame(MultiplexerType::None, $detector->getMultiplexerType());
    }

    // =========================================================================
    // TMUX Detection Tests
    // =========================================================================

    public function testIsActiveReturnsTrueWhenTmuxEnvIsSet(): void
    {
        // TMUX is set to a session name (e.g., "screen,25697,0")
        $detector = new MultiplexerSplitPane('/tmp/tmux-1000/default,12345,0');

        $this->assertTrue($detector->isActive());
    }

    public function testGetMultiplexerTypeReturnsTmuxWhenTmuxEnvIsSet(): void
    {
        $detector = new MultiplexerSplitPane('/tmp/tmux-1000/default,12345,0');

        $this->assertSame(MultiplexerType::Tmux, $detector->getMultiplexerType());
    }

    public function testTmuxDetectionTakesPrecedenceOverTermProgram(): void
    {
        // Both TMUX and iTerm2 detection - TMUX should win
        $detector = new MultiplexerSplitPane('/tmp/tmux-1000/default,12345,0', 'iTerm.app');

        $this->assertSame(MultiplexerType::Tmux, $detector->getMultiplexerType());
        $this->assertTrue($detector->isActive());
    }

    // =========================================================================
    // iTerm2 Detection Tests
    // =========================================================================

    public function testIsActiveReturnsTrueWhenTermProgramIsITermApp(): void
    {
        $detector = new MultiplexerSplitPane('', 'iTerm.app');

        $this->assertTrue($detector->isActive());
    }

    public function testGetMultiplexerTypeReturnsITerm2WhenTermProgramIsITermApp(): void
    {
        $detector = new MultiplexerSplitPane('', 'iTerm.app');

        $this->assertSame(MultiplexerType::ITerm2, $detector->getMultiplexerType());
    }

    // =========================================================================
    // renderWithMultiplexer Fallback Tests
    // =========================================================================

    public function testRenderWithMultiplexerDelegatesToSplitLayout(): void
    {
        $detector = new MultiplexerSplitPane('', '');

        $output = $detector->renderWithMultiplexer(
            "TOP\nLINE2",
            "BOTTOM",
            SplitDirection::Horizontal,
            40,
            10,
        );

        // Should contain the content from both panes
        $this->assertStringContainsString('TOP', $output);
        $this->assertStringContainsString('BOTTOM', $output);

        // Should have the divider
        $divider = SplitDirection::Horizontal->divider();
        $this->assertStringContainsString($divider, $output);
    }

    public function testRenderWithMultiplexerHorizontalSplit(): void
    {
        $detector = new MultiplexerSplitPane('', '');

        $output = $detector->renderWithMultiplexer(
            'TOP',
            'BOTTOM',
            SplitDirection::Horizontal,
            20,
            10,
        );

        // Top should appear before divider, bottom after
        $this->assertStringStartsWith('TOP', $output);
        $this->assertStringContainsString('BOTTOM', $output);
        $this->assertStringContainsString("\u{2500}", $output);
    }

    public function testRenderWithMultiplexerVerticalSplit(): void
    {
        $detector = new MultiplexerSplitPane('', '');

        $output = $detector->renderWithMultiplexer(
            'LEFT',
            'RIGHT',
            SplitDirection::Vertical,
            40,
            10,
        );

        // Both panes should appear with vertical divider
        $this->assertStringContainsString('LEFT', $output);
        $this->assertStringContainsString('RIGHT', $output);
        $this->assertStringContainsString("\u{2502}", $output);
    }

    public function testRenderWithMultiplexerActiveMultiplexerFallsBackToInProcess(): void
    {
        // Even when TMUX is detected, we fall back to in-process rendering
        // (since we can't actually spawn tmux panes from here)
        $detector = new MultiplexerSplitPane('/tmp/tmux-1000/default,12345,0', '');

        $output = $detector->renderWithMultiplexer(
            'TOP',
            'BOTTOM',
            SplitDirection::Horizontal,
            20,
            10,
        );

        // Should still produce valid split output
        $this->assertStringContainsString('TOP', $output);
        $this->assertStringContainsString('BOTTOM', $output);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testEmptyPaneContentRendersCorrectly(): void
    {
        $detector = new MultiplexerSplitPane('', '');

        $output = $detector->renderWithMultiplexer(
            '',
            'BOTTOM',
            SplitDirection::Horizontal,
            20,
            10,
        );

        // Empty pane should not add extra content
        $this->assertStringContainsString('BOTTOM', $output);
        $this->assertStringNotContainsString('TOP', $output);
    }

    public function testZeroDimensionsReturnsEmptyOutput(): void
    {
        $detector = new MultiplexerSplitPane('', '');

        $output = $detector->renderWithMultiplexer(
            'TOP',
            'BOTTOM',
            SplitDirection::Horizontal,
            0,
            0,
        );

        $this->assertSame('', $output);
    }

    public function testBothPanesEmptyRendersDivider(): void
    {
        $detector = new MultiplexerSplitPane('', '');

        $output = $detector->renderWithMultiplexer(
            '',
            '',
            SplitDirection::Horizontal,
            20,
            10,
        );

        // Divider should still appear even with empty panes
        $divider = SplitDirection::Horizontal->divider();
        $this->assertStringContainsString($divider, $output);
    }

    // =========================================================================
    // Rendering Consistency with SplitLayout Tests
    // =========================================================================

    public function testRenderOutputMatchesInProcessRenderer(): void
    {
        $detector = new MultiplexerSplitPane('', '');

        // Direct SplitLayout rendering
        $directLayout = new SplitLayout('LEFT', 'RIGHT', SplitDirection::Vertical);
        $directOutput = $directLayout->render(40, 10);

        // Via MultiplexerSplitPane (fallback path)
        $multiplexerOutput = $detector->renderWithMultiplexer(
            'LEFT',
            'RIGHT',
            SplitDirection::Vertical,
            40,
            10,
        );

        $this->assertSame($directOutput, $multiplexerOutput);
    }
}
