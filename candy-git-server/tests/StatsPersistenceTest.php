<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Stats;

/**
 * S37 (finding 108): the /stats surface exists to serve operational
 * history, and until now every restart zeroed it. Persistence is opt-in
 * behind stats.persist_path; the merge discipline is MONOTONIC — the
 * file's higher counters always win — so neither a restart nor two
 * flushing processes (8b workers record in their own process copy)
 * can move history backwards.
 */
final class StatsPersistenceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/gitserver-stats-' . \uniqid();
        \mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tmpDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function path(string $name = 'stats.json'): string
    {
        return $this->tmpDir . '/' . $name;
    }

    /** @return array<string, mixed> */
    private function file(): array
    {
        $decoded = \json_decode((string) \file_get_contents($this->path()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testDefaultShapeStaysVolatile(): void
    {
        $stats = new Stats();
        $stats->recordConnection();

        $this->assertFalse($stats->flushNow(), 'a path-less instance has nothing to flush to');
        $this->assertSame(1, $stats->connections());
        $this->assertSame([], \glob($this->tmpDir . '/*'), 'volatile means no writes');
    }

    public function testFirstRecordFlushesWhenCadenceIsZero(): void
    {
        $stats = new Stats($this->path(), 0);
        $stats->recordPackUpload();

        $this->assertSame(1, $this->file()['pack_uploads']);
    }

    public function testCadenceHoldsWritesBetweenIntervals(): void
    {
        $stats = new Stats($this->path(), 60);
        $stats->recordConnection();
        $stats->recordConnection();

        $this->assertFileDoesNotExist($this->path(), 'within the interval nothing is written');
        $this->assertTrue($stats->flushNow(), 'the stop-path flush is unconditional');
        $this->assertSame(2, $this->file()['connections']);
    }

    public function testHistorySurvivesARebootMonotonically(): void
    {
        $first = new Stats($this->path(), 0);
        for ($i = 0; $i < 5; $i++) {
            $first->recordConnection();
        }
        $first->flushNow();

        // A new process hydrates where history ended…
        $second = new Stats($this->path(), 0);
        $this->assertSame(5, $second->connections(), 'the counters resume from the persisted floor');

        $second->recordConnection();
        $second->flushNow();
        $this->assertSame(6, $this->file()['connections']);

        $third = new Stats($this->path(), 0);
        $this->assertSame(6, $third->connections(), 'and the next life starts at the new floor');
    }

    public function testAHigherCoTenantWinsAndPullsTheLiveCountersUp(): void
    {
        $mine = new Stats($this->path(), 60);
        $mine->recordConnection();

        // Another process (say, an 8b worker flush) recorded further.
        \file_put_contents($this->path(), \json_encode(['connections' => 200, 'pack_uploads' => 3]));

        $this->assertTrue($mine->flushNow());
        $this->assertSame(200, $this->file()['connections'], 'the merge takes the higher file value (monotonic)');
        $this->assertSame(200, $mine->connections(), 'and the live instance is pulled up — it can never re-flush a lower truth');
        $this->assertSame(3, $this->file()['pack_uploads'], 'a counter the file knows about transfers whole');
    }

    public function testCorruptOrJunkFilesFloorAtZeroAndAreHealedOnFlush(): void
    {
        \file_put_contents($this->path(), 'not json at all');
        $stats = new Stats($this->path(), 60);
        $this->assertSame(0, $stats->connections(), 'history-not-yet is not an error');

        $stats->recordConnection();
        $this->assertTrue($stats->flushNow());
        $this->assertSame(1, $this->file()['connections'], 'the corrupt file is overwritten by the first real write');

        // Negative / non-integer stored junk is ignored, never trusted.
        \file_put_contents($this->path(), \json_encode(['connections' => -5, 'pack_uploads' => 'many', 'bogus' => 7]));
        $again = new Stats($this->path(), 60);
        $this->assertSame(0, $again->connections());
        $this->assertSame(0, $again->packUploads());
        $again->recordPackDownload();
        $again->flushNow();
        $healed = $this->file();
        $this->assertSame(1, $healed['pack_downloads']);
        $this->assertArrayNotHasKey('bogus', $healed, 'foreign keys in the file are not echoed back');
    }

    public function testSnapshotShapeIsUntouchedByPersistence(): void
    {
        // The /stats JSON surface has pinned keys; persistence changes
        // provenance, not shape.
        $stats = new Stats();
        $this->assertSame([
            'uptime_seconds', 'connections', 'pack_uploads', 'pack_downloads',
            'lfs_batch_requests', 'lfs_object_downloads', 'lfs_object_uploads',
        ], \array_keys($stats->snapshot()));

        $persisted = new Stats($this->path(), 0);
        $persisted->recordLfsBatch();
        $this->assertArrayNotHasKey('uptime_seconds', $this->file(), 'the file carries counters only; uptime is per-process truth');
    }

    // -------------------------------------------------------------------------
    // Config surface + bin wiring
    // -------------------------------------------------------------------------

    public function testConfigCarriesTheStatsPersistenceKnobs(): void
    {
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "stats:\n  listen_addr: \":23233\"\n  persist_path: stats/history.json\n  flush_interval_seconds: 120\n"
        );
        $config = Config::load($this->tmpDir . '/config.yaml');

        $this->assertSame($this->tmpDir . '/stats/history.json', $config->statsPersistPath, 'relative paths resolve under the data dir');
        $this->assertSame(120, $config->statsFlushIntervalSeconds);
    }

    public function testConfigDefaultsKeepTheVolatilePosture(): void
    {
        $config = Config::fromDefaults();
        $this->assertSame('', $config->statsPersistPath, 'the shipped default is volatile — zero behavior change');
        $this->assertSame(60, $config->statsFlushIntervalSeconds);
    }

    public function testS07VocabularyGuardsTheFlushInterval(): void
    {
        // Unit truncation ('30s' -> 30) and silent string coercion are
        // exactly what parseInt exists to refuse on security/ops knobs.
        foreach (['stats: { flush_interval_seconds: "30s" }', 'stats: { flush_interval_seconds: true }',
            'stats: { flush_interval_seconds: 0 }', 'stats: { flush_interval_seconds: 90000 }',] as $bad) {
            \file_put_contents($this->tmpDir . '/config.yaml', $bad . "\n");
            $threw = false;
            try {
                Config::load($this->tmpDir . '/config.yaml');
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, "config must refuse stats.flush_interval_seconds shaped like: {$bad}");
        }
    }

    public function testServeArmsThePersistingInstanceAndFlushesOnStop(): void
    {
        // Structural twin of the two bin lines that make 108 reachable
        // from the shipped entrypoint (the flush-on-stop is the last
        // seconds of history; setInstance is the hydrate door).
        $bin = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-git-server');
        $this->assertStringContainsString('statsPersistPath', $bin, 'serve must arm persistence when configured');
        $this->assertStringContainsString('Stats::setInstance(new \SugarCraft\GitServer\Stats(', $bin);
        $this->assertStringContainsString('Stats::instance()->flushNow()', $bin, 'the stop path must flush before the loop exits');
    }
}
