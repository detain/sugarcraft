<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\StreamSink;
use SugarCraft\Shine\Tests\Support\ProbeStreamWrapper;
use SugarCraft\Shine\Theme;
use SugarCraft\Shine\Writer;
use SugarCraft\Sprinkles\Style;

use function fclose;
use function dirname;
use function file_exists;
use function fopen;
use function getmypid;
use function rewind;
use function sprintf;
use function stream_get_contents;
use function str_split;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * The incremental Writer: whole-document byte identity against render(),
 * prefix monotonicity, the true-streaming (early-flush) law, hard close,
 * and the deferred (document-scope) buffered contract — the shapes the
 * salvage shipped code for but never tests.
 */
final class WriterTest extends TestCase
{
    private const TWO_SECTIONS = "# Alpha\n\npara one\n\n# Beta\n\npara two\n";

    private const TRAILING_TEXT  = "# Top\n\nbody text\n\ntrailing";

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->paths = [];
    }

    /**
     * Whole-document identity across chunk-split widths — feed boundaries
     * (including 1-byte seams inside multi-byte characters and between
     * blank-line runs) must never change the rendered bytes.
     *
     * @return array<string, array{string, int}>
     */
    public static function identityCorpus(): array
    {
        return [
            'two-sections'     => [self::TWO_SECTIONS, 1],
            'two-sections-3'   => [self::TWO_SECTIONS, 3],
            'two-sections-7'   => [self::TWO_SECTIONS, 7],
            'two-sections-full' => [self::TWO_SECTIONS, PHP_INT_MAX],
            'utf8-boundaries'  => ["# É\n\n日本語テキスト ☺\n\nnext", 1],
            'fenced-code'      => ["# T\n\n```php\n<?php é\n```\n\n# U\n\ntail", 2],
            'trailing-text'    => [self::TRAILING_TEXT, 1],
            'glued-heading'    => ["# Top\n\npara\n# glued\n", 3],
        ];
    }

    #[DataProvider('identityCorpus')]
    public function testFeedIsByteIdenticalToRender(string $doc, int $split): void
    {
        $stream = self::memory();
        $writer = new Writer(new Renderer(Theme::plain()), StreamSink::toStream($stream));

        foreach (str_split($doc, $split) as $piece) {
            $writer->feed($piece);
        }
        $writer->close();

        rewind($stream);
        $this->assertSame((new Renderer(Theme::plain()))->render($doc), stream_get_contents($stream));
    }

    public function testRenderedBytesAreAlwaysAPrefixOfTheFinalOutput(): void
    {
        $doc    = self::TWO_SECTIONS . "\n# Gamma\n\ngamma body\n";
        $expect = (new Renderer(Theme::plain()))->render($doc);

        $stream = self::memory();
        $writer = new Writer(new Renderer(Theme::plain()), StreamSink::toStream($stream));

        foreach (str_split($doc, 1) as $byte) {
            $writer->feed($byte);
            rewind($stream);
            $visible = stream_get_contents($stream);
            $this->assertTrue(
                str_starts_with($expect, $visible),
                sprintf('sink bytes after feeding %d chars are not a prefix of render()', strlen($byte)),
            );
        }
        $writer->close();

        rewind($stream);
        $this->assertSame($expect, stream_get_contents($stream));
    }

    public function testTrueStreamingLandsBytesBeforeClose(): void
    {
        $handle = ProbeStreamWrapper::open('early');
        $this->assertNotFalse($handle);

        $writer = new Writer(new Renderer(Theme::plain()), StreamSink::toStream($handle));
        $writer->feed(self::TWO_SECTIONS);

        $this->assertNotSame('', ProbeStreamWrapper::$committed['early'], 'first section must flush at the blank-line boundary, not at close()');
        $this->assertTrue(str_starts_with(ProbeStreamWrapper::$committed['early'], '# Alpha'), 'the flushed head must be the first section');
        $this->assertFalse(str_contains(ProbeStreamWrapper::$committed['early'], 'Beta'), 'the second section must not have been rendered yet');

        $writer->close();
    }

    public function testCloseFlushesTerminalPartialSection(): void
    {
        $stream = self::memory();
        $writer = new Writer(new Renderer(Theme::plain()), StreamSink::toStream($stream));
        $writer->feed(self::TRAILING_TEXT);

        // the trailing run is NOT yet a closed section; nothing of it may have
        // been committed as its own body before close()
        rewind($stream);
        $this->assertSame('', stream_get_contents($stream), 'an unterminated carry must not reach the sink before close()');

        $writer->close();

        rewind($stream);
        $this->assertSame((new Renderer(Theme::plain()))->render(self::TRAILING_TEXT), stream_get_contents($stream));
    }

    public function testHardCloseRefusesLateBytesAndIsIdempotent(): void
    {
        $stream = self::memory();
        $writer = new Writer(new Renderer(Theme::plain()), StreamSink::toStream($stream));
        $writer->feed("# A\n\nb\n");
        $writer->close();

        $this->assertFalse($writer->isOpen());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('feed() called after close()');
        $writer->feed("# C\n");
    }

    public function testCloseTwiceIsSafe(): void
    {
        $stream = self::memory();
        $writer = new Writer(new Renderer(Theme::plain()), StreamSink::toStream($stream));
        $writer->feed("# A\n\nb\n");
        $writer->close();
        $writer->close();

        $this->assertFalse($writer->isOpen());
    }

    public function testToPathDoorThrowsBeforeAnyRendering(): void
    {
        $missing = sys_get_temp_dir() . '/shine-writer-missing-' . getmypid() . '/out.md';

        try {
            Writer::toPath(new Renderer(Theme::plain()), $missing);
            $this->fail('expected the path door to throw');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('output directory does not exist', $e->getMessage());
        }

        $this->assertFalse(file_exists(dirname($missing)), 'a rejected path must create nothing on the filesystem');
    }

    public function testDeferredDocumentScopeBuffersUntilClose(): void
    {
        $s      = Style::new();
        $theme  = new Theme($s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, documentIndent: 2);

        $stream = self::memory();
        $writer = (new Renderer($theme))->writer(StreamSink::toStream($stream));
        $writer->feed(self::TWO_SECTIONS);

        rewind($stream);
        $this->assertSame('', stream_get_contents($stream), 'a document-scope theme must hold every byte until close()');

        $writer->close();

        rewind($stream);
        $this->assertSame((new Renderer($theme))->render(self::TWO_SECTIONS), stream_get_contents($stream));
    }

    public function testEmptyDocumentWritesNothing(): void
    {
        $handle = ProbeStreamWrapper::open('empty');
        $this->assertNotFalse($handle);

        $writer = new Writer(new Renderer(Theme::plain()), StreamSink::toStream($handle));
        $writer->feed('');
        $writer->close();

        $this->assertSame(0, ProbeStreamWrapper::$writes['empty']);
        $this->assertSame('', ProbeStreamWrapper::$committed['empty']);
    }

    public function testOneFlushPerSectionPair(): void
    {
        $handle = ProbeStreamWrapper::open('pairs');
        $this->assertNotFalse($handle);

        $writer = new Writer(new Renderer(Theme::plain()), StreamSink::toStream($handle));
        $writer->feed("# A\n\none\n\n# B\n\ntwo\n");
        $writer->close();

        // two sections (head+body per section) plus their carried blank-line
        // runs — writes equal flushes: every write flushed, nothing sat pending
        $this->assertSame(ProbeStreamWrapper::$writes['pairs'], ProbeStreamWrapper::$flushes['pairs']);
        $this->assertGreaterThan(2, ProbeStreamWrapper::$writes['pairs']);
        $this->assertSame('', ProbeStreamWrapper::$pending['pairs']);
    }

    public function testRendererWriterSugarMatchesDirectConstruction(): void
    {
        $stream = self::memory();
        $writer = (new Renderer(Theme::plain()))->writer(StreamSink::toStream($stream));
        $this->assertInstanceOf(Writer::class, $writer);

        $writer->feed(self::TWO_SECTIONS);
        $writer->close();

        rewind($stream);
        $this->assertSame((new Renderer(Theme::plain()))->render(self::TWO_SECTIONS), stream_get_contents($stream));
    }

    /** @return resource */
    private static function memory()
    {
        $stream = fopen('php://memory', 'wb');
        if ($stream === false) {
            throw new LogicException('memory stream unavailable');
        }

        return $stream;
    }
}
