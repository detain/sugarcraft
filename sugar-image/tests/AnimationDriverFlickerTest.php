<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Animation;
use SugarCraft\Image\AnimationDriver;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\Renderer;

/**
 * E-15: byte-order pins for AnimationDriver::view() per renderer family.
 *
 * The flicker defect was `delete($id) . render($frame)` — the screen lost
 * the image for one draw per tick. These tests pin the fix directly:
 * the committed frame bytes always precede any retire byte, and on Kitty
 * the retire byte names the OTHER double-buffer slot — never the id just
 * transmitted (`a=d,i=X` deletes every placement of image X, including a
 * frame committed microseconds earlier). Commas never occur in base64
 * payload bytes, so 'a=d,i=' substrings are unambiguous wire landmarks.
 */
final class AnimationDriverFlickerTest extends TestCase
{
    private ImageSource $frame;

    private Animation $animation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frame     = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->animation = Animation::fixed([$this->frame, $this->frame], 100);
    }

    public function testKittyFrameBytesPrecedeTheOtherSlotsRetireByte(): void
    {
        $renderer = new KittyRenderer();
        $driver   = $this->kittyDriver($renderer, index: 0);

        // Slot pair (10, 11): frame 0 commits into slot 10, retires slot 11.
        $render = $renderer->renderFrame($this->frame, 4, 2, 10);
        $delete = $renderer->delete('11');

        $this->assertSame(
            $render . $delete,
            $driver->view(),
            'E-15: the new frame must be on the wire before the retire byte (render . delete)',
        );
        // The pre-E-15 shape — retire first — must NOT be what ships.
        $this->assertNotSame($delete . $render, $driver->view());
    }

    public function testKittyRetireByteNeverNamesTheFreshlyCommittedId(): void
    {
        // The trap in the naive `$render . $delete` swap while reusing ONE
        // id: kitty `a=d,i=X` deletes image X wholesale — render-then-
        // delete of the same id erases the frame just committed. The two
        // alternating slots exist exactly to keep the retire byte pointing
        // at the stale image (chafa.c:833 double-buffer parity).
        $renderer = new KittyRenderer();

        $even = $this->kittyDriver($renderer, index: 0)->view();
        $odd  = $this->kittyDriver($renderer, index: 1)->view();

        $this->assertStringContainsString('a=d,i=11', $even, 'frame 0 retires the odd slot');
        $this->assertStringNotContainsString('a=d,i=10', $even, 'frame 0 must not self-delete');
        $this->assertStringContainsString('a=d,i=10', $odd, 'frame 1 retires the even slot');
        $this->assertStringNotContainsString('a=d,i=11', $odd, 'frame 1 must not self-delete');
    }

    public function testKittySlotsAlternateAcrossFrames(): void
    {
        $renderer = new KittyRenderer();

        $this->assertSame(
            $renderer->renderFrame($this->frame, 4, 2, 10) . $renderer->delete('11'),
            $this->kittyDriver($renderer, index: 0)->view(),
        );
        $this->assertSame(
            $renderer->renderFrame($this->frame, 4, 2, 11) . $renderer->delete('10'),
            $this->kittyDriver($renderer, index: 1)->view(),
        );
    }

    public function testPopSemanticRendererRetiresBeforeCommitting(): void
    {
        // iTerm2/WezTerm delete() is `OSC 1337;Pop` — it removes the
        // TOPMOST image and ignores ids. A render-before-delete there pops
        // the frame just drawn, so these renderers keep the byte order they
        // had before E-15: delete-first, and the id flows into delete().
        $driver = new AnimationDriver(
            animation: $this->animation,
            renderer:  $this->sentinelRenderer(deleteBytes: static fn(string $id): string => 'POP' . $id . '|'),
            cellWidth: 4,
            cellHeight: 2,
            index:     1,
            imageId:   7,
        );

        $this->assertSame('POP7|FRAME', $driver->view());
    }

    public function testEmptyDeleteRendererIsByteStable(): void
    {
        // Sixel/text families: delete() returns '' — the order cannot
        // matter and the emitted bytes are exactly the render.
        $driver = new AnimationDriver(
            animation: $this->animation,
            renderer:  $this->sentinelRenderer(deleteBytes: static fn(string $id): string => ''),
            cellWidth: 4,
            cellHeight: 2,
            index:     1,
            imageId:   7,
        );

        $this->assertSame('FRAME', $driver->view());
    }

    private function kittyDriver(KittyRenderer $renderer, int $index): AnimationDriver
    {
        return new AnimationDriver(
            animation: $this->animation,
            renderer:  $renderer,
            cellWidth: 4,
            cellHeight: 2,
            index:     $index,
            imageId:   10,
        );
    }

    /**
     * A Renderer double whose bytes name their role, so order assertions
     * read as plain strings.
     */
    private function sentinelRenderer(\Closure $deleteBytes): Renderer
    {
        return new class ($deleteBytes) implements Renderer {
            /** @param \Closure(string): string $deleteBytes */
            public function __construct(private readonly \Closure $deleteBytes) {}

            public function render(ImageSource $image, int $width, ?int $height = null): string
            {
                return 'FRAME';
            }

            public function name(): string
            {
                return 'sentinel';
            }

            public function supportsAlpha(): bool
            {
                return true;
            }

            public function isInline(): bool
            {
                return false;
            }

            public function delete(string $imageId): string
            {
                return ($this->deleteBytes)($imageId);
            }
        };
    }
}
