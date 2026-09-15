<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Glow\FileWatcher;

/**
 * E714 (round 82, lane q13): idle backoff ladder, snap-back, and sleeper-seam
 * pins for FileWatcher::watch(). All timing-free — every test drives the pump
 * through the injectable $idleSleeper seam and NEVER relies on wall-clock
 * sleeps. Scripted drivers carry a sweep budget whose breach THROWS, so a
 * regression fails loud and never hangs.
 *
 * @covers \SugarCraft\Glow\FileWatcher
 */
final class FileWatcherIdlePollTest extends TestCase
{
    /** Temp file used by the current test; removed in tearDown. */
    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null && is_file($this->path)) {
            unlink($this->path);
        }
        $this->path = null;

        parent::tearDown();
    }

    private function createWatchedFile(string $contents = 'v1'): string
    {
        $this->path = sys_get_temp_dir() . '/q13_watcher_' . uniqid() . '.txt';
        file_put_contents($this->path, $contents);

        return $this->path;
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}> name => [sweeps, cap, expected]
     */
    public static function ladderCases(): array
    {
        $cap = 500_000;

        return [
            'zero coerces to floor'      => [0, $cap, 1_000],
            'first idle sweep is floor'  => [1, $cap, 1_000],
            'negative coerces to floor'  => [-3, $cap, 1_000],
            'second sweep doubles'       => [2, $cap, 2_000],
            'fourth sweep'               => [4, $cap, 8_000],
            'ninth sweep just under cap' => [9, $cap, 256_000],
            'tenth sweep hits cap'       => [10, $cap, 500_000],
            'cap holds'                  => [12, $cap, 500_000],
            'absurd count stays capped'  => [PHP_INT_MAX, $cap, 500_000],
            'shift clamp saturates'      => [FileWatcher::MAX_LADDER_SHIFT + 10, $cap, 500_000],
            'sub-floor cap pins to cap'  => [7, 500, 500],
            'non-positive cap floor 1us' => [1, 0, 1],
            'giant cap no overflow'      => [PHP_INT_MAX, PHP_INT_MAX, 1_000 << FileWatcher::MAX_LADDER_SHIFT],
        ];
    }

    /**
     * @dataProvider ladderCases
     */
    public function testIdlePollDelayMicrosecondsFollowsCappedLadder(int $sweeps, int $cap, int $expected): void
    {
        self::assertSame($expected, FileWatcher::idlePollDelayMicroseconds($sweeps, $cap));
    }

    public function testIdleSweepsBackOffAndHoldAtTheIntervalCap(): void
    {
        $path = $this->createWatchedFile();
        $sleeps = [];

        // Never modified: the pump would spin forever, so the seam throws
        // once the budget of sweeps is spent — loud, never hanging.
        $sleeper = static function (int $us) use (&$sleeps): void {
            $sleeps[] = $us;
            if (count($sleeps) === 12) {
                throw new \RuntimeException('sweep budget spent');
            }
        };

        $generator = FileWatcher::watch($path, 500, $sleeper);
        try {
            $generator->current();
            self::fail('the scripted driver must breach its sweep budget');
        } catch (\RuntimeException $breach) {
            self::assertSame('sweep budget spent', $breach->getMessage());
        }

        self::assertSame(
            [1_000, 2_000, 4_000, 8_000, 16_000, 32_000, 64_000, 128_000, 256_000, 500_000, 500_000, 500_000],
            $sleeps,
            'idle ladder must double from 1ms and hold at the interval cap'
        );
    }

    public function testSeenChangeSnapsTheLadderBackToTheFloor(): void
    {
        $path = $this->createWatchedFile();
        $sleeps = [];

        // Sweep 4 sleeps, then the seam edits the file BEFORE that sweep's
        // stat, so sweep 4 detects the change and yields. Sweeps 5-8 run
        // after the yield: a fresh ladder from the 1ms floor proves snap-back.
        $sleeper = static function (int $us) use (&$sleeps, $path): void {
            $sleeps[] = $us;
            if (count($sleeps) === 4) {
                file_put_contents($path, 'changed-content');
            }
            if (count($sleeps) === 8) {
                throw new \RuntimeException('sweep budget spent');
            }
        };

        $generator = FileWatcher::watch($path, 500, $sleeper);
        self::assertTrue($generator->current(), 'the watcher must yield true on the detected change');

        try {
            $generator->next();
            self::fail('the scripted driver must breach its sweep budget');
        } catch (\RuntimeException $breach) {
            self::assertSame('sweep budget spent', $breach->getMessage());
        }

        self::assertSame(
            [1_000, 2_000, 4_000, 8_000, 1_000, 2_000, 4_000, 8_000],
            $sleeps,
            'the sweep after a yielded change must restart at the 1ms floor'
        );
    }

    public function testNonPositiveIntervalClampsToFloorCadenceInsteadOfSpinning(): void
    {
        $path = $this->createWatchedFile();
        $sleeps = [];

        $sleeper = static function (int $us) use (&$sleeps): void {
            $sleeps[] = $us;
            if (count($sleeps) === 3) {
                throw new \RuntimeException('sweep budget spent');
            }
        };

        $generator = FileWatcher::watch($path, 0, $sleeper);
        try {
            $generator->current();
            self::fail('the scripted driver must breach its sweep budget');
        } catch (\RuntimeException $breach) {
            self::assertSame('sweep budget spent', $breach->getMessage());
        }

        self::assertSame([1_000, 1_000, 1_000], $sleeps, 'a zero interval must poll at the 1ms floor, never usleep(0)-spin');
    }

    public function testSizeOnlyChangeIsDetectedAndSweepsResumeFromFloor(): void
    {
        $path = $this->createWatchedFile('aaaa');
        $sleeps = [];
        $yielded = 0;

        // Same-mtime rewrite with a different size: only the (mtime, size)
        // tuple catches it. Seam edits (restoring the exact original mtime)
        // before sweep 3's stat.
        $sleeper = static function (int $us) use (&$sleeps, $path, &$yielded): void {
            $sleeps[] = $us;
            if (count($sleeps) === 3) {
                $originalMtime = filemtime($path);
                file_put_contents($path, 'aaaa-longer');
                touch($path, (int) $originalMtime);
            }
            if (count($sleeps) === 5) {
                throw new \RuntimeException('sweep budget spent');
            }
        };

        $generator = FileWatcher::watch($path, 500, $sleeper);
        self::assertTrue($generator->current());
        $yielded++;

        try {
            $generator->next();
            self::fail('the scripted driver must breach its sweep budget');
        } catch (\RuntimeException $breach) {
            self::assertSame('sweep budget spent', $breach->getMessage());
        }

        self::assertSame(1, $yielded);
        self::assertSame([1_000, 2_000, 4_000, 1_000, 2_000], $sleeps);
    }

    public function testWatchOfMissingFileCompletesImmediatelyWithoutSleeping(): void
    {
        // Default (no-seam) path, safe to drive: the guard returns before any
        // usleep, so the generator ends on first pull. This is the door the
        // poll contract leans on — a dead watch costs nothing to abandon.
        $generator = FileWatcher::watch(sys_get_temp_dir() . '/q13_absent_' . uniqid() . '.txt', 500);

        self::assertInstanceOf(\Generator::class, $generator);
        self::assertFalse($generator->valid());
        self::assertNull($generator->current());
    }
}
