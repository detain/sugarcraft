<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\History\HistoryQuery;
use SugarCraft\Query\Admin\History\HistoryStoreInterface;
use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Tests for HistoryQuery.
 */
final class HistoryQueryTest extends TestCase
{
    private MockStore $store;
    private HistoryQuery $query;

    protected function setUp(): void
    {
        $this->store = new MockStore();
        $this->query = new HistoryQuery($this->store);
    }

    public function testQueryDelegatesToStore(): void
    {
        $snapshots = [
            new StatusSnapshot(['X' => '1'], 100.0),
            new StatusSnapshot(['X' => '2'], 200.0),
        ];
        $this->store->setSnapshots($snapshots);

        $results = $this->query->query(100.0, 200.0);

        $this->assertCount(2, $results);
        $this->assertSame($snapshots, $results);
        $this->assertSame(100, $this->store->lastSince->getTimestamp());
        $this->assertSame(200, $this->store->lastUntil->getTimestamp());
    }

    public function testQuerySinceUsesNowAsUpperBound(): void
    {
        $this->query->querySince(500.0);

        $this->assertNotNull($this->store->lastSince);
        $this->assertNotNull($this->store->lastUntil);
        $this->assertEqualsWithDelta(500.0, $this->store->lastSince->getTimestamp(), 1.0);
    }

    public function testGetRateComputesRateOverRange(): void
    {
        $this->store->setSnapshots([
            new StatusSnapshot(['Queries' => '100'], 0.0),
            new StatusSnapshot(['Queries' => '1000'], 100.0),
        ]);

        $rate = $this->query->getRate('Queries', 0.0, 100.0);

        // (1000 - 100) / (100 - 0) = 9
        $this->assertEqualsWithDelta(9.0, $rate, 0.001);
    }

    public function testGetRateReturnsNullWhenOnlyOneSnapshot(): void
    {
        $this->store->setSnapshots([
            new StatusSnapshot(['Queries' => '100'], 50.0),
        ]);

        $rate = $this->query->getRate('Queries', 0.0, 100.0);

        $this->assertNull($rate);
    }

    public function testGetRateReturnsNullWhenNoSnapshots(): void
    {
        $this->store->setSnapshots([]);

        $rate = $this->query->getRate('Queries', 0.0, 100.0);

        $this->assertNull($rate);
    }

    public function testGetRateReturnsNullWhenVariableMissingInFirstSnapshot(): void
    {
        $this->store->setSnapshots([
            new StatusSnapshot(['Other' => '100'], 0.0),
            new StatusSnapshot(['Queries' => '200'], 100.0),
        ]);

        $rate = $this->query->getRate('Queries', 0.0, 100.0);

        $this->assertNull($rate);
    }

    public function testGetRateReturnsNullWhenVariableMissingInLastSnapshot(): void
    {
        $this->store->setSnapshots([
            new StatusSnapshot(['Queries' => '100'], 0.0),
            new StatusSnapshot(['Other' => '200'], 100.0),
        ]);

        $rate = $this->query->getRate('Queries', 0.0, 100.0);

        $this->assertNull($rate);
    }

    public function testGetRateReturnsNullOnZeroElapsedTime(): void
    {
        $this->store->setSnapshots([
            new StatusSnapshot(['Queries' => '100'], 50.0),
            new StatusSnapshot(['Queries' => '200'], 50.0),
        ]);

        $rate = $this->query->getRate('Queries', 0.0, 100.0);

        $this->assertNull($rate);
    }

    public function testGetRateReturnsZeroWhenDeltaIsNegative(): void
    {
        // Uptime counters can reset on restart
        $this->store->setSnapshots([
            new StatusSnapshot(['Uptime' => '1000'], 0.0),
            new StatusSnapshot(['Uptime' => '100'], 100.0),
        ]);

        $rate = $this->query->getRate('Uptime', 0.0, 100.0);

        // (100 - 1000) = -900, clamped to 0, so rate = 0
        $this->assertEqualsWithDelta(0.0, $rate, 0.001);
    }

    public function testGetRateUsesBoundarySnapshotsNotAll(): void
    {
        // First = 100, Last = 500, ignores middle 300
        $this->store->setSnapshots([
            new StatusSnapshot(['Bytes' => '100'], 0.0),
            new StatusSnapshot(['Bytes' => '300'], 50.0),
            new StatusSnapshot(['Bytes' => '500'], 100.0),
        ]);

        $rate = $this->query->getRate('Bytes', 0.0, 100.0);

        // (500 - 100) / 100 = 4
        $this->assertEqualsWithDelta(4.0, $rate, 0.001);
    }

    public function testGetRateWithNonNumericVariableReturnsNull(): void
    {
        $this->store->setSnapshots([
            new StatusSnapshot(['Version' => '8.0.33'], 0.0),
            new StatusSnapshot(['Version' => '8.0.36'], 100.0),
        ]);

        $rate = $this->query->getRate('Version', 0.0, 100.0);

        $this->assertNull($rate);
    }

    /**
     * Sub-second epoch timestamps must be preserved when building the
     * DateTimeImmutable query bounds. Uses DateTimeImmutable::createFromFormat('U u')
     * to avoid truncating the microseconds component.
     */
    public function testQueryPreservesSubSecondTimestamps(): void
    {
        $this->store->setSnapshots([
            new StatusSnapshot(['X' => '1'], 100.5),
        ]);

        $this->query->query(100.5, 200.5);

        $this->assertNotNull($this->store->lastSince);
        $this->assertNotNull($this->store->lastUntil);
        // Integer seconds must match
        $this->assertSame(100, $this->store->lastSince->getTimestamp());
        $this->assertSame(200, $this->store->lastUntil->getTimestamp());
        // Microseconds must be preserved (500000 usec = 0.5 seconds)
        $this->assertSame('500000', $this->store->lastSince->format('u'));
        $this->assertSame('500000', $this->store->lastUntil->format('u'));
    }
}

/**
 * @implements HistoryStoreInterface
 */
final class MockStore implements HistoryStoreInterface
{
    /** @var list<StatusSnapshot> */
    public array $snapshots = [];

    public ?\DateTimeImmutable $lastSince = null;
    public ?\DateTimeImmutable $lastUntil = null;

    public function setSnapshots(array $snapshots): void
    {
        $this->snapshots = $snapshots;
    }

    public function save(StatusSnapshot $snapshot): void
    {
    }

    /**
     * @return array<StatusSnapshot>
     */
    public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $this->lastSince = $since;
        $this->lastUntil = $until;
        return $this->snapshots;
    }

    public function count(): int
    {
        return \count($this->snapshots);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        return 0;
    }

    public function close(): void
    {
    }
}
