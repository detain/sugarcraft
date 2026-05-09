<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

final class CassetteTest extends TestCase
{
    public function testEventKindValues(): void
    {
        $this->assertSame('resize', EventKind::Resize->value);
        $this->assertSame('input', EventKind::Input->value);
        $this->assertSame('output', EventKind::Output->value);
        $this->assertSame('quit', EventKind::Quit->value);
    }

    public function testEventConstructor(): void
    {
        $e = new Event(t: 0.123, kind: EventKind::Output, payload: ['b' => 'hello']);
        $this->assertSame(0.123, $e->t);
        $this->assertSame(EventKind::Output, $e->kind);
        $this->assertSame(['b' => 'hello'], $e->payload);
    }

    public function testEventRejectsNegativeTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Event(t: -0.001, kind: EventKind::Quit, payload: []);
    }

    public function testHeaderConstructor(): void
    {
        $h = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-core@dev',
        );
        $this->assertSame(1, $h->version);
        $this->assertSame('2026-05-07T10:00:00Z', $h->createdAt);
        $this->assertSame(80, $h->cols);
        $this->assertSame(24, $h->rows);
        $this->assertSame('sugarcraft/candy-core@dev', $h->runtime);
    }

    public function testHeaderRejectsBadVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CassetteHeader(version: 0, createdAt: '', cols: 80, rows: 24, runtime: '');
    }

    public function testHeaderRejectsBadDimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CassetteHeader(version: 1, createdAt: '', cols: 0, rows: 24, runtime: '');
    }

    public function testHeaderCurrentVersionConstant(): void
    {
        $this->assertSame(1, CassetteHeader::CURRENT_VERSION);
    }

    public function testCassetteConstructorAndAccessors(): void
    {
        $h = $this->stubHeader();
        $events = [
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'hi']),
            new Event(t: 1.5, kind: EventKind::Quit, payload: []),
        ];
        $c = new Cassette($h, $events);

        $this->assertSame($h, $c->header);
        $this->assertCount(3, $c->events);
        $this->assertSame(3, $c->eventCount());
        $this->assertSame(1.5, $c->duration());
    }

    public function testCassetteAcceptsIterableNotJustArray(): void
    {
        $events = (function (): \Generator {
            yield new Event(t: 0.0, kind: EventKind::Quit, payload: []);
        })();
        $c = new Cassette($this->stubHeader(), $events);
        $this->assertCount(1, $c->events);
    }

    public function testCassetteRejectsNonEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line — intentional bad type */
        new Cassette($this->stubHeader(), [new \stdClass()]);
    }

    public function testCassetteWithNoEventsHasZeroDuration(): void
    {
        $c = new Cassette($this->stubHeader(), []);
        $this->assertSame(0, $c->eventCount());
        $this->assertSame(0.0, $c->duration());
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
