<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Components\SessionsPane;
use SugarCraft\Code\Tui\Window\AgentColor;
use SugarCraft\Code\Tui\Window\WindowAttention;
use SugarCraft\Code\Tui\Window\WindowEntry;
use SugarCraft\Code\Tui\Window\WindowList;
use SugarCraft\Code\Tui\Window\WindowRef;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * The vertical sessions pane's render laws (P-TABS.c): the row keys align
 * with the painted rows one-for-one, no row ever over-pays the box the dock
 * gave it, agent rows nest directly under their session, a list longer than
 * the pane keeps the focused row in view (the TodoPane anchor rule), and
 * the `＋ New session` row is always body row 0 — pinned, never scrolled.
 *
 * Every measurement is in PAINTED CELLS: SGR is markup. The frame's own
 * border and padding sit OUTSIDE the `Style::width()` the pane is given —
 * the measurement {@see \SugarCraft\Code\Tui\Components\AgentSplitColumn::PANE_CHROME}
 * records for the other docked boxes — so a whole row may reach `$width + 4`
 * cells, and a body line that failed its inner budget would push the
 * finished box past exactly that ceiling, which the sweep here catches.
 */
final class SessionsPaneTest extends TestCase
{
    public function testPlusRowIsBodyRowZeroAcrossEveryShape(): void
    {
        $theme = Theme::default();
        $plus = Lang::t('tui.sessions.new');

        $shapes = [
            'empty list' => [WindowList::empty(), 40, 10],
            'one session' => [$this->stackedList(1, null), 40, 10],
            'crowded and short' => [$this->stackedList(12, 'w12'), 30, 3],
        ];
        foreach ($shapes as $case => [$list, $width, $rows]) {
            [$block, $keys] = SessionsPane::layout($list, $theme, $width, $rows);
            $body = $this->plainRows($block);

            self::assertNotEmpty($body, $case);
            self::assertStringContainsString($plus, $body[0], 'the new-session row must pin body row 0 (' . $case . ')');
            self::assertSame(SessionsPane::KEY_NEW, $keys[0], $case);
        }
    }

    public function testRowKeysAlignOneForOneWithThePaintedRows(): void
    {
        $theme = Theme::default();
        $list = $this->stackedList(3, 'w2')
            ->update('w2', WindowEntry::session(
                WindowRef::session('w2', 'second window'),
                WindowAttention::Working,
                2,
                [WindowEntry::agent(WindowRef::agent('run-1', 'explore', 'w2', 'red'))]
            ));

        [$block, $keys] = SessionsPane::layout($list, $theme, 44, 12);
        $body = $this->plainRows($block);

        self::assertCount(count($keys), $body, 'one key per painted body row, no more, no less');

        // Each key must name the row it sits on.
        $expect = [
            'new' => Lang::t('tui.sessions.new'),
            's:w1' => 'first window',
            's:w2' => 'second window',
            'a:run-1' => '@explore',
            's:w3' => 'third window',
        ];
        foreach ($keys as $i => $key) {
            self::assertIsString($key, 'row ' . $i . ' was expected zoneable in this fixture');
            self::assertArrayHasKey($key, $expect, 'unexpected key on row ' . $i);
            self::assertStringContainsString($expect[$key], $body[$i], 'key ' . $key . ' does not describe painted row ' . $i);
        }
    }

    public function testHostileKeysPaintTheirRowWithoutAZoneKey(): void
    {
        $theme = Theme::default();
        $list = WindowList::empty()->add(WindowEntry::session(
            WindowRef::session('bad~key', 'hostile identity'),
            WindowAttention::Idle
        ));

        [$block, $keys] = SessionsPane::layout($list, $theme, 40, 6);
        $body = $this->plainRows($block);

        self::assertSame(SessionsPane::KEY_NEW, $keys[0]);
        self::assertNull($keys[1], 'a key outside the zone-id charset degrades to not-clickable');
        self::assertStringContainsString('hostile identity', $body[1], 'the row still paints');
    }

    public function testNoRowOverPaysTheBoxAtAnySize(): void
    {
        $theme = Theme::default();
        $list = $this->stackedList(6, 'w4')
            ->update('w4', WindowEntry::session(
                WindowRef::session('w4', 'a window with an absurdly long label for a sidebar'),
                WindowAttention::Unseen,
                42,
                [
                    WindowEntry::agent(WindowRef::agent('r-a', 'an agent with a very long preset name', 'w4', 'purple'), WindowAttention::Working),
                    WindowEntry::agent(WindowRef::agent('r-b', 'x', 'w4'), WindowAttention::Failed),
                ]
            ));

        for ($width = 16; $width <= 120; $width += 8) {
            for ($rows = 4; $rows <= 14; $rows += 2) {
                $block = SessionsPane::renderModel($list, $theme, $width, $rows);
                foreach (explode("\n", $block) as $line) {
                    self::assertLessThanOrEqual(
                        $width + 4,
                        Width::string($line),
                        'frame row over-pays its box at width ' . $width . ' rows ' . $rows
                    );
                }
            }
        }
    }

    public function testAgentRowsNestDirectlyUnderTheirSession(): void
    {
        $theme = Theme::default();
        $list = WindowList::empty()->add(WindowEntry::session(
            WindowRef::session('host', 'the host'),
            WindowAttention::Idle,
            0,
            [
                WindowEntry::agent(WindowRef::agent('run-1', 'explore', 'host', 'red'), WindowAttention::Working),
                WindowEntry::agent(WindowRef::agent('run-2', 'general', 'host'), WindowAttention::Idle),
            ]
        ));

        [$block, $keys] = SessionsPane::layout($list, $theme, 50, 8);
        self::assertSame(['new', 's:host', 'a:run-1', 'a:run-2'], $keys, 'agent rows ride right behind their session row, in attach order');

        $body = $this->plainRows($block);
        self::assertStringContainsString(SessionsPane::BRANCH . ' ⠋ @explore', $body[2]);
        self::assertStringContainsString(SessionsPane::BRANCH . ' ● @general', $body[3]);
    }

    public function testLongListKeepsTheFocusedRowInView(): void
    {
        $theme = Theme::default();
        $list = $this->stackedList(12, 'w12');

        // 6 rows leaves a window of 3 for sessions (2 chrome, 1 pinned `＋`).
        [$block, $keys, $maxOffset] = SessionsPane::layout($list, $theme, 40, 6);

        self::assertContains('s:w12', $keys, 'the focused session must be inside the painted window');
        self::assertGreaterThanOrEqual(2, array_search('s:w12', $keys, true), 'the anchor rule keeps one context row above it');
        self::assertSame(9, $maxOffset, 'scroll max counts exactly the rows beyond the window');
        self::assertCount(4, $this->plainRows($block), 'the painted window costs its budget, not one row more');

        // Focus at the far start of a long list: the window must not wander.
        [, $keysHead] = SessionsPane::layout($this->stackedList(12, 'w1'), $theme, 40, 6);
        self::assertSame(['new', 's:w1', 's:w2', 's:w3'], $keysHead, 'a focus that already fits keeps offset zero');
    }

    public function testFocusHighlightRidesTheFocusedRowOnly(): void
    {
        $theme = Theme::default();
        $list = $this->stackedList(2, 'w1');
        $needle = static fn (string $name): string => Style::new()
            ->foreground($theme->shellPrimary)->bold()->underline()->render(' ' . $name);

        [$block] = SessionsPane::layout($list, $theme, 40, 8);
        self::assertStringContainsString($needle('first window'), $block, 'the focused name carries the strip focus style');
        self::assertStringNotContainsString($needle('second window'), $block);

        [$shifted] = SessionsPane::layout($list->focus('w2'), $theme, 40, 8);
        self::assertStringNotContainsString($needle('first window'), $shifted, 'the highlight follows focus, it does not stack');
        self::assertStringContainsString($needle('second window'), $shifted);
    }

    public function testAgentNamesPaintInTheirPresetColour(): void
    {
        $theme = Theme::default();
        $list = WindowList::empty()->add(WindowEntry::session(
            WindowRef::session('host', 'the host'),
            WindowAttention::Idle,
            0,
            [
                WindowEntry::agent(WindowRef::agent('run-1', 'explore', 'host', 'red'), WindowAttention::Idle),
                WindowEntry::agent(WindowRef::agent('run-2', 'plain', 'host'), WindowAttention::Idle),
            ]
        ));

        $block = SessionsPane::renderModel($list, $theme, 50, 8);

        $hex = AgentColor::resolve('red', $theme)?->toHex();
        self::assertNotNull($hex, 'the vocab is a kit guarantee, not a hope');
        self::assertStringContainsString(
            Style::new()->foreground(Color::hex($hex))->render(' @explore'),
            $block,
            'the preset colour rides the agent name verbatim from the resolver'
        );
        self::assertStringNotContainsString(
            Style::new()->foreground(Color::hex($hex))->render(' @plain'),
            $block,
            'an agent without a preset does not borrow its sibling colour'
        );
        self::assertStringContainsString(
            Style::new()->foreground($theme->shellForeground)->render(' @plain'),
            $block,
            'unpreset agents ride the shell foreground, the strip rule'
        );
    }

    public function testBadgeAndSummaryTailPaintOnTheirSessionRow(): void
    {
        $theme = Theme::default();
        $list = WindowList::empty()->add(WindowEntry::session(
            WindowRef::session('w2', 'step 4'),
            WindowAttention::Working,
            3,
            [
                WindowEntry::agent(WindowRef::agent('r-1', 'a', 'w2'), WindowAttention::Working),
                WindowEntry::agent(WindowRef::agent('r-2', 'b', 'w2'), WindowAttention::Idle),
            ]
        ));

        $body = $this->plainRows(SessionsPane::renderModel($list, $theme, 50, 6));

        self::assertStringContainsString('⠋ step 4 3 · 2 agents', $body[1], 'glyph, name, badge and summary read as one line');
        self::assertStringContainsString(SessionsPane::BRANCH . ' ⠋ @a', $body[2]);
        self::assertStringContainsString(SessionsPane::BRANCH . ' ● @b', $body[3]);
    }

    public function testEmptyListStillOffersTheNewSessionRow(): void
    {
        $theme = Theme::default();
        [$block, $keys, $maxOffset] = SessionsPane::layout(WindowList::empty(), $theme, 40, 6);

        self::assertSame([SessionsPane::KEY_NEW], $keys);
        self::assertSame(0, $maxOffset);
        self::assertCount(1, $this->plainRows($block), 'the empty pane is the pinned row alone');
    }

    /** The body rows (between the frame's borders), stripped to painted text. */
    private function plainRows(string $block): array
    {
        $rows = array_map(
            static fn (string $row): string => Ansi::strip($row),
            array_slice(explode("\n", $block), 1, -1)
        );

        return $rows;
    }

    /** $n sessions named first/second/third window... with focus on $focusedKey. */
    private function stackedList(int $n, ?string $focusedKey): WindowList
    {
        $words = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth'];
        $list = WindowList::empty();
        for ($i = 1; $i <= $n; $i++) {
            $label = ($words[$i - 1] ?? 'window ' . $i) . ' window';
            $list = $list->add(WindowEntry::session(
                WindowRef::session('w' . $i, $label),
                $i % 2 === 0 ? WindowAttention::Working : WindowAttention::Idle,
                $i % 3
            ));
        }

        return $list->focus($focusedKey);
    }
}
