<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Migration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Migration\V1ToV2Migrator;

final class V1ToV2MigratorTest extends TestCase
{
    private V1ToV2Migrator $migrator;

    protected function setUp(): void
    {
        $this->migrator = new V1ToV2Migrator();
    }

    public function testGetSourceVersion(): void
    {
        $this->assertSame(1, $this->migrator->getSourceVersion());
    }

    public function testGetTargetVersion(): void
    {
        $this->assertSame(2, $this->migrator->getTargetVersion());
    }

    public function testDescribeReturnsNonEmptyString(): void
    {
        $desc = $this->migrator->describe();
        $this->assertNotEmpty($desc);
        $this->assertStringContainsString('v1', $desc);
        $this->assertStringContainsString('v2', $desc);
    }

    public function testCanMigrateV1Cassette(): void
    {
        $cassette = $this->buildV1Cassette();
        $this->assertTrue($this->migrator->canMigrate($cassette));
    }

    public function testCannotMigrateV2Cassette(): void
    {
        $cassette = $this->buildV2Cassette();
        $this->assertFalse($this->migrator->canMigrate($cassette));
    }

    public function testCannotMigrateAlreadyV2Cassette(): void
    {
        $header = new CassetteHeader(
            version: 2,
            createdAt: '2026-05-09T03:55:02Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
        );
        $cassette = new Cassette($header, []);
        $this->assertFalse($this->migrator->canMigrate($cassette));
    }

    public function testMigrateUpgradesVersionToV2(): void
    {
        $cassette = $this->buildV1Cassette();
        $migrated = $this->migrator->migrate($cassette);

        $this->assertSame(2, $migrated->header->version);
    }

    public function testMigratePreservesHeaderFields(): void
    {
        $cassette = $this->buildV1Cassette();
        $migrated = $this->migrator->migrate($cassette);

        $this->assertSame('2026-05-09T03:55:02Z', $migrated->header->createdAt);
        $this->assertSame(80, $migrated->header->cols);
        $this->assertSame(24, $migrated->header->rows);
        $this->assertSame('sugarcraft/candy-vcr@dev', $migrated->header->runtime);
    }

    public function testMigratePreservesEventCount(): void
    {
        $cassette = $this->buildV1Cassette();
        $migrated = $this->migrator->migrate($cassette);

        $this->assertSame($cassette->eventCount(), $migrated->eventCount());
    }

    public function testMigrateAddsEventIds(): void
    {
        $cassette = $this->buildV1Cassette();
        $migrated = $this->migrator->migrate($cassette);

        foreach ($migrated->events as $idx => $event) {
            $this->assertArrayHasKey('_id', $event->payload);
            $this->assertSame($idx, $event->payload['_id']);
        }
    }

    public function testMigrateAddsEncodingToOutputEvents(): void
    {
        $cassette = $this->buildV1Cassette();
        $migrated = $this->migrator->migrate($cassette);

        foreach ($migrated->events as $event) {
            if ($event->kind === EventKind::Output) {
                $this->assertArrayHasKey('_enc', $event->payload);
                $this->assertSame('utf-8', $event->payload['_enc']);
            }
        }
    }

    public function testMigratePreservesAllOtherEventFields(): void
    {
        $resizeEvent = new Event(
            t: 0.001,
            kind: EventKind::Resize,
            payload: ['cols' => 80, 'rows' => 24],
        );
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-09T03:55:02Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [$resizeEvent],
        );

        $migrated = $this->migrator->migrate($cassette);

        $this->assertSame(0.001, $migrated->events[0]->t);
        $this->assertSame(EventKind::Resize, $migrated->events[0]->kind);
        $this->assertSame(80, $migrated->events[0]->payload['cols']);
        $this->assertSame(24, $migrated->events[0]->payload['rows']);
    }

    public function testDryRunDoesNotModifyCassette(): void
    {
        $cassette = $this->buildV1Cassette();
        $originalVersion = $cassette->header->version;

        $migrated = $this->migrator->migrate($cassette, dryRun: true);

        // Dry run still returns a migrated cassette (it's a pure function)
        // but we can verify the original is unchanged by reconstructing
        $this->assertSame($originalVersion, $cassette->header->version);
        $this->assertSame(2, $migrated->header->version);
    }

    public function testMigrateIsIdempotentOnV2Cassette(): void
    {
        $cassette = $this->buildV2Cassette();
        $migrated = $this->migrator->migrate($cassette);

        // V2 cassette should pass through unchanged
        $this->assertSame($cassette->header->version, $migrated->header->version);
        $this->assertCount($cassette->eventCount(), $migrated->events);
    }

    public function testMigrateHandlesEmptyCassette(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-01-01T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [],
        );

        $migrated = $this->migrator->migrate($cassette);

        $this->assertSame(2, $migrated->header->version);
        $this->assertSame(0, $migrated->eventCount());
    }

    public function testMigrateHandlesMixedEventTypes(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-09T03:55:02Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => "\x1b[2J"]),
                new Event(t: 0.002, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.003, kind: EventKind::Input, payload: ['b' => 'abc']),
                new Event(t: 0.03, kind: EventKind::Quit, payload: []),
                new Event(t: 0.031, kind: EventKind::Output, payload: ['b' => "\x1b[?2027l"]),
            ],
        );

        $migrated = $this->migrator->migrate($cassette);

        $this->assertSame(5, $migrated->eventCount());
        $this->assertSame(2, $migrated->header->version);

        // Verify each event got an id
        foreach ($migrated->events as $event) {
            $this->assertArrayHasKey('_id', $event->payload);
        }

        // Verify output events got encoding
        $outputEvents = array_filter(
            $migrated->events,
            fn($e) => $e->kind === EventKind::Output,
        );
        foreach ($outputEvents as $event) {
            $this->assertArrayHasKey('_enc', $event->payload);
            $this->assertSame('utf-8', $event->payload['_enc']);
        }
    }

    private function buildV1Cassette(): Cassette
    {
        return new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-09T03:55:02Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => "\x1b[?2027h"]),
                new Event(t: 0.001, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
                new Event(t: 0.002, kind: EventKind::Input, payload: ['b' => 'abc']),
                new Event(t: 0.003, kind: EventKind::Output, payload: ['b' => "\x1b[?2026h"]),
                new Event(t: 0.03, kind: EventKind::Quit, payload: []),
                new Event(t: 0.03, kind: EventKind::Output, payload: ['b' => "\x1b[?2027l"]),
            ],
        );
    }

    private function buildV2Cassette(): Cassette
    {
        return new Cassette(
            new CassetteHeader(
                version: 2,
                createdAt: '2026-05-09T03:55:02Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.001, kind: EventKind::Quit, payload: ['_id' => 0]),
            ],
        );
    }
}
