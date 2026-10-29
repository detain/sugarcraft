<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Palette\PaletteState;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Theme;
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
    use HomeSandboxTrait;

    private const VARS = ['CANDY_CODE_DISABLE_MOUSE', 'CANDY_CODE_DISABLE_MOUSE_CLICKS'];

    /**
     * C-EXT-5.b seam: the palette box paints at a fixed `width(50)`, so a
     * label wider than its interior is clipped BEFORE the frame composites —
     * and `Renderer::recordPaletteItemZones()` claims box lines by a row's
     * FULL content, which a clipped line can never contain. Such a row
     * therefore carries no click zone; only its keyboard half exists. The
     * roster is the four root-list rows whose `/<name> <argument-hint>`
     * labels (PaletteCatalog, C-EXT-5.a) exceed the box width — the first
     * labels in the palette's history long enough to trip it, because the
     * action half is all short sentences. The census arm in
     * `testEveryPaletteRowIsRegisteredAsAPickerItemZone()` re-derives this
     * list from the painted frame, so a fifth wide label reddens there
     * instead of silently dodging the sweep. The renderer-side cure (clip
     * the stored row to the painted line, or budget the label) lives in
     * Renderer.php, outside this lane's design-block files — reported as a
     * seam in the lane's REPORT.md.
     */
    private const WIDE_UNCLICKABLE_ROWS = [
        '/schedules [list|add <name> <interval> <prompt>|enable|disable|delete <name>]',
        '/websearch <query> [--safesearch 0|1|2] [--time-range day|month|year]',
        '/generate <prompt> [--steps N] [--size WxH] [--seed N] [--save]',
        '/sendto <file.png> [--to img2img|inpaint] [--mask FILE]',
    ];

    /**
     * ISS-125: this sweep drives the `Share session` row through the Enter and
     * click arms of `Chat::selectPaletteItem()`, and that arm runs
     * `new ShareCommand()` with no `$exportsDir` — a default export resolves
     * through `HomeDirectory::owned()` and WRITES. Without a per-test HOME the
     * residue landed in the developer's real `~/.candy-code/exports`: two
     * zero-byte transcripts per idle run, 174 of them over twelve days when
     * the defect was filed. `tests/bootstrap.php` now sandboxes HOME for the
     * whole process, but the sweep owns the write, so it quarantines it
     * itself: the trait's snapshot-and-restore keeps every other test's view
     * of home untouched, and `removeTree()` means not even the suite sandbox
     * accumulates this file's litter.
     */
    private string $sandbox = '';

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        $this->sandbox = sys_get_temp_dir() . '/crush-paletteclick-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        Renderer::scanner()->clear();
        $this->resetClickTracker();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
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

        // C-EXT-5.b census: the wide-row roster is exactly the set of labels
        // that paint clipped out of the box. If a label changes painted
        // width — either direction — this reddens before the sweep below
        // could quietly skip or quietly lose a row.
        self::assertSame(
            self::WIDE_UNCLICKABLE_ROWS,
            $this->wideUnclickableLabels($chat),
            'the wide-row roster drifted: a palette label changed its painted width',
        );

        foreach (array_keys($chat->paletteMatches()) as $index) {
            $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . $index);

            if (in_array($chat->paletteMatches()[$index], self::WIDE_UNCLICKABLE_ROWS, true)) {
                // C-EXT-5.b seam (see WIDE_UNCLICKABLE_ROWS): a row painted
                // past the box's interior must own NO zone it cannot answer.
                self::assertNull($zone, 'a row painted clipped out of the box claims a click zone anyway');

                continue;
            }

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
            if (in_array($label, self::WIDE_UNCLICKABLE_ROWS, true)) {
                // C-EXT-5.b seam: no zone exists to locate (censused where
                // it is registered — see WIDE_UNCLICKABLE_ROWS).
                continue;
            }

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
            if (in_array($chat->paletteMatches()[$index], self::WIDE_UNCLICKABLE_ROWS, true)) {
                // C-EXT-5.b seam: a clipped row owns no zone (censused
                // where it is registered), so there is no marked line to
                // measure for raggedness.
                continue;
            }

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
        putenv('CANDY_CODE_DISABLE_MOUSE_CLICKS=1');

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
            if (in_array($chat->paletteMatches()[$index], self::WIDE_UNCLICKABLE_ROWS, true)) {
                continue; // C-EXT-5.b seam: no zone to collect (see WIDE_UNCLICKABLE_ROWS).
            }

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

        putenv('CANDY_CODE_DISABLE_MOUSE_CLICKS=1');
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
        // C-EXT-5.b: the prefill rows grew the list again past the old 80-row
        // viewport — same honest height adaptation as openPalette() below, so
        // every listed row is really PAINTED before its click is driven.
        $chat = (new Chat(palette: PaletteState::root(), inFlight: $inFlight))->withSize(100, 140);
        $rows = $chat->paletteMatches();
        self::assertGreaterThan(1, count($rows), 'fixture: the root palette must offer several rows');

        $refusals = 0;
        foreach ($rows as $index => $label) {
            Renderer::scanner()->clear();
            $this->resetClickTracker();
            Renderer::render($chat);

            $zone = Renderer::scanner()->get(Renderer::PALETTE_ITEM_ZONE_PREFIX . $index);

            if (in_array($label, self::WIDE_UNCLICKABLE_ROWS, true)) {
                // C-EXT-5.b seam (see self::WIDE_UNCLICKABLE_ROWS): the row
                // paints clipped out of the fixed-width box, so no zone —
                // and no click — can exist for it. Pin the zone's absence,
                // then drive only the keyboard half and demand it still
                // answers (a draft write, a transcript write, or a closed
                // palette), counting the mid-turn refusal so the sweep's
                // refusal floor stays honest. The prefill behaviour itself
                // is pinned end-to-end in tests/Chat/PalettePrefillRowsTest.
                self::assertNull($zone, "row {$index} ('{$label}') paints clipped and claims a zone anyway");

                $typed = $chat;
                for ($step = 0; $step < $index; $step++) {
                    [$typed] = $typed->update(new KeyMsg(KeyType::Down));
                }
                self::assertSame($index, $typed->palette()?->selectedIndex, "the arrows reached row {$index}");
                [$entered] = $typed->update(new KeyMsg(KeyType::Enter));

                if ($inFlight && count($entered->history) > count($chat->history)
                    && str_contains($entered->history[count($entered->history) - 1]->content, 'in flight')) {
                    $refusals++;
                }

                self::assertTrue(
                    $entered->palette() === null
                    || $entered->inputBuf !== $chat->inputBuf
                    || count($entered->history) > count($chat->history),
                    "'{$label}' answered Enter on neither the draft, the transcript, nor the palette",
                );

                continue;
            }

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
                } elseif ($inFlight) {
                    // P-TABS.f widened the mid-turn arm: a row that stands
                    // because it touches no turn state may also write an
                    // honest answer instead of a refusal — Close tab on a
                    // deck-less Chat writes its "no tab deck" line, in both
                    // states (pinned in DeckHostedSessionRoutesTest). That is
                    // only legitimate when the answer does not depend on the
                    // turn state at all: drive the keyboard path on the idle
                    // twin and demand the byte-identical row back. A row that
                    // writes words only mid-turn, or a different line per
                    // state, still reddens here — the defect this leg was
                    // written against (an unexplained mid-turn write) is
                    // unexplained whether it arrives in one state or both.
                    $idle = (new Chat(palette: PaletteState::root(), inFlight: false))->withSize(100, 140);
                    for ($step = 0; $step < $index; $step++) {
                        [$idle] = $idle->update(new KeyMsg(KeyType::Down));
                    }
                    [$idleAnswered] = $idle->update(new KeyMsg(KeyType::Enter));
                    self::assertGreaterThan(
                        count($idle->history),
                        count($idleAnswered->history),
                        "'{$label}' wrote nothing at idle but '{$written}' mid-turn — a mid-turn write must be the row's state-independent answer, not a surprise",
                    );
                    self::assertSame(
                        $idleAnswered->history[count($idleAnswered->history) - 1]->content,
                        $written,
                        "'{$label}' wrote a different line mid-turn than it writes idle: {$written}",
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
            // The UTC second ShareCommand stamps an export's default file name
            // with is masked: the click and the Enter run a moment apart, and
            // across a second boundary the two names differ by that alone.
            'lastLine' => $chat->history === []
                ? null
                : preg_replace('/\d{8}T\d{6}Z/', '<stamp>', $chat->history[count($chat->history) - 1]->content),
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
        // VIS-4: the superset list is taller than the default frame, and the
        // Veil composite clips what does not fit — every row here must be
        // PAINTED for its zone pin to be a fair test of the zone registry.
        // C-EXT-5.b grew the list again with the `/<name>` prefill rows, so
        // the viewport grows with it.
        $chat = (new Chat(palette: PaletteState::root()))->withSize(100, 140);
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

    /**
     * The labels of the rows that paint CLIPPED out of the fixed-width
     * palette box and therefore own no click zone (C-EXT-5.b seam — see
     * {@see self::WIDE_UNCLICKABLE_ROWS} for the full story). Derived the
     * honest way: a row is wide exactly when its full label appears on no
     * line of the painted frame — which is precisely why the zone registry,
     * keyed on the same content, loses it too.
     *
     * Renders $chat once more; the zone registry is rebuilt on every render,
     * so calling this from a test that already rendered changes nothing.
     *
     * @return list<string>
     */
    private function wideUnclickableLabels(Chat $chat): array
    {
        $lines = array_map(
            fn (string $line): string => $this->stripSgr($line),
            explode("\n", Renderer::render($chat)),
        );

        $wide = [];
        foreach ($chat->paletteMatches() as $label) {
            $painted = false;
            foreach ($lines as $line) {
                if (str_contains($line, $label)) {
                    $painted = true;

                    break;
                }
            }
            if (!$painted) {
                $wide[] = $label;
            }
        }

        return $wide;
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
