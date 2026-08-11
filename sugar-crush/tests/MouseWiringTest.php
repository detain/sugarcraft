<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Core\MouseMode;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mouse\Mark;
use PHPUnit\Framework\TestCase;

/**
 * crush_feat.md section 8 E1 — mouse mode is enabled at all, both documented
 * escape hatches actually disable what they claim, and the root frame is
 * zone-scanned exactly once.
 *
 * @see Chat::mouseMode()
 * @see Renderer::scanRoot()
 */
final class MouseWiringTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

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

    public function testMouseModeDefaultsToCellMotion(): void
    {
        // Regression guard for the section 8 D finding: ProgramOptions
        // defaulted to MouseMode::Off, so the terminal was never told to
        // report mouse events at all.
        self::assertSame(MouseMode::CellMotion, Chat::mouseMode());
    }

    public function testProgramOptionsCarryMouseModeAndAltScreen(): void
    {
        $options = Chat::programOptions();

        self::assertSame(MouseMode::CellMotion, $options->mouseMode);
        self::assertTrue($options->useAltScreen);
    }

    public function testDisableMouseTurnsTrackingOff(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE=1');

        self::assertSame(MouseMode::Off, Chat::mouseMode());
        self::assertSame(MouseMode::Off, Chat::programOptions()->mouseMode);
        self::assertFalse(Chat::mouseClicksEnabled());
    }

    public function testDisableMouseClicksKeepsTrackingOnForWheelScroll(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');

        // Wheel events are reported over the same tracking mode as clicks,
        // so "scroll still works" means the mode must stay on.
        self::assertSame(MouseMode::CellMotion, Chat::mouseMode());
        self::assertFalse(Chat::mouseClicksEnabled());
    }

    public function testZeroValuedFlagLeavesMouseEnabled(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE=0');
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=0');

        self::assertSame(MouseMode::CellMotion, Chat::mouseMode());
        self::assertTrue(Chat::mouseClicksEnabled());
    }

    public function testScanRootRegistersZonesAndStripsSentinels(): void
    {
        $frame = 'x' . Mark::zone('tab:alpha', 'hello') . 'y';

        $painted = Renderer::scanRoot($frame, 80);

        self::assertSame('xhelloy', $painted);
        self::assertNotNull(Renderer::scanner()->get('tab:alpha'));
    }

    public function testScanRootStripsSentinelsEvenWithMouseDisabled(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE=1');

        $painted = Renderer::scanRoot(Mark::zone('tab:alpha', 'hello'), 80);

        // Markers are Private-Use codepoints; a terminal would paint them as
        // replacement glyphs, so stripping is never conditional.
        self::assertSame('hello', $painted);
        // ...but with tracking off there is nothing to hit-test against.
        self::assertNull(Renderer::scanner()->get('tab:alpha'));
    }

    public function testZoneAtHitTestsTheLastScannedFrame(): void
    {
        Renderer::scanRoot(Mark::zone('tab:alpha', 'hello'), 80);

        $zone = Chat::zoneAt(1, 1);

        self::assertNotNull($zone);
        self::assertSame('tab:alpha', $zone->id);
    }

    public function testZoneAtReturnsNullWhenClicksAreDisabled(): void
    {
        Renderer::scanRoot(Mark::zone('tab:alpha', 'hello'), 80);
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');

        // The zone is still registered (wheel scroll needs the frame scanned);
        // the click hit-test is what the hatch suppresses.
        self::assertNotNull(Renderer::scanner()->get('tab:alpha'));
        self::assertNull(Chat::zoneAt(1, 1));
    }

    public function testRenderedFrameCarriesNoZoneSentinels(): void
    {
        $chat = new Chat();

        $frame = $chat->view();

        self::assertStringNotContainsString("\xEE\x80\x80", $frame);
        self::assertStringNotContainsString("\xEE\x80\x81", $frame);
    }

    /**
     * Injected zone markup must not reach the scanner. U+E000/U+E001 are
     * valid 3-byte UTF-8 Private-Use codepoints, so `Sanitize::untrusted()`
     * alone leaves them intact — and duplicate ids make `Scan::parse()`
     * throw, which used to take `Chat::view()` down with it.
     *
     * @dataProvider injectedSentinelMessages
     */
    public function testInjectedZoneMarkupNeitherCrashesNorRegistersZones(Message $message): void
    {
        $frame = (new Chat([$message]))->view();

        self::assertStringNotContainsString("\xEE\x80\x80", $frame);
        self::assertStringNotContainsString("\xEE\x80\x81", $frame);
        self::assertSame([], Renderer::scanner()->all());
    }

    /**
     * @return iterable<string, array{Message}>
     */
    public static function injectedSentinelMessages(): iterable
    {
        // A duplicate id is the crashing case: Scan::parse() throws
        // InvalidArgumentException on the second 'a'.
        $duplicate = "\u{E000}a\u{E001}hi\u{E000}/a\u{E001} and again \u{E000}a\u{E001}yo\u{E000}/a\u{E001}";

        yield 'assistant markdown' => [Message::assistant($duplicate)];
        yield 'user turn' => [Message::user($duplicate)];
        yield 'tool result body' => [
            Message::assistant('')->withToolResults([
                ToolResult::error('bash', $duplicate, 'call-1'),
            ]),
        ];
    }

    /**
     * The tool NAME is as model-controlled as the body — an "Unknown tool"
     * error echoes back whatever the provider parser produced — so escape
     * sequences smuggled through it must not reach the terminal either.
     */
    public function testInjectedEscapesInToolNameNeverReachTheFrame(): void
    {
        $name = "bash\x1b[2J\x1b]0;pwned\x07\u{E000}a\u{E001}";
        $message = Message::assistant('')->withToolResults([
            ToolResult::error($name, 'Unknown tool', 'call-1'),
        ]);

        $frame = (new Chat([$message]))->view();

        self::assertStringNotContainsString("\x1b[2J", $frame);
        self::assertStringNotContainsString("\x1b]0;pwned\x07", $frame);
        self::assertStringNotContainsString("\xEE\x80\x80", $frame);
        self::assertStringNotContainsString("\xEE\x80\x81", $frame);
    }

    /**
     * A frame with no markers must skip the parse entirely — it is ~24ms of
     * grapheme walking per keystroke, and until W2.S11b marks a widget every
     * real frame is marker-free.
     */
    public function testMarkerFreeFrameIsNotScanned(): void
    {
        Renderer::scanRoot(Mark::zone('tab:alpha', 'hello'), 80);
        self::assertNotNull(Renderer::scanner()->get('tab:alpha'));

        $painted = Renderer::scanRoot('plain frame', 80);

        self::assertSame('plain frame', $painted);
        // Cleared rather than left stale: nothing on this frame is clickable.
        self::assertSame([], Renderer::scanner()->all());
    }

    /**
     * Malformed markup from our OWN Mark calls degrades to "no zones this
     * frame" instead of throwing out of Chat::view().
     */
    public function testMalformedZoneMarkupDoesNotEscapeScanRoot(): void
    {
        $frame = Mark::zone('dup', 'one') . Mark::zone('dup', 'two');

        $painted = Renderer::scanRoot($frame, 80);

        self::assertSame('onetwo', $painted);
        self::assertSame([], Renderer::scanner()->all());
    }
}
