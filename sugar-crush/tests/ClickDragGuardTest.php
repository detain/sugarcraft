<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
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
        $this->resetClickState();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
        $this->resetClickState();
    }

    public function testDragAcrossAToolRowSelectsTextInsteadOfExpandingIt(): void
    {
        [$chat, $zone] = $this->chatWithToolZone();
        self::assertGreaterThan(4, $zone->width(), 'the row must be wide enough to sweep across');

        [$chat] = $chat->update($this->press($zone->startCol, $zone->startRow));
        [$chat, $cmd] = $chat->update($this->release($zone->endCol, $zone->startRow));

        self::assertNull($cmd);
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

        self::assertNull($cmd);
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

        self::assertNull($cmd);
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
    }
}
