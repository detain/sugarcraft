<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Concerns\ImageSourceRgbFallback;
use SugarCraft\Image\GraphicsFrameSession;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\Renderer;

/**
 * @covers \SugarCraft\Image\GraphicsFrameSession::new
 * @covers \SugarCraft\Image\GraphicsFrameSession::withImageId
 * @covers \SugarCraft\Image\GraphicsFrameSession::present
 * @covers \SugarCraft\Image\GraphicsFrameSession::frameCount
 */
final class GraphicsFrameSessionTest extends TestCase
{
    private function frame(int $seed): ImageSource
    {
        $rgba = '';
        for ($i = 0; $i < 4 * 4; $i++) {
            $rgba .= chr(($i + $seed) % 256) . chr(($i * 3) % 256) . chr(90) . chr(255);
        }

        return ImageSource::fromRgb($rgba, 4, 4, true);
    }

    public function testNewStartsAtImageIdOneWithNoFrames(): void
    {
        $session = GraphicsFrameSession::new(new KittyRenderer());

        $this->assertSame(1, $session->imageId);
        $this->assertSame(0, $session->frameCount());
        $this->assertInstanceOf(KittyRenderer::class, $session->renderer);
    }

    public function testWithImageIdReturnsAFreshSessionAtFrameZero(): void
    {
        $session = GraphicsFrameSession::new(new KittyRenderer());
        $session->present($this->frame(1), 4);

        $moved = $session->withImageId(5);

        $this->assertSame(5, $moved->imageId);
        $this->assertSame(0, $moved->frameCount());
        $this->assertSame(1, $session->imageId, 'the original session is unchanged');
        $this->assertSame(1, $session->frameCount());
    }

    /** @dataProvider nonPositiveIds */
    public function testWithImageIdRejectsANonPositiveId(int $id): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GraphicsFrameSession::new(new KittyRenderer())->withImageId($id);
    }

    /** @return iterable<string,array{int}> */
    public static function nonPositiveIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    public function testTheFirstFrameIsDrawnWithNoDelete(): void
    {
        $kitty = new KittyRenderer();
        $session = GraphicsFrameSession::new($kitty);
        $frame = $this->frame(1);

        $this->assertSame($kitty->present($frame, 4, null, '1', null), $session->present($frame, 4));
    }

    public function testTheSecondFrameRetiresTheFirstSlot(): void
    {
        $kitty = new KittyRenderer();
        $session = GraphicsFrameSession::new($kitty);
        $first = $this->frame(1);
        $second = $this->frame(2);

        $session->present($first, 4);

        $this->assertSame($kitty->present($second, 4, null, '2', '1'), $session->present($second, 4));
        $this->assertSame(2, $session->frameCount());
    }

    public function testIdsAlternateBetweenTheTwoSlotsWithEachPreviousRetired(): void
    {
        $seen = [];
        $recorder = new class ($seen) implements Renderer {
            use ImageSourceRgbFallback;

            /** @param list<array{string,?string}> $seen */
            public function __construct(private array &$seen) {}

            public function render(ImageSource $image, int $width, ?int $height = null): string
            {
                return '';
            }

            public function name(): string
            {
                return 'recorder';
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
                return '';
            }

            public function present(ImageSource $image, int $width, ?int $height, string $imageId, ?string $prevId): string
            {
                $this->seen[] = [$imageId, $prevId];

                return '';
            }

            public function supportsIdDelete(): bool
            {
                return true;
            }
        };

        $session = GraphicsFrameSession::new($recorder)->withImageId(10);
        for ($i = 0; $i < 4; $i++) {
            $session->present($this->frame($i), 4);
        }

        $this->assertSame(
            [['10', null], ['11', '10'], ['10', '11'], ['11', '10']],
            $seen,
        );
    }

    public function testFramesCountUpOnEachPresent(): void
    {
        $session = GraphicsFrameSession::new(new KittyRenderer());

        for ($i = 0; $i < 3; $i++) {
            $session->present($this->frame($i), 4);
        }

        $this->assertSame(3, $session->frameCount());
    }
}
