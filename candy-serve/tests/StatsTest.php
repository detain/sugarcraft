<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Stats;

/**
 * Stats counters — plan item 7.6.
 *
 * @covers \SugarCraft\Serve\Stats
 */
final class StatsTest extends TestCase
{
    protected function tearDown(): void
    {
        // Don't leak test counters into the shared instance other tests see.
        Stats::setInstance(new Stats());
        parent::tearDown();
    }

    public function testCountersStartAtZero(): void
    {
        $s = new Stats();

        $this->assertSame(0, $s->connections());
        $this->assertSame(0, $s->packUploads());
        $this->assertSame(0, $s->packDownloads());
        $this->assertSame(0, $s->lfsBatchRequests());
        $this->assertSame(0, $s->lfsObjectDownloads());
        $this->assertSame(0, $s->lfsObjectUploads());
    }

    public function testRecordingIncrementsEachCounter(): void
    {
        $s = new Stats();
        $s->recordConnection();
        $s->recordConnection();
        $s->recordPackUpload();
        $s->recordPackDownload();
        $s->recordPackDownload();
        $s->recordPackDownload();
        $s->recordLfsBatch();
        $s->recordLfsDownload();
        $s->recordLfsUpload();

        $this->assertSame(2, $s->connections());
        $this->assertSame(1, $s->packUploads());
        $this->assertSame(3, $s->packDownloads());
        $this->assertSame(1, $s->lfsBatchRequests());
        $this->assertSame(1, $s->lfsObjectDownloads());
        $this->assertSame(1, $s->lfsObjectUploads());
    }

    public function testSnapshotShape(): void
    {
        $s = new Stats();
        $s->recordConnection();

        $snap = $s->snapshot();

        $this->assertSame(
            ['uptime_seconds', 'connections', 'pack_uploads', 'pack_downloads',
             'lfs_batch_requests', 'lfs_object_downloads', 'lfs_object_uploads'],
            \array_keys($snap),
        );
        $this->assertSame(1, $snap['connections']);
        $this->assertGreaterThanOrEqual(0, $snap['uptime_seconds']);
    }

    public function testToJsonRoundTrips(): void
    {
        $s = new Stats();
        $s->recordLfsBatch();

        $decoded = \json_decode($s->toJson(), true);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['lfs_batch_requests']);
    }

    public function testUptimeAdvances(): void
    {
        $s = new Stats();
        \usleep(2000);

        $this->assertGreaterThan(0.0, $s->uptimeSeconds());
    }

    public function testResetZerosCounters(): void
    {
        $s = new Stats();
        $s->recordConnection();
        $s->recordPackUpload();

        $s->reset();

        $this->assertSame(0, $s->connections());
        $this->assertSame(0, $s->packUploads());
    }

    public function testSharedInstanceIsInjectable(): void
    {
        $mine = new Stats();
        Stats::setInstance($mine);

        $this->assertSame($mine, Stats::getInstance());

        Stats::getInstance()->recordConnection();
        $this->assertSame(1, $mine->connections());
    }
}
