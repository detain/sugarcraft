<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Theme;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;

/**
 * crush_feat.md section 8 E6 — click-to-select in the command palette /
 * picker.
 *
 * Renderer-side assertions are structural snapshots of the zone registry the
 * root scan produces (the sentinels never survive into the frame, so the
 * registry IS the observable output) plus a byte-level check that marking the
 * overlay did not move it; Chat-side assertions drive `update()` and assert on
 * the returned `[Model, ?Cmd]`.
 *
 * @see Renderer::markPaletteItems()
 * @see Chat::update()
 */
final class PaletteClickTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    // =========================================================================
    // Renderer: every palette row carries a picker-item zone
    // =========================================================================

    public function testEveryPaletteRowIsRegisteredAsAPickerItemZone(): void
    {
        $chat = $this->openPalette();
        Renderer::render($chat);

        foreach (array_keys($chat->paletteMatches()) as $index) {
            $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . $index);

            self::assertInstanceOf(Zone::class, $zone, "row {$index} is not clickable");
            // Single-row, like every other zone this renderer marks: a
            // multi-row zone can lose one sentinel to render()'s height clip
            // and desync the whole scan.
            self::assertSame($zone->startRow, $zone->endRow);
        }
    }

    /**
     * The zone id is the row's index into {@see Chat::paletteMatches()}, so a
     * zone must sit on the screen row that renders THAT label — an off-by-one
     * would silently confirm a neighbouring action.
     */
    public function testEachZoneSitsOnTheRowRenderingItsOwnLabel(): void
    {
        $chat = $this->openPalette();
        $lines = explode("\n", Renderer::render($chat));

        foreach ($chat->paletteMatches() as $index => $label) {
            $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . $index);
            self::assertInstanceOf(Zone::class, $zone);
            // Scanner rows are 1-based; the frame's lines are not.
            self::assertStringContainsString($label, $this->stripSgr($lines[$zone->startRow - 1]));
        }
    }

    /**
     * Regression for the layout failure the post-composite marking pass exists
     * to avoid: the palette box is rendered at a fixed `width(50)`, and
     * `Style::render()` counts a sentinel pair as ~29 columns of content. A
     * row marked before the box is drawn therefore ends its border ~31
     * columns short of every other row — a visibly ragged panel. Marking the
     * already-drawn, already-composited line cannot move anything.
     */
    public function testMarkingARowLeavesThePaletteBoxRectangular(): void
    {
        $chat = $this->openPalette();
        $lines = explode("\n", Renderer::render($chat));

        $box = null;
        foreach ($lines as $line) {
            if (str_contains($this->stripSgr($line), 'command palette')) {
                $box = $this->stripSgr($line);

                break;
            }
        }
        self::assertNotNull($box, 'the palette box was not drawn');
        $right = mb_strrpos($box, '╮');

        foreach (array_keys($chat->paletteMatches()) as $index) {
            $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . $index);
            self::assertInstanceOf(Zone::class, $zone);

            $row = $this->stripSgr($lines[$zone->startRow - 1]);
            self::assertSame($right, mb_strrpos($row, '│'), "row {$index} does not reach the box border");
        }
    }

    /**
     * The same failure the other way round: a sentinel eaten by the box's own
     * width fitting leaves an unmatched marker, which makes the root scan
     * throw — and the scan answers a throw by clearing the WHOLE registry, so
     * the status bar's pane zone disappears along with the palette's rows.
     * Its survival is the cheap proof the frame's markup stayed balanced.
     */
    public function testMarkingThePaletteKeepsTheRestOfTheFramesZones(): void
    {
        Renderer::render($this->openPalette());

        self::assertInstanceOf(Zone::class, Renderer::scanner()->get('pane:menu'));
    }

    public function testNoPickerZoneIsMarkedWhenClicksAreDisabled(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');

        $frame = Renderer::render($this->openPalette());

        self::assertStringContainsString('Switch theme', $this->stripSgr($frame));
        self::assertNull(Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . '0'));
    }

    public function testPickerZoneMarkersNeverReachTheRenderedFrame(): void
    {
        $frame = Renderer::render($this->openPalette());

        self::assertStringNotContainsString(Sentinel::OPEN, $frame);
        self::assertStringNotContainsString(Sentinel::CLOSE, $frame);
    }

    /**
     * The faint category headers the empty-query root list interleaves
     * between rows (§4 E6) are not selectable, so no zone may land on one.
     */
    public function testCategoryHeadersAreNotClickable(): void
    {
        $chat = $this->openPalette();
        $lines = explode("\n", Renderer::render($chat));

        $rowLines = [];
        foreach (array_keys($chat->paletteMatches()) as $index) {
            $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . $index);
            self::assertInstanceOf(Zone::class, $zone);
            $rowLines[] = $zone->startRow;
        }

        self::assertSame($rowLines, array_unique($rowLines), 'two rows claimed the same screen line');
        foreach ($rowLines as $line) {
            self::assertStringContainsString('  ', $this->stripSgr($lines[$line - 1]));
        }
    }

    public function testAFilteredPaletteZonesOnlyTheRowsItStillShows(): void
    {
        $chat = $this->openPalette('theme');
        Renderer::render($chat);

        $zones = array_filter(
            Renderer::scanner()->all(),
            static fn (Zone $zone): bool => str_starts_with($zone->id, Renderer::PALETTE_ITEM_ZONE_PREFIX),
        );

        self::assertCount(count($chat->paletteMatches()), $zones);
    }

    // =========================================================================
    // Chat: a completed click runs the row's action
    // =========================================================================

    /**
     * §8 E6 asks for the click to dispatch what Enter dispatches. "Switch
     * theme" is the clearest witness of that: its Enter path both records the
     * §4 E7 MRU entry and transitions the palette into its second-level
     * themes list, so a click-only shortcut could not produce both.
     */
    public function testLeftClickOnAPaletteRowRunsTheSameActionEnterWouldRun(): void
    {
        [$chat, $zone] = $this->renderAndLocate($this->openPalette(), 'Switch theme');

        [$afterPress, $pressCmd] = $chat->update($this->press($zone->startCol, $zone->startRow));
        self::assertNull($pressCmd);
        self::assertSame('root', $afterPress->palette()?->mode, 'press alone must not confirm');

        // Released on the press cell, not the row's far edge: sweeping to
        // endCol is a text selection under section 8 E8, not a click.
        [$after, $cmd] = $afterPress->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertSame('themes', $after->palette()?->mode);
        self::assertSame(['Switch theme'], $after->paletteMru());
    }

    /**
     * The keyboard selection starts on row 0; a click must confirm the row it
     * landed on instead of whatever the arrow keys had highlighted.
     */
    public function testClickConfirmsThePointedRowNotTheHighlightedOne(): void
    {
        $chat = $this->openPalette();
        self::assertSame(0, $chat->palette()?->selectedIndex);
        self::assertNotSame('Switch model', $chat->paletteMatches()[0]);

        [$chat, $zone] = $this->renderAndLocate($chat, 'Switch model');
        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertSame('providers', $chat->palette()?->mode);
    }

    /**
     * A row whose Enter path returns a Cmd must return the SAME Cmd on click —
     * the palette's Exit row is the only way out of the app for a user who
     * never learned Ctrl+C.
     */
    public function testClickingExitReturnsTheQuitCommand(): void
    {
        [$chat, $zone] = $this->renderAndLocate($this->openPalette(), 'Exit');

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNotNull($cmd);
        self::assertNull($chat->palette());
    }

    /** The second-level picker (§8 E6's "session picker" half) clicks too. */
    public function testClickingAThemeRowAppliesThatTheme(): void
    {
        $chat = new Chat(palette: new PaletteState('themes', '', 0));
        $labels = $chat->paletteMatches();
        $target = $labels[count($labels) - 1];
        self::assertNotSame($labels[0], $target);

        [$chat, $zone] = $this->renderAndLocate($chat, $target);
        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertNull($chat->palette());
        self::assertEquals(Theme::byName($target), $chat->theme());
    }

    public function testPressOnARowAndReleaseElsewhereRunsNothing(): void
    {
        // The drag-away / text-selection case: routing through
        // ZoneClickTracker is what keeps this from firing.
        [$chat, $zone] = $this->renderAndLocate($this->openPalette(), 'Switch theme');

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow + 2));

        self::assertNull($cmd);
        self::assertSame('root', $chat->palette()?->mode);
        self::assertSame([], $chat->paletteMru());
    }

    /**
     * Zones describe the PREVIOUSLY painted frame, so a click can arrive after
     * the palette closed (Escape, or an action confirmed by the keyboard in
     * between). Running the row anyway would confirm an action against a
     * palette the user is no longer looking at.
     */
    public function testAClickArrivingAfterThePaletteClosedIsANoOp(): void
    {
        [$open, $zone] = $this->renderAndLocate($this->openPalette(), 'Exit');
        $closed = new Chat();

        [$after] = $closed->update($this->press($zone->startCol, $zone->startRow));
        [$after, $cmd] = $after->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertNull($after->palette());
        self::assertNull($open->update($this->press(0, 0))[1]);
    }

    /**
     * Same staleness in the other direction: the frame that was on screen had
     * more rows than the palette now offers (a typed character re-filtered the
     * list between paint and click). The index is re-checked rather than
     * indexed blindly, so it runs nothing instead of the wrong row.
     */
    public function testAnOutOfRangePickerIndexRunsNothing(): void
    {
        $chat = $this->openPalette();
        Renderer::scanner()->scan(Mark::zone(Renderer::PALETTE_ITEM_ZONE_PREFIX . '99', 'stale row'), 40);
        $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . '99');
        self::assertInstanceOf(Zone::class, $zone);

        [$after] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$after, $cmd] = $after->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertSame('root', $after->palette()?->mode);
        self::assertSame([], $after->paletteMru());
    }

    /**
     * The same staleness question asked of the OTHER malformed id: a row index
     * that is not a number at all.
     *
     * Two checks stand between this zone and a confirmed action —
     * {@see Chat::paletteRowIndex()}'s `\A\d+\z` test, which is the delivery
     * whitelist, and {@see Chat::selectPaletteItem()}'s own copy of it one
     * call later. NEITHER is pinned on its own, and that is a measured fact
     * rather than an oversight: drop either alone and `(int) 'abc'` is 0, so
     * the other one still refuses; this test reds only when BOTH are gone,
     * at which point a click on a malformed id confirms row 0. That is the
     * same joint shape as {@see testAnOutOfRangePickerIndexRunsNothing()},
     * and both are reported as such in this commit's survivor list rather
     * than claimed as individual kills.
     *
     * Nothing PRODUCES such an id today — {@see Renderer::recordPaletteItemZones()}
     * builds `(string) $id` from an `array<int, string>` key — which is why
     * the id is hand-marked here, exactly as the out-of-range sibling
     * hand-marks `picker-item:99`.
     */
    public function testANonNumericPickerIndexRunsNothing(): void
    {
        $chat = $this->openPalette();
        Renderer::scanner()->scan(Mark::zone(Renderer::PALETTE_ITEM_ZONE_PREFIX . 'abc', 'bogus row'), 40);
        $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . 'abc');
        self::assertInstanceOf(Zone::class, $zone);

        $before = $chat->paletteMatches();
        self::assertNotSame([], $before, 'fixture: row 0 must exist, or "confirms row 0" proves nothing');

        [$after] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$after, $cmd] = $after->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertSame('root', $after->palette()?->mode);
        self::assertSame([], $after->paletteMru());
    }

    public function testClickIsIgnoredWhenClicksAreDisabled(): void
    {
        [$chat, $zone] = $this->renderAndLocate($this->openPalette(), 'Switch theme');

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertSame('root', $chat->palette()?->mode);
    }

    public function testRightButtonOnAPaletteRowIsIgnored(): void
    {
        [$chat, $zone] = $this->renderAndLocate($this->openPalette(), 'Switch theme');

        [$chat] = $chat->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Right, MouseAction::Press));
        [$chat, $cmd] = $chat->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Right, MouseAction::Release));

        self::assertNull($cmd);
        self::assertSame('root', $chat->palette()?->mode);
    }

    // =========================================================================
    // The click must dispatch whatever Enter dispatches — in EITHER state
    // =========================================================================

    /**
     * §8 E6's rule is "the click dispatches what Enter dispatches", and Enter's
     * arm BRANCHED on `inFlight` after this click path was written:
     * {@see Chat::handlePaletteKey()} sends Enter to
     * `runSelectedPaletteActionWhileInFlight()` mid-turn, while
     * {@see Chat::selectPaletteItem()} called `runSelectedPaletteAction()`
     * unconditionally. So mid-turn a click ran what Enter refused — measured
     * over the root palette, the two devices disagreed on 8 of its 9 rows, and
     * the one that agreed was `Exit`, the row the mid-turn arm also allows.
     * `New session` wiped the history a streaming reply was appending to;
     * `Switch model` opened the providers submenu, the one that swaps the
     * backend the running agentic loop is about to call next.
     *
     * Driven ROW BY ROW rather than on a chosen example, because the defect
     * was not in any one row: it was one device consulting `inFlight` and the
     * other not. A row added later is covered without an edit, and the two
     * arms cannot drift apart again without reddening this.
     *
     * DRIVEN IN BOTH STATES, and that is a correction rather than a flourish.
     * The revision that added this test drove `inFlight: true` only, while
     * {@see Chat::selectPaletteItem()}'s docblock said it drove "both states";
     * idle parity did hold when it was measured, but a property nothing reads
     * back is a property the next edit is free to break. Idle is also the half
     * that proves the mid-turn half is about `inFlight` and not about the
     * mouse: with the state parameterised, a guard that refused every click
     * would red the idle rows instead of passing them.
     *
     * This file had 17 other tests when the mid-turn half was written, and
     * none of them mentioned `inFlight`.
     *
     * @param bool $inFlight the state the whole row sweep is driven in
     *
     * @dataProvider turnStates
     */
    public function testEveryPaletteRowAnswersAClickExactlyAsItAnswersEnter(bool $inFlight): void
    {
        $chat = new Chat(palette: PaletteState::root(), inFlight: $inFlight);
        $rows = $chat->paletteMatches();
        self::assertGreaterThan(1, count($rows), 'fixture: the root palette must offer several rows');

        $refusals = 0;
        foreach ($rows as $index => $label) {
            Renderer::scanner()->clear();
            $this->resetClickTracker();
            Renderer::render($chat);

            $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . $index);
            self::assertInstanceOf(Zone::class, $zone, "row {$index} ('{$label}') is not clickable");

            [$pressed] = $chat->update($this->press($zone->startCol, $zone->startRow));
            [$clicked, $clickCmd] = $pressed->update($this->release($zone->startCol, $zone->startRow));

            // The keyboard half, through the same front door: arrow down onto
            // the row, then Enter.
            $typed = $chat;
            for ($step = 0; $step < $index; $step++) {
                [$typed] = $typed->update(new KeyMsg(KeyType::Down));
            }
            self::assertSame($index, $typed->palette()?->selectedIndex, "the arrows reached row {$index}");
            [$entered, $enterCmd] = $typed->update(new KeyMsg(KeyType::Enter));

            self::assertSame(
                $this->answer($entered, $enterCmd),
                $this->answer($clicked, $clickCmd),
                "'{$label}' answers the mouse differently from the keyboard while a turn is in flight",
            );

            if (count($clicked->history) > count($chat->history)) {
                $written = $clicked->history[count($clicked->history) - 1]->content;
                if (str_contains($written, 'in flight')) {
                    $refusals++;
                } else {
                    self::assertFalse(
                        $inFlight,
                        "'{$label}' wrote a line mid-turn that does not say why it was refused: {$written}",
                    );
                }
            }
        }

        if ($inFlight) {
            self::assertGreaterThan(
                0,
                $refusals,
                'fixture: at least one root row must be refused mid-turn, or this proves nothing',
            );
        } else {
            // Idle rows may still write — 'New session' says the store is not
            // configured on this bare fixture — but none of them may write the
            // MID-TURN refusal, which would be this defect pointing the other
            // way: a click refusing on a turn that is not running.
            self::assertSame(
                0,
                $refusals,
                'idle, no root row may write an in-flight refusal',
            );
        }
    }

    /**
     * The two states {@see Chat::selectPaletteItem()} branches on, which is
     * the whole of what the sweep above is parameterised over.
     *
     * @return array<string, array{0: bool}>
     */
    public static function turnStates(): array
    {
        return ['idle' => [false], 'mid-turn' => [true]];
    }

    /**
     * Everything that separates one palette answer from another, as one
     * comparable value: what the overlay did, what was written, and whether a
     * `Cmd` came back (`Exit` is the row that hands one back in either state).
     *
     * @return array<string, mixed>
     */
    private function answer(Chat $chat, ?\Closure $cmd): array
    {
        return [
            'palette' => $chat->palette()?->mode,
            'selected' => $chat->palette()?->selectedIndex,
            'historyCount' => count($chat->history),
            'lastLine' => $chat->history === []
                ? null
                : $chat->history[count($chat->history) - 1]->content,
            'mru' => $chat->paletteMru(),
            'cmd' => $cmd !== null,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** An open root palette, optionally with $query already typed into it. */
    private function openPalette(string $query = ''): Chat
    {
        $chat = new Chat(palette: PaletteState::root());
        foreach (str_split($query) as $ch) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $chat;
    }

    /**
     * Render $chat (filling the zone registry the way a live frame would) and
     * return it with the zone of the row labelled $label.
     *
     * @return array{0: Chat, 1: Zone}
     */
    private function renderAndLocate(Chat $chat, string $label): array
    {
        Renderer::render($chat);

        $index = array_search($label, $chat->paletteMatches(), true);
        self::assertIsInt($index, "the palette does not offer a '{$label}' row");

        $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . $index);
        self::assertInstanceOf(Zone::class, $zone);

        return [$chat, $zone];
    }

    private function press(int $col, int $row): MouseClickMsg
    {
        return new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press);
    }

    private function release(int $col, int $row): MouseReleaseMsg
    {
        return new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release);
    }

    private function stripSgr(string $line): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }
}
