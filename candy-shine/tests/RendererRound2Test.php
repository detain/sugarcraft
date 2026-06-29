<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the round-2 CandyShine additions:
 * - Renderer::withStandardStyle / withEmoji
 * - Theme::orderedListMarkerFormat / unorderedListMarkerGlyph
 * - Theme::documentBlockPrefix / Suffix
 */
final class RendererRound2Test extends TestCase
{
    public function testWithStandardStyleSwapsTheme(): void
    {
        $r = (new Renderer(Theme::plain()))->withStandardStyle('dracula');
        // dracula is a colour theme so SGR codes appear in output.
        $rendered = $r->render('# Hello');
        $this->assertStringContainsString("\x1b[", $rendered);
    }

    public function testWithStandardStyleRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Renderer())->withStandardStyle('nonexistent');
    }

    public function testWithEmojiExpandsShortcodes(): void
    {
        $out = (new Renderer(Theme::plain()))
            ->withEmoji(true)
            ->render(':candy: hello :rocket:');
        $this->assertStringContainsString('🍬', $out);
        $this->assertStringContainsString('🚀', $out);
    }

    public function testWithEmojiOffPassesThrough(): void
    {
        $out = (new Renderer(Theme::plain()))->render(':candy: hi');
        $this->assertStringContainsString(':candy:', $out);
    }

    public function testWithEmojiUnknownShortcodePassesThrough(): void
    {
        $out = (new Renderer(Theme::plain()))
            ->withEmoji(true)
            ->render(':notarealemoji: hi');
        $this->assertStringContainsString(':notarealemoji:', $out);
    }

    public function testOrderedListMarkerFormat(): void
    {
        $base = Theme::plain();
        $custom = $this->cloneWithOverrides($base, [
            'orderedListMarkerFormat' => '%d)',
        ]);
        $out = (new Renderer($custom))->render("1. one\n2. two");
        $this->assertStringContainsString('1) one',  $out);
        $this->assertStringContainsString('2) two',  $out);
        $this->assertStringNotContainsString('1.',   $out);
    }

    public function testUnorderedListMarkerGlyph(): void
    {
        $base = Theme::plain();
        $custom = $this->cloneWithOverrides($base, [
            'unorderedListMarkerGlyph' => '★',
        ]);
        $out = (new Renderer($custom))->render("- alpha\n- beta");
        $this->assertStringContainsString('★ alpha', $out);
        $this->assertStringContainsString('★ beta',  $out);
        $this->assertStringNotContainsString('• alpha', $out);
    }

    public function testDocumentBlockPrefixSuffix(): void
    {
        $base = Theme::plain();
        $custom = $this->cloneWithOverrides($base, [
            'documentBlockPrefix' => '### START ###',
            'documentBlockSuffix' => '### END ###',
        ]);
        $out = (new Renderer($custom))->render('hello');
        $this->assertStringStartsWith('### START ###', $out);
        $this->assertStringEndsWith('### END ###', $out);
    }

    /**
     * Reflectively rebuild the Theme constructor with one slot
     * overridden — Theme is final-readonly so we list every field.
     *
     * @param array<string, mixed> $overrides
     */
    private function cloneWithOverrides(Theme $base, array $overrides): Theme
    {
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
            'orderedListMarker','unorderedListMarker',
            'orderedListMarkerFormat','unorderedListMarkerGlyph',
            'tableCenterSeparator','tableColumnSeparator','tableRowSeparator',
            'definitionList',
            'documentBlockPrefix','documentBlockSuffix',
        ] as $slot) {
            $args[$slot] = $overrides[$slot] ?? $base->{$slot};
        }
        return new Theme(...$args);
    }
}
