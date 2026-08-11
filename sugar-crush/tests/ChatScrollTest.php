<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;

/**
 * crush_feat.md section 8 E4 — scrollwheel in the chat transcript.
 *
 * Chat-side assertions drive `update()` and assert on the returned
 * `[Model, ?Cmd]`; Renderer-side assertions are snapshots of the frame the
 * offset produces (which window of the transcript is painted, and the
 * status-bar readout of where that window sits).
 *
 * Every Chat here is built with explicit rows/cols so the clip height is
 * the test's, not the terminal's.
 *
 * @see Chat::scrollOffset()
 * @see Renderer::maxScrollOffset()
 */
final class ChatScrollTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    /** Terminal height every fixture renders against. */
    private const ROWS = 14;

    /**
     * Terminal width every fixture renders against — the canonical 80, which
     * is also the width the status bar's scroll readout has to fit inside.
     */
    private const COLS = 80;

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
    }

    /**
     * A transcript guaranteed to overflow {@see ROWS}: one line per message,
     * numbered so a window can be identified by the text in it.
     */
    private function tallChat(int $messages = 40): Chat
    {
        $history = [];
        for ($i = 1; $i <= $messages; $i++) {
            $history[] = new Message(Role::User, "line {$i}", 0);
        }

        return new Chat(history: $history, rows: self::ROWS, cols: self::COLS);
    }

    private function wheel(MouseButton $button): MouseWheelMsg
    {
        return new MouseWheelMsg(1, 1, $button, MouseAction::Motion);
    }

    // =========================================================================
    // Chat: the wheel moves the offset, clamped at both ends
    // =========================================================================

    public function testWheelUpScrollsBackAndWheelDownReturnsToTheBottom(): void
    {
        $chat = $this->tallChat();
        // The offset is clamped against the frame on screen, so there has to
        // be one - exactly as in the live loop, where a wheel event can only
        // follow a painted frame.
        Renderer::render($chat);

        [$scrolled, $cmd] = $chat->update($this->wheel(MouseButton::WheelUp));

        self::assertInstanceOf(Chat::class, $scrolled);
        self::assertNull($cmd);
        self::assertSame(3, $scrolled->scrollOffset());
        self::assertSame(0, $chat->scrollOffset(), 'update() must not mutate the receiver');

        [$back] = $scrolled->update($this->wheel(MouseButton::WheelDown));

        self::assertInstanceOf(Chat::class, $back);
        self::assertSame(0, $back->scrollOffset());
    }

    public function testWheelDownAtTheBottomIsANoOp(): void
    {
        $chat = $this->tallChat();
        Renderer::render($chat);

        [$same, $cmd] = $chat->update($this->wheel(MouseButton::WheelDown));

        self::assertSame($chat, $same);
        self::assertNull($cmd);
    }

    public function testWheelUpCannotScrollPastTheOldestLine(): void
    {
        $chat = $this->tallChat();
        Renderer::render($chat);
        $max = Renderer::maxScrollOffset();

        self::assertGreaterThan(0, $max);

        // Far more notches than the transcript has lines to give.
        for ($i = 0; $i < 200; $i++) {
            [$chat] = $chat->update($this->wheel(MouseButton::WheelUp));
            self::assertInstanceOf(Chat::class, $chat);
        }

        self::assertSame($max, $chat->scrollOffset());
    }

    public function testWheelDoesNothingWhenTheTranscriptFitsTheWindow(): void
    {
        $chat = new Chat(history: [new Message(Role::User, 'hi', 0)], rows: 40, cols: 60);
        Renderer::render($chat);

        self::assertSame(0, Renderer::maxScrollOffset());

        [$same] = $chat->update($this->wheel(MouseButton::WheelUp));

        self::assertSame($same, $chat);
    }

    public function testWheelStillScrollsWhileClicksAreDisabled(): void
    {
        // The whole point of SUGARCRUSH_DISABLE_MOUSE_CLICKS (crush_feat.md
        // section 8 B) is "keep wheel scroll, drop clicks" - routing the
        // wheel through the click hit-test would take scrolling down with it.
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        $chat = $this->tallChat();
        Renderer::render($chat);

        [$scrolled] = $chat->update($this->wheel(MouseButton::WheelUp));

        self::assertInstanceOf(Chat::class, $scrolled);
        self::assertSame(3, $scrolled->scrollOffset());
    }

    public function testAWheelMessageCarryingANonWheelButtonIsIgnored(): void
    {
        $chat = $this->tallChat();
        Renderer::render($chat);

        [$same] = $chat->update($this->wheel(MouseButton::Left));

        self::assertSame($chat, $same);
    }

    // =========================================================================
    // Chat: withScrollOffset()
    // =========================================================================

    public function testWithScrollOffsetClampsNegativesToTheBottom(): void
    {
        $chat = $this->tallChat();

        self::assertSame(0, $chat->withScrollOffset(-10)->scrollOffset());
        self::assertSame(7, $chat->withScrollOffset(7)->scrollOffset());
    }

    public function testWithScrollOffsetIsImmutableAndPreservesOtherState(): void
    {
        $chat = new Chat(history: [new Message(Role::User, 'x', 0)], inputBuf: 'draft', rows: 8, cols: 40);

        $scrolled = $chat->withScrollOffset(4);

        self::assertNotSame($chat, $scrolled);
        self::assertSame(0, $chat->scrollOffset());
        self::assertSame('draft', $scrolled->inputBuf, 'mutate() must thread the new field, not drop the old ones');
        self::assertSame($chat->history, $scrolled->history);
        // The offset survives an unrelated mutate() - i.e. it is a real
        // constructorProps entry, not a value only the setter can produce.
        self::assertSame(4, $scrolled->withStreaming(true)->scrollOffset());
    }

    // =========================================================================
    // Renderer: the offset picks the window, and says so
    // =========================================================================

    public function testScrollingBackPaintsTheEarlierWindowWithoutChangingFrameHeight(): void
    {
        $chat = $this->tallChat();
        $bottom = explode("\n", Renderer::render($chat));
        $scrolled = explode("\n", Renderer::render($chat->withScrollOffset(3)));

        self::assertCount(count($bottom), $scrolled, 'the frame must stay exactly rows tall');
        self::assertSame(self::ROWS, count($scrolled));

        // Content region only - the status bar (last line) carries the
        // scroll readout and is asserted separately. Scrolling back by 3
        // shifts the same lines down by 3.
        $content = self::ROWS - 1;
        for ($i = 3; $i < $content; $i++) {
            self::assertSame($bottom[$i - 3], $scrolled[$i], "content line {$i} must be the unscrolled line " . ($i - 3));
        }

        // And it really is a different view: the old renderer always sliced
        // the tail, so these two frames were byte-identical.
        self::assertNotSame($bottom, $scrolled);
    }

    public function testAnOffsetPastTheTopIsReClampedByTheFrameItIsDrawnAgainst(): void
    {
        $chat = $this->tallChat();
        Renderer::render($chat);
        $max = Renderer::maxScrollOffset();

        $atTop = explode("\n", Renderer::render($chat->withScrollOffset($max)));
        $wayPastTop = explode("\n", Renderer::render($chat->withScrollOffset($max + 500)));

        self::assertCount(self::ROWS, $wayPastTop);
        self::assertSame($atTop, $wayPastTop);
    }

    public function testTheStatusBarReportsTheScrollPositionOnlyWhileScrolled(): void
    {
        $chat = $this->tallChat();

        $bottom = explode("\n", Renderer::render($chat));
        self::assertStringNotContainsString('scrolled', end($bottom));

        $scrolled = explode("\n", Renderer::render($chat->withScrollOffset(3)));
        $max = Renderer::maxScrollOffset();
        self::assertStringContainsString("↑ 3/{$max} scrolled", end($scrolled));
    }

    public function testAScrolledFrameNeverOverflowsTheTerminalWidth(): void
    {
        // 400 messages give a 3-digit offset against a 3-digit max - the
        // widest the readout ever gets, and the case where prepending it
        // whole pushed the 62-column status bar to 83 columns, wrapping it
        // onto a second physical row and breaking the frame's
        // one-logical-line-per-row invariant.
        $chat = $this->tallChat(400);
        Renderer::render($chat);
        $lines = explode("\n", Renderer::render($chat->withScrollOffset(123)));

        foreach ($lines as $i => $line) {
            self::assertLessThanOrEqual(self::COLS, Width::of($line), "frame line {$i} must fit the terminal");
        }

        // Narrowed rather than dropped: the readout is the only clue that the
        // newest output is off-screen.
        self::assertStringContainsString('↑123', end($lines));
    }

    public function testMaxScrollOffsetTracksTheLastRenderedFrame(): void
    {
        Renderer::render($this->tallChat());
        $tall = Renderer::maxScrollOffset();

        self::assertGreaterThan(0, $tall);

        Renderer::render(new Chat(history: [new Message(Role::User, 'hi', 0)], rows: 40, cols: 60));

        self::assertSame(0, Renderer::maxScrollOffset());
    }
}
