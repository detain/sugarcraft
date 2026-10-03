<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Expect;

/**
 * {@see Expect::send()} absorbs ordinary back-pressure from a busy master and
 * fails only a master that stops accepting bytes for the whole stall budget.
 * It used to give up after a single 1 ms retry.
 */
final class ExpectSendRetryTest extends TestCase
{
    public function testSendSurvivesAMasterThatWouldBlockForSeveralRetries(): void
    {
        // Busy for ~20 consecutive attempts (~20 ms of back-off): well inside
        // the stall budget, far beyond the old single retry.
        $master = self::busyMaster(20);

        Expect::on($master)->send('hello world');

        $this->assertSame('hello world', $master->accepted);
    }

    public function testEveryAcceptedChunkRestartsTheStallWindow(): void
    {
        // Accepts one byte, then would-block 15 times, repeatedly. The send
        // as a whole lasts far longer than the budget, but it never goes the
        // budget without progress, so it must complete.
        $master = self::busyMaster(15, chunk: 1, repeat: true);
        $payload = \str_repeat('x', 12);

        $start = \microtime(true);
        Expect::on($master)->send($payload);
        $elapsed = \microtime(true) - $start;

        $this->assertSame($payload, $master->accepted);
        $this->assertGreaterThan(Expect::SEND_STALL_BUDGET_SEC, $elapsed, 'the scenario must outlast one stall window to prove the window restarts');
    }

    public function testSendFailsAMasterThatNeverAcceptsWithinTheBudget(): void
    {
        $master = self::busyMaster(\PHP_INT_MAX);

        $start = \microtime(true);
        try {
            Expect::on($master)->send('abc');
            $this->fail('send() into a master that never accepts must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('0 of 3 bytes delivered', $e->getMessage());
        }
        $elapsed = \microtime(true) - $start;

        $this->assertGreaterThanOrEqual(Expect::SEND_STALL_BUDGET_SEC, $elapsed);
        $this->assertLessThan(Expect::SEND_STALL_BUDGET_SEC + 1.0, $elapsed, 'the stall budget must bound the send');
    }

    /**
     * A master whose write() reports would-block (0) `$busy` times in a row
     * before accepting up to `$chunk` bytes (0 = all).
     */
    private static function busyMaster(int $busy, int $chunk = 0, bool $repeat = false): MasterPty
    {
        return new class ($busy, $chunk, $repeat) implements MasterPty {
            public string $accepted = '';
            private int $left;

            public function __construct(
                private readonly int $busy,
                private readonly int $chunk,
                private readonly bool $repeat,
            ) {
                $this->left = $busy;
            }

            public function write(string $bytes): int
            {
                if ($this->left > 0) {
                    $this->left--;
                    return 0;
                }
                if ($this->repeat) {
                    $this->left = $this->busy;
                }
                $take = $this->chunk > 0 ? \substr($bytes, 0, $this->chunk) : $bytes;
                $this->accepted .= $take;
                return \strlen($take);
            }

            public function read(int $len = 8192, ?float $timeout = null): ?string { return null; }
            public function resize(int $cols, int $rows): void {}
            public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
            public function stream(): mixed { throw new \LogicException('stub master has no stream'); }
            public function close(): void {}
            public function isClosed(): bool { return false; }
            public function fd(): int { return -1; }
        };
    }
}
