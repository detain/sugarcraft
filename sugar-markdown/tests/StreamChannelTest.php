<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use SugarCraft\Markdown\Writer;
use SugarCraft\Style\Style;

/**
 * Pins for the E736 7.1/7.3 stream channel (salvage of the x7 lane):
 * {@see Renderer::stream()} and {@see Renderer::write()}.
 *
 * The load-bearing law is chunk-split invariance: for every split of the
 * same input bytes, implode(stream(splits)) === render(bytes) byte-for-
 * byte. The 1-byte-split legs additionally prove a codepoint can never be
 * corrupted at a chunk seam (a partial UTF-8 sequence can never contain a
 * 0x0A byte, so the line scanner is byte-safe by construction).
 */
final class StreamChannelTest extends TestCase
{
    private function plain(): Renderer
    {
        return new Renderer(Theme::plain());
    }

    /** @return array<string, array{string}> */
    public static function corpus(): array
    {
        return [
            'headings'      => ["# Alpha\n\npara one\n\n# Beta\n\npara two\n"],
            'para-only'     => ["just a paragraph\n\nand another\n"],
            'fence-hash'    => ["# Top\n\n```text\n# fake heading\n```\n\n# Second\n\nbody\n"],
            'quote-refuse'  => ["q\n\n> quote\n\n# AfterQuote\n"],
            'utf8'          => ["# 見出し\n\n日本語テキスト 🚀 combining: é\n\n# Two\n"],
            'glued-heading' => ["# Top\n\npara\n# glued\n"],
            'table-refuse'  => ["# Top\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n# After\n"],
            'list-heading'  => ["# Top\n\n- one\n- two\n\n# After\n"],
            // A heading after a list item is a cut; every list shape must
            // still render byte-identically on its own.
            'list-loose'    => ["# A\n\n- one\n\n- two\n\n# B\n\n- three\n"],
            'list-ordered'  => ["# A\n\n1. one\n2. two\n\n# B\n\n3) x\n"],
            'list-nested'   => ["# A\n\n- one\n  - two\n    - three\n\n# B\n\ntext\n"],
            'list-fenced'   => ["- a\n  ```\n  x\n  ```\n\n# H\n\n- b\n"],
            'list-tasks'    => ["# A\n\n- [ ] todo\n- [x] done\n\n# B\n\n- [ ] more\n"],
            'list-ref'      => ["- see [x][a]\n\n# H\n\n- [a]: https://e.x\n\n[a]: https://e.x\n"],
            'list-lazy'     => ["- a\nlazy\n\n# H\n"],
            'list-empty'    => ["-\n\n# H\n\n*\n"],
            'indent-refuse' => ["# Top\n\n    code\n\n# After\n"],
            'raw-html'      => ["# Top\n\n<pre>\n# inside\n</pre>\n\n# After\n"],
            'trailing-run'  => ["# Top\n\nbody\n\n\n"],
            // Audit 15b-30: link reference definitions cross sections.
            'ref-forward'   => ["See [x][a].\n\n# H\n\n[a]: https://e.x\n"],
            'ref-backward'  => ["[a]: https://e.x\n\n# H\n\nSee [x][a].\n"],
            'ref-first-wins' => ["# One\n\n[a]: https://first.x\n\n# Two\n\n[a]: https://second.x\n\nSee [a].\n"],
            'ref-in-quote'  => ["See ![i][b].\n\n# H\n\n> [b]: https://q.x\n"],
            // Audit 15b-31: the unterminated-heading tail, and headings
            // straight after a closing fence.
            'unterminated-heading' => ["Intro\n\n# F"],
            'fence-glued-heading'  => ["```php\necho 1;\n```\n## Next\n\ntext\n```\nx\n```\n# Last"],
            // Lines the scanner could misread; the parser confirms each cut.
            'html-comment'  => ["<!--\n\n# H\n-->\n\n# After\n"],
            'backtick-info' => ["```a`b\n```\n\n# H\n"],
            'outdented-fence-close' => ["- a\n  ```\n  x\n```\n\n# H\n\ny\n"],
            // MD-1/MD-2 (M5 arbitration): the exact bytes of the contested
            // tui-arch repro — a paragraph holding TWO unresolved refs whose
            // definition arrives only after the blank-line hold. Exhaustive
            // byte-width splits run over every corpus row above.
            'held-ref-double-token' => ["see [a] now [a] now\n\n[a]: /url\n"],
            'ref-straddling-heading' => ["Intro [q] here\n\n# H\n[q]: /u\n\nTrailing [q] again\n"],
        ];
    }

    /** @return array<string, array{string, int, bool}> */
    public static function splitCorpus(): array
    {
        $rows = [];
        foreach (self::corpus() as $name => [$doc]) {
            foreach ([1, 2, 3, 7, 13, 64, PHP_INT_MAX] as $width) {
                foreach ([false, true] as $ansi) {
                    $rows["$name/width-$width/" . ($ansi ? 'ansi' : 'plain')] = [$doc, $width, $ansi];
                }
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private static function splitBytes(string $document, int $width): array
    {
        return $width >= strlen($document) ? [$document] : str_split($document, $width);
    }

    /** @param iterable<string> $chunks */
    private static function collect(Renderer $renderer, iterable $chunks): array
    {
        $out = [];
        foreach ($renderer->stream($chunks) as $chunk) {
            $out[] = $chunk;
        }

        return $out;
    }

    #[DataProvider('splitCorpus')]
    public function testStreamIsByteIdenticalToRenderForEveryChunkSplit(string $doc, int $width, bool $ansi): void
    {
        $renderer = new Renderer($ansi ? Theme::ansi() : Theme::plain());

        $this->assertSame(
            $renderer->render($doc),
            implode('', self::collect($renderer, self::splitBytes($doc, $width))),
        );
    }

    public function testStreamSplitsMidCodepointWithoutCorruption(): void
    {
        // Rocket + CJK + combining accent, fed ONE BYTE at a time: the only
        // split shape that could corrupt a character if the channel ever
        // reassembled partial codepoints wrongly.
        $doc      = "# 🚀 見出し\n\ntext é\n\n# done\n";
        $renderer = $this->plain();
        $glued    = implode('', self::collect($renderer, str_split($doc, 1)));

        $this->assertSame($renderer->render($doc), $glued);
        $this->assertStringNotContainsString("\u{FFFD}", $glued);
    }

    public function testHeadingBoundaryStreamsTrueSectionsWithExactCarryRuns(): void
    {
        $chunks = self::collect($this->plain(), ["# Alpha\n\npara one\n\n# Beta\n\npara two\n"]);

        $this->assertSame(
            ['# Alpha' . "\n\n" . 'para one', "\n\n", '# Beta' . "\n\n" . 'para two'],
            $chunks,
            'the blank-line run between sections is carried to the next head; the final run is dropped exactly as render() rtrims it',
        );
    }

    /**
     * Audit 15b-30: a reference in one section and its definition in a later
     * one used to stay literal `[x][a]` text in stream() while render()
     * resolved it.
     */
    public function testAReferenceDefinedInALaterSectionResolves(): void
    {
        $t        = "See [x][a].\n\n# H\n\n[a]: https://e.x\n";
        $renderer = $this->plain();
        $streamed = implode('', iterator_to_array($renderer->stream([$t]), false));

        $this->assertSame($renderer->render($t), $streamed);
        $this->assertStringNotContainsString('[x][a]', $streamed);
    }

    /**
     * Audit 15b-31: an unterminated last line that is a heading used to
     * throw away the section it closed.
     */
    public function testAnUnterminatedHeadingKeepsTheSectionBeforeIt(): void
    {
        $renderer = $this->plain();

        $this->assertSame(
            $renderer->render("Intro\n\n# F"),
            implode('', self::collect($renderer, ["Intro\n\n# F"])),
        );
    }

    public function testParagraphOnlyDocumentEmitsSingleChunk(): void
    {
        $chunks = self::collect($this->plain(), ["para one\n\npara two\n"]);

        $this->assertCount(1, $chunks);
        $this->assertSame("para one\n\npara two", $chunks[0]);
    }

    /**
     * The defensive refusals: a heading preceded by a blank line is NOT cut
     * when the previous content line was a quote / indented-code / table
     * row. The identity sweep cannot see this (the cut would also be
     * byte-safe), so the chunk SHAPE is the pin that holds the published
     * law of stream().
     *
     * @return array<string, array{string, int}>
     */
    public static function refusalCorpus(): array
    {
        return [
            'glued-heading-is-not-a-boundary' => ["# Top\n\npara\n# glued\n", 1],
            'after-quote-refused'             => ["q\n\n> quote\n\n# AfterQuote\n", 1],
            // A heading after a list item is a cut: a reply whose
            // sections each end in a list must not stream as one tail.
            'after-list-cuts'                 => ["# Top\n\n- one\n\n# After\n", 3],
            'after-indented-code-refused'     => ["# Top\n\n    code\n\n# After\n", 1],
            'after-table-refused'             => ["# Top\n\n| a |\n|---|\n| 1 |\n\n# After\n", 1],
            'bare-heading-twin-cuts'          => ["# Top\n\nprose\n\n# After\n", 3],
            // Audit 15b-31: a heading right under a closing fence is a cut.
            'heading-after-fence-cuts'        => ["```\ncode\n```\n# After\n", 3],
            // The parser refuses the scanner's cut: "# H" is inside the
            // fence the outdented "```" opens.
            'refused-cut-holds'               => ["- a\n  ```\n  x\n```\n\n# H\n", 1],
        ];
    }

    #[DataProvider('refusalCorpus')]
    public function testDefensiveRefusalsHoldThePublishedChunkShape(string $doc, int $expectedChunks): void
    {
        $this->assertCount(
            $expectedChunks,
            self::collect($this->plain(), str_split($doc, 3)),
        );
    }

    /** @return array<string, array{Theme}> */
    public static function documentScopedThemes(): array
    {
        $s = Style::new();
        $make = static fn(array $overrides): Theme => new Theme(
            $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s,
            ...$overrides,
        );

        return [
            'indent' => [$make(['documentIndent' => 2])],
            'margin' => [$make(['documentMargin' => 1])],
            'prefix' => [$make(['documentBlockPrefix' => '<'])],
            'suffix' => [$make(['documentBlockSuffix' => '>'])],
        ];
    }

    #[DataProvider('documentScopedThemes')]
    public function testDocumentScopeAffixesForceBufferedSingleChunk(Theme $theme): void
    {
        $doc = "# Top\n\none\n\n# Second\n\ntwo\n";

        $chunks = self::collect(new Renderer($theme), str_split($doc, 5));

        $this->assertCount(1, $chunks, 'document-scope post-processing must wrap the whole stream exactly once');
        $this->assertSame((new Renderer($theme))->render($doc), $chunks[0]);
    }

    public function testPreservedNewLinesForceBufferedSingleChunk(): void
    {
        $doc      = "# Top\n\none\n\n\n# Second\n";
        $renderer = $this->plain()->withPreservedNewLines(true);

        $chunks = self::collect($renderer, str_split($doc, 4));

        $this->assertCount(1, $chunks);
        $this->assertSame($renderer->render($doc), $chunks[0]);
    }

    public function testEmptyInputEmitsNothing(): void
    {
        $this->assertSame([], self::collect($this->plain(), []));
        $this->assertSame([], self::collect($this->plain(), ['']));
        $this->assertSame([], self::collect($this->plain(), ["   \n\n\n"]));
    }

    public function testStreamConsumesInputLazily(): void
    {
        $pulls  = 0;
        $source = (function () use (&$pulls): \Generator {
            $i = 0;
            while (true) {
                $pulls++;
                yield "# H$i\n\n";
            }
        })();

        $seen = 0;
        foreach ($this->plain()->stream($source) as $chunk) {
            $this->assertNotSame('', $chunk);
            if (++$seen === 3) {
                break;
            }
        }

        $this->assertSame(3, $seen);
        $this->assertLessThanOrEqual(5, $pulls, 'the channel must pull only as far as the yielded sections require');
    }

    public function testWriteReturnsByteCountAndEmitsRenderBytes(): void
    {
        $doc      = "# Title\n\nbody\n";
        $renderer = $this->plain();
        $stream   = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);

        $written  = $renderer->write($doc, $stream);
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame($renderer->render($doc), $contents);
        $this->assertSame(strlen($renderer->render($doc)), $written);
    }

    public function testWriteRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->plain()->write('# doc', 'not-a-resource');
    }

    public function testWriteDoorThrowsBeforeRenderingOnReadOnlyStream(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'y4door');
        $this->assertIsString($path);
        try {
            $readHandle = fopen($path, 'r');
            $this->assertNotFalse($readHandle);

            try {
                $this->plain()->write("# never rendered\n", $readHandle);
                $this->fail('expected InvalidArgumentException from the write() door');
            } catch (\InvalidArgumentException $expected) {
                $this->assertStringContainsString('writable', $expected->getMessage());
            }
            fclose($readHandle);

            // The door fired BEFORE any write attempt: the file stayed empty.
            $this->assertSame(0, (int) filesize($path));
        } finally {
            @unlink($path);
        }
    }

    public function testStreamRejectsNonStringChunks(): void
    {
        $generator = $this->plain()->stream(['# ok', 42]);

        $this->expectException(\InvalidArgumentException::class);
        self::collect($this->plain(), $generator); // lazy: must surface on consumption
    }

    /**
     * MD-2 (M5, option B — probe verdict NO-REPRO, see
     * /tmp/opencode/sugar-docs-reviews-1104/fixes/sugar-markdown-M5-probe.md):
     * the review cited a triple whose bytes sum to
     * "see [a] now [a] now\n\n[a]: /url\n" — NOT the doc it compared against.
     * Faithfully streamed against its own concatenation the invariant holds;
     * this pins the exact split so a regression can never re-bill it as an
     * unfixed bug.
     */
    public function testTheExactTuiArchTripleIsByteIdenticalToItsOwnConcatenation(): void
    {
        $chunks   = ['see [a] no', 'w [a] now' . "\n", "\n[a]: /url\n"];
        $renderer = $this->plain();

        $this->assertSame(
            $renderer->render(implode('', $chunks)),
            implode('', self::collect($renderer, $chunks)),
        );
    }

    /**
     * MD-2: seeded randomized multi-cut sweep over the held-reference shapes
     * the probe family used — definition-before, definition-after, and
     * definition-in-a-third-section, each fed 1..6 random cuts. Deterministic
     * (fixed seeds), so a red here is a real regression, not dice.
     */
    public function testSeededRandomMultiCutsHoldTheByteIdentityInvariant(): void
    {
        $family = [
            "see [a] now [a] now\n\n[a]: /url\n",
            "see [a] now\n\n[a]: /url\n",
            "[a]: /url\n\nsee [a] now\n",
            "# H1\n\nsee [a] now\n\n# H2\n\n[a]: /url\n",
            "text [a]\n\ntext [b]\n\n[a]: /1\n\n[b]: /2\n",
        ];
        $renderer = $this->plain();
        foreach ($family as $seed => $doc) {
            $whole = $renderer->render($doc);
            $len   = strlen($doc);
            for ($trial = 0; $trial < 60; $trial++) {
                mt_srand(1000 * $seed + $trial);
                $cuts = [];
                for ($c = 0, $k = 1 + ($trial % 6); $c < $k; $c++) {
                    $cuts[] = mt_rand(0, $len);
                }
                sort($cuts);
                $pieces = [];
                $prev = 0;
                foreach ($cuts as $cut) {
                    $piece = substr($doc, $prev, $cut - $prev);
                    if ($piece !== '') {
                        $pieces[] = $piece;
                    }
                    $prev = $cut;
                }
                $tailPiece = substr($doc, $prev);
                if ($tailPiece !== '') {
                    $pieces[] = $tailPiece;
                }
                $this->assertSame(
                    $whole,
                    implode('', self::collect($renderer, $pieces)),
                    "seed $seed trial $trial: " . json_encode($pieces),
                );
            }
        }
    }

    /**
     * SM-S20 (MDTEST-06): {@see Renderer::defersStreaming()} both-state legs
     * per knob. Each `||` leg of the predicate gets a theme/option state that
     * is the ONLY reason deferral fires, plus the false-state — dropping or
     * mis-conjoining any single leg flips one of these legs red.
     */
    public function testDefersStreamingReportsBothStatesPerKnob(): void
    {
        $s    = Style::new();
        $make = static fn(array $overrides): Theme => new Theme(
            $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s,
            ...$overrides,
        );

        // False state: nothing set — plain() carries no document-scope
        // affixes, so a streaming renderer must report no deferral.
        self::assertFalse((new Renderer(Theme::plain()))->defersStreaming());
        self::assertFalse(
            (new Renderer(Theme::plain()))->withPreservedNewLines(false)->defersStreaming(),
            'explicitly-off preserved newlines must not defer',
        );

        // True state, one knob at a time.
        $knobs = [
            'block prefix'   => $make(['documentBlockPrefix' => '<']),
            'block suffix'   => $make(['documentBlockSuffix' => '>']),
            'document indent' => $make(['documentIndent' => 2]),
            'document margin' => $make(['documentMargin' => 1]),
        ];
        foreach ($knobs as $label => $theme) {
            self::assertFalse(
                (new Renderer(Theme::plain()))->defersStreaming(),
                "$label alone off: baseline stays streaming",
            );
            self::assertTrue(
                (new Renderer($theme))->defersStreaming(),
                "$label alone must force the buffered single-chunk regime",
            );
        }
        self::assertTrue(
            (new Renderer(Theme::plain()))->withPreservedNewLines(true)->defersStreaming(),
            'preserved newlines must force the buffered single-chunk regime',
        );
    }

    /**
     * SM-S20 (MDTEST-06): {@see Writer::toStream()} written end-to-end over
     * php://memory — the static door was previously only exercised via the
     * constructor with StreamSink::toStream inlined. Both regimes (streaming
     * and deferred) must land the exact render() bytes at close().
     */
    public function testWriterToStreamWritesThroughEndToEnd(): void
    {
        $doc = "# Title\n\nbody one\n\n# Second\n\nbody two\n";

        $cases = [
            'streaming' => new Renderer(Theme::plain()),
            // Document-scope affixes flip defersStreaming(): the whole
            // document buffers and render() emits exactly once at close().
            'deferred'  => new Renderer(new Theme(
                ...array_fill(0, 15, Style::new()),
                documentIndent: 2,
            )),
        ];

        foreach ($cases as $label => $renderer) {
            $stream = fopen('php://memory', 'w+');
            self::assertNotFalse($stream);

            $writer = Writer::toStream($renderer, $stream);
            self::assertTrue($writer->isOpen(), "$label: writer must start open");
            foreach (str_split($doc, 7) as $piece) {
                $writer->feed($piece);
            }
            $writer->close();
            self::assertFalse($writer->isOpen(), "$label: close must release the door");

            rewind($stream);
            $written = stream_get_contents($stream);
            fclose($stream);

            self::assertSame($renderer->render($doc), $written, $label);
        }
        self::assertTrue($cases['deferred']->defersStreaming(), 'the deferred leg must really defer');
    }

    public function testWriterToStreamRefusesReadOnlyResourceAtTheDoor(): void
    {
        $stream = fopen('php://memory', 'r');
        self::assertNotFalse($stream);

        try {
            $this->expectException(\InvalidArgumentException::class);
            Writer::toStream($this->plain(), $stream);
        } finally {
            fclose($stream);
        }
    }
}
