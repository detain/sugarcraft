<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentWorkerPool;

/**
 * The read-only view the UI owner surfaces: the cap, the queue and why the
 * pool last ran sequentially. Nothing here dispatches an agent.
 */
final class AgentWorkerPoolCapAccessorsTest extends TestCase
{
    public function testAnUncappedPoolReportsNoCapAndNoQueue(): void
    {
        $pool = new AgentWorkerPool();

        self::assertNull($pool->cap());
        self::assertSame(0, $pool->queuedCount());
        self::assertSame(0, $pool->activeCount());
        self::assertNull($pool->fallbackReason());
    }

    public function testACappedPoolReportsItsCap(): void
    {
        self::assertSame(2, (new AgentWorkerPool(2))->cap());
    }
}
