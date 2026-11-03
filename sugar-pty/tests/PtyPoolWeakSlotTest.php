<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Pty\PtyPool;

/**
 * m3 (round lib-fixes-1103): abandoned pairs must stop pinning pool
 * capacity. Bookkeeping is weak-referenced, so a pair dropped without
 * release() frees its slot at collection (its master fd leak until
 * process end is documented on PtyPool — release() remains the
 * closing contract).
 */
final class PtyPoolWeakSlotTest extends TestCase
{
    /** A master fake small enough that nothing else keeps it alive. */
    public function pair(): PtyPair
    {
        return new class implements PtyPair {
            public bool $closed = false;

            public function master(): MasterPty
            {
                return new class ($this) implements MasterPty {
                    public function __construct(private $owner) {}

                    public function read(int $len = 8192, ?float $timeout = 0.05): ?string { return null; }
                    public function write(string $bytes): int { return strlen($bytes); }
                    public function resize(int $cols, int $rows): void {}
                    public function size(): array { return ['rows' => 24, 'cols' => 80, 'xpix' => 0, 'ypix' => 0]; }
                    public function stream(): mixed { return null; }
                    public function close(): void { $this->owner->closed = true; }
                    public function isClosed(): bool { return $this->owner->closed; }
                    public function fd(): int { return 3; }
                };
            }

            public function slave(): SlavePty
            {
                throw new \LogicException('unused');
            }
        };
    }

    public function system(): PtySystem
    {
        return new class ($this) implements PtySystem {
            /** @param callable():PtyPair $factory supplied via closure binding */
            public function __construct(private TestCase $test) {}

            public function open(int $cols = 80, int $rows = 24): PtyPair
            {
                return $this->test->pair();
            }

            public function name(): string { return 'fake-weak'; }

            public function capabilities(): array { return []; }
        };
    }

    public function testAbandonedPairFreesItsSlot(): void
    {
        $pool = new PtyPool(system: $this->system(), maxSize: 2);

        // Acquire twice and drop the pairs WITHOUT releasing.
        (function () use ($pool): void {
            $pool->acquire();
            $pool->acquire();
        })();
        \gc_collect_cycles();

        $this->assertSame(0, $pool->inFlight(), 'collected pairs must not pin capacity');
        $this->assertSame(2, $pool->available());

        // Pre-fix this threw PoolExhaustedException: the strong refs
        // kept both dead slots counted at maxSize.
        $pair = $pool->acquire();
        $this->assertSame(1, $pool->inFlight());
        $pool->release($pair);
        $this->assertSame(0, $pool->inFlight());
    }

    public function testReleaseStillClosesHeldPair(): void
    {
        $pool = new PtyPool(system: $this->system(), maxSize: 4);
        $pair = $pool->acquire();
        $pool->release($pair);
        $this->assertTrue($pair->master()->isClosed());
    }

    public function testDrainIgnoresDeadSlots(): void
    {
        $pool = new PtyPool(system: $this->system(), maxSize: 4);
        $held = $pool->acquire();
        (static function () use ($pool): void {
            $pool->acquire(); // abandoned
        })();
        \gc_collect_cycles();

        $pool->drain(); // must not fatal on the dead weak slot
        $this->assertTrue($held->master()->isClosed());
        $this->assertSame(0, $pool->inFlight());
    }
}
