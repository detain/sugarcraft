<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Theme;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Theme\ThemeFile;

final class ThemeFileTest extends TestCase
{
    public function testParsesQuotedValuesAndSkipsComments(): void
    {
        $src = <<<'THEME'
            # leading comment theme[title]="#ffffff"
            theme[main_bg]="#2E3440"

               # indented comment
            theme[main_fg]="#D8DEE9"
            #theme[hi_fg]="#000000"
            THEME;
        self::assertSame(['main_bg' => '#2E3440', 'main_fg' => '#D8DEE9'], ThemeFile::parse($src));
    }

    public function testQuotedValueStopsAtClosingQuote(): void
    {
        // dracula-style stray second value after the closing quote is ignored.
        self::assertSame(['main_fg' => '#F8F8F2'], ThemeFile::parse('theme[main_fg]="#F8F8F2" #2eb398"'));
    }

    public function testEmptyAndUnquotedValues(): void
    {
        $parsed = ThemeFile::parse("theme[main_bg]=\"\"\ntheme[title] = 10 20 30  \r\ntheme[hi_fg]=#ff");
        self::assertSame(['main_bg' => '', 'title' => '10 20 30', 'hi_fg' => '#ff'], $parsed);
    }

    public function testUnknownKeysAndMalformedLinesIgnored(): void
    {
        $parsed = ThemeFile::parse("theme[bogus]=\"#fff\"\ntheme[title\nnoeq[title]\ntheme[title]=\"#01\"");
        self::assertSame(['title' => '#01'], $parsed);
    }

    public function testLastDuplicateWinsAndPrefixIsFree(): void
    {
        self::assertSame(['title' => '#02'], ThemeFile::parse("theme[title]=\"#01\"\nanything[title]=\"#02\""));
    }

    public function testUnterminatedQuoteTakesRestOfLine(): void
    {
        self::assertSame(['title' => '#0a0a0a'], ThemeFile::parse('theme[title]="#0a0a0a'));
    }

    public function testLoadMissingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThemeFile::load('/nonexistent/none.theme');
    }
}
