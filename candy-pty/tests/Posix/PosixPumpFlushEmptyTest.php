<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PumpOptions;

/**
 * Deterministic, PTY-free coverage for PosixPump::flushMaster()'s
 * early return when nothing is left to drain and no live child can
 * produce more output.
 *
 * Regression guard for the PERF fix: flushMaster() used to usleep(20ms)
 * on every empty read and loop until flushDeadlineSec even for a
 * stdin->master-only pump (null child) that can never emit tail bytes.
 * With the fix a null/exited child breaks after the first empty read,
 * so read() is called exactly once.
 */
final class PosixPumpFlushEmptyTest extends TestCase
{
    public function testFlushMasterReturnsAfterOneReadWhenNoChild(): void
    {
        $master = new class implements MasterPty {
            public int $readCount = 0;

            public function read(int $len = 8192, ?float $timeout = null): ?string
            {
                $this->readCount++;

                return ''; // always empty: nothing buffered to drain
            }

            public function write(string $bytes): int
            {
                return \strlen($bytes);
            }

            public function resize(int $cols, int $rows): void {}

            public function size(): array
            {
                return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
            }

            public function stream(): mixed
            {
                return null;
            }

            public function close(): void {}

            public function isClosed(): bool
            {
                return false;
            }

            public function fd(): int
            {
                return -1;
            }
        };

        $stdout = \fopen('php://temp', 'r+');

        // A generous deadline: the reverted (sleep-until-deadline)
        // flushMaster would read ~deadline/20ms times; the fixed one
        // breaks after exactly one empty read.
        $opts = (new PumpOptions())->withFlushDeadlineSec(1.0);

        $flush = new \ReflectionMethod(PosixPump::class, 'flushMaster');
        $flush->setAccessible(true);

        $start = \microtime(true);
        $flush->invoke(new PosixPump(), $master, $stdout, null, $opts);
        $elapsed = \microtime(true) - $start;

        \fclose($stdout);

        $this->assertSame(
            1,
            $master->readCount,
            'flushMaster must break after one empty read when there is no child',
        );
        $this->assertLessThan(
            0.5,
            $elapsed,
            'flushMaster must return immediately on empty, not sleep to the deadline',
        );
    }
}
