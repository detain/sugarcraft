<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\AgentOutputPane;
use SugarCraft\Crush\Tui\AgentOutputState;
use SugarCraft\Crush\Tui\Mode;

final class AgentOutputPaneTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    // render() — Mode::Peek
    // ─────────────────────────────────────────────────────────────────

    public function testRenderPeekEmptyBufferShowsHeaderAndNoOutputLines(): void
    {
        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading files',
            elapsedSeconds: 45,
            tokensUsed: 500,
            costUsd: 0.0012,
            model: 'claude-sonnet-4-6',
            outputBuffer: [],
        );

        $output = AgentOutputPane::render($state, 60, 10, Mode::Peek);

        // Header must be present (contains agent name and status).
        $this->assertStringContainsString('coder-1', $output);
        $this->assertStringContainsString('[working]', $output);
        $this->assertStringContainsString('claude-sonnet-4-6', $output);
        // No "more line(s)" indicator when buffer is empty.
        $this->assertStringNotContainsString('more line(s)', $output);
    }

    public function testRenderPeekNonEmptyBufferShowsLastNPeeksLines(): void
    {
        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading files',
            elapsedSeconds: 45,
            tokensUsed: 500,
            costUsd: 0.0012,
            model: 'claude-sonnet-4-6',
            outputBuffer: ['line 1', 'line 2', 'line 3'],
        );

        $output = AgentOutputPane::render($state, 60, 10, Mode::Peek);

        // All three lines must appear (buffer is <= PEEK_LINES = 4).
        $this->assertStringContainsString('line 1', $output);
        $this->assertStringContainsString('line 2', $output);
        $this->assertStringContainsString('line 3', $output);
        // No "more line(s)" indicator when buffer fits entirely.
        $this->assertStringNotContainsString('more line(s)', $output);
    }

    public function testRenderPeekExceedsPeekLinesShowsTruncationIndicator(): void
    {
        $lines = array_map(fn($i) => "line {$i}", range(1, 8));
        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'streaming',
            operation: 'Generating code',
            elapsedSeconds: 120,
            tokensUsed: 2_500,
            costUsd: 0.0087,
            model: 'claude-sonnet-4-6',
            outputBuffer: $lines,
        );

        $output = AgentOutputPane::render($state, 60, 10, Mode::Peek);

        // Only last PEEK_LINES (4) lines should appear: 5, 6, 7, 8.
        $this->assertStringContainsString('line 5', $output);
        $this->assertStringContainsString('line 6', $output);
        $this->assertStringContainsString('line 7', $output);
        $this->assertStringContainsString('line 8', $output);
        // Earlier lines should not appear.
        $this->assertStringNotContainsString('line 1', $output);
        $this->assertStringNotContainsString('line 2', $output);
        // Truncation indicator should appear.
        $this->assertStringContainsString('more line(s)', $output);
        $this->assertStringContainsString('4', $output); // 8 - 4 = 4 more lines.
    }

    // ─────────────────────────────────────────────────────────────────
    // render() — Mode::Attach
    // ─────────────────────────────────────────────────────────────────

    public function testRenderAttachNonEmptyBufferShowsFullBufferWithFooter(): void
    {
        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'waiting',
            operation: 'Waiting for tool results',
            elapsedSeconds: 30,
            tokensUsed: 300,
            costUsd: 0.0009,
            model: 'claude-sonnet-4-6',
            outputBuffer: ['first output', 'second output'],
        );

        // Height large enough to show all lines.
        $output = AgentOutputPane::render($state, 60, 20, Mode::Attach);

        // Header present.
        $this->assertStringContainsString('coder-1', $output);
        $this->assertStringContainsString('[waiting]', $output);
        // Full buffer content present.
        $this->assertStringContainsString('first output', $output);
        $this->assertStringContainsString('second output', $output);
        // Footer must show "showing last N" when buffer fits.
        $this->assertStringContainsString('showing last 2', $output);
    }

    public function testRenderAttachBufferExceedsHeightTruncatesAndShowsFooter(): void
    {
        $lines = array_map(fn($i) => "output line {$i}", range(1, 10));
        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'streaming',
            operation: 'Generating response',
            elapsedSeconds: 60,
            tokensUsed: 1_000,
            costUsd: 0.0035,
            model: 'claude-sonnet-4-6',
            outputBuffer: $lines,
        );

        // Height of 6 → reserve 3 → outputRows = 3.
        $output = AgentOutputPane::render($state, 60, 6, Mode::Attach);

        // Lines 1-3 should be visible.
        $this->assertStringContainsString('output line 1', $output);
        $this->assertStringContainsString('output line 2', $output);
        $this->assertStringContainsString('output line 3', $output);
        // Lines beyond row limit should NOT appear.
        $this->assertStringNotContainsString('output line 4', $output);
        // Footer should indicate total line count and that we're showing a subset.
        $this->assertStringContainsString('lines: 10', $output);
        $this->assertStringContainsString('showing last 3', $output);
    }

    // ─────────────────────────────────────────────────────────────────
    // borderColor()
    // ─────────────────────────────────────────────────────────────────

    public function testBorderColorAllSixStatuses(): void
    {
        // status → expected hex (with #)
        $expected = [
            'working'   => '#9ece6a',
            'waiting'   => '#e0af68',
            'streaming' => '#7aa2f7',
            'failed'    => '#f7768e',
            'completed' => '#7d6e98',
            'stopped'   => '#7d6e98',
        ];

        foreach ($expected as $status => $hex) {
            $color = AgentOutputPane::borderColor($status);
            $this->assertSame(
                $hex,
                $color->toHex(),
                "borderColor({$status}) should return #{$hex}",
            );
        }
    }

    public function testBorderColorUnknownStatusDefaultsToGray(): void
    {
        $color = AgentOutputPane::borderColor('not-a-real-status');
        // Default is 'completed' which maps to #7d6e98.
        $this->assertSame('#7d6e98', $color->toHex());
    }

    // ─────────────────────────────────────────────────────────────────
    // statusColor()
    // ─────────────────────────────────────────────────────────────────

    public function testStatusColorAllSixStatuses(): void
    {
        $expected = [
            'working'   => '#9ece6a',
            'waiting'   => '#e0af68',
            'streaming' => '#7aa2f7',
            'failed'    => '#f7768e',
            'completed' => '#7d6e98',
            'stopped'   => '#7d6e98',
        ];

        foreach ($expected as $status => $hex) {
            $color = AgentOutputPane::statusColor($status);
            $this->assertSame(
                $hex,
                $color->toHex(),
                "statusColor({$status}) should return #{$hex}",
            );
        }
    }

    public function testStatusColorUnknownStatusDefaultsToGray(): void
    {
        $color = AgentOutputPane::statusColor('unknown-status');
        // Default is 'completed' which maps to #7d6e98.
        $this->assertSame('#7d6e98', $color->toHex());
    }
}
