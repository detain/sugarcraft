<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mouse\Zone;

/**
 * crush_feat.md §8 (mouse integration) read with §5 E7 — the hosted chat's
 * click zones have to be re-based onto the terminal by the chat pane's origin.
 *
 * Regression cover for a live bug the pane-shell migration introduced:
 * {@see LiveRenderer::scanRoot()} records every zone in the coordinate space
 * of the chat's OWN frame (origin 0,0), while a `MouseMsg` carries
 * terminal-absolute coordinates and {@see App::update()} hands it to `Chat`
 * unchanged. In the composed shell the chat pane is INSET — below the menu
 * bar, right of the sidebar, inside a bordered box — so before
 * {@see TuiRenderer::renderView()} declared the offset through
 * {@see LiveRenderer::setZoneOrigin()} a click on the visible tool row hit
 * nothing and a click on the sidebar wrongly expanded that tool.
 *
 * Zone boxes stay pane-local on purpose (the shell only knows the delta after
 * it has composed the frame), so the assertions below are written against the
 * one thing that has to line up: where the row actually appears in the
 * composed frame versus where {@see Chat::zoneAt()} resolves a click.
 *
 * @see TuiRenderer::renderView()
 * @see LiveRenderer::setZoneOrigin()
 * @see Chat::zoneAt()
 */
final class ShellMouseZoneTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    /** The label the transcript draws for the tool result every case below marks. */
    private const TOOL_LABEL = 'tool: bash';

    private const ZONE_ID = 'toolcall:call_1';

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARS as $var) {
            putenv($var);
        }

        TuiRenderer::resetSizeCache();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        $this->resetClickTracker();

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }

        TuiRenderer::resetSizeCache();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        $this->resetClickTracker();

        parent::tearDown();
    }

    // =========================================================================
    // Standalone control: the frame IS the terminal, so nothing is offset.
    // =========================================================================

    public function testAStandaloneChatRecordsItsZonesAtTheOriginOfTheTerminal(): void
    {
        $frame = LiveRenderer::render($this->chat());

        $zone = LiveRenderer::scanner()->get(self::ZONE_ID);
        [$col, $row] = $this->locate($frame, self::TOOL_LABEL);

        self::assertInstanceOf(Zone::class, $zone);
        self::assertSame([0, 0], LiveRenderer::zoneOrigin());
        self::assertSame($row, $zone->startRow);
        self::assertGreaterThanOrEqual($zone->startCol, $col);
        self::assertLessThanOrEqual($zone->endCol, $col);
    }

    // =========================================================================
    // Shell: the same registry, re-based onto the composed frame.
    // =========================================================================

    /**
     * The bug in one assertion: the shell's chat pane is inset, so the zone
     * origin has to be a real offset rather than the `[0, 0]` the scan resets
     * it to.
     */
    public function testComposingTheShellDeclaresANonZeroZoneOrigin(): void
    {
        TuiRenderer::renderView($this->app(), 100, 30);

        [$originCol, $originRow] = LiveRenderer::zoneOrigin();

        self::assertGreaterThan(0, $originCol, 'the chat pane starts right of the sidebar and its own border');
        self::assertGreaterThan(0, $originRow, 'the chat pane starts below the menu bar and its own border');
    }

    /**
     * The recorded box is pane-local; the row on screen is that box plus the
     * pane origin, exactly. This is the arithmetic {@see Chat::zoneAt()}
     * undoes, checked against the composed frame rather than against the
     * layout code that produced it.
     */
    public function testTheShellZoneDiffersFromWhereTheRowIsDrawnByExactlyThePaneOrigin(): void
    {
        $view = TuiRenderer::renderView($this->app(), 100, 30);

        $zone = LiveRenderer::scanner()->get(self::ZONE_ID);
        self::assertInstanceOf(Zone::class, $zone);

        [$col, $row] = $this->locate($view->body, self::TOOL_LABEL);
        [$originCol, $originRow] = LiveRenderer::zoneOrigin();

        self::assertSame($row - $zone->startRow, $originRow);
        self::assertGreaterThanOrEqual($zone->startCol + $originCol, $col);
        self::assertLessThanOrEqual($zone->endCol + $originCol, $col);
    }

    /**
     * The user-visible half: a click reported where the tool row is actually
     * painted must resolve to that tool's zone. Before the fix this returned
     * null (or a different row's zone) and the click did nothing.
     */
    public function testAClickWhereTheToolRowIsPaintedResolvesToThatToolsZone(): void
    {
        $view = TuiRenderer::renderView($this->app(), 100, 30);
        [$col, $row] = $this->locate($view->body, self::TOOL_LABEL);

        $zone = Chat::zoneAt($col, $row);

        self::assertInstanceOf(Zone::class, $zone);
        self::assertSame(self::ZONE_ID, $zone->id);
    }

    /**
     * The mirror-image half of the same bug: the pane-local coordinates land
     * on the menu bar / sidebar chrome once the shell composes the frame, so
     * a click there must NOT reach the tool row. Before the fix it did.
     */
    public function testAClickAtThePaneLocalCoordinatesNoLongerHitsTheToolRow(): void
    {
        TuiRenderer::renderView($this->app(), 100, 30);

        $zone = LiveRenderer::scanner()->get(self::ZONE_ID);
        self::assertInstanceOf(Zone::class, $zone);

        $hit = Chat::zoneAt($zone->startCol, $zone->startRow);

        self::assertNotSame(self::ZONE_ID, $hit?->id);
    }

    /**
     * End to end through the Model contract: press + release on the painted
     * row travel `App::update()` → `Chat::update()` and expand that call's
     * output, which is what §8 E5 promises the click does.
     */
    public function testPressAndReleaseOnThePaintedRowExpandTheHostedChatsToolOutput(): void
    {
        $app = $this->app();
        $view = TuiRenderer::renderView($app, 100, 30);
        [$col, $row] = $this->locate($view->body, self::TOOL_LABEL);

        [$app] = $app->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));
        [$app, $cmd] = $app->update(new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release));

        self::assertInstanceOf(App::class, $app);
        self::assertNull($cmd);
        self::assertNotNull($app->chat);
        self::assertTrue($app->chat->isToolOutputExpanded('call_1'));
    }

    /**
     * A frame the shell stops hosting must not inherit the shell's offset:
     * {@see LiveRenderer::scanRoot()} resets the origin on every scan, so the
     * standalone path is always `[0, 0]` no matter what ran before it.
     */
    public function testAStandaloneRenderAfterAShellRenderResetsTheOrigin(): void
    {
        TuiRenderer::renderView($this->app(), 100, 30);
        self::assertNotSame([0, 0], LiveRenderer::zoneOrigin());

        $frame = LiveRenderer::render($this->chat());

        self::assertSame([0, 0], LiveRenderer::zoneOrigin());
        [$col, $row] = $this->locate($frame, self::TOOL_LABEL);
        self::assertSame(self::ZONE_ID, Chat::zoneAt($col, $row)?->id);
    }

    /**
     * `SUGARCRUSH_DISABLE_MOUSE_CLICKS` is still honoured above the offset:
     * the escape hatch lives in {@see Chat::zoneAt()}, which returns before
     * it reads the origin at all.
     */
    public function testDisablingClicksSuppressesTheHitTestInTheShellToo(): void
    {
        $view = TuiRenderer::renderView($this->app(), 100, 30);
        [$col, $row] = $this->locate($view->body, self::TOOL_LABEL);

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');

        self::assertNull(Chat::zoneAt($col, $row));
    }

    /**
     * The image/zone invariant the offset must not disturb: candy-core's
     * image markers and candy-mouse's sentinels share the Private-Use block,
     * so {@see LiveRenderer::maskImageMarkers()} masks the scan copy. With a
     * picture on screen the placement still rides out of the pane AND the
     * re-based zone still resolves — placements need no fix-up because
     * `ImageOverlay::resolve()` reads the final composed frame.
     */
    public function testAnImageBearingToolResultKeepsBothItsPlacementAndItsRebasedZone(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('candy-mosaic decodes images through ext-gd');
        }

        $chat = new Chat(
            history: [
                Message::user('screenshot'),
                Message::assistant('')->withToolResults([
                    ToolResult::ok('bash', 'out', 'call_1'),
                    new ToolResult(name: 'shot', result: 'captured', id: 'call_2', imageBytes: $this->pngBytes()),
                ]),
            ],
            // Expanded: a picture collapses with its tool row now (§1 E5
            // applied to images), and a collapsed one is never encoded, so
            // only the expanded state has a placement to keep.
            expanded: ['call_2' => true],
            mosaic: Mosaic::sixel(),
        );

        $view = TuiRenderer::renderView($this->app($chat), 100, 40);

        self::assertNotSame([], $view->images, 'the pane must still hand the image layer up to the shell');

        [$col, $row] = $this->locate($view->body, self::TOOL_LABEL);
        self::assertSame(self::ZONE_ID, Chat::zoneAt($col, $row)?->id);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function chat(): Chat
    {
        return new Chat(history: [
            Message::user('hi'),
            Message::assistant('ok')->withToolResults([ToolResult::ok('bash', 'out', 'call_1')]),
        ]);
    }

    private function app(?Chat $chat = null): App
    {
        return App::new($this->provider, 'test-model')->withChat($chat ?? $this->chat());
    }

    /**
     * The display column and row of `$needle` in a rendered frame, 1-based —
     * the coordinate space both an SGR mouse report and {@see Zone} use. SGR
     * runs and wide/box-drawing glyphs before the match are measured rather
     * than counted as bytes, so the column is a real terminal cell.
     *
     * @return array{0: int, 1: int}
     */
    private function locate(string $frame, string $needle): array
    {
        foreach (explode("\n", $frame) as $index => $line) {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            $at = mb_strpos($plain, $needle);
            if ($at === false) {
                continue;
            }

            return [Width::string(mb_substr($plain, 0, $at)) + 1, $index + 1];
        }

        self::fail("frame does not contain '{$needle}'");
    }

    /** A real, decodable 20x10 PNG — candy-mosaic rejects anything it cannot decode. */
    private function pngBytes(): string
    {
        $gd = imagecreatetruecolor(20, 10);
        imagefilledrectangle($gd, 0, 0, 19, 9, (int) imagecolorallocate($gd, 200, 30, 30));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    private function resetClickTracker(): void
    {
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }
}
