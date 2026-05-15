<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * Tests for relative timestamp mode (M1).
 *
 * Relative timestamps store intervals since the previous event instead of
 * absolute time since cassette start, making cassettes easier to edit manually
 * (like asciinema v3 format).
 */
final class RelativeTimestampTest extends TestCase
{
    public function testCassetteHeaderDefaultTimestampModeIsAbsolute(): void
    {
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-core@dev',
        );
        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_ABSOLUTE, $header->timestampMode);
    }

    public function testCassetteHeaderTimestampModeCanBeSetToRelative(): void
    {
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-core@dev',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
        );
        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $header->timestampMode);
    }

    public function testCassetteHeaderTimestampModeConstantValues(): void
    {
        $this->assertSame('absolute', CassetteHeader::TIMESTAMP_MODE_ABSOLUTE);
        $this->assertSame('relative', CassetteHeader::TIMESTAMP_MODE_RELATIVE);
    }

    public function testCassetteHeaderInvalidTimestampModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("timestampMode must be 'absolute' or 'relative'");
        new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-core@dev',
            timestampMode: 'invalid',
        );
    }

    public function testAbsoluteModeEncodePreservesOriginalTimestamps(): void
    {
        $cassette = new Cassette(
            $this->absoluteHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => 'hello']),
                new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg']]),
                new Event(t: 1.201, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new JsonlFormat();
        $encoded = $format->encode($cassette);
        $lines = explode("\n", trim($encoded));

        // Header should NOT have timestampMode when absolute
        $header = json_decode($lines[0], true);
        $this->assertArrayNotHasKey('timestampMode', $header);

        // Event timestamps should be absolute
        $this->assertEqualsWithDelta(0.0, json_decode($lines[1], true)['t'], 0.001);
        $this->assertEqualsWithDelta(0.001, json_decode($lines[2], true)['t'], 0.001);
        $this->assertEqualsWithDelta(0.45, json_decode($lines[3], true)['t'], 0.001);
        $this->assertEqualsWithDelta(1.201, json_decode($lines[4], true)['t'], 0.001);
    }

    public function testRelativeModeEncodeConvertsToIntervals(): void
    {
        $cassette = new Cassette(
            $this->relativeHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => 'hello']),
                new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg']]),
                new Event(t: 1.201, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new JsonlFormat();
        $encoded = $format->encode($cassette);
        $lines = explode("\n", trim($encoded));

        // Header should have timestampMode set to relative
        $header = json_decode($lines[0], true);
        $this->assertSame('relative', $header['timestampMode']);

        // Event timestamps should be intervals (relative to previous event)
        // Event 0: t=0.0 (first event, interval = 0.0)
        // Event 1: t=0.001 (interval = 0.001 - 0.0 = 0.001)
        // Event 2: t=0.449 (interval = 0.450 - 0.001 = 0.449)
        // Event 3: t=0.751 (interval = 1.201 - 0.450 = 0.751)
        $this->assertEqualsWithDelta(0.0, json_decode($lines[1], true)['t'], 0.001);
        $this->assertEqualsWithDelta(0.001, json_decode($lines[2], true)['t'], 0.001);
        $this->assertEqualsWithDelta(0.449, json_decode($lines[3], true)['t'], 0.001);
        $this->assertEqualsWithDelta(0.751, json_decode($lines[4], true)['t'], 0.001);
    }

    public function testRelativeModeDecodeConvertsIntervalsToAbsolute(): void
    {
        // Simulate what relative mode would write (intervals in file)
        $contents = json_encode([
            'v' => 1,
            'created' => '2026-05-07T10:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'sugarcraft/candy-core@dev',
            'timestampMode' => 'relative',
        ]) . "\n";
        $contents .= json_encode(['t' => 0.0, 'k' => 'resize', 'cols' => 80, 'rows' => 24]) . "\n";
        $contents .= json_encode(['t' => 0.001, 'k' => 'output', 'b' => 'hello']) . "\n";
        $contents .= json_encode(['t' => 0.449, 'k' => 'input', 'msg' => ['@type' => 'KeyMsg']]) . "\n";
        $contents .= json_encode(['t' => 0.751, 'k' => 'quit']) . "\n";

        $format = new JsonlFormat();
        $cassette = $format->decode($contents);

        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $cassette->header->timestampMode);

        // Events should have absolute timestamps after decoding
        $this->assertEqualsWithDelta(0.0, $cassette->events[0]->t, 0.001);
        $this->assertEqualsWithDelta(0.001, $cassette->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(0.45, $cassette->events[2]->t, 0.001);
        $this->assertEqualsWithDelta(1.201, $cassette->events[3]->t, 0.001);
    }

    public function testRoundTripWithRelativeTimestamps(): void
    {
        $original = new Cassette(
            $this->relativeHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => "\x1b[2J\x1b[H"]),
                new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'key' => 'q']]),
                new Event(t: 1.201, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($original));

        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $loaded->header->timestampMode);
        $this->assertSame(4, $loaded->eventCount());

        // Timestamps should round-trip exactly
        $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
        $this->assertEqualsWithDelta(0.001, $loaded->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(0.45, $loaded->events[2]->t, 0.001);
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

    public function testAbsoluteModeRoundTripPreservesTimestamps(): void
    {
        $original = new Cassette(
            $this->absoluteHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'test']),
                new Event(t: 2.5, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($original));

        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_ABSOLUTE, $loaded->header->timestampMode);
        $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
        $this->assertEqualsWithDelta(0.5, $loaded->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(2.5, $loaded->events[2]->t, 0.001);
    }

    public function testRelativeModeWithSingleEvent(): void
    {
        $original = new Cassette(
            $this->relativeHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($original));

        $this->assertSame(1, $loaded->eventCount());
        $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
    }

    public function testRelativeModeWithEmptyCassette(): void
    {
        $original = new Cassette(
            $this->relativeHeader(),
            [],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($original));

        $this->assertSame(0, $loaded->eventCount());
        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $loaded->header->timestampMode);
    }

    public function testBackwardsCompatibilityWithoutTimestampMode(): void
    {
        // Cassettes without timestampMode in header should default to absolute
        $contents = json_encode([
            'v' => 1,
            'created' => '2026-05-07T10:00:00Z',
            'cols' => 80,
            'rows' => 24,
            'runtime' => 'sugarcraft/candy-core@dev',
            // no timestampMode key
        ]) . "\n";
        $contents .= json_encode(['t' => 1.5, 'k' => 'quit']) . "\n";

        $format = new JsonlFormat();
        $cassette = $format->decode($contents);

        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_ABSOLUTE, $cassette->header->timestampMode);
        $this->assertEqualsWithDelta(1.5, $cassette->events[0]->t, 0.001);
    }

    public function testFileWriteAndReadWithRelativeTimestamps(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-relative-test-');
        $this->assertNotFalse($path);

        $cassette = new Cassette(
            $this->relativeHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 120, 'rows' => 40]),
                new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'ready']),
                new Event(t: 1.5, kind: EventKind::Quit, payload: []),
            ],
        );

        try {
            $format = new JsonlFormat();
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

    public function testTimestampPrecisionRoundingInRelativeMode(): void
    {
        $cassette = new Cassette(
            $this->relativeHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.123456789, kind: EventKind::Output, payload: ['b' => 'x']),
                new Event(t: 0.223456789, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($cassette));

        // Relative intervals should be rounded to millisecond precision
        // 0.123456789 - 0.0 = 0.123 (rounded)
        // 0.223456789 - 0.123456789 = 0.100 (rounded)
        $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
        $this->assertEqualsWithDelta(0.123, $loaded->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(0.223, $loaded->events[2]->t, 0.001);
    }

    public function testConsecutiveEventsWithZeroInterval(): void
    {
        $cassette = new Cassette(
            $this->relativeHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'b']),
                new Event(t: 0.0, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($cassette));

        $this->assertEqualsWithDelta(0.0, $loaded->events[0]->t, 0.001);
        $this->assertEqualsWithDelta(0.0, $loaded->events[1]->t, 0.001);
        $this->assertEqualsWithDelta(0.0, $loaded->events[2]->t, 0.001);
    }

    public function testRelativeModeHeaderEncodedInFile(): void
    {
        $cassette = new Cassette(
            $this->relativeHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );

        $format = new JsonlFormat();
        $encoded = $format->encode($cassette);
        $lines = explode("\n", trim($encoded));

        $header = json_decode($lines[0], true);
        $this->assertSame('relative', $header['timestampMode']);

        // For absolute, timestampMode should not be in header
        $absoluteCassette = new Cassette(
            $this->absoluteHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );
        $absoluteEncoded = $format->encode($absoluteCassette);
        $absoluteLines = explode("\n", trim($absoluteEncoded));
        $absoluteHeader = json_decode($absoluteLines[0], true);
        $this->assertArrayNotHasKey('timestampMode', $absoluteHeader);
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