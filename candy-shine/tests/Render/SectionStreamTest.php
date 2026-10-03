<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Render\SectionStream;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;

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
        // so it must not stall every section behind it (crush_libs shine #1).
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
        // behind it, on every frame (crush_libs shine #1).
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
        $this->assertRendersLike($doc, (clone $stream)->finish());
    }

    public function testADefinitionInTheOpenTailResolvesAHeldSection(): void
    {
        $doc = "# A\n\nsee [r]\n\n# B\n\n[r]: https://r.x";
        $stream = new SectionStream($this->renderer());
        $this->assertSame([], $stream->push($doc));

        $preview = (clone $stream)->finish();
        $this->assertStringContainsString('https://r.x', $preview[0], 'a cached body is not reused once a later definition exists');
        $this->assertRendersLike($doc, $preview);
    }
}
