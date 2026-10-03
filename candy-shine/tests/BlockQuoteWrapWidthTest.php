<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;

/**
 * Quoted text wraps at the width the `▎ ` bar leaves — 2 cells per quote
 * level — not 2 cells narrower: the stack used to charge the bar as both
 * the quote's margin and its indent.
 */
final class BlockQuoteWrapWidthTest extends TestCase
{
    /** @return iterable<string, array{Theme}> */
    public static function themes(): iterable
    {
        yield 'plain' => [Theme::plain()];
        yield 'ansi' => [Theme::ansi()];
    }

    #[DataProvider('themes')]
    public function testQuotedTextUsesEveryCellTheBarLeaves(Theme $theme): void
    {
        $out = (new Renderer($theme))->withWordWrap(12)->render("> aaaa bbbb cccc dddd\n");
        $lines = array_values(array_filter(
            explode("\n", Ansi::strip($out)),
            static fn (string $l): bool => trim($l) !== '',
        ));

        $this->assertSame(['▎ aaaa bbbb', '▎ cccc dddd'], $lines);
    }

    public function testNestedQuoteChargesTwoCellsPerLevel(): void
    {
        $out = Renderer::plain()->withWordWrap(13)->render("> > aaaa bbbb cccc dddd\n");
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $l): bool => trim($l) !== ''));

        $this->assertSame(['▎ ▎ aaaa bbbb', '▎ ▎ cccc dddd'], $lines);
    }

    #[DataProvider('themes')]
    public function testQuotedLinesNeverExceedTheWrapWidth(Theme $theme): void
    {
        $words = 'aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii jjjj kkkk llll mmmm';
        foreach (["> {$words}\n", "> > {$words}\n", "> - {$words}\n", "- > {$words}\n"] as $md) {
            foreach ([8, 12, 17, 30] as $width) {
                $out = (new Renderer($theme))->withWordWrap($width)->render($md);
                foreach (explode("\n", $out) as $line) {
                    $this->assertLessThanOrEqual($width, Width::string($line), json_encode($md) . " overflows {$width}: " . json_encode($line));
                }
            }
        }
    }
}
