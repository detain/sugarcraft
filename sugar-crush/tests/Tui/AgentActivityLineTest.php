<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Live\ActivityItem;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Agents\Live\AgentLiveState;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\AgentActivityLine;

/**
 * Roadmap P-B2: the one row a delegated run gets under its Task row — its
 * glyph, its latest item, its figures — and the promise that matters most to
 * the frame: it is never wider than the pane, at any width, whatever the run
 * wrote into it.
 */
final class AgentActivityLineTest extends TestCase
{
    private const NOW = 1000.0;

    public function testARunningRunShowsItsCurrentCallAndItsFigures(): void
    {
        $state = self::running([
            ActivityItem::toolStarted('c1', 'Grep', '"LoginController" routes/'),
        ], ['tools' => 7, 'tokensIn' => 3100, 'tokensOut' => 1000, 'costUsd' => 0.0021, 'startedAt' => self::NOW - 12]);

        $line = self::plain(AgentActivityLine::render($state, 120, Theme::default(), 0, self::NOW));

        self::assertSame('  └ ⠋ Grep "LoginController" routes/ · 7 tools · 0:12 · 4.1K tok · $0.0021', $line);
    }

    public function testTheSpinnerFrameIsTheCallersSoTheGlyphIsPinnable(): void
    {
        $state = self::running([ActivityItem::thinking()]);

        self::assertStringStartsWith('  └ ⠙ thinking…', self::plain(AgentActivityLine::render($state, 80, Theme::default(), 1, self::NOW)));
        self::assertStringStartsWith('  └ ⠏ thinking…', self::plain(AgentActivityLine::render($state, 80, Theme::default(), 9, self::NOW)));
    }

    /**
     * @return iterable<string, array{0: list<ActivityItem>, 1: string}>
     */
    public static function latestItems(): iterable
    {
        yield 'nothing yet' => [[], 'starting…'];
        yield 'a finished call' => [[ActivityItem::toolStarted('c1', 'Read', 'a.php'), ActivityItem::toolFinished('c1', 'Read', true, 3)], '✓ Read a.php'];
        yield 'a failed call' => [[ActivityItem::toolStarted('c1', 'Bash', 'make'), ActivityItem::toolFinished('c1', 'Bash', false, 3)], '✗ Bash make'];
        yield 'a thought after a call' => [[ActivityItem::toolStarted('c1', 'Read', 'a.php'), ActivityItem::toolFinished('c1', 'Read', true, 3), ActivityItem::thinking()], 'thinking…'];
        yield 'prose' => [[ActivityItem::text('The guard is attached in')], '"The guard is attached in"'];
        yield 'a running call outranks newer prose' => [[ActivityItem::toolStarted('c1', 'Glob', '*.php'), ActivityItem::text('meanwhile')], 'Glob *.php'];
    }

    /**
     * @param list<ActivityItem> $items
     */
    #[DataProvider('latestItems')]
    public function testTheLatestItemIsWhatTheRunDidLast(array $items, string $expected): void
    {
        $line = self::plain(AgentActivityLine::render(self::running($items), 120, Theme::default(), 0, self::NOW));

        self::assertStringStartsWith('  └ ⠋ ' . $expected, $line);
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function outcomes(): iterable
    {
        yield 'complete' => [SubAgentActivity::OUTCOME_COMPLETE, null, '  └ ✓ done · 2 tools · 0:31 · 500 tok'];
        yield 'failed' => [SubAgentActivity::OUTCOME_FAILED, 'sub-agent "explore" failed: step cap 50 reached', '  └ ✗ failed: step cap 50 reached · 2 tools · 0:31 · 500 tok · resumable'];
        yield 'cancelled' => [SubAgentActivity::OUTCOME_CANCELLED, null, '  └ ⏹ cancelled · 2 tools · 0:31 · 500 tok · resumable'];
        yield 'no report' => [SubAgentActivity::OUTCOME_EMPTY, null, '  └ ⏸ stopped without a report · 2 tools · 0:31 · 500 tok · resumable'];
    }

    #[DataProvider('outcomes')]
    public function testAFinishedRunShowsItsOutcomeGlyph(string $outcome, ?string $error, string $expected): void
    {
        $state = self::finished($outcome, $error);

        self::assertSame($expected, self::plain(AgentActivityLine::render($state, 120, Theme::default(), 3, self::NOW + 31)));
    }

    public function testAKnownDurationOutranksTheFramesClock(): void
    {
        $state = self::finished(SubAgentActivity::OUTCOME_COMPLETE, null);

        self::assertStringContainsString('· 1:05 ·', self::plain(AgentActivityLine::render($state, 120, Theme::default(), 0, self::NOW + 999, 65.4)));
    }

    public function testFiguresDropCostThenTokensThenToolsAsThePaneNarrows(): void
    {
        $state = self::running([ActivityItem::toolStarted('c1', 'Grep', '"LoginController" routes/')], [
            'tools' => 7, 'tokensIn' => 3100, 'tokensOut' => 1000, 'costUsd' => 0.0021, 'startedAt' => self::NOW - 12,
        ]);

        $at = static fn (int $width): string => self::plain(AgentActivityLine::render($state, $width, Theme::default(), 0, self::NOW));

        self::assertStringContainsString('$0.0021', $at(120));
        self::assertStringNotContainsString('$', $at(48));
        self::assertStringContainsString('4.1K tok', $at(48));
        self::assertStringNotContainsString('tok', $at(38));
        self::assertStringContainsString('7 tools', $at(38));
        self::assertStringNotContainsString('tools', $at(28));
        self::assertStringContainsString('0:12', $at(28));
        // The item keeps its minimum before the clock goes, then is elided
        // in the middle rather than cut off its tail.
        self::assertStringContainsString('…', $at(28));
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function widths(): iterable
    {
        foreach ([1, 2, 3, 5, 8, 12, 20, 24, 30, 40, 60, 80, 100, 120, 160] as $width) {
            yield "{$width} cols" => [$width];
        }
    }

    #[DataProvider('widths')]
    public function testTheLineIsNeverWiderThanThePane(int $width): void
    {
        $states = [
            self::running([ActivityItem::toolStarted('c1', 'Bash', str_repeat('very long command ', 20))], [
                'tools' => 123, 'tokensIn' => 1_234_567, 'tokensOut' => 99_999, 'costUsd' => 12.5, 'startedAt' => self::NOW - 4000,
            ]),
            self::running([ActivityItem::text(str_repeat('全角の文章 ', 40))]),
            self::finished(SubAgentActivity::OUTCOME_FAILED, str_repeat('a long failure reason ', 20)),
        ];

        foreach ($states as $state) {
            $line = AgentActivityLine::render($state, $width, Theme::default(), 4, self::NOW);
            self::assertStringNotContainsString("\n", $line);
            self::assertLessThanOrEqual($width, Width::string(self::plain($line)), "at {$width} columns: " . self::plain($line));
        }
    }

    public function testAgentWrittenTextCannotSmuggleMarkupOrBreakTheRow(): void
    {
        $hostile = "Read \u{E000}toolcall:x\u{E001}evil\t\x1b[31mred\x1b[0m\nsecond line\u{E002}";
        $state = self::running([ActivityItem::toolStarted('c1', "Gr\u{E000}ep", $hostile)]);

        $line = AgentActivityLine::render($state, 200, Theme::default(), 0, self::NOW);
        $plain = self::plain($line);

        self::assertSame(0, preg_match('/[\x{E000}-\x{F8FF}]/u', $line), 'no Private-Use code point (zone or image marker) survives');
        self::assertStringNotContainsString("\n", $line);
        self::assertStringNotContainsString("\t", $line);
        self::assertStringNotContainsString('[31m', $plain, 'the agent\'s own escape is stripped, not painted');
        self::assertStringContainsString('second line', $plain, 'the newline is folded to a space, not dropped with its text');
    }

    public function testAZeroWidthPaneGetsNothing(): void
    {
        self::assertSame('', AgentActivityLine::render(self::running([]), 0, Theme::default(), 0, self::NOW));
    }

    /**
     * @param list<ActivityItem> $items
     * @param array<string, int|float> $stats
     */
    private static function running(array $items, array $stats = []): AgentLiveState
    {
        return AgentLiveState::fromActivity(new SubAgentActivity(
            SubAgentActivity::OP_PROGRESS,
            'subagent_1_a',
            'explore',
            '',
            2,
            '',
            parentCallId: 'tc_1',
            items: $items,
            stats: $stats,
        ), self::NOW);
    }

    private static function finished(string $outcome, ?string $error): AgentLiveState
    {
        return AgentLiveState::fromActivity(new SubAgentActivity(
            SubAgentActivity::OP_FINISHED,
            'subagent_1_a',
            'explore',
            '',
            9,
            '',
            parentCallId: 'tc_1',
            stats: ['tools' => 2, 'tokensIn' => 400, 'tokensOut' => 100, 'startedAt' => self::NOW - 31],
            outcome: $outcome,
            error: $error,
            resumeId: 'abcdef0123456789',
        ), self::NOW);
    }

    private static function plain(string $line): string
    {
        return Ansi::strip($line);
    }
}
