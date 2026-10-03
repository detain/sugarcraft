<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
use SugarCraft\Sprinkles\Style;

/**
 * `headingCase` transforms the heading's prose, never the bytes the
 * renderer emits around it (crush_libs shine #2: `upper` turned the bold
 * SGR terminator `m` into `M`).
 */
final class HeadingCaseTest extends TestCase
{
    private static function renderer(string $case): Renderer
    {
        $b = Theme::ansi();

        return new Renderer(new Theme(
            heading1: Style::new(), heading2: $b->heading2, heading3: $b->heading3,
            heading4: $b->heading4, heading5: $b->heading5, heading6: $b->heading6,
            paragraph: $b->paragraph, bold: Style::new()->bold(), italic: $b->italic,
            code: $b->code, codeBlock: $b->codeBlock, link: $b->link,
            blockquote: $b->blockquote, listMarker: $b->listMarker, rule: $b->rule,
            headingCase: $case,
        ));
    }

    public function testUpperLeavesInlineSgrIntact(): void
    {
        $this->assertSame("# A \e[1mB\e[0m C", self::renderer('upper')->render('# a **b** c'));
    }

    public function testLowerLeavesInlineSgrIntact(): void
    {
        $this->assertSame("# a \e[1mb\e[0m c", self::renderer('lower')->render('# A **B** C'));
    }

    public function testTitleCasesEachWordAndKeepsSgr(): void
    {
        $this->assertSame("# Hello \e[1mBig\e[0m World", self::renderer('title')->render('# hello **big** world'));
    }

    public function testCodeSpansAndLinkUrlsKeepTheirCase(): void
    {
        $out = self::renderer('upper')->withHyperlinks()->render('# see `cOde` [Link](https://Ex.com/P)');

        $this->assertStringContainsString('cOde', $out);
        $this->assertStringContainsString("\e]8;;https://Ex.com/P\e\\", $out, 'the OSC-8 target is not upper-cased');
        $this->assertStringContainsString('(https://Ex.com/P)', $out);
        $this->assertStringContainsString('LINK', $out);
        $this->assertStringContainsString('SEE', $out);
    }

    public function testTheTransformStaysInsideTheHeading(): void
    {
        $out = self::renderer('upper')->render("# title\n\nbody **text**");

        $this->assertStringContainsString('# TITLE', $out);
        $this->assertStringContainsString("body \e[1mtext\e[0m", $out);
    }

    public function testAutolinkInsideCasedHeadingKeepsUrlAndHasNoSuffix(): void
    {
        $link = Theme::ansi()->link;
        $url = 'https://ex.com/A';
        $expected = '# SEE ' . "\e]8;;{$url}\e\\" . $link->render($url) . "\e]8;;\e\\";

        $this->assertSame($expected, self::renderer('upper')->withHyperlinks()->render("# see <{$url}>"));
        $this->assertSame($expected, self::renderer('upper')->withHyperlinks()->render("# see {$url}"), 'bare GFM autolink');
        $this->assertSame('# SEE ' . $link->render($url), self::renderer('upper')->withHyperlinks(false)->render("# see <{$url}>"), 'without OSC-8');
    }

    public function testLabelledLinkInsideCasedHeadingStillGetsSuffix(): void
    {
        $out = self::renderer('lower')->withHyperlinks(false)->render('# See [DOCS](https://Ex.com/P)');

        $this->assertSame('# see ' . Theme::ansi()->link->render('docs') . ' (https://Ex.com/P)', $out);
    }
}
