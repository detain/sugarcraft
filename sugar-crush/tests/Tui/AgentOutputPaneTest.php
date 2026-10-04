<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\AgentOutputPane;
use SugarCraft\Crush\Tui\AgentOutputState;
use SugarCraft\Crush\Tui\Mode;
use SugarCraft\Crush\Tui\StallWarning;
use SugarCraft\Crush\Theme;
use SugarCraft\Core\Util\Color;

final class AgentOutputPaneTest extends TestCase
{
    /** The palette every colour assertion below is stated against. */
    private static function theme(): Theme
    {
        return Theme::byName('dark');
    }

    /**
     * The `r;g;b` triple a TrueColor SGR carries for this colour — the shape
     * these tests match on, so an assertion catches the colour whether it is
     * used as a foreground (38;2;…) or a border (also 38;2;…).
     */
    private static function rgb(Color $c): string
    {
        return $c->r . ';' . $c->g . ';' . $c->b;
    }

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

        $output = AgentOutputPane::render($state, 60, 10, self::theme(), Mode::Peek);

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

        $output = AgentOutputPane::render($state, 60, 10, self::theme(), Mode::Peek);

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

        $output = AgentOutputPane::render($state, 60, 10, self::theme(), Mode::Peek);

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

    /**
     * The buffer handed in is a bounded tail, so the "more" figure has to
     * count what the agent produced in ALL: counting the tail pinned it at
     * "+ 4 more" for every long-running agent, however much it wrote.
     */
    public function testRenderPeekCountsHiddenLinesAgainstEverythingProducedNotTheTail(): void
    {
        $tail = array_map(static fn (int $i): string => "line {$i}", range(93, 100));
        $state = new AgentOutputState(
            name: 'coder-1',
            status: 'streaming',
            operation: 'Generating code',
            elapsedSeconds: 120,
            tokensUsed: 2_500,
            costUsd: 0.0,
            model: 'claude-sonnet-4-6',
            outputBuffer: $tail,
            totalLines: 100,
        );

        $output = AgentOutputPane::render($state, 60, 10, self::theme(), Mode::Peek);

        $this->assertStringContainsString('+ 96 more line(s)', $output, '100 produced, 4 shown');
        $this->assertStringContainsString('line 100', $output);
        $this->assertStringContainsString('Generating code', $output, 'a peek tile names what the agent is doing');
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
        $output = AgentOutputPane::render($state, 60, 20, self::theme(), Mode::Attach);

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
        $output = AgentOutputPane::render($state, 60, 6, self::theme(), Mode::Attach);

        // The newest three — the buffer is a live tail (P-C2 reaches this).
        $this->assertStringContainsString('output line 8', $output);
        $this->assertStringContainsString('output line 9', $output);
        $this->assertStringContainsString('output line 10', $output);
        // Older lines than the row limit should NOT appear.
        $this->assertStringNotContainsString('output line 7', $output);
        $this->assertDoesNotMatchRegularExpression('/output line 1(?!0)/', $output);
        // Footer should indicate total line count and that we're showing a subset.
        $this->assertStringContainsString('lines: 10', $output);
        $this->assertStringContainsString('showing last 3', $output);
    }

    // ─────────────────────────────────────────────────────────────────
    // borderColor()
    // ─────────────────────────────────────────────────────────────────

    public function testBorderColorAllSixStatuses(): void
    {
        $t = self::theme();
        // status → the Theme token that must colour it
        $expected = [
            'working'   => $t->shellSuccess,
            'waiting'   => $t->shellWarning,
            'streaming' => $t->shellInfo,
            'failed'    => $t->shellError,
            'completed' => $t->shellMuted,
            'stopped'   => $t->shellMuted,
        ];

        foreach ($expected as $status => $token) {
            $color = AgentOutputPane::borderColor($status, $t);
            $this->assertSame(
                $token->toHex(),
                $color->toHex(),
                "borderColor({$status}) must come from the palette, not a literal",
            );
        }
    }

    public function testBorderColorUnknownStatusDefaultsToTheCompletedToken(): void
    {
        $t = self::theme();
        $this->assertSame(
            AgentOutputPane::borderColor('completed', $t)->toHex(),
            AgentOutputPane::borderColor('not-a-real-status', $t)->toHex(),
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // statusColor()
    // ─────────────────────────────────────────────────────────────────

    public function testStatusColorAllSixStatuses(): void
    {
        $t = self::theme();
        $expected = [
            'working'   => $t->shellSuccess,
            'waiting'   => $t->shellWarning,
            'streaming' => $t->shellInfo,
            'failed'    => $t->shellError,
            'completed' => $t->shellMuted,
            'stopped'   => $t->shellMuted,
        ];

        foreach ($expected as $status => $token) {
            $color = AgentOutputPane::statusColor($status, $t);
            $this->assertSame(
                $token->toHex(),
                $color->toHex(),
                "statusColor({$status}) must come from the palette, not a literal",
            );
        }
    }

    public function testStatusColorUnknownStatusDefaultsToTheCompletedToken(): void
    {
        $t = self::theme();
        $this->assertSame(
            AgentOutputPane::statusColor('completed', $t)->toHex(),
            AgentOutputPane::statusColor('unknown-status', $t)->toHex(),
        );
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

        $output = AgentOutputPane::render($state, 60, 10, self::theme(), Mode::Peek);

        // The stall colour is the palette's `warning`, not a literal amber.
        $this->assertStringContainsString(self::rgb(self::theme()->shellWarning), $output);
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

        $output = AgentOutputPane::render($state, 60, 20, self::theme(), Mode::Attach);

        // The palette's warning colour must appear in the stall state.
        $this->assertStringContainsString(self::rgb(self::theme()->shellWarning), $output);
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

        $output = AgentOutputPane::render($state, 60, 10, self::theme(), Mode::Peek);

        // When not stalled, the border uses the STATUS token, not STALL_TOKEN.
        $this->assertStringContainsString(self::rgb(self::theme()->shellSuccess), $output);
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

        $normalOutput = AgentOutputPane::render($normalState, 60, 10, self::theme(), Mode::Peek);
        $stalledOutput = AgentOutputPane::render($stalledState, 60, 10, self::theme(), Mode::Peek);

        // Normal state uses the `working` token (the palette's success).
        $this->assertStringContainsString(self::rgb(self::theme()->shellSuccess), $normalOutput);
        // Stalled state uses the stall token (the palette's warning) instead.
        $this->assertStringContainsString(self::rgb(self::theme()->shellWarning), $stalledOutput);
        $this->assertStringNotContainsString(self::rgb(self::theme()->shellSuccess), $stalledOutput);
    }
}
