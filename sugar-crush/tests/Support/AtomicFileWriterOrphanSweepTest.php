<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\AtomicFileWriter;

/**
 * Audit F-T7 residual (R8): a SIGKILL between {@see AtomicFileWriter}'s temp
 * `fopen()` and its `rename()` runs no cleanup, so `.<name>.tmp.<16 hex>`
 * stayed beside the user's file forever — one more per kill. The next
 * successful publish of the same target now sweeps them, but only the ones no
 * live writer can still own.
 *
 * A real SIGKILL is not needed to make the orphan: it is exactly a file of
 * that name, and its age is the only thing the sweep reads. Planting one with
 * an old mtime is the state the kill leaves.
 */
final class AtomicFileWriterOrphanSweepTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath((string) sys_get_temp_dir()) . '/sugarcrush_afw_sweep_' . uniqid((string) getmypid(), true);
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (array_diff((array) scandir($this->dir), ['.', '..']) as $name) {
            $path = $this->dir . '/' . $name;
            is_dir($path) && !is_link($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->dir);
    }

    public function testReplaceSweepsAStaleOrphanOfTheSameTarget(): void
    {
        $target = $this->dir . '/f.txt';
        file_put_contents($target, 'old');
        $orphan = $this->plant('.f.txt.tmp.0123456789abcdef', AtomicFileWriter::ORPHAN_TEMP_MIN_AGE_SECONDS + 60);

        AtomicFileWriter::replace($target, 'new');

        clearstatcache();
        self::assertFileDoesNotExist($orphan, 'a temp a killed publish left behind must not outlive the next publish');
        self::assertSame('new', file_get_contents($target));
        self::assertSame(['f.txt'], $this->entries());
    }

    public function testWriteSweepsTooBecauseItSharesThePublishCore(): void
    {
        $target = $this->dir . '/MEMORY.md';
        $orphan = $this->plant('.MEMORY.md.tmp.ffffffffffffffff', AtomicFileWriter::ORPHAN_TEMP_MIN_AGE_SECONDS + 60);

        AtomicFileWriter::write($target, 'index');

        clearstatcache();
        self::assertFileDoesNotExist($orphan);
        self::assertSame(['MEMORY.md'], $this->entries());
    }

    public function testAFreshTempIsLeftAloneBecauseALiveWriterMayOwnIt(): void
    {
        // A concurrent publish of the same file holds a temp of exactly this
        // shape while it writes; removing it would fail THAT publish.
        $target = $this->dir . '/f.txt';
        file_put_contents($target, 'old');
        $live = $this->plant('.f.txt.tmp.aaaaaaaaaaaaaaaa', 5);

        AtomicFileWriter::replace($target, 'new');

        clearstatcache();
        self::assertFileExists($live);
    }

    public function testOnlyTheExactTempShapeOfThisTargetIsSwept(): void
    {
        $target = $this->dir . '/f.txt';
        file_put_contents($target, 'old');
        $age = AtomicFileWriter::ORPHAN_TEMP_MIN_AGE_SECONDS + 60;

        $kept = [
            // another file's temp
            $this->plant('.g.txt.tmp.0123456789abcdef', $age),
            // a user's own lookalikes: wrong length, wrong alphabet, a suffix
            $this->plant('.f.txt.tmp.0123456789abcde', $age),
            $this->plant('.f.txt.tmp.0123456789ABCDEF', $age),
            $this->plant('.f.txt.tmp.0123456789abcdef.bak', $age),
            $this->plant('.f.txt.tmp.bak', $age),
            // a name that would match if the target's basename were not
            // taken literally (`f?txt` against `fxtxt`)
            $this->plant('.fxtxt.tmp.0123456789abcdef', $age),
        ];

        AtomicFileWriter::replace($target, 'new');

        clearstatcache();
        foreach ($kept as $path) {
            self::assertFileExists($path, basename($path) . ' is not a temp of f.txt and must survive');
        }
    }

    public function testGlobMetacharactersInTheTargetNameDoNotWidenTheSweep(): void
    {
        $target = $this->dir . '/n[1]*.md';
        file_put_contents($target, 'old');
        $age = AtomicFileWriter::ORPHAN_TEMP_MIN_AGE_SECONDS + 60;
        $own = $this->plant('.n[1]*.md.tmp.0123456789abcdef', $age);
        // `[1]` as a class would match `n1`, `*` would match anything.
        $neighbour = $this->plant('.n1zz.md.tmp.0123456789abcdef', $age);

        AtomicFileWriter::replace($target, 'new');

        clearstatcache();
        self::assertFileDoesNotExist($own);
        self::assertFileExists($neighbour);
    }

    public function testASymlinkOrDirectoryWearingTheTempNameIsNeverRemoved(): void
    {
        $target = $this->dir . '/f.txt';
        file_put_contents($target, 'old');
        $victim = $this->dir . '/victim';
        file_put_contents($victim, 'keep');

        $link = $this->dir . '/.f.txt.tmp.1111111111111111';
        symlink($victim, $link);
        $dir = $this->dir . '/.f.txt.tmp.2222222222222222';
        mkdir($dir);
        $old = time() - AtomicFileWriter::ORPHAN_TEMP_MIN_AGE_SECONDS - 60;
        touch($dir, $old);

        AtomicFileWriter::replace($target, 'new');

        clearstatcache();
        self::assertTrue(is_link($link), 'a planted symlink is not ours to remove');
        self::assertSame('keep', file_get_contents($victim));
        self::assertDirectoryExists($dir);
    }

    public function testAFailedPublishDoesNotSweep(): void
    {
        // The sweep belongs to a publish that SUCCEEDED; one that threw leaves
        // the directory exactly as it found it apart from its own temp.
        $target = $this->dir . '/f.txt';
        file_put_contents($target, 'old');
        $orphan = $this->plant('.f.txt.tmp.0123456789abcdef', AtomicFileWriter::ORPHAN_TEMP_MIN_AGE_SECONDS + 60);

        try {
            AtomicFileWriter::replace($target, 'new', static function ($handle, string $contents): void {
                throw new \RuntimeException('disk full');
            });
            self::fail('the failing writer seam must surface');
        } catch (\RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        }

        clearstatcache();
        self::assertFileExists($orphan);
        self::assertSame('old', file_get_contents($target));
    }

    private function plant(string $name, int $ageSeconds): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, 'half-written');
        touch($path, time() - $ageSeconds);

        return $path;
    }

    /** @return list<string> */
    private function entries(): array
    {
        $names = array_values(array_diff((array) scandir($this->dir), ['.', '..']));
        sort($names);

        return $names;
    }
}
