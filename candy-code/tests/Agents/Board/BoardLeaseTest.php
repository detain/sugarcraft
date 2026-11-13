<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents\Board;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Board\BoardLease;

/** The lease owns the board file's lifetime: released explicitly, on destruct, and idempotently. */
final class BoardLeaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/boardlease-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function file(): string
    {
        $path = $this->dir . '/b.board';
        file_put_contents($path, 'x');

        return $path;
    }

    public function testReleaseDeletesTheFile(): void
    {
        $path = $this->file();
        $lease = new BoardLease($path);

        $lease->release();

        self::assertFileDoesNotExist($path);
    }

    public function testReleaseIsIdempotentAndDoesNotTouchARecreatedFile(): void
    {
        $path = $this->file();
        $lease = new BoardLease($path);
        $lease->release();
        file_put_contents($path, 'again');

        $lease->release();

        self::assertFileExists($path, 'a second release must be a no-op');
    }

    public function testDestructReleasesTheFile(): void
    {
        $path = $this->file();
        $lease = new BoardLease($path);

        unset($lease);

        self::assertFileDoesNotExist($path);
    }

    public function testReleasingAnAlreadyMissingFileIsSilent(): void
    {
        $lease = new BoardLease($this->dir . '/never-existed.board');

        $lease->release();

        $this->addToAssertionCount(1);
    }
}
