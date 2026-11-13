<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Admin\ServerStatusSnapshotAdapter;

/**
 * Delegation pins for the Sampler bridge (admindata-016, SQL-A09).
 *
 * Before this file the adapter was exercised only as a constructor input in
 * one gauge suite, so a re-wired leg — forwarding to the wrong context
 * member, or reviving the removed write-only $currentSnapshot as a real
 * stale memo — failed nothing directly. Each leg is pinned at its true
 * contract here: the adapter is a STATELESS bridge. currentSnapshot() must
 * re-read the context on every call (the A03-era hazard this guards is a
 * memo answering the Sampler's second sample with the first poll's rows),
 * the timestamp and reset-flag legs forward verbatim, and the constructor
 * takes nothing but the context.
 */
final class ServerStatusSnapshotAdapterTest extends TestCase
{
    private function adapterWith(ServerContextInterface $context): ServerStatusSnapshotAdapter
    {
        return new ServerStatusSnapshotAdapter($context);
    }

    public function testCurrentSnapshotReReadsTheContextOnEveryArrival(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('statusVariables')
            ->willReturn(['Threads_connected' => '1'], ['Threads_connected' => '2']);

        $adapter = $this->adapterWith($context);

        $this->assertSame('1', $adapter->currentSnapshot()['Threads_connected'] ?? null);
        $this->assertSame(
            '2',
            $adapter->currentSnapshot()['Threads_connected'] ?? null,
            'a second sample must observe the fresh context read, never a parked memo',
        );
    }

    public function testStatusVariablesTsForwardsTheContextsArrivalClock(): void
    {
        $context = $this->createMock(ServerContextInterface::class);
        $context->method('statusVariablesTs')->willReturn(1234.5);

        $this->assertSame(1234.5, $this->adapterWith($context)->statusVariablesTs());
    }

    public function testWasResetForwardsTheCounterFlipVerdict(): void
    {
        $reset = $this->createMock(ServerContextInterface::class);
        $reset->method('wasReset')->willReturn(true);
        $steady = $this->createMock(ServerContextInterface::class);
        $steady->method('wasReset')->willReturn(false);

        $this->assertTrue($this->adapterWith($reset)->wasReset());
        $this->assertFalse($this->adapterWith($steady)->wasReset());
    }

    public function testTheBridgeCarriesNoStateOfItsOwn(): void
    {
        $props = (new \ReflectionClass(ServerStatusSnapshotAdapter::class))->getProperties();

        $this->assertCount(1, $props, 'the adapter must hold only the context it bridges');
        $this->assertSame('context', $props[0]->getName());
    }
}
