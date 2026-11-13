<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session\Gc;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\Gc\GcInventory;
use SugarCraft\Code\Session\Gc\GcVacuum;

final class GcVacuumTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite required');
        }
        $this->dir = sys_get_temp_dir() . '/gcvacuum-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function seed(string $path, int $autoVacuum): void
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA auto_vacuum = ' . $autoVacuum);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec('BEGIN');
        $stmt = $pdo->prepare('INSERT INTO t (v) VALUES (?)');
        for ($i = 0; $i < 200; $i++) {
            $stmt->execute([str_repeat('x', 2000)]);
        }
        $pdo->exec('COMMIT');
        $pdo->exec('DELETE FROM t');
        $stmt = null;
        $pdo = null;
    }

    public function testFullVacuumShrinksFileAndEmptiesFreelist(): void
    {
        $db = $this->dir . '/full.db';
        $this->seed($db, GcInventory::AUTO_VACUUM_NONE);

        $r = (new GcVacuum($db))->run(false);

        self::assertTrue($r['performed']);
        self::assertSame('full', $r['mode']);
        self::assertFalse($r['flipped']);
        self::assertGreaterThan(0, $r['freelist_before']);
        self::assertSame(0, $r['freelist_after']);
        self::assertSame($r['freelist_before'], $r['pages_released']);
        self::assertLessThan($r['before']['db'], $r['after']['db']);
        self::assertSame(GcInventory::AUTO_VACUUM_NONE, $r['auto_vacuum_after']);
    }

    public function testIncrementalOnPlainStoreFlipsAutoVacuumMode(): void
    {
        $db = $this->dir . '/flip.db';
        $this->seed($db, GcInventory::AUTO_VACUUM_NONE);

        $r = (new GcVacuum($db))->run(true);

        self::assertSame('flip', $r['mode']);
        self::assertTrue($r['flipped']);
        self::assertSame(GcInventory::AUTO_VACUUM_NONE, $r['auto_vacuum_before']);
        self::assertSame(GcInventory::AUTO_VACUUM_INCREMENTAL, $r['auto_vacuum_after']);
    }

    public function testIncrementalOnIncrementalStoreDrainsFreelist(): void
    {
        $db = $this->dir . '/drain.db';
        $this->seed($db, GcInventory::AUTO_VACUUM_INCREMENTAL);

        $r = (new GcVacuum($db))->run(true);

        self::assertSame('drain', $r['mode']);
        self::assertFalse($r['flipped']);
        self::assertSame(GcInventory::AUTO_VACUUM_INCREMENTAL, $r['auto_vacuum_before']);
        self::assertSame(0, $r['freelist_after']);
        self::assertGreaterThan(0, $r['pages_released']);
    }

    public function testReportShapeCarriesSizesAndFreeBytes(): void
    {
        $db = $this->dir . '/shape.db';
        $this->seed($db, GcInventory::AUTO_VACUUM_NONE);

        $r = (new GcVacuum($db))->run(false);

        self::assertSame(['db', 'wal', 'shm', 'total', 'present'], array_keys($r['before']));
        self::assertSame(['db', 'wal', 'shm', 'total', 'present'], array_keys($r['after']));
        self::assertGreaterThanOrEqual(0, $r['free_bytes']);
    }
}
