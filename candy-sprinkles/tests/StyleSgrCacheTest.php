<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Border\TitleAnchor;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\UnderlineStyle;
use PHPUnit\Framework\TestCase;

/**
 * Locks the per-instance SGR memoization added to {@see Style} against
 * behavioural regressions.
 *
 * The optimization caches the content-independent opening/closing SGR
 * escapes (both the text-attribute sequence and the border sequence) once
 * per immutable instance. Two properties must hold:
 *
 *   1. Byte parity — the cache changes nothing about the rendered output.
 *      The pinned literals below are the exact bytes produced BEFORE the
 *      cache was introduced (verified with an original-vs-modified render
 *      diff across 215 representative styles).
 *   2. Per-instance isolation — a `with*()`-derived instance never reuses a
 *      parent's cached SGR; each immutable instance computes (once) and
 *      keeps its own.
 */
final class StyleSgrCacheTest extends TestCase
{
    private static function red(): Color
    {
        return Color::hex('#ff0000');
    }

    private static function green(): Color
    {
        return Color::hex('#00ff00');
    }

    private static function blue(): Color
    {
        return Color::hex('#0000ff');
    }

    /**
     * @return array<string,array{Style,string,string}>
     */
    public static function pinnedRenders(): array
    {
        $red   = self::red();
        $green = self::green();
        $blue  = self::blue();

        return [
            'fgBold' => [
                Style::new()->fg($red)->bold(true),
                'hi',
                "\x1b[1m\x1b[38;2;255;0;0mhi\x1b[0m",
            ],
            'bg' => [
                Style::new()->bg($blue),
                'hi',
                "\x1b[48;2;0;0;255mhi\x1b[0m",
            ],
            'combo' => [
                Style::new()->fg($red)->bg($green)->bold(true)->italic(true)->underline(true),
                'hi',
                "\x1b[1;3;4m\x1b[38;2;255;0;0m\x1b[48;2;0;255;0mhi\x1b[0m",
            ],
            'padding' => [
                Style::new()->fg($red)->padding(1, 2, 1, 2),
                'hi',
                "\x1b[38;2;255;0;0m      \x1b[0m\n\x1b[38;2;255;0;0m  hi  \x1b[0m\n\x1b[38;2;255;0;0m      \x1b[0m",
            ],
            'widthCenter' => [
                Style::new()->fg($green)->width(10)->align(Align::Center),
                'hi',
                "\x1b[38;2;0;255;0m    hi    \x1b[0m",
            ],
            'borderFg' => [
                Style::new()->border(Border::normal())->borderForeground($green),
                'hi',
                "\x1b[38;2;0;255;0m┌──┐\x1b[0m\n\x1b[38;2;0;255;0m│\x1b[0mhi\x1b[38;2;0;255;0m│\x1b[0m\n\x1b[38;2;0;255;0m└──┘\x1b[0m",
            ],
            'borderTitle' => [
                Style::new()->border(Border::rounded()->withTitle('App', TitleAnchor::TopLeft))->borderForeground($red)->width(12),
                'body',
                "\x1b[38;2;255;0;0m╭\x1b[38;2;255;0;0mApp\x1b[0m─────────╮\x1b[0m\n\x1b[38;2;255;0;0m│\x1b[0mbody        \x1b[38;2;255;0;0m│\x1b[0m\n\x1b[38;2;255;0;0m╰────────────╯\x1b[0m",
            ],
            'multiline' => [
                Style::new()->fg($red)->padding(0, 1),
                "a\nbb\nlonger",
                "\x1b[38;2;255;0;0m a      \x1b[0m\n\x1b[38;2;255;0;0m bb     \x1b[0m\n\x1b[38;2;255;0;0m longer \x1b[0m",
            ],
            'wideBorder' => [
                Style::new()->border(Border::double())->borderForeground($blue),
                "日本\nok",
                "\x1b[38;2;0;0;255m╔════╗\x1b[0m\n\x1b[38;2;0;0;255m║\x1b[0m日本\x1b[38;2;0;0;255m║\x1b[0m\n\x1b[38;2;0;0;255m║\x1b[0mok  \x1b[38;2;0;0;255m║\x1b[0m\n\x1b[38;2;0;0;255m╚════╝\x1b[0m",
            ],
            'ulStyleColor' => [
                Style::new()->underline(true)->underlineStyle(UnderlineStyle::Double)->underlineColor($blue),
                'x',
                "\x1b[4:2m\x1b[58;2;0;0;255mx\x1b[0m",
            ],
            'sideOverride' => [
                Style::new()->border(Border::normal())->borderTopForeground($red)->borderLeftForeground($green),
                'hi',
                "\x1b[38;2;255;0;0m┌──┐\x1b[0m\n\x1b[38;2;0;255;0m│\x1b[0mhi│\n└──┘",
            ],
        ];
    }

    /**
     * @dataProvider pinnedRenders
     */
    public function testRenderIsByteIdenticalToPreCacheOutput(Style $style, string $content, string $expected): void
    {
        $this->assertSame($expected, $style->render($content));
    }

    /**
     * @dataProvider pinnedRenders
     */
    public function testRepeatedRenderIsStableAcrossCacheHit(Style $style, string $content, string $expected): void
    {
        // First render primes the memo; the second must reuse it and stay
        // byte-identical. Rendering different content in between must not
        // pollute the content-independent cache.
        $first = $style->render($content);
        $style->render('OTHER CONTENT ' . $content . "\nsecond");
        $second = $style->render($content);

        $this->assertSame($expected, $first);
        $this->assertSame($expected, $second);
    }

    public function testContentSgrMemoStartsNullAndPopulatesOnFirstRender(): void
    {
        $style = Style::new()->fg(self::red())->bold(true);

        $memo = new \ReflectionProperty(Style::class, 'contentSgrMemo');
        $this->assertNull($memo->getValue($style), 'memo must be lazy — unset before first render');

        $style->render('hi');

        $cached = $memo->getValue($style);
        $this->assertNotNull($cached, 'memo must be populated after first render');
        $this->assertSame("\x1b[1m\x1b[38;2;255;0;0m", $cached);
    }

    public function testRenderConsultsCachedContentSgrRatherThanRecomputing(): void
    {
        $style = Style::new()->fg(self::red())->bold(true);
        $first = $style->render('x');

        // Poison the cache with a sentinel. A genuinely memoized render
        // reuses the stored escape verbatim; a re-computing render would
        // regenerate the real SGR and the sentinel would never appear.
        $memo = new \ReflectionProperty(Style::class, 'contentSgrMemo');
        $memo->setValue($style, "\x1b[SENTINELm");
        $second = $style->render('x');

        $this->assertStringNotContainsString("\x1b[SENTINELm", $first);
        $this->assertStringContainsString("\x1b[SENTINELm", $second);
    }

    public function testWithDerivedInstanceDoesNotReuseParentContentSgr(): void
    {
        $red  = self::red();
        $blue = self::blue();

        $parent    = Style::new()->fg($red)->bold(true);
        $parentOut = $parent->render('x'); // primes the parent memo

        // Change only the foreground. If the memo leaked across with*(),
        // the child would render red; it must render blue.
        $child    = $parent->fg($blue);
        $childOut = $child->render('x');

        $this->assertSame(Style::new()->fg($blue)->bold(true)->render('x'), $childOut);
        $this->assertNotSame($parentOut, $childOut);

        $memo = new \ReflectionProperty(Style::class, 'contentSgrMemo');
        $this->assertNotSame(
            $memo->getValue($parent),
            $memo->getValue($child),
            'child must own an independent content-SGR memo',
        );
    }

    public function testWithDerivedInstanceDoesNotReuseParentBorderSgr(): void
    {
        $green = self::green();
        $blue  = self::blue();

        $parent = Style::new()->border(Border::normal())->borderForeground($green);
        $parent->render('x'); // primes the border memo

        $child = $parent->borderForeground($blue);

        $this->assertSame(
            Style::new()->border(Border::normal())->borderForeground($blue)->render('x'),
            $child->render('x'),
            'child border must recolour, not reuse the parent border-SGR memo',
        );
    }

    public function testBorderSgrMemoPopulatesOnceAndIsConsulted(): void
    {
        $style = Style::new()->border(Border::normal())->borderForeground(self::green());

        $memo = new \ReflectionProperty(Style::class, 'borderSgrMemo');
        $this->assertNull($memo->getValue($style), 'border memo must be lazy');

        $style->render('x');
        $this->assertIsArray($memo->getValue($style), 'border memo must be populated after render');

        // Poison one slot (the top-side opening SGR) and confirm render
        // reuses the cached bundle instead of recomputing it.
        $bundle = $memo->getValue($style);
        $bundle[0] = "\x1b[BORDERSENTINELm";
        $memo->setValue($style, $bundle);
        $this->assertStringContainsString("\x1b[BORDERSENTINELm", $style->render('x'));
    }

    public function testCopyCarriesAValidMemo(): void
    {
        $orig = Style::new()->fg(self::red())->border(Border::rounded())->borderForeground(self::green());
        $expected = $orig->render('copytest'); // primes both memos

        $copy = $orig->copy();

        // The copy is byte-identical, so a carried-over memo stays correct.
        $this->assertSame($expected, $copy->render('copytest'));
        $this->assertSame($expected, $orig->render('copytest'));
    }
}
