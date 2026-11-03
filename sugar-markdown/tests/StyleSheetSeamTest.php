<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Render\BlockKind;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Style\StyleSheet;
use SugarCraft\Style\Style;

/**
 * M7: {@see Renderer::withStyleSheet()} must reach the cascade that the
 * render paths build per document. Before the seam, StyleSheet::base() was
 * hard-coded at both entry points, so the public withBlockKind* builders had
 * no production route. These pins are red on any renderer that drops the
 * override again — and they keep the default byte-identical, because the
 * base sheet seeds every kind with an empty style.
 */
final class StyleSheetSeamTest extends TestCase
{
    public function testACallerCascadeStylesWholeBlockKinds(): void
    {
        $sheet = StyleSheet::base()->withBlockKind(BlockKind::Paragraph, Style::new()->bold());

        $plain = Renderer::plain()->render("hello world\n");
        $styled = Renderer::plain()->withStyleSheet($sheet)->render("hello world\n");

        self::assertSame('hello world', $plain);
        self::assertSame("\x1b[1mhello world\x1b[0m", $styled);
    }

    public function testTheBaseSheetIsByteNeutral(): void
    {
        self::assertSame(
            Renderer::plain()->render("# Head\n\nbody prose\n"),
            Renderer::plain()->withStyleSheet(StyleSheet::base())->render("# Head\n\nbody prose\n"),
            'passing base() must change nothing: the default cascade is base()',
        );
    }

    public function testTheSeamRidesTheStreamChannelIdentically(): void
    {
        $sheet = StyleSheet::base()->withBlockKind(BlockKind::Paragraph, Style::new()->underline());
        $renderer = Renderer::plain()->withStyleSheet($sheet);

        $streamed = '';
        foreach ($renderer->stream(["# Head\n", "\nbody prose\n"]) as $section) {
            $streamed .= $section;
        }

        self::assertSame($renderer->render("# Head\n\nbody prose\n"), $streamed);
        self::assertStringContainsString("\x1b[4m", $streamed, 'the paragraph carries the cascade style on the stream channel too');
    }

    public function testTheOverrideSurvivesBuilderCopies(): void
    {
        $sheet = StyleSheet::base()->withBlockKind(BlockKind::Paragraph, Style::new()->italic());

        $derived = Renderer::plain()->withStyleSheet($sheet)->withWordWrap(400);

        self::assertStringContainsString(
            "\x1b[3m",
            $derived->render("hello world\n"),
            'copy() must carry the cascade override through further builders',
        );
    }
}
