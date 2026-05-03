<?php

declare(strict_types=1);

namespace CandyCore\Tick\Tests;

use CandyCore\Tick\Heartbeat;
use CandyCore\Tick\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sugar-tick-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmp);
    }

    public function testAppendAndLoadDayRoundTrip(): void
    {
        $s = new Store($this->tmp);
        $day = new \DateTimeImmutable('2026-05-03');
        $hb  = new Heartbeat(
            time:     $day->getTimestamp() + 3600,
            project:  'sugarcraft',
            language: 'php',
            file:     'src/X.php',
            duration: 120,
        );
        $s->append($hb);

        $loaded = $s->loadDay($day);
        $this->assertCount(1, $loaded);
        $this->assertSame('sugarcraft', $loaded[0]->project);
        $this->assertSame('php',        $loaded[0]->language);
        $this->assertSame(120,          $loaded[0]->duration);
    }

    public function testLoadMissingDayReturnsEmpty(): void
    {
        $s = new Store($this->tmp);
        $this->assertSame(
            [],
            $s->loadDay(new \DateTimeImmutable('2026-01-01')),
        );
    }

    public function testLoadRangeMergesAcrossDays(): void
    {
        $s = new Store($this->tmp);
        $a = new \DateTimeImmutable('2026-05-01 12:00');
        $b = new \DateTimeImmutable('2026-05-02 12:00');
        $c = new \DateTimeImmutable('2026-05-03 12:00');
        $s->append(new Heartbeat($a->getTimestamp(), 'p', 'php', '', 60));
        $s->append(new Heartbeat($b->getTimestamp(), 'p', 'php', '', 60));
        $s->append(new Heartbeat($c->getTimestamp(), 'p', 'php', '', 60));

        $range = $s->loadRange(
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-03'),
        );
        $this->assertCount(3, $range);
    }

    public function testCorruptLineIsSkipped(): void
    {
        $day = new \DateTimeImmutable('2026-05-03');
        file_put_contents(
            $this->tmp . '/2026-05-03.jsonl',
            json_encode(['time' => 1, 'project' => 'a', 'duration' => 30]) . "\n"
            . "this is not json\n"
            . json_encode(['time' => 2, 'project' => 'b', 'duration' => 45]) . "\n"
        );
        $s = new Store($this->tmp);
        $loaded = $s->loadDay($day);
        $this->assertCount(2, $loaded);
        $this->assertSame('a', $loaded[0]->project);
        $this->assertSame('b', $loaded[1]->project);
    }
}
