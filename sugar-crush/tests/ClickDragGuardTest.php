<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Support\SystemClipboard;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mouse\Zone;

/**
 * crush_feat.md section 8 E8 — drag-vs-click disambiguation, so a text
 * selection swept across a clickable row copies text instead of firing the
 * control underneath it.
 *
 * The failure these cover is invisible to {@see \SugarCraft\Mouse\ZoneClickTracker}
 * on its own: a tool-call row is ONE zone spanning the whole width, so a
 * selection drag starts and ends inside it and the tracker reports a clean
 * click. Behaviour style throughout — drive `update()`, assert on the
 * returned `[Model, ?Cmd]`.
 *
 * @see Chat::update()
 */
final class ClickDragGuardTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        // CL-3 (d712ae106) made transcript rows selectable, so the sweeps
        // below now release fa8e3a437's drag-to-copy batch — whose second op
        // spawns a real clipboard tool. Pin candidate discovery to nothing
        // so invoking the batch for the shape asserts stays a pure write
        // census (the OSC 52 relay is unaffected: it is built, not spawned).
        SystemClipboard::useCandidatesForTesting([]);
        $this->resetClickState();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        SystemClipboard::useCandidatesForTesting(null);
        $this->resetClickState();
    }

    public function testDragAcrossAToolRowSelectsTextInsteadOfExpandingIt(): void
    {
        [$chat, $zone] = $this->chatWithToolZone();
        self::assertGreaterThan(4, $zone->width(), 'the row must be wide enough to sweep across');

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->endCol, $zone->startRow));

        // HONEST OUTCOME (re-pinned after CL-3, d712ae106): the inner transcript
        // frame went and its rows became selectable, so a sweep across this row
        // extracts a selection and the release hands back fa8e3a437's
        // drag-to-copy batch — a selection that COPIES, which is exactly what
        // the gesture always meant. The guard's point stands below: the row
        // under the sweep is not expanded.
        self::assertClipboardBatch($cmd, 'a sweep across now-selectable transcript text releases the copy batch');
        self::assertFalse(
            $chat->isToolOutputExpanded('call_1'),
            'a press-and-sweep inside one wide zone is a selection, not a click',
        );
    }

    public function testOneCellOfJitterBetweenPressAndReleaseStillClicks(): void
    {
        [$chat, $zone] = $this->chatWithToolZone();

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol + 1, $zone->startRow));

        self::assertNull($cmd);
        self::assertTrue($chat->isToolOutputExpanded('call_1'));
    }

    public function testDragOutAndBackToThePressCellIsStillASelection(): void
    {
        // The press→release delta is zero here; only the motion the terminal
        // reported in between says this was a sweep.
        [$chat, $zone] = $this->chatWithToolZone();

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->motion($zone->endCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        // Re-pinned with CL-3 (d712ae106): the drift recorded by the motion is
        // a sweep across now-selectable text, so the release copies — the E8
        // guard's job is that it does NOT also expand, asserted below.
        self::assertClipboardBatch($cmd, 'a dragged-out-and-back sweep still selects, and selecting copies');
        self::assertFalse($chat->isToolOutputExpanded('call_1'));
    }

    public function testMotionWithoutAPendingPressDoesNotAffectTheNextClick(): void
    {
        [$chat, $zone] = $this->chatWithToolZone();

        [$chat] = $chat->update($this->motion($zone->endCol, $zone->startRow));
        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertTrue($chat->isToolOutputExpanded('call_1'));
    }

    public function testDriftFromAnAbandonedPressDoesNotSuppressTheNextClick(): void
    {
        // A press that wandered off the zone is rejected by the tracker, but
        // its drift must not survive into the click that follows.
        [$chat, $zone] = $this->chatWithToolZone();

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->release($zone->startCol, $zone->startRow + 40));

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        self::assertNull($cmd);
        self::assertTrue($chat->isToolOutputExpanded('call_1'));
    }

    public function testAVerticalDragDownARowIsAlsoASelection(): void
    {
        // Zones are single-row here, so a vertical sweep already leaves the
        // zone; assert it explicitly anyway - the guard measures rows and
        // columns alike, and multi-row zones are one Renderer change away.
        [$chat, $zone] = $this->chatWithToolZone();

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat] = $chat->update($this->motion($zone->startCol, $zone->startRow + 3));
        [$chat, $cmd] = $chat->update($this->release($zone->startCol, $zone->startRow));

        // Re-pinned with CL-3 (d712ae106): the vertical head-clamp lands the
        // selection on text the sweep covers, so a now-selectable transcript
        // copies on release even for a row-straddling drag — while still
        // refusing to fire the zone's click, asserted below.
        self::assertClipboardBatch($cmd, 'a vertical drag over selectable text selects, and selecting copies');
        self::assertFalse($chat->isToolOutputExpanded('call_1'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A Chat whose last frame registered one full-width `toolcall:call_1`
     * zone, plus that zone.
     *
     * @return array{0:Chat,1:Zone}
     */
    private function chatWithToolZone(): array
    {
        $chat = new Chat(history: [
            Message::assistant('')->withToolResults([ToolResult::ok('grep', "alpha\nbeta", 'call_1')]),
        ]);
        Renderer::render($chat);

        $zone = Renderer::scanner()->get('toolcall:call_1');
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

    private function motion(int $col, int $row): MouseMotionMsg
    {
        return new MouseMotionMsg($col, $row, MouseButton::Left, MouseAction::Motion);
    }

    private function resetClickState(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
        (new \ReflectionProperty(Chat::class, 'pressGesture'))->setValue(null, null);
        // CL-3 made these sweeps real selections; the static selection must
        // not ride from one test into the next.
        Chat::clearTextSelection();
    }

    /**
     * The minimal honest shape of a release whose sweep landed on
     * now-selectable transcript text (post-CL-3, d712ae106): the Cmd is
     * fa8e3a437's copy batch — a closure yielding a BatchMsg that carries
     * exactly one terminal write, the OSC 52 set-clipboard op. The swept
     * text is deliberately NOT pinned: the row's wording is the Renderer's
     * business and this file guards the gesture, not the glyphs. Invoking
     * the batch spawns no real clipboard tool — setUp() pins candidate
     * discovery to nothing, leaving only the (built, not spawned) relay.
     */
    private static function assertClipboardBatch(?\Closure $cmd, string $why): void
    {
        self::assertNotNull($cmd, $why);

        $msg = $cmd();
        self::assertInstanceOf(BatchMsg::class, $msg, 'a copy release hands back a batch, not a bare cmd');

        $writes = [];
        foreach ($msg->cmds as $op) {
            $inner = $op instanceof \Closure ? $op() : $op;
            if ($inner instanceof RawMsg) {
                $writes[] = $inner->bytes;
            }
        }

        self::assertCount(1, $writes, 'exactly one terminal write rides the batch');
        self::assertStringStartsWith(Ansi::OSC . '52;', $writes[0], 'and it is the OSC 52 set-clipboard');
    }
}
