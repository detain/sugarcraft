<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\Format;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Format\RelativeFormat;

/**
 * Tests for RelativeFormat which uses `dt` (delta-time) field at the file
 * level instead of `t` (absolute time).
 */
final class RelativeFormatTest extends TestCase
{
    public function testImplementsFormatInterface(): void
    {
        $this->assertInstanceOf(Format::class, new RelativeFormat());
    }

    public function testHeaderEncodesWithRelativeTimestampMode(): void
    {
        $cassette = new Cassette($this->stubHeader(), []);
        $encoded = (new RelativeFormat())->encode($cassette);
        $lines = explode("\n", trim($encoded));

        $this->assertCount(1, $lines);
        $decoded = json_decode($lines[0], true);
        $this->assertSame(1, $decoded['v']);
        $this->assertSame('relative', $decoded['timestampMode']);
        $this->assertSame(80, $decoded['cols']);
        $this->assertSame(24, $decoded['rows']);
    }

    public function testEventsUseDtFieldInsteadOfT(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => 'hello']),
                new Event(t: 0.450, kind: EventKind::Quit, payload: []),
            ],
        );

        $encoded = (new RelativeFormat())->encode($cassette);
        $lines = explode("\n", rtrim($encoded, "\n"));
        $this->assertCount(4, $lines, '1 header + 3 events');

        // First event should have dt=0.0
        $resize = json_decode($lines[1], true);
        $this->assertSame('resize', $resize['k']);
        $this->assertArrayHasKey('dt', $resize);
        $this->assertArrayNotHasKey('t', $resize);
        $this->assertEqualsWithDelta(0.0, $resize['dt'], 0.001);

        // Second event: dt = 0.001 - 0.0 = 0.001
        $output = json_decode($lines[2], true);
        $this->assertSame('output', $output['k']);
        $this->assertEqualsWithDelta(0.001, $output['dt'], 0.001);

        // Third event: dt = 0.450 - 0.001 = 0.449
        $quit = json_decode($lines[3], true);
        $this->assertSame('quit', $quit['k']);
        $this->assertEqualsWithDelta(0.449, $quit['dt'], 0.001);
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

        $format = new RelativeFormat();
        $loaded = $format->decode($format->encode($cassette));

        $this->assertSame(1, $loaded->header->version);
        $this->assertSame(80, $loaded->header->cols);
        $this->assertSame(24, $loaded->header->rows);
        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $loaded->header->timestampMode);

        $this->assertSame(4, $loaded->eventCount());

        // Absolute timestamps should be restored
        $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
        $this->assertEqualsWithDelta(0.001, $loaded->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(0.450, $loaded->events[2]->t, 0.001);
        $this->assertEqualsWithDelta(1.201, $loaded->events[3]->t, 0.001);

        // Kinds and payloads should be preserved
        $this->assertSame(EventKind::Resize, $loaded->events[0]->kind);
        $this->assertSame(80, $loaded->events[0]->payload['cols']);
        $this->assertSame(24, $loaded->events[0]->payload['rows']);

        $this->assertSame(EventKind::Output, $loaded->events[1]->kind);
        $this->assertSame("\x1b[2J\x1b[H", $loaded->events[1]->payload['b']);

        $this->assertSame(EventKind::Input, $loaded->events[2]->kind);
        $this->assertSame('q', $loaded->events[2]->payload['msg']['key']);

        $this->assertSame(EventKind::Quit, $loaded->events[3]->kind);
    }

    public function testFileWriteAndRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-relative-format-test-');
        $this->assertNotFalse($path);

        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 120, 'rows' => 40]),
                new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'ready']),
                new Event(t: 1.5, kind: EventKind::Quit, payload: []),
            ],
        );

        try {
            $format = new RelativeFormat();
            $format->write($cassette, $path);
            $this->assertFileExists($path);

            $loaded = $format->read($path);
            $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $loaded->header->timestampMode);
            $this->assertSame(3, $loaded->eventCount());
            $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
            $this->assertEqualsWithDelta(0.1, $loaded->events[1]->t, 0.001);
            $this->assertEqualsWithDelta(1.5, $loaded->events[2]->t, 0.001);
        } finally {
            @unlink($path);
        }
    }

    public function testTimestampPrecisionRounding(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.123456789, kind: EventKind::Output, payload: ['b' => 'x']),
                new Event(t: 0.223456789, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new RelativeFormat();
        $loaded = $format->decode($format->encode($cassette));

        // Relative intervals should be rounded to millisecond precision
        $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
        $this->assertEqualsWithDelta(0.123, $loaded->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(0.223, $loaded->events[2]->t, 0.001);
    }

    public function testConsecutiveEventsWithZeroInterval(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'b']),
                new Event(t: 0.0, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new RelativeFormat();
        $loaded = $format->decode($format->encode($cassette));

        $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
        $this->assertEqualsWithDelta(0.0, $loaded->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(0.0, $loaded->events[2]->t, 0.001);
    }

    public function testEmptyCassette(): void
    {
        $original = new Cassette($this->stubHeader(), []);
        $format = new RelativeFormat();
        $loaded = $format->decode($format->encode($original));

        $this->assertSame(0, $loaded->eventCount());
        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $loaded->header->timestampMode);
    }

    public function testUnknownEventKindThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("unknown kind 'crash'");

        $contents = json_encode([
            'v' => 1,
            'created' => '2026-05-07T10:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'r',
            'timestampMode' => 'relative',
        ]) . "\n";
        $contents .= json_encode(['dt' => 0.0, 'k' => 'crash']) . "\n";

        (new RelativeFormat())->decode($contents);
    }

    public function testMissingDtOrKThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'dt' or 'k'");

        $contents = json_encode([
            'v' => 1,
            'created' => '2026-05-07T10:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'r',
            'timestampMode' => 'relative',
        ]) . "\n";
        $contents .= json_encode(['dt' => 0.0]) . "\n"; // missing 'k'

        (new RelativeFormat())->decode($contents);
    }

    public function testCrlfLineEndingsAccepted(): void
    {
        $format = new RelativeFormat();
        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );
        $crlf = str_replace("\n", "\r\n", $format->encode($cassette));
        $loaded = $format->decode($crlf);
        $this->assertSame(1, $loaded->eventCount());
    }

    public function testTrailingNewlineTolerated(): void
    {
        $format = new RelativeFormat();
        $cassette = new Cassette($this->stubHeader(), []);
        $encoded = $format->encode($cassette) . "\n\n";
        $loaded = $format->decode($encoded);
        $this->assertSame(0, $loaded->eventCount());
    }

    public function testReplayEquivalenceWithJsonlFormat(): void
    {
        // Record same events with both formats
        $events = [
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => 'hello']),
            new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'key' => 'q']]),
            new Event(t: 1.201, kind: EventKind::Quit, payload: []),
        ];

        $jsonlFormat = new JsonlFormat();
        $relativeFormat = new RelativeFormat();

        // Encode/decode with both formats
        $cassetteJsonl = new Cassette($this->absoluteHeader(), $events);
        $cassetteRelative = new Cassette($this->relativeHeader(), $events);

        $decodedJsonl = $jsonlFormat->decode($jsonlFormat->encode($cassetteJsonl));
        $decodedRelative = $relativeFormat->decode($relativeFormat->encode($cassetteRelative));

        // Both should produce the same absolute timestamps and payloads
        $this->assertSame($decodedJsonl->eventCount(), $decodedRelative->eventCount());

        for ($i = 0; $i < $decodedJsonl->eventCount(); $i++) {
            $this->assertEqualsWithDelta(
                $decodedJsonl->events[$i]->t,
                $decodedRelative->events[$i]->t,
                0.001,
                "Event $i timestamp mismatch",
            );
            $this->assertSame(
                $decodedJsonl->events[$i]->kind,
                $decodedRelative->events[$i]->kind,
                "Event $i kind mismatch",
            );
            $this->assertSame(
                $decodedJsonl->events[$i]->payload,
                $decodedRelative->events[$i]->payload,
                "Event $i payload mismatch",
            );
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
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
        );
    }

    private function absoluteHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-core@dev',
        );
    }

    private function relativeHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-core@dev',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
        );
    }
}
