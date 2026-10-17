<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the Theme element extensions added in audit #9
 * wrap-up: tableHeader / tableCell / tableSeparator, imageText,
 * headingPrefix / headingSuffix, paragraphPrefix / paragraphSuffix,
 * headingCase. Also covers the new top-level
 * Renderer::renderMarkdown() static convenience.
 */
final class ThemeExtensionsTest extends TestCase
{
    private function plainBase(): Theme
    {
        return Theme::plain();
    }

    private function with(Theme $base, array $overrides): Theme
    {
        // Reflectively merge overrides onto the base — Theme constructor
        // takes named args, so we list every existing slot explicitly
        // via the public readonly properties.
        $args = [];
        foreach ([
            'heading1','heading2','heading3','heading4','heading5','heading6',
            'paragraph','bold','italic','code','codeBlock','link','blockquote',
            'listMarker','rule',
            'keyword','string','number','comment',
            'strike','linkText','image','htmlBlock','htmlSpan',
            'definitionTerm','definitionDescription','text','autolink',
            'documentMargin','documentIndent','listLevelIndent',
            'taskTickedGlyph','taskUntickedGlyph',
            'horizontalRuleGlyph','horizontalRuleLength',
            'tableHeader','tableCell','tableSeparator',
            'imageText',
            'headingPrefix','headingSuffix',
            'paragraphPrefix','paragraphSuffix','headingCase',
        ] as $slot) {
            $args[$slot] = $overrides[$slot] ?? $base->{$slot};
        }
        return new Theme(...$args);
    }

    public function testTopLevelRenderMarkdown(): void
    {
        $out = Renderer::renderMarkdown('# Hi', Theme::plain());
        $this->assertSame('# Hi', $out);
    }

    public function testTopLevelDefaultTheme(): void
    {
        $out = Renderer::renderMarkdown('# Hi');
        $this->assertStringContainsString('Hi', $out);
    }

    public function testHeadingPrefixOverride(): void
    {
        $t = $this->with($this->plainBase(), [
            'headingPrefix' => '❯ ',
        ]);
        $out = (new Renderer($t))->render('# Hello');
        $this->assertStringContainsString('❯ Hello', $out);
        $this->assertStringNotContainsString('# Hello', $out);
    }

    public function testHeadingSuffixAppends(): void
    {
        $t = $this->with($this->plainBase(), [
            'headingSuffix' => ' ⟵',
        ]);
        $out = (new Renderer($t))->render('# Hello');
        $this->assertStringContainsString('Hello ⟵', $out);
    }

    public function testHeadingCaseUpper(): void
    {
        $t = $this->with($this->plainBase(), [
            'headingCase' => 'upper',
        ]);
        $out = (new Renderer($t))->render('# Mixed Case');
        $this->assertStringContainsString('MIXED CASE', $out);
    }

    public function testHeadingCaseLower(): void
    {
        $t = $this->with($this->plainBase(), [
            'headingCase' => 'lower',
        ]);
        $out = (new Renderer($t))->render('# Mixed CASE');
        $this->assertStringContainsString('mixed case', $out);
    }

    public function testHeadingCaseTitle(): void
    {
        $t = $this->with($this->plainBase(), [
            'headingCase' => 'title',
        ]);
        $out = (new Renderer($t))->render('# hello world');
        $this->assertStringContainsString('Hello World', $out);
    }

    public function testParagraphPrefixSuffix(): void
    {
        $t = $this->with($this->plainBase(), [
            'paragraphPrefix' => '> ',
            'paragraphSuffix' => ' <',
        ]);
        $out = (new Renderer($t))->render('plain text');
        $this->assertStringContainsString('> plain text <', $out);
    }

    public function testTableHeaderStyleApplied(): void
    {
        $t = $this->with($this->plainBase(), [
            'tableHeader' => Style::new()->bold(),
        ]);
        $md = "| col |\n|-----|\n| body |\n";
        $out = (new Renderer($t))->render($md);
        // SGR 1 (bold) wraps the header content.
        $this->assertStringContainsString("\x1b[1m", $out);
    }

    public function testTableCellStyleApplied(): void
    {
        $t = $this->with($this->plainBase(), [
            'tableCell' => Style::new()->italic(),
        ]);
        $md = "| col |\n|-----|\n| body |\n";
        $out = (new Renderer($t))->render($md);
        $this->assertStringContainsString("\x1b[3m", $out);
    }

    public function testImageTextStyleApplied(): void
    {
        $t = $this->with($this->plainBase(), [
            'imageText' => Style::new()->bold(),
        ]);
        $out = (new Renderer($t))->render('![pretty alt](x.png)');
        // The alt text is bolded; the (url) suffix is not.
        $this->assertStringContainsString("\x1b[1mpretty alt\x1b[0m", $out);
        $this->assertStringContainsString('(x.png)', $out);
    }

    public function testImageTextFallbackToImage(): void
    {
        $t = $this->with($this->plainBase(), [
            'image' => Style::new()->italic(),
        ]);
        $out = (new Renderer($t))->render('![alt](x.png)');
        // No imageText set → image style paints alt.
        $this->assertStringContainsString("\x1b[3malt\x1b[0m", $out);
    }
}
