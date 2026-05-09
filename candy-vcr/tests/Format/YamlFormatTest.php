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
use SugarCraft\Vcr\Format\YamlFormat;

final class YamlFormatTest extends TestCase
{
    public function testImplementsFormatInterface(): void
    {
        $this->assertInstanceOf(Format::class, new YamlFormat());
    }

    public function testEncodeIsHumanReadable(): void
    {
        $cassette = new Cassette($this->stubHeader(), [
            new Event(t: 0.001, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ]);
        $yaml = (new YamlFormat())->encode($cassette);
        $this->assertStringContainsString('header:', $yaml);
        $this->assertStringContainsString('events:', $yaml);
        $this->assertStringContainsString('v: 1', $yaml);
        $this->assertStringContainsString('cols: 80', $yaml);
        $this->assertStringContainsString('runtime:', $yaml);
        $this->assertStringContainsString('k: resize', $yaml);
        $this->assertStringContainsString('k: quit', $yaml);
    }

    public function testRoundTripPreservesAllFields(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => "\x1b[2J\x1b[H"]),
                new Event(t: 0.450, kind: EventKind::Input, payload: ['msg' => ['@type' => 'KeyMsg', 'rune' => 'q']]),
                new Event(t: 1.201, kind: EventKind::Quit, payload: []),
            ],
        );

        $format = new YamlFormat();
        $loaded = $format->decode($format->encode($cassette));

        $this->assertSame(1, $loaded->header->version);
        $this->assertSame(80, $loaded->header->cols);
        $this->assertSame(4, $loaded->eventCount());
        $this->assertSame(EventKind::Resize, $loaded->events[0]->kind);
        $this->assertSame(EventKind::Output, $loaded->events[1]->kind);
        $this->assertSame(EventKind::Input, $loaded->events[2]->kind);
        $this->assertSame(EventKind::Quit, $loaded->events[3]->kind);
        $this->assertSame("\x1b[2J\x1b[H", $loaded->events[1]->payload['b']);
        $this->assertSame('q', $loaded->events[2]->payload['msg']['rune']);
        $this->assertSame(1.201, $loaded->events[3]->t);
    }

    public function testCrossFormatJsonlYamlRoundTrip(): void
    {
        // The same logical Cassette must round-trip through both
        // formats with identical event semantics. Both round t to ms.
        $cassette = new Cassette(
            $this->stubHeader(),
            [
                new Event(t: 0.000, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.123, kind: EventKind::Output, payload: ['b' => "frame"]),
                new Event(t: 1.500, kind: EventKind::Quit, payload: []),
            ],
        );

        $viaJsonl = (new JsonlFormat())->decode((new JsonlFormat())->encode($cassette));
        $viaYaml = (new YamlFormat())->decode((new YamlFormat())->encode($cassette));

        $this->assertSame($viaJsonl->eventCount(), $viaYaml->eventCount());
        for ($i = 0; $i < $viaJsonl->eventCount(); $i++) {
            $this->assertSame($viaJsonl->events[$i]->t, $viaYaml->events[$i]->t);
            $this->assertSame($viaJsonl->events[$i]->kind, $viaYaml->events[$i]->kind);
            $this->assertSame($viaJsonl->events[$i]->payload, $viaYaml->events[$i]->payload);
        }
        $this->assertSame($viaJsonl->header->cols, $viaYaml->header->cols);
        $this->assertSame($viaJsonl->header->rows, $viaYaml->header->rows);
        $this->assertSame($viaJsonl->header->runtime, $viaYaml->header->runtime);
    }

    public function testFileWriteAndRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-yaml-');
        $this->assertNotFalse($path);

        $cassette = new Cassette(
            $this->stubHeader(),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );

        try {
            $format = new YamlFormat();
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
        $format = new YamlFormat();
        $loaded = $format->decode($format->encode($cassette));
        $this->assertSame(0.123, $loaded->events[0]->t);
    }

    public function testHandWrittenYamlParses(): void
    {
        $yaml = <<<YAML
header:
    v: 1
    created: "2026-05-08T12:00:00Z"
    cols: 80
    rows: 24
    runtime: "sugarcraft/candy-vcr@dev"
events:
    - { t: 0.0, k: resize, cols: 80, rows: 24 }
    - { t: 0.5, k: input, b: q }
    - { t: 1.0, k: quit }
YAML;
        $cassette = (new YamlFormat())->decode($yaml);
        $this->assertSame(3, $cassette->eventCount());
        $this->assertSame(EventKind::Resize, $cassette->events[0]->kind);
        $this->assertSame('q', $cassette->events[1]->payload['b']);
        $this->assertSame(EventKind::Quit, $cassette->events[2]->kind);
    }

    public function testEmptyYamlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'header'");
        (new YamlFormat())->decode('');
    }

    public function testMissingHeaderVersionThrows(): void
    {
        $yaml = "header:\n    cols: 80\n    rows: 24\n    created: x\n    runtime: r\n";
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'v'");
        (new YamlFormat())->decode($yaml);
    }

    public function testUnknownEventKindThrows(): void
    {
        $yaml = <<<YAML
header:
    v: 1
    created: x
    cols: 80
    rows: 24
    runtime: r
events:
    - { t: 0.0, k: crash }
YAML;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("unknown kind 'crash'");
        (new YamlFormat())->decode($yaml);
    }

    public function testEventMissingTOrKThrows(): void
    {
        $yaml = <<<YAML
header:
    v: 1
    created: x
    cols: 80
    rows: 24
    runtime: r
events:
    - { t: 0.0 }
YAML;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 't' or 'k'");
        (new YamlFormat())->decode($yaml);
    }

    public function testReadMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot read cassette');
        (new YamlFormat())->read('/does/not/exist/cassette.yaml');
    }

    public function testInvalidYamlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid YAML');
        (new YamlFormat())->decode("[: invalid yaml :]\n  -- bad");
    }

    private function stubHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-08T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
        );
    }
}
