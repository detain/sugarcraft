<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;

/**
 * List bodies wrap at the width left after the marker column, so no
 * rendered line exceeds `withWordWrap()` (crush_libs shine #3: a
 * third-level item rendered 44 cells at wrap 40).
 */
final class ListWrapWidthTest extends TestCase
{
    private const WORDS = 'aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii jjjj kkkk llll mmmm';

    /** @return iterable<string, array{Theme}> */
    public static function themes(): iterable
    {
        yield 'plain' => [Theme::plain()];
        yield 'ansi' => [Theme::ansi()];
    }

    private static function assertFits(string $out, int $width): void
    {
        foreach (explode("\n", $out) as $line) {
            self::assertLessThanOrEqual($width, Width::string($line), "line overflows wrap {$width}: " . json_encode($line));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testNestedBulletListsFitTheWrapWidth(Theme $theme): void
    {
        $md = '- ' . self::WORDS . "\n  - " . self::WORDS . "\n    - " . self::WORDS . "\n";

        self::assertFits((new Renderer($theme))->withWordWrap(40)->render($md), 40);
    }

    public function testOrderedListsFitTheWrapWidth(): void
    {
        $items = '';
        for ($i = 1; $i <= 11; $i++) {
            $items .= "{$i}. " . self::WORDS . "\n";
        }

        self::assertFits((new Renderer(Theme::plain()))->withWordWrap(30)->render($items), 30);
    }

    public function testListInsideABlockquoteFitsTheWrapWidth(): void
    {
        $md = "> - " . self::WORDS . "\n>   - " . self::WORDS . "\n";

        self::assertFits((new Renderer(Theme::plain()))->withWordWrap(36)->render($md), 36);
    }

    public function testContinuationLinesUseTheWidthTheMarkerLeaves(): void
    {
        // Wrap 20 leaves 18 cells beside `• `: "aaaa bbbb cccc" (14) fits,
        // "aaaa bbbb cccc dddd" (19) does not — exactly, not conservatively.
        $out = (new Renderer(Theme::plain()))->withWordWrap(20)->render('- aaaa bbbb cccc dddd eeee');

        $this->assertSame("• aaaa bbbb cccc\n  dddd eeee", $out);
    }

    public function testAWideListLevelIndentNarrowsTheBody(): void
    {
        $p = Theme::plain();
        $theme = new Theme(
            heading1: $p->heading1, heading2: $p->heading2, heading3: $p->heading3,
            heading4: $p->heading4, heading5: $p->heading5, heading6: $p->heading6,
            paragraph: $p->paragraph, bold: $p->bold, italic: $p->italic,
            code: $p->code, codeBlock: $p->codeBlock, link: $p->link,
            blockquote: $p->blockquote, listMarker: $p->listMarker, rule: $p->rule,
            listLevelIndent: 6,
        );
        $md = '- ' . self::WORDS . "\n  - " . self::WORDS . "\n";

        self::assertFits((new Renderer($theme))->withWordWrap(32)->render($md), 32);
    }
}
