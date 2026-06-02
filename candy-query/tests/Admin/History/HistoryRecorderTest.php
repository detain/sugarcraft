<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\History\HistoryRecorder;
use SugarCraft\Query\Admin\History\HistoryStoreInterface;
use SugarCraft\Query\Admin\StatusSnapshot;
use SugarCraft\Query\Admin\StatusSnapshotProviderInterface;

/**
 * Tests for HistoryRecorder.
 */
final class HistoryRecorderTest extends TestCase
{
    private FakeHistoryStore $store;
    private HistoryRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = new FakeHistoryStore();
        $this->recorder = new HistoryRecorder($this->store);
    }

    public function testRecordSavesSnapshotToStore(): void
    {
        $snapshot = new StatusSnapshot(['Queries' => '100'], 10.0);
        $this->recorder->record($snapshot);

        $this->assertCount(1, $this->store->saved);
        $this->assertSame($snapshot, $this->store->saved[0]);
    }

    public function testRecordUpdatesLastSnapshotForProviderInterface(): void
    {
        $snapshot = new StatusSnapshot(['Queries' => '200'], 20.0);
        $this->recorder->record($snapshot);

        $this->assertSame(['Queries' => '200'], $this->recorder->currentSnapshot());
        $this->assertEqualsWithDelta(20.0, $this->recorder->statusVariablesTs(), 0.001);
    }

    public function testRecordFromProviderWithNoProviderDoesNothing(): void
    {
        $recorder = new HistoryRecorder($this->store, null);
        $recorder->recordFromProvider();

        $this->assertCount(0, $this->store->saved);
    }

    public function testRecordFromProviderWithProviderSavesSnapshot(): void
    {
        $provider = new FakeProvider();
        $provider->setSnapshot(['Queries' => '500'], 30.0);

        $recorder = new HistoryRecorder($this->store, $provider);
        $recorder->recordFromProvider();

        $this->assertCount(1, $this->store->saved);
        $this->assertSame(['Queries' => '500'], $this->store->saved[0]->variables);
        $this->assertEqualsWithDelta(30.0, $this->store->saved[0]->ts, 0.001);
    }

    public function testRecordFromProviderDoesNothingWhenProviderReturnsNull(): void
    {
        $provider = new FakeProvider();
        $provider->setSnapshot(null, 0.0);

        $recorder = new HistoryRecorder($this->store, $provider);
        $recorder->recordFromProvider();

        $this->assertCount(0, $this->store->saved);
    }

    public function testPruneOlderThanDelegatesToStore(): void
    {
        $this->store->setPruneResult(5);
        $cutoff = new \DateTimeImmutable('@100');

        $deleted = $this->recorder->pruneOlderThan($cutoff);

        $this->assertSame(5, $deleted);
        $this->assertSame($cutoff, $this->store->pruneCutoff);
    }

    public function testImplementsStatusSnapshotProviderInterface(): void
    {
        $this->assertInstanceOf(StatusSnapshotProviderInterface::class, $this->recorder);
    }

    public function testWasResetDelegatesToProvider(): void
    {
        $provider = new FakeProvider();
        $provider->setWasReset(true);

        $recorder = new HistoryRecorder($this->store, $provider);
        $this->assertTrue($recorder->wasReset());

        // Second call should be false (flag cleared)
        $this->assertFalse($recorder->wasReset());
    }

    public function testWasResetReturnsFalseWhenNoProvider(): void
    {
        $this->assertFalse($this->recorder->wasReset());
    }

    public function testCurrentSnapshotReturnsNullBeforeAnyRecord(): void
    {
        $recorder = new HistoryRecorder($this->store, null);
        $this->assertNull($recorder->currentSnapshot());
    }

    public function testMultipleRecordsUpdateLastSnapshot(): void
    {
        $this->recorder->record(new StatusSnapshot(['X' => '1'], 1.0));
        $this->recorder->record(new StatusSnapshot(['X' => '2'], 2.0));
        $this->recorder->record(new StatusSnapshot(['X' => '3'], 3.0));

        $this->assertSame(['X' => '3'], $this->recorder->currentSnapshot());
        $this->assertEqualsWithDelta(3.0, $this->recorder->statusVariablesTs(), 0.001);
    }
}

/**
 * @implements HistoryStoreInterface
 */
final class FakeHistoryStore implements HistoryStoreInterface
{
    /** @var list<StatusSnapshot> */
    public array $saved = [];
    public ?\DateTimeImmutable $pruneCutoff = null;
    public int $pruneResult = 0;

    public function save(StatusSnapshot $snapshot): void
    {
        $this->saved[] = $snapshot;
    }

    /**
     * @return array<StatusSnapshot>
     */
    public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return [];
    }

    public function count(): int
    {
        return \count($this->saved);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $this->pruneCutoff = $before;
        return $this->pruneResult;
    }

    public function close(): void
    {
    }

    public function setPruneResult(int $n): void
    {
        $this->pruneResult = $n;
    }
}

/**
 * @implements StatusSnapshotProviderInterface
 */
final class FakeProvider implements StatusSnapshotProviderInterface
{
    private ?array $snapshot = null;
    private float $ts = 0.0;
    private bool $wasReset = false;

    public function setSnapshot(?array $snap, float $ts): void
    {
        $this->snapshot = $snap;
        $this->ts = $ts;
    }

    public function setWasReset(bool $val): void
    {
        $this->wasReset = $val;
    }

    public function currentSnapshot(): ?array
    {
        return $this->snapshot;
    }

    public function statusVariablesTs(): float
    {
        return $this->ts;
    }

    public function wasReset(): bool
    {
        return $this->wasReset;
    }
}
