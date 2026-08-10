<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\AgentOutputPane;
use SugarCraft\Crush\Tui\AgentOutputState;
use SugarCraft\Crush\Tui\Mode;
use SugarCraft\Crush\Tui\StallWarning;

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

    // ─────────────────────────────────────────────────────────────────
    // render() — stall warning display
    // ─────────────────────────────────────────────────────────────────

    public function testRenderPeekWithStallWarningUsesAmberBorderColor(): void
    {
        $stallWarning = new StallWarning(
            agentId: 'coder-1',
            detectedAt: new \DateTimeImmutable(),
            tokenRate: 0.1,
            durationSeconds: 35,
        );

        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading files',
            elapsedSeconds: 45,
            tokensUsed: 500,
            costUsd: 0.0012,
            model: 'claude-sonnet-4-6',
            outputBuffer: ['some output line'],
            stallWarning: $stallWarning,
        );

        $output = AgentOutputPane::render($state, 60, 10, Mode::Peek);

        // STALL_HEX is '#e0af68' (amber/yellow) with RGB (224,175,104).
        // The border color ANSI code [38;2;224;175;104m must appear when stalled.
        $this->assertStringContainsString('224;175;104', $output);
        // Agent name and status still visible.
        $this->assertStringContainsString('coder-1', $output);
        $this->assertStringContainsString('[working]', $output);
    }

    public function testRenderAttachWithStallWarningUsesAmberBorderColor(): void
    {
        $stallWarning = new StallWarning(
            agentId: 'coder-1',
            detectedAt: new \DateTimeImmutable(),
            tokenRate: 0.0,
            durationSeconds: 45,
        );

        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'waiting',
            operation: 'Waiting for tool results',
            elapsedSeconds: 60,
            tokensUsed: 300,
            costUsd: 0.0009,
            model: 'claude-sonnet-4-6',
            outputBuffer: ['first output', 'second output'],
            stallWarning: $stallWarning,
        );

        $output = AgentOutputPane::render($state, 60, 20, Mode::Attach);

        // Amber border color must appear in stall state.
        $this->assertStringContainsString('224;175;104', $output);
        // Buffer content still visible.
        $this->assertStringContainsString('first output', $output);
        $this->assertStringContainsString('second output', $output);
    }

    public function testRenderWithoutStallWarningDoesNotUseAmberColor(): void
    {
        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading files',
            elapsedSeconds: 45,
            tokensUsed: 500,
            costUsd: 0.0012,
            model: 'claude-sonnet-4-6',
            outputBuffer: ['some output'],
            stallWarning: null,
        );

        $output = AgentOutputPane::render($state, 60, 10, Mode::Peek);

        // When not stalled, border uses STATUS_HEX not STALL_HEX.
        // STALL_HEX is #e0af68 (224;175;104) - should NOT appear.
        // The working status color is #9ece6a (158;206;106).
        $this->assertStringContainsString('158;206;106', $output);
        // Normal status still visible.
        $this->assertStringContainsString('coder-1', $output);
    }

    public function testRenderStallWarningUsesAmberColorNotStatusColor(): void
    {
        // Create two identical states - one with stall warning, one without.
        $normalState = new AgentOutputState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading files',
            elapsedSeconds: 45,
            tokensUsed: 500,
            costUsd: 0.0012,
            model: 'claude-sonnet-4-6',
            outputBuffer: ['output'],
            stallWarning: null,
        );

        $stallWarning = new StallWarning(
            agentId: 'coder-1',
            detectedAt: new \DateTimeImmutable(),
            tokenRate: 0.0,
            durationSeconds: 45,
        );

        $stalledState = new AgentOutputState(
            name: 'coder-1',
            status: 'working',
            operation: 'Reading files',
            elapsedSeconds: 45,
            tokensUsed: 500,
            costUsd: 0.0012,
            model: 'claude-sonnet-4-6',
            outputBuffer: ['output'],
            stallWarning: $stallWarning,
        );

        $normalOutput = AgentOutputPane::render($normalState, 60, 10, Mode::Peek);
        $stalledOutput = AgentOutputPane::render($stalledState, 60, 10, Mode::Peek);

        // Normal state uses working color (#9ece6a = 158;206;106).
        $this->assertStringContainsString('158;206;106', $normalOutput);
        // Stalled state uses amber color (#e0af68 = 224;175;104), not working color.
        $this->assertStringContainsString('224;175;104', $stalledOutput);
        // Stalled state should NOT use the working color.
        $this->assertStringNotContainsString('158;206;106', $stalledOutput);
    }
}
