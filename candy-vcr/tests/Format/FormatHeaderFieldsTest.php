<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tests\Support\HostFilesystem;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CompressedJsonlFormat;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Format\RelativeFormat;
use SugarCraft\Vcr\Format\YamlFormat;
use SugarCraft\Vcr\Recorder;

/**
 * Header fidelity and wire-contract pins for the whole Format family.
 *
 * One header model, four encoders: every field of CassetteHeader must
 * survive a write/read cycle through each format (the historical defect was
 * formats silently dropping font/theme/playback fields, which also made
 * `diff` blind to them), reserved event keys (`t`/`k`, `dt`/`k`) can never
 * be clobbered by payload entries of the same name, and a kill-9-torn tail
 * line is dropped instead of bricking the cassette.
 */
final class FormatHeaderFieldsTest extends TestCase
{
    use HostFilesystem;

    private function fullHeader(string $mode = CassetteHeader::TIMESTAMP_MODE_ABSOLUTE): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-11-04T09:00:00Z',
            cols: 100,
            rows: 30,
            runtime: 'sugarcraft/candy-vcr@test',
            timestampMode: $mode,
            env: ['TERM' => 'xterm-256color', 'LANG' => 'C.UTF-8'],
            typingSpeed: 7.5,
            theme: 'tokyo-night',
            playbackSpeed: 2.0,
            fontSize: 16,
            fontFamily: 'JetBrains Mono',
            widthPx: 1234,
            heightPx: 567,
        );
    }

    /** @return list<Event> */
    private function events(): array
    {
        return [
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 100, 'rows' => 30]),
            new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'hi']),
            new Event(t: 1.25, kind: EventKind::Quit, payload: []),
        ];
    }

    private function assertFullHeader(CassetteHeader $h): void
    {
        $this->assertSame(1, $h->version);
        $this->assertSame('2026-11-04T09:00:00Z', $h->createdAt);
        $this->assertSame(100, $h->cols);
        $this->assertSame(30, $h->rows);
        $this->assertSame('sugarcraft/candy-vcr@test', $h->runtime);
        $this->assertSame(['TERM' => 'xterm-256color', 'LANG' => 'C.UTF-8'], $h->env);
        $this->assertSame(7.5, $h->typingSpeed);
        $this->assertSame('tokyo-night', $h->theme);
        $this->assertSame(2.0, $h->playbackSpeed);
        $this->assertSame(16, $h->fontSize);
        $this->assertSame('JetBrains Mono', $h->fontFamily);
        $this->assertSame(1234, $h->widthPx);
        $this->assertSame(567, $h->heightPx);
    }

    public function testJsonlCarriesEveryHeaderField(): void
    {
        $format = new JsonlFormat();
        $cassette = new Cassette($this->fullHeader(), $this->events());
        $decoded = $format->decode($format->encode($cassette));
        $this->assertFullHeader($decoded->header);
        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_ABSOLUTE, $decoded->header->timestampMode);
        $this->assertCount(3, $decoded->events);
    }

    public function testRelativeCarriesEveryHeaderField(): void
    {
        $format = new RelativeFormat();
        $cassette = new Cassette($this->fullHeader(CassetteHeader::TIMESTAMP_MODE_RELATIVE), $this->events());
        $decoded = $format->decode($format->encode($cassette));
        $this->assertFullHeader($decoded->header);
        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $decoded->header->timestampMode);
        // dt accumulation must restore the absolute timeline.
        $this->assertSame([0.0, 0.5, 1.25], array_map(static fn (Event $e): float => $e->t, $decoded->events));
    }

    public function testYamlCarriesEveryHeaderField(): void
    {
        $format = new YamlFormat();
        $cassette = new Cassette($this->fullHeader(), $this->events());
        $decoded = $format->decode($format->encode($cassette));
        $this->assertFullHeader($decoded->header);
        $this->assertCount(3, $decoded->events);
    }

    public function testGzippedJsonlCarriesEveryHeaderField(): void
    {
        $dir = (string) realpath(sys_get_temp_dir()) . '/cv-hdr-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $path = $dir . '/full.cas.gz';
        try {
            $format = new CompressedJsonlFormat();
            $format->write(new Cassette($this->fullHeader(), $this->events()), $path);
            $decoded = $format->read($path);
            $this->assertFullHeader($decoded->header);
            if (self::hostHasPosixModes()) {
                $this->assertSame(0600, fileperms($path) & 0777, 'gzip cassette must land owner-readable only');
            }
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testAbsoluteModeRecordedThroughRelativeTimelineSurvivesJsonlRoundTrip(): void
    {
        // A cassette recorded in relative mode still reads back as absolute
        // timestamps through JsonlFormat (mode is a header discriminator).
        $format = new JsonlFormat();
        $cassette = new Cassette($this->fullHeader(CassetteHeader::TIMESTAMP_MODE_RELATIVE), $this->events());
        $decoded = $format->decode($format->encode($cassette));
        $this->assertFullHeader($decoded->header);
        $this->assertSame(CassetteHeader::TIMESTAMP_MODE_RELATIVE, $decoded->header->timestampMode);
        $this->assertSame([0.0, 0.5, 1.25], array_map(static fn (Event $e): float => $e->t, $decoded->events));
    }

    public function testRecorderStreamsTheFullHeaderToDisk(): void
    {
        // The streaming header line is written by Recorder's ctor; it must
        // carry the same full field set as every Format encoder (previously
        // it wrote five fields, so anything recorded with font/theme hints
        // lost them on disk even though the in-memory header had them).
        $path = tempnam(sys_get_temp_dir(), 'cv-rec-') . '.cas';
        try {
            $fh = fopen($path, 'wb');
            $this->assertNotFalse($fh);
            $recorder = new Recorder($fh, $this->fullHeader());
            $recorder->recordOutput('hi');
            $recorder->close();
            $decoded = (new JsonlFormat())->read($path);
            $this->assertFullHeader($decoded->header);
            $this->assertCount(1, $decoded->events);
        } finally {
            @unlink($path);
        }
    }

    public function testFormatWritesLandAtZeroSixHundred(): void
    {
        $this->requirePosixModes();
        // Cassettes contain everything typed at the terminal; every rewrite
        // path must match Recorder's 0600, not the ambient umask.
        $paths = [];
        try {
            $cassette = new Cassette($this->fullHeader(), $this->events());
            $targets = [
                'jsonl' => [new JsonlFormat(), 'a.cas'],
                'relative' => [new RelativeFormat(), 'b.cas'],
                'yaml' => [new YamlFormat(), 'c.yaml'],
            ];
            foreach ($targets as $name => [$format, $file]) {
                $dir = (string) realpath(sys_get_temp_dir()) . '/cv-perm-' . $name . '-' . bin2hex(random_bytes(5));
                mkdir($dir);
                $path = $dir . '/' . $file;
                $paths[] = [$path, $dir];
                $format->write($cassette, $path);
                $this->assertSame(0600, fileperms($path) & 0777, "{$name} cassette must be 0600");
            }
        } finally {
            foreach ($paths as [$path, $dir]) {
                @unlink($path);
                @rmdir($dir);
            }
        }
    }

    public function testAbsoluteReservedKeysCannotBeClobberedByPayload(): void
    {
        $format = new JsonlFormat();
        $cassette = new Cassette(
            $this->fullHeader(),
            [new Event(t: 0.25, kind: EventKind::Output, payload: ['t' => 'evil', 'k' => 'nope', 'b' => 'x'])],
        );
        $decoded = $format->decode($format->encode($cassette));
        $event = $decoded->events[0];
        $this->assertSame(0.25, $event->t);
        $this->assertSame(EventKind::Output, $event->kind);
        $this->assertSame(['b' => 'x'], $event->payload);
    }

    public function testYamlReservedKeysCannotBeClobberedByPayload(): void
    {
        $format = new YamlFormat();
        $cassette = new Cassette(
            $this->fullHeader(),
            [new Event(t: 0.25, kind: EventKind::Output, payload: ['t' => 'evil', 'k' => 'nope', 'b' => 'x'])],
        );
        $decoded = $format->decode($format->encode($cassette));
        $event = $decoded->events[0];
        $this->assertSame(0.25, $event->t);
        $this->assertSame(EventKind::Output, $event->kind);
        $this->assertSame(['b' => 'x'], $event->payload);
    }

    public function testRelativeReservedKeyCannotBeClobberedByPayload(): void
    {
        $format = new RelativeFormat();
        $cassette = new Cassette(
            $this->fullHeader(CassetteHeader::TIMESTAMP_MODE_RELATIVE),
            [new Event(t: 0.75, kind: EventKind::Output, payload: ['dt' => 'evil', 'k' => 'nope', 'b' => 'x'])],
        );
        $decoded = $format->decode($format->encode($cassette));
        $event = $decoded->events[0];
        $this->assertSame(0.75, $event->t);
        $this->assertSame(EventKind::Output, $event->kind);
        $this->assertSame(['b' => 'x'], $event->payload);
    }

    public function testTornTailLineIsDroppedButToleratedMidFileStillThrows(): void
    {
        $format = new JsonlFormat();
        $contents = $format->encode(new Cassette($this->fullHeader(), $this->events()));

        // kill -9 shape: last line cut mid-JSON, no trailing newline.
        $cut = rtrim($contents, "\n");
        $cut = substr($cut, 0, (int) strrpos($cut, "\n")) . "\n{\"t\":9.9,\"k\":\"out";
        $decoded = $format->decode($cut);
        $this->assertCount(2, $decoded->events, 'only the torn final line may be dropped');

        // Corruption anywhere else stays loud.
        $lines = explode("\n", rtrim($contents, "\n"));
        $lines[2] = '{definitely not json';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON on line');
        $format->decode(implode("\n", $lines) . "\n");
    }

    public function testRelativeTornTailLineIsDropped(): void
    {
        $format = new RelativeFormat();
        $contents = $format->encode(new Cassette($this->fullHeader(CassetteHeader::TIMESTAMP_MODE_RELATIVE), $this->events()));
        $cut = rtrim($contents, "\n");
        $cut = substr($cut, 0, (int) strrpos($cut, "\n")) . "\n{\"dt\":1,\"k\":\"out";
        $decoded = $format->decode($cut);
        $this->assertCount(2, $decoded->events);
    }
}
