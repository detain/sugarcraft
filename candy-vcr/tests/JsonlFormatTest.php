<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\Format;
use SugarCraft\Vcr\Format\JsonlFormat;

final class JsonlFormatTest extends TestCase
{
    public function testImplementsFormatInterface(): void
    {
        $this->assertInstanceOf(Format::class, new JsonlFormat());
    }

    public function testHeaderEncodesAsFirstLine(): void
    {
        $cassette = new Cassette($this->stubHeader(), []);
        $encoded = (new JsonlFormat())->encode($cassette);
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

        $encoded = (new JsonlFormat())->encode($cassette);
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

        $format = new JsonlFormat();
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

    public function testFileWriteAndRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-test-');
        $this->assertNotFalse($path);

        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );

        try {
            $format = new JsonlFormat();
            $format->write($cassette, $path);
            $this->assertFileExists($path);

            $loaded = $format->read($path);
            $this->assertSame(1, $loaded->eventCount());
            $this->assertSame(EventKind::Quit, $loaded->events[0]->kind);
        } finally {
            @unlink($path);
        }
    }

    public function testTimestampRoundedToMs(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.123456789, kind: EventKind::Quit, payload: [])],
        );
        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($cassette));
        $this->assertSame(0.123, $loaded->events[0]->t);
    }

    public function testTrailingNewlineToleratedOnRead(): void
    {
        $format = new JsonlFormat();
        $cassette = new Cassette($this->stubHeader(), []);
        $encoded = $format->encode($cassette) . "\n\n";
        $loaded = $format->decode($encoded);
        $this->assertSame(0, $loaded->eventCount());
    }

    public function testCrlfLineEndingsAccepted(): void
    {
        $format = new JsonlFormat();
        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );
        $crlf = str_replace("\n", "\r\n", $format->encode($cassette));
        $loaded = $format->decode($crlf);
        $this->assertSame(1, $loaded->eventCount());
    }

    public function testEmptyContentsThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cassette is empty');
        (new JsonlFormat())->decode('');
    }

    public function testMissingHeaderVersionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'v'");
        (new JsonlFormat())->decode("{\"created\":\"x\",\"cols\":80,\"rows\":24,\"runtime\":\"r\"}\n");
    }

    public function testMalformedLineThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON on line 2');
        $header = '{"v":1,"created":"2026-05-07T10:00:00Z","cols":80,"rows":24,"runtime":"r"}';
        (new JsonlFormat())->decode($header . "\nNOT-JSON\n");
    }

    public function testUnknownEventKindThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("unknown kind 'crash'");
        $header = '{"v":1,"created":"2026-05-07T10:00:00Z","cols":80,"rows":24,"runtime":"r"}';
        $bad = '{"t":0,"k":"crash"}';
        (new JsonlFormat())->decode($header . "\n" . $bad . "\n");
    }

    public function testEventMissingTOrKThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 't' or 'k'");
        $header = '{"v":1,"created":"2026-05-07T10:00:00Z","cols":80,"rows":24,"runtime":"r"}';
        $bad = '{"t":0}';
        (new JsonlFormat())->decode($header . "\n" . $bad . "\n");
    }

    public function testReadMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot read cassette');
        (new JsonlFormat())->read('/does/not/exist/cassette.cas');
    }

    public function testWriteToUnwritablePathThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot write cassette');
        $missing = sys_get_temp_dir() . '/candy-vcr-missing-' . uniqid() . '/never/path.cas';
        (new JsonlFormat())->write(
            new Cassette($this->stubHeader(), []),
            $missing,
        );
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
