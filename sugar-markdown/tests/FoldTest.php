<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Render\SectionScanner;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

/**
 * Heading folds: a folded heading keeps its line and drops its body, up to
 * the next heading at its level or above. Both render() and the streaming
 * family read the same scanner, so the two must agree.
 */
final class FoldTest extends TestCase
{
    private const DOC = "# Intro\n\nkeep me\n\n## Install\n\nhidden one\n\n### Deep\n\nhidden two\n\n## Usage\n\nshown\n\n# Next\n\nshown too\n";

    private function plain(string $markdown, array $folds = []): string
    {
        return (new Renderer(Theme::plain(), null, folds: $folds))->render($markdown);
    }

    public function testFoldDropsItsBodyAndDeeperSubheadings(): void
    {
        $out = $this->plain(self::DOC, ['install']);

        $this->assertStringContainsString('Install', $out);
        $this->assertStringNotContainsString('hidden one', $out);
        $this->assertStringNotContainsString('Deep', $out, 'a deeper heading inside the body is dropped with it');
        $this->assertStringNotContainsString('hidden two', $out);
        $this->assertStringContainsString('Usage', $out, 'a sibling heading at the same level ends the fold');
        $this->assertStringContainsString('shown', $out);
    }

    public function testFoldAtTopLevelRunsToTheNextTopLevelHeading(): void
    {
        $out = $this->plain(self::DOC, ['intro']);

        $this->assertStringContainsString('Intro', $out);
        $this->assertStringNotContainsString('keep me', $out);
        $this->assertStringNotContainsString('Usage', $out, 'the fold covers the level-2 sections under Intro');
        $this->assertStringContainsString('Next', $out);
        $this->assertStringContainsString('shown too', $out);
    }

    public function testUnfoldRendersEverySection(): void
    {
        $renderer = new Renderer(Theme::plain());

        $this->assertSame($renderer->render(self::DOC), $renderer->withFolds(['install'])->withFolds([])->render(self::DOC));
        $this->assertSame([], $renderer->withFolds(['install'])->withFolds([])->folds);
    }

    public function testFoldIdsAreNormalisedTheSameWayAsHeadings(): void
    {
        $this->assertSame(['install'], (new Renderer(Theme::plain()))->withFolds(['Install'])->folds);
        $this->assertSame(
            $this->plain(self::DOC, ['install']),
            $this->plain(self::DOC, ['  INSTALL  ']),
        );
    }

    public function testBlankAndDuplicateFoldIdsAreDropped(): void
    {
        $this->assertSame(['install'], (new Renderer(Theme::plain()))->withFolds(['', 'install', 'Install', '#'])->folds);
    }

    public function testAHeadingInsideFencedCodeIsNeverAFold(): void
    {
        $doc = "```\n# Install\nx\n```\n\n## Install\n\nbody\n";
        $out = $this->plain($doc, ['install']);

        $this->assertStringContainsString('x', $out, 'the fenced line is code, not a heading that ends a fold');
        $this->assertStringNotContainsString('body', $out, 'the real heading still folds its body');
    }

    public function testClosingHashesAreNotPartOfTheId(): void
    {
        $doc = "## Install ##\n\nhidden\n\n## Usage\n\nshown\n";
        $out = $this->plain($doc, ['install']);

        $this->assertStringNotContainsString('hidden', $out);
        $this->assertStringContainsString('shown', $out);
    }

    public function testStreamingAgreesWithRenderAtEveryChunkSize(): void
    {
        $renderer = new Renderer(Theme::plain(), null, folds: ['install']);
        $whole = $renderer->render(self::DOC);

        foreach ([1, 3, 7, 64] as $size) {
            $streamed = implode('', iterator_to_array($renderer->stream(str_split(self::DOC, $size)), false));
            $this->assertSame($whole, $streamed, "chunk size {$size}");
        }
    }

    /**
     * Shapes that stress the boundary rules inside a fold: a heading with no
     * blank line before it, a fold closed by a non-boundary heading, fences
     * that hide `#` lines, and a folded heading that is the last line.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function streamingShapes(): iterable
    {
        yield 'no blank before fold end' => ["## Install\nbody\n## Usage\nshown\n", ['install']];
        yield 'fence hides a heading' => ["## Install\n```\n# x\n```\nbody\n\n## Usage\nshown\n", ['install']];
        yield 'folded heading last' => ["# Intro\n\nkeep\n\n## Install", ['install']];
        yield 'nested folds' => ["# A\n\n## B\n\n### C\n\nx\n\n# D\n\ny\n", ['a', 'c']];
        yield 'two folds at one level' => ["## One\n\na\n\n## Two\n\nb\n\n## Three\n\nc\n", ['one', 'three']];
    }

    /** @dataProvider streamingShapes */
    public function testStreamingAgreesWithRenderOnBoundaryShapes(string $doc, array $folds): void
    {
        $renderer = new Renderer(Theme::plain(), null, folds: $folds);
        $whole = $renderer->render($doc);

        foreach ([1, 2, 5, 100] as $size) {
            $streamed = implode('', iterator_to_array($renderer->stream(str_split($doc, $size)), false));
            $this->assertSame($whole, $streamed, "chunk size {$size}");
        }
    }

    public function testApplyFoldsWithoutFoldsIsIdentityOnTerminatedDocuments(): void
    {
        $doc = "a\n\n\n# B\nc\n\n";

        $this->assertSame($doc, SectionScanner::applyFolds($doc, []));
    }

    public function testHeadingIdAnswersOnlyForAtxHeadings(): void
    {
        $this->assertSame('hello-world', SectionScanner::headingId('# Hello, World!'));
        $this->assertSame('ünïcode-title', SectionScanner::headingId('## Ünïcode Title'));
        $this->assertSame('', SectionScanner::headingId('#'));
        $this->assertNull(SectionScanner::headingId('#nospace'));
        $this->assertNull(SectionScanner::headingId('####### seven'));
        $this->assertNull(SectionScanner::headingId('plain text'));
    }
}
