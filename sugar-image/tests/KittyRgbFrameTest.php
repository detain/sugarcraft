<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Image\Renderer\KittyRenderer;

/**
 * Pins the id-addressed raw RGBA path that frame loops use. renderRgb() sends an
 * anonymous frame that no delete can retire, so a loop calling it per frame grows
 * the terminal's image store; renderRgbFrame() names the frame so the previous one
 * can be retired after the next is drawn.
 *
 * @covers \SugarCraft\Image\Renderer\KittyRenderer::renderRgbFrame
 * @covers \SugarCraft\Image\Renderer\KittyRenderer::renderRgb
 * @covers \SugarCraft\Image\Renderer\KittyRenderer::delete
 * @covers \SugarCraft\Image\Renderer\KittyRenderer::assertPositiveId
 */
final class KittyRgbFrameTest extends TestCase
{
    private const W = 4;
    private const H = 2;

    private function rgba(): string
    {
        return str_repeat("\x10\x20\x30\xff", self::W * self::H);
    }

    /**
     * The key=value pairs of the first APC control block in $out, so an `i=` that
     * happens to sit inside a base64 payload cannot be mistaken for the id key.
     *
     * @return array<string, string>
     */
    private static function controlKeys(string $out): array
    {
        self::assertSame(1, preg_match('/\x1b_G([^;]*);/', $out, $m), 'the output opens with an APC control block');

        $keys = [];
        foreach (explode(',', $m[1]) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $keys[$key] = $value;
        }

        return $keys;
    }

    public function testFrameCarriesTheCallerImageIdAndQuietFlag(): void
    {
        $keys = self::controlKeys((new KittyRenderer())->renderRgbFrame($this->rgba(), self::W, self::H, 4, null, 7));

        self::assertSame('7', $keys['i'] ?? null, 'the frame must be named by the caller id');
        self::assertSame('2', $keys['q'] ?? null, 'the frame must be sent quiet, as present() frames are');
        self::assertSame('32', $keys['f'] ?? null, 'the payload is raw RGBA');
        self::assertSame('T', $keys['a'] ?? null, 'the frame is stored and placed, never left uncomposited');
    }

    public function testFrameIsRetiredByDeleteWithTheSameId(): void
    {
        $renderer = new KittyRenderer();
        $frame    = $renderer->renderRgbFrame($this->rgba(), self::W, self::H, 4, null, 7);

        self::assertSame('7', self::controlKeys($frame)['i'] ?? null);
        self::assertSame(
            Ansi::APC . 'G' . 'a=d,d=I,i=7' . Ansi::ST,
            $renderer->delete('7'),
            'the delete that retires the frame names the same id and frees its data',
        );
    }

    public function testRenderRgbStaysAnonymous(): void
    {
        // Pins the documented leak: no id, so nothing can retire the frame. A
        // change here must be deliberate and must update the README note too.
        $keys = self::controlKeys((new KittyRenderer())->renderRgb($this->rgba(), self::W, self::H, 4));

        self::assertArrayNotHasKey('i', $keys);
        self::assertArrayNotHasKey('q', $keys);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveIds(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-3],
        ];
    }

    /**
     * @dataProvider nonPositiveIds
     */
    public function testRejectsANonPositiveImageId(int $imageId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new KittyRenderer())->renderRgbFrame($this->rgba(), self::W, self::H, 4, null, $imageId);
    }

    public function testRejectsAMismatchedBuffer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new KittyRenderer())->renderRgbFrame(str_repeat("\x00", 10), self::W, self::H, 4, null, 7);
    }
}
