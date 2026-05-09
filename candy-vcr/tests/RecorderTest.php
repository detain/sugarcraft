<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Recorder as RecorderInterface;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Recorder;

final class RecorderTest extends TestCase
{
    public function testImplementsCoreInterface(): void
    {
        $this->assertInstanceOf(
            RecorderInterface::class,
            new Recorder($this->memory(), $this->stubHeader()),
        );
    }

    public function testHeaderWrittenAtConstruction(): void
    {
        $fh = $this->memory();
        new Recorder($fh, $this->stubHeader());
        $first = $this->firstLine($fh);
        $this->assertSame(1, $first['v']);
        $this->assertSame(80, $first['cols']);
        $this->assertSame(24, $first['rows']);
        $this->assertSame('sugarcraft/candy-vcr@dev', $first['runtime']);
    }

    public function testResizeEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader(), startTime: microtime(true));
        $r->recordResize(120, 40);

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events);
        $this->assertSame('resize', $events[0]['k']);
        $this->assertSame(120, $events[0]['cols']);
        $this->assertSame(40, $events[0]['rows']);
        $this->assertGreaterThanOrEqual(0.0, $events[0]['t']);
    }

    public function testInputBytesEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordInputBytes("\x1b[A");

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events);
        $this->assertSame('input', $events[0]['k']);
        $this->assertSame("\x1b[A", $events[0]['b']);
    }

    public function testOutputEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput("hello\n");

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events);
        $this->assertSame('output', $events[0]['k']);
        $this->assertSame("hello\n", $events[0]['b']);
    }

    public function testEmptyInputAndOutputAreSkipped(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordInputBytes('');
        $r->recordOutput('');

        $this->assertSame([], $this->readEvents($fh));
    }

    public function testQuitEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordQuit();

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events);
        $this->assertSame('quit', $events[0]['k']);
        $this->assertArrayNotHasKey('b', $events[0]);
    }

    public function testCloseIsIdempotent(): void
    {
        $r = new Recorder($this->memory(), $this->stubHeader());
        $r->close();
        $r->close();
        $this->assertTrue(true);
    }

    public function testWriteAfterCloseIsNoOp(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput('before');
        rewind($fh);
        $beforeContents = stream_get_contents($fh);
        $r->close();
        // After close, all record* calls silently no-op so program teardown
        // writes that fire after recordQuit don't pollute the cassette.
        $r->recordOutput('after');
        $r->recordResize(1, 1);
        $r->recordInputBytes('x');
        $r->recordQuit();
        // Stream is closed, but the contents we already captured shouldn't grow.
        $this->assertStringContainsString('"before"', $beforeContents);
        $this->assertStringNotContainsString('"after"', $beforeContents);
    }

    public function testTimingIncreases(): void
    {
        $fh = $this->memory();
        $start = microtime(true);
        $r = new Recorder($fh, $this->stubHeader(), startTime: $start);
        $r->recordOutput('a');
        usleep(5_000);
        $r->recordOutput('b');

        $events = $this->readEvents($fh);
        $this->assertCount(2, $events);
        $this->assertLessThanOrEqual($events[1]['t'], $events[0]['t']);
    }

    public function testRecorderProducesValidCassetteRoundTripsThroughFormat(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader(), startTime: microtime(true));
        $r->recordResize(80, 24);
        $r->recordOutput("\x1b[2J");
        $r->recordInputBytes('q');
        $r->recordQuit();

        rewind($fh);
        $contents = stream_get_contents($fh);
        $cassette = (new JsonlFormat())->decode($contents);

        $this->assertSame(1, $cassette->header->version);
        $this->assertSame(4, $cassette->eventCount());
        $this->assertSame(EventKind::Resize, $cassette->events[0]->kind);
        $this->assertSame(EventKind::Output, $cassette->events[1]->kind);
        $this->assertSame(EventKind::Input, $cassette->events[2]->kind);
        $this->assertSame(EventKind::Quit, $cassette->events[3]->kind);
        $this->assertSame("\x1b[2J", $cassette->events[1]->payload['b']);
        $this->assertSame('q', $cassette->events[2]->payload['b']);
    }

    public function testOpenFactoryWritesToFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-test-');
        $this->assertNotFalse($path);
        try {
            $r = Recorder::open($path);
            $r->recordResize(80, 24);
            $r->recordQuit();
            $r->close();

            $cassette = (new JsonlFormat())->read($path);
            $this->assertSame(2, $cassette->eventCount());
        } finally {
            @unlink($path);
        }
    }

    public function testOpenFactoryThrowsOnUnwritablePath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot open recorder file');
        Recorder::open(sys_get_temp_dir() . '/candy-vcr-missing-' . uniqid() . '/never/path.cas');
    }

    public function testRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line — intentional bad type */
        new Recorder('not-a-resource', $this->stubHeader());
    }

    public function testDefaultHeaderHasSensibleDefaults(): void
    {
        $h = Recorder::defaultHeader();
        $this->assertSame(1, $h->version);
        $this->assertSame(80, $h->cols);
        $this->assertSame(24, $h->rows);
        $this->assertSame('sugarcraft/candy-vcr@dev', $h->runtime);
        $this->assertNotEmpty($h->createdAt);
    }

    /** @return resource */
    private function memory()
    {
        $fh = fopen('php://memory', 'w+b');
        $this->assertNotFalse($fh);
        return $fh;
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

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    private function firstLine($fh): array
    {
        rewind($fh);
        $line = fgets($fh);
        $this->assertNotFalse($line);
        $data = json_decode(trim($line), true);
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * @param resource $fh
     * @return list<array<string, mixed>>
     */
    private function readEvents($fh): array
    {
        rewind($fh);
        fgets($fh);  // skip header
        $events = [];
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $data = json_decode($line, true);
            $this->assertIsArray($data);
            $events[] = $data;
        }
        return $events;
    }
}
