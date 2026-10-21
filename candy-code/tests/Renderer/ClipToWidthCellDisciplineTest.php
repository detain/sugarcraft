<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Renderer;
use SugarCraft\Core\Util\Width;

/**
 * tui-F-3: the shared popup clip must land on a grapheme-cluster boundary.
 *
 * The clip was already cell-width CORRECT (a while-loop trimmed codepoints
 * until the run fit), so a width assertion alone cannot tell the fixed cut from
 * the broken one. What the loop could not fix is WHERE it stopped: cutting per
 * codepoint can strand a combining mark from its base, or slice a ZWJ family
 * in half. These fixtures pin the cut position, using planted Unicode whose
 * cluster structure is asserted directly rather than re-derived from the code
 * under test.
 *
 * @see Renderer::clipToWidth()
 */
final class ClipToWidthCellDisciplineTest extends TestCase
{
    private static function clip(string $text, int $cols): string
    {
        $method = new ReflectionMethod(Renderer::class, 'clipToWidth');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke(null, $text, $cols);

        return $result;
    }

    private static function asciiBanner(string $text, int $cols): string
    {
        $method = new ReflectionMethod(Renderer::class, 'clipAsciiBanner');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke(null, $text, $cols);

        return $result;
    }

    public function testClipKeepsFittingTextByteIdentical(): void
    {
        $this->assertSame('run tests', self::clip('run tests', 20));
    }

    public function testClipReturnsNothingWhenThereAreNoColumns(): void
    {
        $this->assertSame('', self::clip('anything', 0));
    }

    public function testClipCutsWholeCombiningClustersNotCodepoints(): void
    {
        // 40 "e + COMBINING ACUTE" clusters = 40 columns, one cell each.
        // A per-codepoint cut stops between a base and its mark and leaves a
        // bare `e` ahead of the ellipsis; a cluster cut cannot.
        $cluster = "e\u{0301}";
        $result = self::clip(str_repeat($cluster, 40), 10);

        self::assertSame(str_repeat($cluster, 9) . "\u{2026}", $result);
        self::assertSame(
            1,
            preg_match('/\A(?:e\x{0301})+\x{2026}\z/u', $result),
            'clip left a base without its mark: ' . bin2hex($result),
        );
    }

    public function testClipNeverSplitsAZwjFamilyAtTheCut(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // 👨‍👩‍👧 one 2-cell cluster
        $result = self::clip(str_repeat($family, 20), 15);

        // Room is 14 cells after the ellipsis → 7 whole families, never a half.
        $body = substr($result, 0, -strlen("\u{2026}"));
        self::assertStringEndsWith("\u{2026}", $result);
        self::assertSame(7, substr_count($body, $family));
        self::assertSame(14, substr_count($body, "\u{200D}"), 'a family was cut, stranding its ZWJ joiner');
        self::assertLessThanOrEqual(15, Width::of($result));
    }

    public function testClipEmojiRunFitsItsColumnBudget(): void
    {
        $emoji = "\u{1F600}"; // U+1F600, 2 cells
        $result = self::clip(str_repeat($emoji, 20), 12);

        self::assertStringEndsWith("\u{2026}", $result);
        self::assertSame(5, substr_count($result, $emoji), 'an emoji must cost two cells');
        self::assertLessThanOrEqual(12, Width::of($result));
    }

    public function testClipAsciiBannerReproducesTheHistoricCodepointCutForAscii(): void
    {
        // clipAsciiBanner is the byte-compat home for the input-draft banners;
        // for ASCII it must be IDENTICAL to the pre-fix codepoint algorithm,
        // so the shipped goldens that carry "↑ N lines above" do not move.
        $text = str_repeat('x', 40);
        $cols = 12;

        $historic = mb_substr($text, 0, max(1, $cols - 1));
        while ($historic !== '' && Width::string($historic) > $cols - 1) {
            $historic = mb_substr($historic, 0, mb_strlen($historic) - 1);
        }
        $historic .= '…';

        self::assertSame($historic, self::asciiBanner($text, $cols));
        self::assertSame(str_repeat('x', 11) . '…', self::asciiBanner($text, $cols));
    }

    public function testClipAsciiBannerKeepsAShortBannerWhole(): void
    {
        $banner = "↑ 3 lines above";
        self::assertSame($banner, self::asciiBanner($banner, 40));
    }
}
