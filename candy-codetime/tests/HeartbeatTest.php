<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Stats;
use PHPUnit\Framework\TestCase;

final class HeartbeatTest extends TestCase
{
    public function testConstructorStoresFields(): void
    {
        $hb = new Heartbeat(time: 1234, project: 'demo', language: 'php', file: 'a.php', duration: 30);
        $this->assertSame(1234, $hb->time);
        $this->assertSame('demo', $hb->project);
        $this->assertSame('php', $hb->language);
        $this->assertSame('a.php', $hb->file);
        $this->assertSame(30, $hb->duration);
    }

    public function testDefaultDurationIs60(): void
    {
        $hb = new Heartbeat(0, 'p', 'l', 'f');
        $this->assertSame(60, $hb->duration);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $hb = Heartbeat::fromArray([
            'time' => 1700000000,
            'project' => 'sugarcraft',
            'language' => 'php',
            'file' => '/src/a.php',
            'duration' => 120,
        ]);
        $this->assertSame(1700000000, $hb->time);
        $this->assertSame('sugarcraft', $hb->project);
        $this->assertSame('php', $hb->language);
        $this->assertSame('/src/a.php', $hb->file);
        $this->assertSame(120, $hb->duration);
    }

    public function testFromArrayUsesDefaultsForMissingFields(): void
    {
        // CT-S15 re-pin: the empty row no longer hydrates with wall-clock
        // NOW. `time` is required; the optional text legs keep their
        // defaults.
        $hb = Heartbeat::fromArray(['time' => 100]);
        $this->assertSame(100, $hb->time);
        $this->assertSame('unknown', $hb->project);
        $this->assertSame('unknown', $hb->language);
        $this->assertSame('', $hb->file);
        $this->assertSame(60, $hb->duration);
    }

    public function testFromArrayRefusesAMissingTimeInsteadOfFabricatingOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Heartbeat::fromArray(['project' => 'x']);
    }

    public function testFromArrayRefusesANonNumericTimeLikeAMissingOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Heartbeat::fromArray(['time' => 'not-a-time']);
    }

    public function testConstructorClampsToTheDocumentedUpperBounds(): void
    {
        $hb = new Heartbeat(
            time: PHP_INT_MAX, project: 'p', language: 'l', file: 'f',
            duration: PHP_INT_MAX,
        );
        $this->assertSame(Heartbeat::MAX_TIME_SECONDS, $hb->time);
        $this->assertSame(Heartbeat::MAX_DURATION_SECONDS, $hb->duration);
    }

    public function testFromArrayCoercesScalars(): void
    {
        $hb = Heartbeat::fromArray([
            'time' => '42',
            'duration' => '15',
            'project' => 12345,
        ]);
        $this->assertSame(42, $hb->time);
        $this->assertSame(15, $hb->duration);
        $this->assertSame('12345', $hb->project);
    }

    public function testToArrayRoundTrips(): void
    {
        $row = [
            'time' => 1234,
            'project' => 'demo',
            'language' => 'php',
            'file' => 'a.php',
            'duration' => 60,
            'tags' => [],
        ];
        $this->assertSame($row, Heartbeat::fromArray($row)->toArray());
    }

    public function testConstructorStoresTags(): void
    {
        $hb = new Heartbeat(time: 1234, project: 'demo', language: 'php', file: 'a.php', duration: 30, tags: ['refactoring']);
        $this->assertSame(['refactoring'], $hb->tags);
    }

    public function testDefaultTagsIsEmptyArray(): void
    {
        $hb = new Heartbeat(0, 'p', 'l', 'f');
        $this->assertSame([], $hb->tags);
    }

    public function testWithTagsReturnsNewInstance(): void
    {
        $hb = new Heartbeat(time: 1234, project: 'demo', language: 'php', file: 'a.php', duration: 30);
        $hb2 = $hb->withTags(['feature', 'bugfix']);
        $this->assertSame([], $hb->tags);
        $this->assertSame(['feature', 'bugfix'], $hb2->tags);
    }

    public function testFromArrayParsesTags(): void
    {
        $hb = Heartbeat::fromArray([
            'time' => 1700000000,
            'project' => 'sugarcraft',
            'language' => 'php',
            'file' => '/src/a.php',
            'duration' => 120,
            'tags' => ['refactoring', 'feature'],
        ]);
        $this->assertSame(['refactoring', 'feature'], $hb->tags);
    }

    public function testFromArrayDefaultsTagsToEmptyArray(): void
    {
        $hb = Heartbeat::fromArray([
            'time' => 1700000000,
            'project' => 'sugarcraft',
            'language' => 'php',
            'file' => '/src/a.php',
            'duration' => 120,
        ]);
        $this->assertSame([], $hb->tags);
    }

    public function testFromArrayIgnoresNonStringTags(): void
    {
        $hb = Heartbeat::fromArray([
            'time' => 1700000000,
            'project' => 'sugarcraft',
            'language' => 'php',
            'file' => '/src/a.php',
            'duration' => 120,
            'tags' => ['valid', 123, 'also-valid', null, false],
        ]);
        $this->assertSame(['valid', 'also-valid'], $hb->tags);
    }

    public function testToArrayIncludesTags(): void
    {
        $hb = new Heartbeat(time: 1234, project: 'demo', language: 'php', file: 'a.php', duration: 60, tags: ['tag1', 'tag2']);
        $arr = $hb->toArray();
        $this->assertArrayHasKey('tags', $arr);
        $this->assertSame(['tag1', 'tag2'], $arr['tags']);
    }

    public function testToArrayRoundTripsWithTags(): void
    {
        $row = [
            'time' => 1234,
            'project' => 'demo',
            'language' => 'php',
            'file' => 'a.php',
            'duration' => 60,
            'tags' => ['tag1', 'tag2'],
        ];
        $this->assertSame($row, Heartbeat::fromArray($row)->toArray());
    }

    public function testNegativeDurationThrows(): void
    {
        // Mirrors Heartbeat::__construct() validation - negative duration throws
        $this->expectException(\InvalidArgumentException::class);
        new Heartbeat(time: 0, project: 'p', language: 'l', file: 'f', duration: -1);
    }

    public function testNegativeTimeThrows(): void
    {
        // Mirrors Heartbeat::__construct() validation - negative time throws
        $this->expectException(\InvalidArgumentException::class);
        new Heartbeat(time: -1, project: 'p', language: 'l', file: 'f', duration: 60);
    }

    public function testFromArrayClampsNegativeDuration(): void
    {
        // Mirrors Heartbeat::fromArray() clamping for untrusted JSONL input
        $hb = Heartbeat::fromArray(['time' => 1, 'duration' => -5, 'project' => 'p', 'language' => 'l', 'file' => 'f']);
        $this->assertSame(0, $hb->duration);
    }

    public function testFromArrayClampsNegativeTime(): void
    {
        // Mirrors Heartbeat::fromArray() clamping for untrusted JSONL input
        $hb = Heartbeat::fromArray(['time' => -100, 'duration' => 60, 'project' => 'p', 'language' => 'l', 'file' => 'f']);
        $this->assertSame(0, $hb->time);
    }

    public function testFromArrayDefaultsTagsWhenNotArray(): void
    {
        // Mirrors Heartbeat::fromArray() lines 37-40: when tags is not an array,
        // it falls back to empty array instead of using the invalid value
        $hb = Heartbeat::fromArray([
            'time' => 1700000000,
            'project' => 'sugarcraft',
            'language' => 'php',
            'file' => '/src/a.php',
            'duration' => 120,
            'tags' => 'not-an-array',  // scalar should be treated as invalid
        ]);
        $this->assertSame([], $hb->tags);
    }

    /**
     * Read-path clamp (review M12): a hand-echoed or tampered row carrying a
     * near-PHP_INT_MAX duration used to sail past the write-side clamp and
     * poison every downstream reader — Stats sums overflow to float and
     * formatHours(int) TypeErrors under strict_types, and date() calls on a
     * far-future stamp blow up the window maths. fromArray is where stored
     * data becomes live objects, so it re-applies exactly the window the
     * writer enforced (the write clamp must not be silently widened).
     */
    public function testPoisonedStoredRowsAreReclampedToTheWriteWindow(): void
    {
        $poisoned = Heartbeat::fromArray([
            'time'     => PHP_INT_MAX,
            'duration' => PHP_INT_MAX,
            'project'  => 'p',
            'language' => 'l',
            'file'     => 'f',
        ]);
        $this->assertSame(Heartbeat::MAX_DURATION_SECONDS, $poisoned->duration);
        $this->assertSame(Heartbeat::MAX_TIME_SECONDS, $poisoned->time);

        $negative = Heartbeat::fromArray(['time' => 1000, 'duration' => -5]);
        $this->assertSame(0, $negative->duration);

        // The clamped beat must be inert for the dashboard's arithmetic:
        // per-day sums stay integers (pre-clamp the += overflowed to float).
        $stats = new Stats(
            [$poisoned, $negative],
            [new \DateTimeImmutable('@' . (string) Heartbeat::MAX_TIME_SECONDS)],
        );
        $timeline = $stats->timeline();
        $this->assertIsInt($timeline[0]);
        $this->assertSame(Heartbeat::MAX_DURATION_SECONDS, $timeline[0]);
        $this->assertStringContainsString('h', Stats::formatHours($stats->totalSeconds()));
    }
}
