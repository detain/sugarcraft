<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;
use SugarCraft\Sprinkles\Style;

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
            'list-refuse'   => ["# Top\n\n- one\n- two\n\n# After\n"],
            'indent-refuse' => ["# Top\n\n    code\n\n# After\n"],
            'raw-html'      => ["# Top\n\n<pre>\n# inside\n</pre>\n\n# After\n"],
            'trailing-run'  => ["# Top\n\nbody\n\n\n"],
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

    public function testParagraphOnlyDocumentEmitsSingleChunk(): void
    {
        $chunks = self::collect($this->plain(), ["para one\n\npara two\n"]);

        $this->assertCount(1, $chunks);
        $this->assertSame("para one\n\npara two", $chunks[0]);
    }

    /**
     * The defensive refusals: a heading preceded by a blank line is NOT cut
     * when the previous content line was a quote / list / indented-code /
     * table row. The identity sweep cannot see this (the cut would also be
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
            'after-list-refused'              => ["# Top\n\n- one\n\n# After\n", 1],
            'after-indented-code-refused'     => ["# Top\n\n    code\n\n# After\n", 1],
            'after-table-refused'             => ["# Top\n\n| a |\n|---|\n| 1 |\n\n# After\n", 1],
            'bare-heading-twin-cuts'          => ["# Top\n\nprose\n\n# After\n", 3],
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
}
