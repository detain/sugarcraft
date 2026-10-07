<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\View;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

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
    use HomeSandboxTrait;

    private string $homeSandbox = '';

    /**
     * The Private-Use cell image id 0's marker ends in (U+E000/U+E001 belong to
     * the zone sentinels). Only half a marker: the zero-width authenticating
     * escape before it is what makes candy-core paint (audit 15b-17).
     */
    private const MARKER = "\u{E002}";

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }

        // Constructing a Chat reaches the skill trees under HOME; sandbox both
        // spellings so this never reads the developer's -- see HomeSandboxTrait.
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/renderer_image_home_' . uniqid('', true),
        );
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);

        parent::tearDown();
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
     *
     * The '▀' shape was re-verified against candy-mosaic e5f60c4f6's fixed
     * transparency mapping (CL-3): this fixture is a fully opaque PNG, so only
     * the untouched both-opaque branch fires — the ▄/space shapes the fix
     * introduced for transparent cells are unreachable through crush's decode
     * path anyway (probe: an alpha PNG still yields all-'▀' cells end-to-end).
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

        // 40 / (16/1600) / 2 = 2000 natural rows, clamped to the full
        // rows(40): CL-3 took the shell's two border rows out of the budget.
        $this->assertSame(40, $placement->heightCells);
    }

    /**
     * Program repaints on every keystroke, streaming chunk and spinner tick, so
     * the decode+encode has to be memoized - unmemoized, a single Sixel
     * screenshot in the transcript costs hundreds of milliseconds per keypress.
     *
     * PROVEN AS STATE, NOT AS A STOPWATCH RATIO. A warm/cold timing ratio is a
     * coin flip on a loaded box (and needed a "too fast to tell" skip guard to
     * avoid going vacuous the other way). `Renderer::$imageCache` is the
     * memoization itself, so this pokes it directly: the cold frame must add
     * EXACTLY ONE encoded entry for the picture, and a frame rendered after
     * that entry is poisoned with a sentinel body must PUT THE SENTINEL ON
     * SCREEN - which only a cache read can do. Deleting the `isset` fast path
     * in renderToolImage() re-encodes over the sentinel and goes red here,
     * at any machine speed.
     */
    public function testRepeatedRendersReuseTheEncodedImageInsteadOfReEncoding(): void
    {
        $png = $this->pngBytes(120, 90);
        $chat = $this->chatWithImage(Mosaic::sixel(), $png);

        $cache = new \ReflectionProperty(Renderer::class, 'imageCache');
        $cache->setAccessible(true);
        $before = $cache->getValue();

        try {
            $first = Renderer::renderView($chat);
            $afterCold = $cache->getValue();

            $newKeys = array_diff(array_keys($afterCold), array_keys($before));
            $this->assertCount(
                1,
                $newKeys,
                'the cold frame must memoize exactly one encoded picture - none means the cache '
                . 'never fills, more means the same picture encoded twice'
            );

            $key = (string) reset($newKeys);
            if (!($afterCold[$key]['ok'] ?? false)) {
                $this->markTestSkipped('this build cannot encode the fixture picture; the reuse path has nothing to memoize');
            }

            // Poison the memoized body. A frame that RE-ENCODES ignores the
            // entry and paints the real sixel; only a frame that READS the
            // cache can show this string.
            $poisoned = $afterCold;
            $poisoned[$key] = ['ok' => true, 'body' => 'SENTINEL-FROM-CACHE'];
            $cache->setValue(null, $poisoned);

            $second = Renderer::renderView($chat);

            $this->assertSame(
                'SENTINEL-FROM-CACHE',
                array_values($second->images)[0]->bytes,
                'the warm frame did not read the memoized picture - it re-encoded over the poisoned '
                . 'cache entry, which is exactly the per-keypress re-encode this memoization exists to stop'
            );
        } finally {
            $cache->setValue(null, $before);
        }
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

    /**
     * Audit 15b-17: model, user or tool text containing U+E002 painted a second
     * copy of the frame's first picture wherever the text put it, and every
     * Private-Use glyph on screen (Powerline separators, Nerd Font icons from
     * `eza --icons` or a starship prompt) was blanked to a space. Driven through
     * Chat::view(), the exact View candy-core's Program resolves, for each row
     * kind and for both the bare cell and a whole forged marker (escape
     * included, which the untrusted-text boundary must strip).
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function forgedMarkerRows(): iterable
    {
        foreach (['bare cell' => "\u{E002}", 'whole forged marker' => ImageOverlay::marker(0)] as $label => $forgery) {
            foreach (['assistant', 'user', 'tool result'] as $kind) {
                yield "{$kind}, {$label}" => [$kind, $forgery];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forgedMarkerRows')]
    public function testForgedMarkerTextNeitherPaintsTheImageNorBlanksPrivateUseGlyphs(string $kind, string $forgery): void
    {
        $text = "answer {$forgery} sep \u{E0B0} dir \u{F115} end";
        $history = [
            Message::user('/doctor'),
            Message::assistant('')->withToolResults([new ToolResult(name: 'Doctor', result: 'report', id: 'call_img', imageBytes: $this->pngBytes())]),
            match ($kind) {
                'assistant' => Message::assistant($text),
                'user' => Message::user($text),
                'tool result' => Message::assistant('')->withToolResults([new ToolResult(name: 'Bash', result: $text, id: 'c2')]),
            },
        ];
        $chat = new Chat(history: $history, rows: 60, cols: 100, expanded: ['call_img' => true, 'c2' => true], mosaic: Mosaic::sixel());

        $view = $chat->view();
        $this->assertInstanceOf(View::class, $view);
        [$body, $paints] = ImageOverlay::resolve($view->body, $view->images);

        $this->assertCount(1, $paints, 'one image on screen, one paint');
        $this->assertStringContainsString("sep \u{E0B0} dir \u{F115} end", $body, 'Private-Use glyphs reach the terminal intact');
    }
}
