<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Word wrap in {@see Style}: wordWrap() breaks content to width() instead of
 * truncating it. The default stays truncation, pinned by the first test.
 */
final class WordWrapTest extends TestCase
{
    /** @return list<string> the rendered lines with their trailing padding removed */
    private static function plainLines(string $rendered): array
    {
        return array_map(
            static fn (string $l): string => rtrim(Ansi::strip($l)),
            explode("\n", $rendered),
        );
    }

    public function testWidthStillTruncatesByDefault(): void
    {
        self::assertSame('abcde', Style::new()->width(5)->render('abcdefghij'));
    }

    public function testWordWrapBreaksOnWhitespace(): void
    {
        $out = Style::new()->width(6)->wordWrap()->render('one two three');

        self::assertSame(['one', 'two', 'three'], self::plainLines($out));
        foreach (explode("\n", $out) as $line) {
            self::assertSame(6, Width::string($line), 'every wrapped line is padded to width()');
        }
    }

    public function testWordWrapGreedyFillsEachLine(): void
    {
        $out = Style::new()->width(9)->wordWrap()->render('aa bb cc dd ee');

        self::assertSame(['aa bb cc', 'dd ee'], self::plainLines($out));
    }

    public function testWordWrapBreaksAWordWiderThanTheBudget(): void
    {
        $out = Style::new()->width(5)->wordWrap()->render('abcdefghij');

        self::assertSame(['abcde', 'fghij'], self::plainLines($out));
    }

    public function testWordWrapHonoursExplicitNewlines(): void
    {
        $out = Style::new()->width(5)->wordWrap()->render("ab\ncd");

        self::assertSame(['ab', 'cd'], self::plainLines($out));
    }

    public function testWordWrapCarriesSgrAcrossBreaks(): void
    {
        $out = Style::new()->width(5)->wordWrap()->render("\x1b[31mhello world\x1b[0m");

        self::assertSame(['hello', 'world'], self::plainLines($out));
        self::assertStringContainsString("\x1b[31m", explode("\n", $out)[0], 'the color opens the first line');
        self::assertStringContainsString("\x1b[0m", explode("\n", $out)[1], 'the reset closes the last line');
    }

    public function testStyleAttributesApplyToEveryWrappedLine(): void
    {
        $out = Style::new()->bold()->width(5)->wordWrap()->render('hello world');

        foreach (explode("\n", $out) as $line) {
            self::assertStringStartsWith("\x1b[1m", $line, 'bold opens every wrapped line');
        }
    }

    public function testWithoutAWidthWordWrapIsANoOp(): void
    {
        self::assertSame('abcdefghij', Style::new()->wordWrap()->render('abcdefghij'));
    }

    public function testWordWrapFalseRestoresTruncation(): void
    {
        $out = Style::new()->wordWrap()->wordWrap(false)->width(5)->render('abcdefghij');

        self::assertSame('abcde', $out);
    }

    public function testInlineCollapsesNewlinesBeforeWrapping(): void
    {
        $out = Style::new()->inline()->wordWrap()->width(7)->render("one\ntwo");

        self::assertSame('one two', rtrim(Ansi::strip($out)));
        self::assertStringNotContainsString("\n", $out);
    }

    public function testInlineWordWrapOfAnOverflowingLineStaysOneLine(): void
    {
        // The wrap pass must not run under inline(): breaking "one two three"
        // at width 4 would reintroduce newlines that inline() has removed.
        $out = Style::new()->inline()->wordWrap()->width(4)->render('one two three');

        self::assertSame('one ', $out);
        self::assertStringNotContainsString("\n", $out);
    }

    public function testInlineWordWrapOfNewlineSeparatedOverflowStaysOneLine(): void
    {
        $out = Style::new()->inline()->wordWrap()->width(4)->render("one two\nthree four");

        self::assertStringNotContainsString("\n", $out);
        self::assertSame(4, Width::string($out));
    }

    public function testWordWrapIsIgnoredUnderInlineSoTheResultStaysOneLine(): void
    {
        $inline = Style::new()->inline()->width(5)->render('one two three');
        $wrapped = Style::new()->inline()->wordWrap()->width(5)->render('one two three');

        self::assertSame($inline, $wrapped, 'inline() keeps width() truncation');
        self::assertSame('one t', rtrim(Ansi::strip($wrapped)));
        self::assertStringNotContainsString("\n", $wrapped);
    }

    public function testInlineWithNewlinesAndWordWrapStaysOneLineAtWidth(): void
    {
        $out = Style::new()->inline()->wordWrap()->width(4)->render("one two\nthree");

        self::assertStringNotContainsString("\n", $out);
        self::assertSame(4, Width::string($out));
    }

    public function testWordWrapSurvivesAnUnsetOfAnotherProp(): void
    {
        $style = Style::new()->wordWrap()->width(5)->bold()->unsetBold();

        self::assertSame(['abcde', 'fghij'], self::plainLines($style->render('abcdefghij')));
    }

    public function testWordWrapIsInheritedFromAParent(): void
    {
        $parent = Style::new()->wordWrap();
        $child = Style::new()->width(5)->inherit($parent);

        self::assertSame(['abcde', 'fghij'], self::plainLines($child->render('abcdefghij')));
    }

    public function testWordWrapIsPatchedInFromAnotherStyle(): void
    {
        $patched = Style::new()->width(5)->patch(Style::new()->wordWrap());

        self::assertSame(['abcde', 'fghij'], self::plainLines($patched->render('abcdefghij')));
    }

    public function testWordWrapIsOffWhenUnset(): void
    {
        $plain = Style::new()->width(5)->render('abcdefghij');
        $wrapped = Style::new()->width(5)->wordWrap()->render('abcdefghij');

        self::assertSame('abcde', $plain);
        self::assertNotSame($plain, $wrapped);
    }
}
