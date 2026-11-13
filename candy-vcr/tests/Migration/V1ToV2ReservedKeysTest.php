<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Migration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\MigrateCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Migration\V1ToV2Migrator;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * The v2 reserved-key contract (finding 108).
 *
 * `_id` and `_enc` are v2 namespace. A v1 payload already carrying them
 * used to be stamped over with no word spoken — user data destroyed
 * silently. The overwrite stays (a skip would emit a malformed v2), but
 * it is now counted by reservedKeyCollisions() and named on stderr by
 * the migrate door before anything is written.
 */
final class V1ToV2ReservedKeysTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testCounterSeesBothReservedKeysAndCountsPerHit(): void
    {
        $cassette = new Cassette($this->v1Header(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi', '_id' => 'mine', '_enc' => 'base64']),
            new Event(t: 0.1, kind: EventKind::Quit, payload: ['_id' => 7]),
        ]);

        $this->assertSame(3, V1ToV2Migrator::reservedKeyCollisions($cassette));
    }

    public function testCleanV1PayloadsCollideWithNothing(): void
    {
        $cassette = new Cassette($this->v1Header(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi']),
            new Event(t: 0.1, kind: EventKind::Quit, payload: []),
        ]);

        $this->assertSame(0, V1ToV2Migrator::reservedKeyCollisions($cassette));
    }

    public function testStampKeepsV2ValidityOverUserValues(): void
    {
        $cassette = new Cassette($this->v1Header(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi', '_id' => 'mine', '_enc' => 'base64']),
            new Event(t: 0.1, kind: EventKind::Quit, payload: ['_id' => 7]),
        ]);

        $migrated = (new V1ToV2Migrator())->migrate($cassette);

        // A skip-instead would leave these user values as non-sequential
        // ids / un-declared encodings: the stamp wins, BY CONTRACT.
        $this->assertSame(0, $migrated->events[0]->payload['_id']);
        $this->assertSame('utf-8', $migrated->events[0]->payload['_enc']);
        $this->assertSame(1, $migrated->events[1]->payload['_id']);
    }

    public function testMigrateDoorWarnsByNameBeforeWriting(): void
    {
        $input = $this->writeCassette('cv-reserved-', new Cassette($this->v1Header(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi', '_id' => 'mine', '_enc' => 'base64']),
        ]));
        $backup = $input . '.bak';
        $this->paths[] = $backup;

        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $code = (new MigrateCommand())->run([$input], $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('reserves the payload keys _id and _enc', $err);
        $this->assertStringContainsString('2 pre-existing value(s)', $err);
    }

    public function testCleanMigrationStaysSilent(): void
    {
        $input = $this->writeCassette('cv-reserved-', new Cassette($this->v1Header(), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hi']),
        ]));
        $backup = $input . '.bak';
        $this->paths[] = $backup;

        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $code = (new MigrateCommand())->run([$input], $stdout, $stderr);
        rewind($stderr);

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('reserves', (string) stream_get_contents($stderr));
    }

    private function v1Header(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
        );
    }

    private function writeCassette(string $prefix, Cassette $cassette): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), $prefix);
        $this->paths[] = $path;
        (new JsonlFormat())->write($cassette, $path);

        return $path;
    }
}
