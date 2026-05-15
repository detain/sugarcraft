<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CompressedJsonlFormat;
use SugarCraft\Vcr\Format\Format;

final class CompressedJsonlFormatTest extends TestCase
{
    public function testImplementsFormatInterface(): void
    {
        $this->assertInstanceOf(Format::class, new CompressedJsonlFormat());
    }

    public function testHeaderEncodesAsFirstLine(): void
    {
        $cassette = new Cassette($this->stubHeader(), []);
        $encoded = (new CompressedJsonlFormat())->encode($cassette);
        $lines = explode("\n", trim($encoded));

        $this->assertCount(1, $lines);
        $decoded = json_decode($lines[0], true);
        $this->assertSame(1, $decoded['v']);
        $this->assertSame(80, $decoded['cols']);
        $this->assertSame(24, $decoded['rows']);
        $this->assertSame('2026-05-07T10:00:00Z', $decoded['created']);
        $this->assertSame('sugarcraft/candy-core@dev', $decoded['runtime']);
    }

    public function testEachEventEncodesAsLine(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.001, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'key' => 'j']]),
                new Event(t: 1.201, kind: EventKind::Quit, payload: []),
            ],
        );

        $encoded = (new CompressedJsonlFormat())->encode($cassette);
        $lines = explode("\n", rtrim($encoded, "\n"));
        $this->assertCount(4, $lines, '1 header + 3 events');

        $resize = json_decode($lines[1], true);
        $this->assertSame('resize', $resize['k']);
        $this->assertSame(0.001, $resize['t']);
        $this->assertSame(80, $resize['cols']);
        $this->assertSame(24, $resize['rows']);

        $input = json_decode($lines[2], true);
        $this->assertSame('input', $input['k']);
        $this->assertSame('KeyMsg', $input['msg']['@type']);

        $quit = json_decode($lines[3], true);
        $this->assertSame('quit', $quit['k']);
        $this->assertArrayNotHasKey('payload', $quit);
    }

    public function testRoundTripPreservesAllFields(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => "\x1b[2J\x1b[H"]),
                new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'key' => 'q']]),
                new Event(t: 1.201, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new CompressedJsonlFormat();
        $loaded = $format->decode($format->encode($cassette));

        $this->assertSame(1, $loaded->header->version);
        $this->assertSame(80, $loaded->header->cols);
        $this->assertSame(24, $loaded->header->rows);
        $this->assertSame('sugarcraft/candy-core@dev', $loaded->header->runtime);

        $this->assertSame(4, $loaded->eventCount());
        $this->assertSame(EventKind::Resize, $loaded->events[0]->kind);
        $this->assertSame(EventKind::Output, $loaded->events[1]->kind);
        $this->assertSame(EventKind::Input, $loaded->events[2]->kind);
        $this->assertSame(EventKind::Quit, $loaded->events[3]->kind);

        $this->assertSame(["\x1b[2J\x1b[H"], [$loaded->events[1]->payload['b']]);
        $this->assertSame('q', $loaded->events[2]->payload['msg']['key']);
        $this->assertSame([], $loaded->events[3]->payload);
        $this->assertSame(1.201, $loaded->events[3]->t);
    }

    public function testFileWriteAndReadWithGzipExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-test-') . '.gz';
        $this->assertNotFalse($path);

        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );

        try {
            $format = new CompressedJsonlFormat();
            $format->write($cassette, $path);
            $this->assertFileExists($path);

            $loaded = $format->read($path);
            $this->assertSame(1, $loaded->eventCount());
            $this->assertSame(EventKind::Quit, $loaded->events[0]->kind);
        } finally {
            @unlink($path);
        }
    }

    public function testFileWriteAndReadWithoutGzipExtension(): void
    {
        // CompressedJsonlFormat can also write plain JSONL when path doesn't end with .gz
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-test-');
        $this->assertNotFalse($path);

        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );

        try {
            $format = new CompressedJsonlFormat();
            $format->write($cassette, $path);
            $this->assertFileExists($path);

            $loaded = $format->read($path);
            $this->assertSame(1, $loaded->eventCount());
            $this->assertSame(EventKind::Quit, $loaded->events[0]->kind);
        } finally {
            @unlink($path);
        }
    }

    public function testIsGzipPathDetectsGzipExtension(): void
    {
        $this->assertTrue(CompressedJsonlFormat::isGzipPath('session.cas.gz'));
        $this->assertTrue(CompressedJsonlFormat::isGzipPath('/path/to/session.jsonl.gz'));
        $this->assertFalse(CompressedJsonlFormat::isGzipPath('session'));
        $this->assertFalse(CompressedJsonlFormat::isGzipPath('session.cas'));
        $this->assertFalse(CompressedJsonlFormat::isGzipPath('/path/to/session.jsonl'));
        $this->assertFalse(CompressedJsonlFormat::isGzipPath('session.gz.bak'));
    }

    public function testGzipFileIsActuallyCompressed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-test-') . '.gz';
        $this->assertNotFalse($path);

        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => str_repeat("\x1b[2J\x1b[H", 100)]),
                new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'key' => 'q']]),
                new Event(t: 1.201, kind: EventKind::Quit, payload: []),
            ],
        );

        try {
            $format = new CompressedJsonlFormat();
            $format->write($cassette, $path);

            $gzipSize = filesize($path);
            $plainSize = strlen($format->encode($cassette));

            // Gzip file should be smaller than plain JSONL for repetitive content
            $this->assertLessThan($plainSize, $gzipSize, 'Gzip file should be smaller than plain content');
        } finally {
            @unlink($path);
        }
    }

    public function testReadMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot read cassette');
        (new CompressedJsonlFormat())->read('/does/not/exist/cassette.cas.gz');
    }

    public function testWriteToUnwritablePathThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot open gzip stream');
        $missing = sys_get_temp_dir() . '/candy-vcr-missing-' . uniqid() . '/never/path.cas.gz';
        (new CompressedJsonlFormat())->write(
            new Cassette($this->stubHeader(), []),
            $missing,
        );
    }

    public function testEmptyCassetteRoundTrip(): void
    {
        $cassette = new Cassette($this->stubHeader(), []);
        $format = new CompressedJsonlFormat();
        $loaded = $format->decode($format->encode($cassette));
        $this->assertSame(0, $loaded->eventCount());
    }

    public function testLargePayloadRoundTrip(): void
    {
        $largePayload = str_repeat('Hello, World! This is a test. ', 1000);
        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => $largePayload]),
                new Event(t: 1.201, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new CompressedJsonlFormat();
        $loaded = $format->decode($format->encode($cassette));

        $this->assertSame(2, $loaded->eventCount());
        $this->assertSame($largePayload, $loaded->events[0]->payload['b']);
    }

    public function testTimestampRoundedToMs(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.123456789, kind: EventKind::Quit, payload: [])],
        );
        $format = new CompressedJsonlFormat();
        $loaded = $format->decode($format->encode($cassette));
        $this->assertSame(0.123, $loaded->events[0]->t);
    }

    public function testCustomCompressionLevel(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-test-') . '.gz';
        $this->assertNotFalse($path);

        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );

        try {
            // Use fastest compression (level 1)
            $formatFast = new CompressedJsonlFormat(compressionLevel: 1);
            $formatFast->write($cassette, $path);
            $fastSize = filesize($path);

            // Use best compression (level 9)
            $formatBest = new CompressedJsonlFormat(compressionLevel: 9);
            $formatBest->write($cassette, $path);
            $bestSize = filesize($path);

            // Best compression should be <= fastest (same content)
            $this->assertLessThanOrEqual($fastSize, $bestSize);
        } finally {
            @unlink($path);
        }
    }

    private function stubHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-core@dev',
        );
    }
}
