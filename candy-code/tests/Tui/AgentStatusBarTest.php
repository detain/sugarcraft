<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\AgentDisplayState;
use SugarCraft\Code\Tui\AgentStatusBar;
use SugarCraft\Code\Theme;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

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

        $this->assertSame('1.2K tok | $0.0042', $state->usageDisplay());
    }

    /**
     * Counts are compact, and a run shows its CURRENT context beside the
     * total it has burned — 2.1M spent can sit at a 200K context.
     */
    public function testUsageDisplayShowsCompactTotalsAndTheCurrentContext(): void
    {
        $state = AgentDisplayState::new('a', 'working', 'op', 5, 2_100_000, 0.0, 200_000);

        $this->assertSame('2.1M tok · 200K ctx', $state->usageDisplay());
    }

    public function testUsageDisplayLeavesOutACostThatIsZero(): void
    {
        $state = AgentDisplayState::new('a', 'working', 'op', 5, 1234, 0.0);

        $this->assertSame('1.2K tok', $state->usageDisplay(), 'an unpriced backend bills nothing, so no `| $0.0000`');
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

        $this->assertStringContainsString('1.2K tok', $line);
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

    // =========================================================================
    // Truncation cell discipline (tui-F-2) — the status line is painted on ONE
    // terminal row, so a mis-count pushes it past the pane width. The private
    // per-codepoint table this guards used to score every emoji as 1 cell, a
    // ZWJ family as 5, a combining base+mark pair as 2, and — on invalid
    // UTF-8, where preg_split('//u') returns false — byte-slice a raw substr
    // mid-codepoint. The fixtures below are planted Unicode strings whose TRUE
    // cell count is asserted with a hardcoded expected value and cross-checked
    // against the canonical Width measure, so the pin does not grade the code
    // against itself.
    // =========================================================================

    /**
     * Invoke the private static AgentStatusBar::truncate() cell budget.
     */
    private static function truncatingOperation(string $text, int $maxWidth): string
    {
        $method = new \ReflectionMethod(AgentStatusBar::class, 'truncate');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke(null, $text, $maxWidth);

        return $result;
    }

    public function testTruncateReturnsEmptyStringUnchanged(): void
    {
        $this->assertSame('', self::truncatingOperation('', 30));
    }

    public function testTruncateKeepsFittingTextByteIdentical(): void
    {
        $fits = 'Reading auth.php';
        $this->assertSame($fits, self::truncatingOperation($fits, 30));
    }

    public function testTruncatePacksAsciiToBudgetMinusTheEllipsis(): void
    {
        $result = self::truncatingOperation(str_repeat('x', 40), 30);
        $this->assertSame(str_repeat('x', 29) . "\u{2026}", $result);
    }

    public function testTruncateScoresEmojiAsTwoCellsNotOne(): void
    {
        // 20 grinning faces = 40 cells; the old table read them as 20 and let
        // the whole run through un-truncated, blowing the 30-cell budget.
        $emoji = "\u{1F600}";
        $result = self::truncatingOperation(str_repeat($emoji, 20), 30);

        $this->assertLessThanOrEqual(30, Width::of($result), 'operation overflowed its cell budget');
        $this->assertSame(14, substr_count($result, $emoji), 'emoji must count two cells each');
        $this->assertStringEndsWith("\u{2026}", $result);
    }

    public function testTruncateScoresCombiningClustersAsOneCellEach(): void
    {
        // "e" + U+0301 is ONE cluster of ONE cell. The old table charged 2 and
        // walked per codepoint, so it cut between a base and its mark, leaving
        // a dangling "e". The result must be whole clusters only.
        $cluster = "e\u{0301}";
        $result = self::truncatingOperation(str_repeat($cluster, 40), 30);

        $this->assertSame(str_repeat($cluster, 29) . "\u{2026}", $result);
        $this->assertMatchesRegularExpression('/^(?:e\x{0301})*\x{2026}$/u', $result, 'orphaned combining base');
    }

    public function testTruncateNeverSplitsAZwjFamilyCluster(): void
    {
        // 👨‍👩‍👧 is one 2-cell cluster. The old per-codepoint walk
        // charged its five codepoints individually and cut mid-sequence,
        // stranding a dangling ZWJ before the ellipsis.
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $result = self::truncatingOperation(str_repeat($family, 20), 30);

        $this->assertLessThanOrEqual(30, Width::of($result));
        $this->assertStringEndsWith("\u{2026}", $result);
        $body = substr($result, 0, -strlen("\u{2026}"));

        // Every retained family carries both of its ZWJ joiners: the body is a
        // whole number of families, never a half-joined one.
        $this->assertSame(
            substr_count($body, $family) * 2,
            substr_count($body, "\u{200D}"),
            'ZWJ family was split at the cut',
        );
        $this->assertGreaterThan(0, substr_count($body, $family));
    }

    public function testTruncateScoresRegionalFlagPairAsTwoCells(): void
    {
        $flag = "\u{1F1FA}\u{1F1F8}";
        $result = self::truncatingOperation(str_repeat($flag, 20), 30);

        $this->assertLessThanOrEqual(30, Width::of($result));
        $this->assertSame(14, substr_count($result, $flag));
    }

    public function testTruncateScoresCjkAsTwoCells(): void
    {
        $han = "\u{4F60}"; // 你
        $result = self::truncatingOperation(str_repeat($han, 20), 30);

        $this->assertSame(14, substr_count($result, $han));
        $this->assertStringEndsWith("\u{2026}", $result);
    }

    public function testTruncateNeverByteSlicesInvalidUtf8(): void
    {
        // A lone continuation lead byte at the tail: the old preg_split('//u')
        // returned false and the fallback did a raw substr, emitting invalid
        // UTF-8 (mojibake) into the bar. The cell route must stay well-formed.
        $overlong = str_repeat('x', 35) . "\xC3";
        $result = self::truncatingOperation($overlong, 30);

        $this->assertTrue(
            mb_check_encoding($result, 'UTF-8'),
            'truncate emitted invalid UTF-8',
        );
        $this->assertLessThanOrEqual(30, Width::of($result));
    }

    public function testTruncateStripsControlBytesFromAnOverflowingOperation(): void
    {
        // A model-supplied operation carrying a BEL/CSI must not smuggle the
        // escape through the overflow leg — Sanitize::untrusted() is applied
        // there before the cut.
        $result = self::truncatingOperation("a\x07b" . str_repeat('x', 40), 30);

        $this->assertStringNotContainsString("\x07", $result);
        $this->assertLessThanOrEqual(30, Width::of($result));
    }

    public function testRenderAgentLineTruncatesAnEmojiOperationInCells(): void
    {
        // Integration: the production budget is 30 cells (renderAgentLine
        // passes 30). Twenty emoji used to ride in whole; now the operation
        // slot holds 14 of them plus the ellipsis.
        $agent = new AgentDisplayState(
            name: 'coder-1',
            status: 'working',
            operation: str_repeat("\u{1F600}", 20),
            elapsedSeconds: 10,
            tokensUsed: 100,
            costUsd: 0.0003,
        );

        $line = AgentStatusBar::renderAgentLine($agent, self::theme());

        $this->assertSame(14, substr_count($line, "\u{1F600}"));
        $this->assertStringContainsString("\u{2026}", $line);
    }
}
