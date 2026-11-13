<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CompressedJsonlFormat;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * Finding 012 — the gzip cassette door had no ceiling on TOTAL DECOMPRESSED
 * bytes: the gzread loop concat'd until the process ran out of memory, and
 * file size is no signal for expanded size (that is the whole point of
 * compression). readAll() now judges every chunk against a constructor-backed
 * ceiling BEFORE the concat and dies with a named error, so a few-KB bomb
 * becomes an exit message instead of an OOM.
 */
final class GzipBombTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/vcrbomb-' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->fail("Failed to create temp dir: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
    }

    private function stubHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/sugar-core@dev',
        );
    }

    private function honestCassette(): Cassette
    {
        return new Cassette($this->stubHeader(), [
            new Event(t: 0.010, kind: EventKind::Output, payload: ['b' => 'hello ']),
            new Event(t: 0.450, kind: EventKind::Output, payload: ['b' => 'world']),
        ]);
    }

    /**
     * @param non-empty-string $path
     * @param positive-int     $rawBytes
     */
    private function bomb(string $path, int $rawBytes): void
    {
        // Highly compressible filler: the premise of the finding is a real
        // gzip that expands far beyond its on-disk size.
        $gz = gzopen($path, 'wb6');
        $this->assertNotFalse($gz);
        $chunk = str_repeat('A', 65536);
        for ($i = 0; $i < intdiv($rawBytes, 65536); $i++) {
            gzwrite($gz, $chunk);
        }
        $tail = $rawBytes % 65536;
        if ($tail > 0) {
            gzwrite($gz, substr($chunk, 0, $tail));
        }
        gzclose($gz);
    }

    public function testDefaultCeilingIsDocumentedAtSixtyFourMebibytes(): void
    {
        // Pin the RUNTIME default of the constructor's third parameter (not
        // the constant against itself — a folded-constant assertSame is a
        // tautology): the door honest readers walk through must sit where the
        // docblock says, and an edit to MAX_EXPANDED_BYTES turns this red.
        $default = (new \ReflectionMethod(CompressedJsonlFormat::class, '__construct'))
            ->getParameters()[2]
            ->getDefaultValue();
        $this->assertSame(64 * 1024 * 1024, $default);
    }

    public function testBombDiesAtTheNamedCapWithBoundedMemory(): void
    {
        $path = $this->dir . '/bomb.gz';
        $cap = 2 * 1024 * 1024;
        $this->bomb($path, 32 * 1024 * 1024);

        // The premise: a 32 MiB expansion must ride in a small file, or the
        // test proves nothing about amplification.
        $this->assertLessThan(200_000, (int) filesize($path), 'bomb.gz stays few-KB sized');

        $reader = new CompressedJsonlFormat(new JsonlFormat(), 6, $cap);
        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();
        try {
            $reader->read($path);
            $this->fail('a cassette expanding beyond the cap must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                "expands beyond {$cap} byte cap",
                $e->getMessage(),
                'the error names the ceiling the bomb died at',
            );
            $this->assertStringContainsString('bomb.gz', $e->getMessage());
            // The whole point: growth over the pre-read baseline stays in the
            // single-digit MiB band (cap string + realloc slop). Uncapped, a
            // 32 MiB expansion plus concat-doubling slop adds tens of MiB
            // before any verdict exists.
            $growth = memory_get_peak_usage(true) - $baseline;
            $this->assertLessThan(
                24 * 1024 * 1024,
                $growth,
                "bounded peak: memory grew {$growth} bytes over baseline; the cap must hold memory, not just the verdict",
            );
        }
    }

    public function testExpansionExactlyAtTheCapPassesTheGate(): void
    {
        // The check is '>', not '>=': honest bytes up to and including the
        // ceiling land whole; the first byte over is what trips it.
        $path = $this->dir . '/exact.gz';
        $cap = 81920; // ten gzread chunks exactly
        $this->bomb($path, $cap);

        $reader = new CompressedJsonlFormat(new JsonlFormat(), 6, $cap);
        $readAll = new \ReflectionMethod(CompressedJsonlFormat::class, 'readAll');
        $readAll->setAccessible(true);

        $out = $readAll->invoke($reader, $path);
        $this->assertIsString($out);
        $this->assertSame($cap, strlen($out));
    }

    public function testHonestCassetteRoundTripsUnderTheDefaultCap(): void
    {
        $path = $this->dir . '/honest.cas.gz';
        $cassette = $this->honestCassette();
        (new CompressedJsonlFormat())->write($cassette, $path);

        $loaded = (new CompressedJsonlFormat())->read($path);

        $this->assertSame(80, $loaded->header->cols);
        $this->assertCount(2, $loaded->events);
        $this->assertSame('hello ', $loaded->events[0]->payload['b']);
        $this->assertSame('world', $loaded->events[1]->payload['b']);
    }

    public function testPlainPathIsUntouchedByTheGzipCeilings(): void
    {
        $path = $this->dir . '/plain.cas';
        (new CompressedJsonlFormat())->write($this->honestCassette(), $path);

        $loaded = (new CompressedJsonlFormat())->read($path);
        $this->assertCount(2, $loaded->events);
    }

    public function testSillyCeilingRefusedAtTheConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxExpandedBytes must be at least 1 byte, got 0');

        new CompressedJsonlFormat(new JsonlFormat(), 6, 0);
    }
}
