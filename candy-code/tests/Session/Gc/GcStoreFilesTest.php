<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session\Gc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\Gc\GcStoreFiles;

final class GcStoreFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gcstorefiles-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testAbsentStoreIsAllZeroAndNotPresent(): void
    {
        $sizes = GcStoreFiles::sizes($this->dir . '/nope.db');

        self::assertSame(['db' => 0, 'wal' => 0, 'shm' => 0, 'total' => 0, 'present' => false], $sizes);
    }

    public function testSizesSumDbWalAndShm(): void
    {
        $db = $this->dir . '/s.db';
        file_put_contents($db, str_repeat('a', 100));
        file_put_contents($db . '-wal', str_repeat('b', 20));
        file_put_contents($db . '-shm', str_repeat('c', 3));

        self::assertSame(['db' => 100, 'wal' => 20, 'shm' => 3, 'total' => 123, 'present' => true], GcStoreFiles::sizes($db));
    }

    public function testEmptyDbFileIsStillPresent(): void
    {
        $db = $this->dir . '/e.db';
        touch($db);

        $sizes = GcStoreFiles::sizes($db);

        self::assertSame(0, $sizes['db']);
        self::assertTrue($sizes['present']);
    }

    public function testSizesReflectGrowthDespiteStatCache(): void
    {
        $db = $this->dir . '/g.db';
        file_put_contents($db, 'abc');
        self::assertSame(3, GcStoreFiles::sizes($db)['db']);

        file_put_contents($db, 'abcdefgh');

        self::assertSame(8, GcStoreFiles::sizes($db)['db']);
    }

    public function testWalWithoutDbIsCountedButNotPresent(): void
    {
        $db = $this->dir . '/w.db';
        file_put_contents($db . '-wal', 'xx');

        $sizes = GcStoreFiles::sizes($db);

        self::assertSame(2, $sizes['total']);
        self::assertFalse($sizes['present']);
    }

    public function testFreeSpaceIsNonNegativeForExistingDirAndZeroForMissing(): void
    {
        self::assertGreaterThanOrEqual(0, GcStoreFiles::freeSpace($this->dir . '/x.db'));
        self::assertSame(0, GcStoreFiles::freeSpace($this->dir . '/missing/deeper/x.db'));
    }
}
