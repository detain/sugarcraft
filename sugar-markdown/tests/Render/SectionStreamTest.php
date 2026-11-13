<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests\Render;

use League\CommonMark\Reference\Reference;
use League\CommonMark\Reference\ReferenceInterface;
use League\CommonMark\Reference\ReferenceMap;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Markdown\Render\SectionStream;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

/**
 * {@see SectionStream}: parser-confirmed boundaries and link reference
 * definitions carried across sections (audits 15b-30, 15b-31).
 */
final class SectionStreamTest extends TestCase
{
    private function renderer(): Renderer
    {
        return new Renderer(Theme::plain());
    }

    /** @param list<string> $bodies */
    private function assertRendersLike(string $doc, array $bodies): void
    {
        $this->assertSame($this->renderer()->render($doc), rtrim(implode('', $bodies), "\n"));
    }

    public function testASectionWaitsForTheDefinitionItsReferenceNeeds(): void
    {
        $stream = new SectionStream($this->renderer());

        $this->assertSame([], $stream->push("See [x][a].\n\n# H\n\n"), 'an unresolved reference must not be answered');

        $released = $stream->push("[a]: https://e.x\n\n# I\n");
        $this->assertCount(2, $released, 'the definition releases the held section and the one after it');
        $this->assertStringContainsString('https://e.x', $released[0]);

        $this->assertRendersLike(
            "See [x][a].\n\n# H\n\n[a]: https://e.x\n\n# I\n",
            [...$released, ...$stream->finish()],
        );
    }

    public function testAnUndefinedReferenceIsHeldToTheEndAndStaysLiteral(): void
    {
        $doc = "See [nope].\n\n# H\n\nbody\n\n# I\n\nmore\n";
        $stream = new SectionStream($this->renderer());

        $this->assertSame([], $stream->push($doc), 'later sections wait behind the held one');
        $bodies = $stream->finish();

        $this->assertCount(3, $bodies);
        $this->assertStringContainsString('[nope]', $bodies[0]);
        $this->assertRendersLike($doc, $bodies);
    }

    public function testAReferenceToAnEarlierDefinitionResolves(): void
    {
        $doc = "[a]: https://e.x\n\n# H\n\nSee [x][a].\n\n# I\n";
        $stream = new SectionStream($this->renderer());

        $bodies = $stream->push($doc);
        $this->assertNotSame([], $bodies);
        $this->assertStringContainsString('https://e.x', implode('', $bodies));
        $this->assertRendersLike($doc, [...$bodies, ...$stream->finish()]);
    }

    public function testTheFirstDefinitionOfALabelWins(): void
    {
        $doc = "# One\n\n[a]: https://first.x\n\n# Two\n\n[A]: https://second.x\n\nSee [a].\n";
        $stream = new SectionStream($this->renderer());

        $all = implode('', [...$stream->push($doc), ...$stream->finish()]);

        $this->assertStringContainsString('https://first.x', $all);
        $this->assertStringNotContainsString('https://second.x', $all);
        $this->assertRendersLike($doc, [$all]);
    }

    public function testABoundaryTheParserRefusesMergesIntoTheNextSection(): void
    {
        // The scanner closes the fence at the outdented "```", but the parser
        // ends the list item there and opens a new fence, so "# H" is code.
        $doc = "- a\n  ```\n  x\n```\n\n# H\n\ny\n";
        $stream = new SectionStream($this->renderer());

        $this->assertSame([], $stream->push($doc));
        $bodies = $stream->finish();

        $this->assertCount(1, $bodies);
        $this->assertRendersLike($doc, $bodies);
    }

    public function testAHeadingAfterAClosingFenceStartsANewSection(): void
    {
        $stream = new SectionStream($this->renderer());

        $this->assertCount(1, $stream->push("```\ncode\n```\n# H\n"));
    }

    public function testAnUnterminatedHeadingKeepsTheSectionBeforeIt(): void
    {
        $stream = new SectionStream($this->renderer());

        $this->assertSame([], $stream->push("Intro\n\n# F"));
        $this->assertRendersLike("Intro\n\n# F", $stream->finish());
    }

    public function testACloneFinishesWithoutDisturbingTheOriginal(): void
    {
        $doc = "# A\n\nSee [r].\n\n# B\n\ntext\n\n# C\n\n[r]: https://r.x\n\ntail";
        $stream = new SectionStream($this->renderer());
        $bodies = [];
        $fed = '';

        foreach (str_split($doc, 9) as $piece) {
            $fed .= $piece;
            array_push($bodies, ...$stream->push($piece));
            // Every frame of a growing reply: what is answered plus a preview
            // of the rest renders like the whole prefix.
            $this->assertRendersLike($fed, [...$bodies, ...(clone $stream)->finish()]);
        }
        array_push($bodies, ...$stream->finish());

        $this->assertRendersLike($doc, $bodies);
    }

    public function testABracketWithNoOpenerHoldsNothing(): void
    {
        // A `]` with no `[` before it in its block can never become a link,
        // so it must not stall every section behind it (crush_libs shine #1). @rename-keep historical provenance: crush_libs audit citation
        $doc = "# A\n\nx] and y]\n\n# B\n\ntwo\n\n# C\n";
        $stream = new SectionStream($this->renderer());

        $released = $stream->push($doc);
        $this->assertCount(2, $released);
        $this->assertRendersLike($doc, [...$released, ...$stream->finish()]);
    }

    public function testABracketPairAcrossInlineNodesStillHolds(): void
    {
        // `[a *b*]` splits into Text `[a `, Emphasis, Text `]`: still a
        // shortcut-reference candidate a later definition can resolve.
        $stream = new SectionStream($this->renderer());

        $this->assertSame([], $stream->push("See [a *b*].\n\n# H\n\n"));
        $released = $stream->push("[a *b*]: https://e.x\n\n# I\n");
        $this->assertCount(2, $released);
        $this->assertStringContainsString('https://e.x', $released[0]);
    }

    public function testBracketsInSeparateBlocksDoNotPair(): void
    {
        $doc = "# A\n\nopen [ here\n\nclose ] there\n\n# B\n";
        $stream = new SectionStream($this->renderer());

        $this->assertCount(1, $stream->push($doc));
    }

    public function testAHeldSectionIsRenderedOnceNotPerPreview(): void
    {
        // A held section keeps its body: a preview (clone + finish) of a
        // long reply must not re-render it, and every section queued
        // behind it, on every frame (crush_libs shine #1). @rename-keep historical provenance: crush_libs audit citation
        $doc = "# A\n\nsee array[0]\n\n# B\n\ntwo\n\n# C\n";
        $stream = new SectionStream($this->renderer());
        $this->assertSame([], $stream->push($doc));

        $queue = (new \ReflectionProperty(SectionStream::class, 'queue'))->getValue($stream);
        $this->assertCount(2, $queue);
        $this->assertTrue($queue[0]['held']);
        $this->assertSame(
            $this->renderer()->renderSection("# A\n\nsee array[0]\n\n"),
            $queue[0]['body'],
            'the held body is rendered when the section is confirmed',
        );
        $this->assertRendersLike($doc, $stream->preview());
    }

    public function testADefinitionInTheOpenTailResolvesAHeldSection(): void
    {
        $doc = "# A\n\nsee [r]\n\n# B\n\n[r]: https://r.x";
        $stream = new SectionStream($this->renderer());
        $this->assertSame([], $stream->push($doc));

        $preview = $stream->preview();
        $this->assertStringContainsString('https://r.x', $preview[0], 'a cached body is not reused once a later definition exists');
        $this->assertRendersLike($doc, $preview);
    }

    /**
     * A reply whose sections each end in a list streams section by section:
     * each one is answered as soon as the next heading arrives, instead of
     * the whole reply staying one open tail re-rendered every frame.
     */
    public function testSectionsEndingInListsAreAnsweredIncrementally(): void
    {
        $stream = new SectionStream($this->renderer());

        $this->assertSame([], $stream->push("# A\n\n- one\n- two\n\n"));
        $first = $stream->push("# B\n\n1. three\n\n");
        $this->assertCount(1, $first);
        $this->assertStringContainsString('two', $first[0]);
        $second = $stream->push("# C\n\n- four\n");
        $this->assertCount(1, $second);
        $this->assertStringContainsString('three', $second[0]);

        $this->assertRendersLike(
            "# A\n\n- one\n- two\n\n# B\n\n1. three\n\n# C\n\n- four\n",
            [...$first, ...$second, ...$stream->finish()],
        );
    }

    public function testAHeldLadderStreamsWithoutQuadraticReRenders(): void
    {
        // FUZZ-01 (#95, with #94/#99/#103 lineage): K held sections whose
        // labels a later definition resolves one by one used to re-render
        // EVERY still-held entry on EVERY definition event — Θ(K²) parses.
        // K=1600 measured 61.3s against render()'s 0.5s before the
        // per-entry watch-list fix; a held section now only re-renders when
        // a definition names one of its own unresolved labels. The cap is
        // machine-relative (the absolute term fails the quadratic shape on
        // any box while leaving the fixed path a 10x margin); SM-S07 then
        // narrowed the per-section definition seeding to the section's own
        // bracket spans, so the factor left over render() is the held-entry
        // retry machinery — the 8x cap keeps SM-S07's narrowing (13x before
        // it) and SM-S01's watch lists (120x before those) both pinned out.
        $renderer = $this->renderer();
        $doc = '';
        for ($i = 0; $i < 1600; $i++) {
            $doc .= "# S$i\n\nsee [q$i]\n\n";
        }
        for ($i = 0; $i < 1600; $i++) {
            $doc .= "# D$i\n\n[q$i]: /u$i\n\n";
        }

        $start = microtime(true);
        $rendered = $renderer->render($doc);
        $renderTime = microtime(true) - $start;

        $start = microtime(true);
        $streamed = '';
        foreach ($renderer->stream(str_split($doc, 600)) as $body) {
            $streamed .= $body;
        }
        $streamTime = microtime(true) - $start;

        $this->assertSame($rendered, $streamed, 'the ladder streams byte-identical to render()');
        $this->assertLessThan(max(4.0, 8 * $renderTime), $streamTime, 'unrelated definitions must not re-render or re-seed held sections');
    }

    public function testABracketlessStreamRunsNearRenderParity(): void
    {
        // SM-S07: 800 bracket-free sections over a trailing block of 300
        // definitions. Each section parse used to copy the whole seed map
        // into its fresh environment — O(sections x defs) of pure seeding
        // work; narrowed to each section's own bracket spans the copy set
        // is empty and the stream runs at render() parity.
        $renderer = $this->renderer();
        $doc = '';
        for ($i = 0; $i < 800; $i++) {
            $doc .= "# S$i\n\nprose section without any references\n\n";
        }
        for ($i = 0; $i < 300; $i++) {
            $doc .= "# D$i\n\n[q$i]: /u$i\n\n";
        }

        $start = microtime(true);
        $rendered = $renderer->render($doc);
        $renderTime = microtime(true) - $start;

        $start = microtime(true);
        $streamed = '';
        foreach ($renderer->stream(str_split($doc, 600)) as $body) {
            $streamed .= $body;
        }
        $streamTime = microtime(true) - $start;

        $this->assertSame($rendered, $streamed, 'a bracket-free ladder streams byte-identical to render()');
        $this->assertLessThan(max(2.0, 3 * $renderTime), $streamTime, 'bracket-free sections must not pay for the document definition count');
    }

    public function testAPreviewLadderStaysNearLinearAndByteExact(): void
    {
        // SM-S15: the chat tail-preview pattern — one heading, then every
        // token chunk lands in the still-open section, each frame previewed.
        // Since the scanner keeps the open section as a line list, pushes
        // after a clone no longer copy the shared bytes whole; what remains
        // per frame is the M4 tail replay. Best-of-two at n=400/800 must
        // stay under 2.5x per doubling (2.11x measured here) and every
        // 64th frame must answer the byte-exact whole prefix.
        $renderer = $this->renderer();

        $bodies = '';
        $prefix = "# Head\n\n";
        $stream = new SectionStream($renderer);
        $stream->push($prefix);

        for ($i = 0; $i < 800; $i++) {
            $token = 'tok' . $i . ' ';
            $bodies .= implode('', $stream->push($token));
            $prefix .= $token;
            if ($i % 64 === 63) {
                $this->assertRendersLike($prefix, [$bodies, ...$stream->preview()]);
            }
        }
        $bodies .= implode('', $stream->finish());
        // The finish() answer must complete the released bodies exactly.
        $this->assertRendersLike($prefix, [$bodies]);

        // Twice at each size so the 2.5x bound can only trip on a real
        // superlinear regression, not on jitter between cold pages.
        $best400 = min($this->timePreviewLadder(400), $this->timePreviewLadder(400));
        $best800 = min($this->timePreviewLadder(800), $this->timePreviewLadder(800));
        $this->assertLessThan(2.5 * $best400, $best800, 'doubling the previewed token stream must not more-than-2.5x the wall time');
    }

    /** Push one heading, then $n token chunks into the open section, previewing each frame; wall time in ms. */
    private function timePreviewLadder(int $n): float
    {
        $stream = new SectionStream($this->renderer());
        $stream->push("# Head\n\n");

        $start = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $stream->push('tok' . $i . ' ');
            $stream->preview();
        }
        $stream->finish();

        return (hrtime(true) - $start) / 1e6;
    }

    public function testMultiLineLabelResolvesAcrossStreamedSections(): void
    {
        // End-to-end SM-S07: a label defined across lines in one section
        // and used across lines (and case/whitespace-folded) in the next
        // must still resolve — the narrowing keys lookups through the
        // same normalizer the reference map itself uses.
        $renderer = $this->renderer();
        $doc = "[Foo\nBar]: /url\n\nSee [foo   \nbar].\n\n# X\n";

        $streamed = '';
        foreach ($renderer->stream(str_split($doc, 400)) as $body) {
            $streamed .= $body;
        }

        $this->assertSame($renderer->render($doc), $streamed, 'the folded label streams byte-identical to render()');
        $this->assertStringContainsString('/url', $streamed, 'the cross-section reference resolved');
    }

    /** @return list<ReferenceInterface> */
    private function narrow(string $section, ReferenceMap $seed): array
    {
        return (new ReflectionMethod(Renderer::class, 'narrowSeed'))->invoke(null, $section, $seed);
    }

    private function seedWith(string ...$labels): ReferenceMap
    {
        $map = new ReferenceMap();
        foreach ($labels as $label) {
            $map->add(new Reference($label, '/u/' . rawurlencode($label), ''));
        }

        return $map;
    }

    /** @param list<ReferenceInterface> $kept */
    private static function labelsOf(array $kept): array
    {
        $labels = array_map(static fn (ReferenceInterface $r): string => $r->getLabel(), $kept);
        sort($labels);

        return $labels;
    }

    public function testNarrowSeedKeepsOnlyLabelsTheSectionCanResolve(): void
    {
        $kept = $this->narrow("See [foo   \nbar] and [a&b] here.\n", $this->seedWith('Foo Bar', 'unused', 'a&b'));
        $this->assertSame(
            ['Foo Bar', 'a&b'],
            self::labelsOf($kept),
            'case/whitespace-folded spans and literal-ampersand labels resolve while unrelated definitions drop',
        );
    }

    public function testNarrowSeedCoversCollapsedFullFormAndEscapes(): void
    {
        $kept = $this->narrow("![img][L1] plus \\[no\\] but [real]\n", $this->seedWith('L1', 'no', 'real'));
        $this->assertSame(
            ['L1', 'real'],
            self::labelsOf($kept),
            'the full-form second span resolves; an escaped bracket pair is not a label',
        );
    }

    public function testNarrowSeedHandlesNumericLabels(): void
    {
        // A prose indexer over-matches the span '0'; numeric labels must
        // survive candidate dedup — PHP casts numeric-string array keys to
        // int, and an int reaching ReferenceMap::get() is a TypeError.
        $kept = $this->narrow("\$array[0] is fine, see [0].\n", $this->seedWith('0'));
        $this->assertCount(1, $kept);
        $this->assertSame('0', $kept[0]->getLabel());
    }

    public function testNarrowSeedSkipsOverlongCandidates(): void
    {
        // A label may not exceed 999 characters, so an overlong span can
        // never hit — skipping it must not disturb the spans that can.
        $long = str_repeat('x', 1000);
        $kept = $this->narrow("[$long] and [short]\n", $this->seedWith('short'));
        $this->assertSame(['short'], self::labelsOf($kept));
    }

    public function testNarrowSeedFallsBackToFullSeedOnDenseText(): void
    {
        $section = '';
        for ($i = 0; $i < 5000; $i++) {
            $section .= "[z$i]";
        }
        $kept = $this->narrow($section . "\n", $this->seedWith('a', 'b'));
        $this->assertCount(2, $kept, 'past the span guard the full map seeds, as before narrowing');
    }

    public function testNarrowSeedIsEmptyOnBracketlessText(): void
    {
        $this->assertSame([], $this->narrow("nothing referenced here\n", $this->seedWith('a')));
    }
}
