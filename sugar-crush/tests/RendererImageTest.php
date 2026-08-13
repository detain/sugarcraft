<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\View;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolResult;

/**
 * crush_feat.md §9 E3 — image-bearing tool results reach the terminal through
 * candy-mosaic + candy-core's ImageOverlay compositor.
 *
 * Every assertion here fails against the pre-E3 renderer, which had no
 * {@see Renderer::renderView()} at all: a ToolResult's `imageBytes` were
 * carried across the whole pipeline and then silently dropped at display time.
 *
 * @see Renderer::renderView()
 */
final class RendererImageTest extends TestCase
{
    /** First Private-Use-Area codepoint candy-core's ImageOverlay uses as a marker. */
    private const MARKER = "\u{E000}";

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }
    }

    /** A real, decodable 20x10 PNG — candy-mosaic rejects anything it cannot decode. */
    private function pngBytes(int $width = 20, int $height = 10): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($gd, 200, 30, 30));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    /**
     * The call is EXPANDED because a picture now collapses with its tool row
     * (§1 E5, applied to images): a collapsed result renders a one-line
     * affordance and never decodes the bytes at all, so every encode/placement
     * assertion in this file is about the expanded state by definition. The
     * collapsed state is covered in {@see RendererTest}.
     */
    private function chatWithImage(?Mosaic $mosaic, ?string $bytes = null): Chat
    {
        $result = new ToolResult(
            name: 'Doctor',
            result: 'terminal capability report',
            id: 'call_img',
            imageBytes: $bytes ?? $this->pngBytes(),
        );

        return new Chat(
            history: [Message::user('/doctor'), Message::assistant('')->withToolResults([$result])],
            rows: 40,
            cols: 80,
            expanded: ['call_img' => true],
            mosaic: $mosaic,
        );
    }

    /**
     * The step-defining behaviour: a pixel-graphics protocol's blob must NOT be
     * concatenated into the text frame (it would corrupt candy-core's line
     * diff) — it is parked on the View's image layer and represented in the
     * frame by a one-cell PUA marker.
     */
    public function testSixelImageIsPlacedOnTheViewLayerNotInlinedIntoTheFrame(): void
    {
        $view = Renderer::renderView($this->chatWithImage(Mosaic::sixel()));

        $this->assertInstanceOf(View::class, $view);
        $this->assertCount(1, $view->images, 'the image must ride out on the View image layer');
        $this->assertStringContainsString(self::MARKER, $view->body, 'the frame must reserve the image box with a marker');
        // The raw DCS sixel envelope must never appear in the diffed text body.
        $this->assertStringNotContainsString("\x1bPq", $view->body);
    }

    /**
     * The placement carries the blob plus its cell footprint, which is what
     * Program uses to clear the image's rows when it scrolls away.
     */
    public function testPlacementCarriesTheBlobAndAnAspectCorrectFootprint(): void
    {
        $view = Renderer::renderView($this->chatWithImage(Mosaic::sixel()));
        $placement = array_values($view->images)[0];

        $this->assertNotSame('', $placement->bytes);
        $this->assertSame(40, $placement->widthCells, 'crush_feat.md §9 E3 pins the image width at 40 cells');
        // 20x10 source, cells ~2:1 → 40 / (20/10) / 2 = 10 rows.
        $this->assertSame(10, $placement->heightCells);
    }

    /**
     * An inline renderer (half-block/quarter-block/ASCII) emits ordinary cells,
     * so it goes straight into the frame and needs no overlay at all.
     */
    public function testInlineHalfBlockImageIsPaintedIntoTheFrameWithNoPlacements(): void
    {
        $view = Renderer::renderView($this->chatWithImage(Mosaic::halfBlock()));

        $this->assertSame([], $view->images, 'inline cells need no image layer');
        $this->assertStringNotContainsString(self::MARKER, $view->body);
        $this->assertStringContainsString('▀', $view->body, 'half-block cells must be visible in the frame');
    }

    /**
     * A Chat built without the probe-once Mosaic (any direct `new Chat(...)`,
     * as opposed to `Cli\Bootstrap::chat()`) has no known protocol; the tool's
     * text still renders, only the picture is skipped.
     */
    public function testChatWithoutAMosaicRendersTheToolTextAndSkipsTheImage(): void
    {
        $view = Renderer::renderView($this->chatWithImage(null));

        $this->assertSame([], $view->images);
        $this->assertStringNotContainsString(self::MARKER, $view->body);
        $this->assertStringContainsString('tool: Doctor', $view->body);
    }

    /**
     * view() runs every frame and must never throw: undecodable bytes cost one
     * line of the transcript, not the session.
     */
    public function testUndecodableImageBytesDegradeToANoteInsteadOfThrowing(): void
    {
        $view = Renderer::renderView($this->chatWithImage(Mosaic::sixel(), 'definitely-not-a-png'));

        $this->assertSame([], $view->images);
        $this->assertStringContainsString('image unavailable', $view->body);
        $this->assertStringContainsString('tool: Doctor', $view->body);
    }

    /**
     * A tall source must not be encoded at its natural cell height: the frame
     * is tail-clipped to the viewport, so those rows are thrown away, and a
     * 16x1600 screenshot would otherwise cost a 2000-row Sixel encode on every
     * single frame.
     */
    public function testTallImageIsClampedToTheViewportRowBudget(): void
    {
        $view = Renderer::renderView($this->chatWithImage(Mosaic::sixel(), $this->pngBytes(16, 1600)));
        $placement = array_values($view->images)[0];

        // 40 / (16/1600) / 2 = 2000 natural rows, clamped to rows(40) - 2.
        $this->assertSame(38, $placement->heightCells);
    }

    /**
     * Program repaints on every keystroke, streaming chunk and spinner tick, so
     * the decode+encode has to be memoized - unmemoized, a single Sixel
     * screenshot in the transcript costs hundreds of milliseconds per keypress.
     */
    public function testRepeatedRendersReuseTheEncodedImageInsteadOfReEncoding(): void
    {
        $chat = $this->chatWithImage(Mosaic::sixel(), $this->pngBytes(120, 90));

        $start = hrtime(true);
        $first = Renderer::renderView($chat);
        $cold = hrtime(true) - $start;

        if ($cold < 20_000_000) {
            $this->markTestSkipped('encode is too fast here to tell a cache hit from a miss');
        }

        $start = hrtime(true);
        $second = Renderer::renderView($chat);
        $warm = hrtime(true) - $start;

        $this->assertSame(array_values($first->images)[0]->bytes, array_values($second->images)[0]->bytes);
        $this->assertLessThan($cold / 4, $warm, 'the second frame must not re-encode the picture');
    }

    /** A result with no image is untouched by any of this. */
    public function testTextOnlyToolResultProducesNoImageLayer(): void
    {
        $chat = new Chat(
            history: [Message::assistant('')->withToolResults([ToolResult::ok('calculator', '42', 'call_1')])],
            rows: 40,
            cols: 80,
            mosaic: Mosaic::sixel(),
        );

        $this->assertSame([], Renderer::renderView($chat)->images);
    }

    /**
     * render() keeps its string contract for every existing caller — it is
     * renderView()'s body.
     */
    public function testRenderReturnsExactlyTheViewBody(): void
    {
        $chat = $this->chatWithImage(Mosaic::sixel());

        $this->assertSame(Renderer::renderView($chat)->body, Renderer::render($chat));
    }

    /**
     * The wiring that makes the whole feature reachable: Program only paints an
     * image layer it is handed, so Chat::view() has to surface the View.
     */
    public function testChatViewReturnsTheViewWhenTheFrameCarriesAnImage(): void
    {
        $view = $this->chatWithImage(Mosaic::sixel())->view();

        $this->assertInstanceOf(View::class, $view);
        $this->assertCount(1, $view->images);
    }

    /** Text-only sessions keep the plain-string simple case candy-core documents. */
    public function testChatViewStaysAPlainStringWithoutImages(): void
    {
        $chat = new Chat(history: [Message::user('hello')], rows: 40, cols: 80, mosaic: Mosaic::sixel());

        $this->assertIsString($chat->view());
    }
}
