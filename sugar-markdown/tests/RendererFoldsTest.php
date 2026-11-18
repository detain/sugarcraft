<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

final class RendererFoldsTest extends TestCase
{
    private const MARKER = '▸ folded';

    private const DOC = "# Intro\n\nIntro body.\n\n## Usage\n\nUsage body.\n\n### Options\n\nOptions body.\n\n## Notes\n\nNotes body.\n";

    public function testAFoldedHeadingHidesItsBodyAndShowsTheMarker(): void
    {
        $out = (new Renderer(Theme::plain()))->withFolds(['usage'])->render(self::DOC);

        $this->assertStringContainsString("## Usage\n\n" . self::MARKER . "\n\n", $out);
        $this->assertStringNotContainsString('Usage body.', $out);
    }

    public function testFoldingLeavesTheOtherSectionsAlone(): void
    {
        $out = (new Renderer(Theme::plain()))->withFolds(['usage'])->render(self::DOC);

        $this->assertStringContainsString('Intro body.', $out);
        $this->assertStringContainsString('Notes body.', $out);
    }

    public function testANestedHeadingIsNotHiddenByItsParentsFold(): void
    {
        // A fold hides the body up to the next heading of any level, so the
        // subsection is a section of its own and shows.
        $out = (new Renderer(Theme::plain()))->withFolds(['usage'])->render(self::DOC);

        $this->assertStringContainsString('### Options', $out);
        $this->assertStringContainsString('Options body.', $out);
    }

    public function testFoldingAHeadingWithoutBodyStillShowsTheMarker(): void
    {
        $out = (new Renderer(Theme::plain()))->withFolds(['notes'])->render("# Notes\n\n## Empty\n");

        $this->assertStringContainsString("# Notes\n\n" . self::MARKER . "\n\n", $out);
        $this->assertStringContainsString('## Empty', $out);
    }

    public function testUnfoldingRestoresTheFullRender(): void
    {
        $renderer = new Renderer(Theme::plain());
        $folded = $renderer->withFolds(['usage']);

        $this->assertSame($renderer->render(self::DOC), $folded->withFolds([])->render(self::DOC));
        $this->assertSame([], $folded->withFolds([])->folds);
    }

    public function testTheFoldListIsKeptInOrderWithoutRepeats(): void
    {
        $renderer = (new Renderer(Theme::plain()))->withFolds(['usage', 'notes', 'usage']);

        $this->assertSame(['usage', 'notes'], $renderer->folds);
    }

    public function testAnUnknownIdChangesNothing(): void
    {
        $renderer = new Renderer(Theme::plain());

        $this->assertSame(
            $renderer->render(self::DOC),
            $renderer->withFolds(['no-such-heading'])->render(self::DOC),
        );
    }

    public function testDuplicateHeadingTextsShareOneFoldId(): void
    {
        $doc = "# Same\n\nfirst body\n\n# Same\n\nsecond body\n";
        $out = (new Renderer(Theme::plain()))->withFolds(['same'])->render($doc);

        $this->assertStringNotContainsString('first body', $out);
        $this->assertStringNotContainsString('second body', $out);
        $this->assertSame(2, substr_count($out, self::MARKER));
    }

    public function testAHeadingInsideAQuoteIsNotASectionAndNeverFolds(): void
    {
        $doc = "> ## Usage\n>\n> quoted body\n\nafter\n";
        $out = (new Renderer(Theme::plain()))->withFolds(['usage'])->render($doc);

        $this->assertStringContainsString('quoted body', $out);
        $this->assertStringNotContainsString(self::MARKER, $out);
    }

    public function testFoldsFollowInlineCodeAndTextInTheHeading(): void
    {
        $out = (new Renderer(Theme::plain()))->withFolds(['run-now'])->render("## Run `now`\n\nhidden\n");

        $this->assertStringNotContainsString('hidden', $out);
        $this->assertStringContainsString(self::MARKER, $out);
    }

    public function testTheStreamFamilyFoldsLikeRender(): void
    {
        $renderer = (new Renderer(Theme::ansi()))->withFolds(['usage', 'intro']);
        $expected = $renderer->render(self::DOC);

        foreach ([1, 7, 64, strlen(self::DOC)] as $size) {
            $chunks = str_split(self::DOC, $size);
            $streamed = implode('', iterator_to_array($renderer->stream($chunks), false));
            $this->assertSame($expected, $streamed, "chunk size {$size}");
        }
    }

    public function testFoldsSurviveTheBuilderChain(): void
    {
        $renderer = (new Renderer(Theme::plain()))->withFolds(['usage'])->withWordWrap(20)->withHyperlinks(false);

        $this->assertSame(['usage'], $renderer->folds);
        $this->assertStringContainsString(self::MARKER, $renderer->render(self::DOC));
    }

    public function testHeadingIdLowercasesAndHyphenatesTheVisibleText(): void
    {
        $this->assertSame('usage', Renderer::headingId('Usage'));
        $this->assertSame('hello-world', Renderer::headingId('Hello, World!'));
        $this->assertSame('multi-space', Renderer::headingId("  --Multi \t  Space--  "));
        $this->assertSame('123-go', Renderer::headingId('123 Go'));
        $this->assertSame('', Renderer::headingId('!!!'));
        $this->assertSame('', Renderer::headingId(''));
    }

    public function testHeadingIdKeepsUnicodeLettersAndDigits(): void
    {
        $this->assertSame('café-ünïcode', Renderer::headingId('Café Ünïcode'));
        $this->assertSame('日本語-見出し', Renderer::headingId('日本語 見出し'));
    }

    public function testFoldIdsFollowTheVisibleTextWhenEmojiExpands(): void
    {
        // `:rocket: Launch` renders as "🚀 Launch", so the reader's ID is
        // headingId('🚀 Launch') = 'launch'. The source literal would yield
        // 'rocket-launch', and folding by the visible ID must still hide the body.
        $doc = "## :rocket: Launch\n\nHidden body.\n\n## Next\n\nShown body.\n";
        $renderer = (new Renderer(Theme::plain()))->withEmoji(true);

        $this->assertSame('launch', Renderer::headingId('🚀 Launch'));
        $out = $renderer->withFolds([Renderer::headingId('🚀 Launch')])->render($doc);
        $this->assertStringNotContainsString('Hidden body.', $out);
        $this->assertStringContainsString('Shown body.', $out);

        $bySource = $renderer->withFolds(['rocket-launch'])->render($doc);
        $this->assertStringContainsString('Hidden body.', $bySource, 'the source spelling is not the visible ID');
    }

    public function testFoldIdsWithoutEmojiStillUseTheSourceText(): void
    {
        $out = (new Renderer(Theme::plain()))->withFolds(['rocket-launch'])->render("## :rocket: Launch\n\nHidden body.\n");
        $this->assertStringNotContainsString('Hidden body.', $out);
    }

    public function testAHeadingWithASoftBreakFoldsByItsVisibleText(): void
    {
        // The break shows as a word gap, so the visible heading is "Foo Bar".
        $out = (new Renderer(Theme::plain()))
            ->withFolds(['foo-bar'])
            ->render("Foo\nBar\n===\n\nHidden body.\n\n# Next\n");

        $this->assertStringNotContainsString('Hidden body.', $out);
        $this->assertStringContainsString('Next', $out);
    }

    public function testAHeadingWithInlineHtmlFoldsByItsVisibleText(): void
    {
        // Raw inline HTML renders as written, tags included, so the ID carries them.
        $out = (new Renderer(Theme::plain()))
            ->withFolds(['foo-span-bar-span'])
            ->render("# Foo <span>bar</span>\n\nHidden body.\n\n# Next\n");

        $this->assertStringNotContainsString('Hidden body.', $out);
    }

    public function testFoldMarkerIsLocalised(): void
    {
        // The marker is catalogued, so no locale string is hard-coded here.
        $this->assertSame(\SugarCraft\Markdown\Lang::t('fold.marker'), self::MARKER);
    }
}
