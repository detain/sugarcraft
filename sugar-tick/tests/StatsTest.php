<?php

declare(strict_types=1);

namespace CandyCore\Tick\Tests;

use CandyCore\Tick\Heartbeat;
use CandyCore\Tick\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    private function beats(array $rows): array
    {
        return array_map(static fn(array $r) => Heartbeat::fromArray($r), $rows);
    }

    public function testPerProjectSortedDesc(): void
    {
        $beats = $this->beats([
            ['time' => 1, 'project' => 'a', 'language' => 'php', 'duration' => 60],
            ['time' => 2, 'project' => 'a', 'language' => 'php', 'duration' => 30],
            ['time' => 3, 'project' => 'b', 'language' => 'go',  'duration' => 120],
        ]);
        $s = Stats::compute($beats,
            new \DateTimeImmutable('@0'), new \DateTimeImmutable('@86400'));
        $rank = $s->perProject();
        $this->assertSame(['b' => 120, 'a' => 90], $rank);
    }

    public function testPerLanguageSums(): void
    {
        $beats = $this->beats([
            ['time' => 1, 'project' => 'a', 'language' => 'php', 'duration' => 60],
            ['time' => 2, 'project' => 'b', 'language' => 'php', 'duration' => 60],
            ['time' => 3, 'project' => 'a', 'language' => 'go',  'duration' => 30],
        ]);
        $s = Stats::compute($beats,
            new \DateTimeImmutable('@0'), new \DateTimeImmutable('@86400'));
        $rank = $s->perLanguage();
        $this->assertSame(['php' => 120, 'go' => 30], $rank);
    }

    public function testTimelineBucketsByDay(): void
    {
        // Two days, two beats each.
        $day1 = new \DateTimeImmutable('2026-05-01 12:00');
        $day2 = new \DateTimeImmutable('2026-05-02 09:00');
        $beats = $this->beats([
            ['time' => $day1->getTimestamp(), 'project' => 'a', 'duration' => 60],
            ['time' => $day1->getTimestamp() + 3600, 'project' => 'a', 'duration' => 30],
            ['time' => $day2->getTimestamp(), 'project' => 'a', 'duration' => 90],
        ]);
        $s = Stats::compute($beats,
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-02'));
        $this->assertSame([90, 90], $s->timeline());
    }

    public function testFormatHours(): void
    {
        $this->assertSame('0h 00m', Stats::formatHours(0));
        $this->assertSame('0h 30m', Stats::formatHours(1800));
        $this->assertSame('1h 00m', Stats::formatHours(3600));
        $this->assertSame('2h 30m', Stats::formatHours(9000));
    }

    public function testTotalSecondsSums(): void
    {
        $beats = $this->beats([
            ['time' => 1, 'duration' => 60],
            ['time' => 2, 'duration' => 30],
            ['time' => 3, 'duration' => 90],
        ]);
        $s = Stats::compute($beats,
            new \DateTimeImmutable('@0'), new \DateTimeImmutable('@86400'));
        $this->assertSame(180, $s->totalSeconds());
    }
}
