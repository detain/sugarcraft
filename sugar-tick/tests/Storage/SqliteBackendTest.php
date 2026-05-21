<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tick\Heartbeat;
use SugarCraft\Tick\Storage\SqliteBackend;

final class SqliteBackendTest extends TestCase
{
    private string $dbPath;
    private SqliteBackend $backend;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/sugar-tick-test-' . uniqid() . '.db';
        $this->backend = new SqliteBackend($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testInsertAndQuery(): void
    {
        $hb = new Heartbeat(
            time: 1718900000,
            project: 'test-project',
            language: 'php',
            file: 'src/Test.php',
            duration: 120,
            tags: ['feature', 'refactor'],
        );

        $this->backend->insert($hb);

        $results = $this->backend->query(1718899000, 1719000000);
        $this->assertCount(1, $results);
        $this->assertSame('test-project', $results[0]->project);
        $this->assertSame('php', $results[0]->language);
        $this->assertSame('src/Test.php', $results[0]->file);
        $this->assertSame(120, $results[0]->duration);
        $this->assertSame(['feature', 'refactor'], $results[0]->tags);
    }

    public function testQueryRangeBoundary(): void
    {
        $hb = new Heartbeat(
            time: 1718900000,
            project: 'test-project',
            language: 'php',
            file: 'src/Test.php',
        );
        $this->backend->insert($hb);

        // Outside range
        $this->assertCount(0, $this->backend->query(0, 1718899999));
        // Inside range
        $this->assertCount(1, $this->backend->query(1718900000, 1719000000));
    }

    public function testMultipleHeartbeatsOrderedByTime(): void
    {
        $hb1 = new Heartbeat(time: 1718900000, project: 'p1', language: 'php', file: 'a.php');
        $hb2 = new Heartbeat(time: 1718900100, project: 'p2', language: 'py', file: 'b.php');
        $hb3 = new Heartbeat(time: 1718900200, project: 'p3', language: 'rs', file: 'c.php');

        $this->backend->insert($hb2);
        $this->backend->insert($hb3);
        $this->backend->insert($hb1);

        $results = $this->backend->query(0, PHP_INT_MAX);
        $this->assertCount(3, $results);
        $this->assertSame('a.php', $results[0]->file);
        $this->assertSame('b.php', $results[1]->file);
        $this->assertSame('c.php', $results[2]->file);
    }

    public function testInsertMilestone(): void
    {
        $this->backend->insertMilestone('v1.0 shipped', 1719000000, 'First stable release');

        $milestones = $this->backend->milestones();
        $this->assertCount(1, $milestones);
        $this->assertSame('v1.0 shipped', $milestones[0]['name']);
        $this->assertSame(1719000000, $milestones[0]['time']);
        $this->assertSame('First stable release', $milestones[0]['description']);
    }

    public function testMilestonesOrderedByTime(): void
    {
        $this->backend->insertMilestone('later', 1719100000);
        $this->backend->insertMilestone('earlier', 1718900000);
        $this->backend->insertMilestone('middle', 1719000000);

        $milestones = $this->backend->milestones();
        $this->assertCount(3, $milestones);
        $this->assertSame('earlier', $milestones[0]['name']);
        $this->assertSame('middle', $milestones[1]['name']);
        $this->assertSame('later', $milestones[2]['name']);
    }
}
