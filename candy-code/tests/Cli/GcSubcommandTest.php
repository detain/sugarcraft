<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionKind;
use SugarCraft\Code\Support\SqliteConnect;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `candy-code gc status|vacuum` — the B1 proof surface (gc-cli-1106).
 *
 * In-process, the way {@see SessionSubcommandsTest} drives its verb:
 * ArgvParser -> Subcommands::dispatch with `HOME` pointed at a private
 * directory, so {@see Subcommands}' `gc` path reads the fixture database
 * this file seeds. `sessions`, `analyze` and `purge` are B2/B3 rungs of the
 * same verb: TODAY they must answer the unknown-action door at exit 2, and
 * the cases below pin exactly that (they flip when the rungs land).
 *
 * stdout is ob-captured; stderr is not capturable in-process, so every
 * failure arm is asserted through the `--output-format json` document.
 */
final class GcSubcommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;
    private string $dbPath;
    private string|false $originalRetention;

    protected function setUp(): void
    {
        $this->home = (string) realpath(sys_get_temp_dir()) . '/gc-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->home . '/.candy-code', 0700, true));
        chmod($this->home, 0700);
        $this->originalRetention = getenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        $this->useHomeSandbox($this->home);
        putenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        $this->dbPath = $this->home . '/.candy-code/session.db';
    }

    /**
     * Recursive cleanup, gc-prefixed because the suite-wide helper-drift
     * guard treats a same-named private helper in a second file as debt.
     */
    private function gcRemoveTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->gcRemoveTree($path . '/' . $entry);
        }

        @rmdir($path);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        putenv($this->originalRetention === false
            ? 'CANDY_CODE_SESSION_RETENTION_DAYS'
            : 'CANDY_CODE_SESSION_RETENTION_DAYS=' . $this->originalRetention);
        $this->gcRemoveTree($this->home);
    }

    // ── gc status ─────────────────────────────────────────────────────────
    public function testStatusTextReportsCountsHeadersAndRetentionOff(): void
    {
        $this->gcSeedStore();

        [$rc, $out] = $this->gcDispatch(['status']);

        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('candy-code gc status', $out);
        self::assertStringContainsString($this->dbPath, $out);
        self::assertStringContainsString('file db ', $out);
        self::assertStringContainsString('journal ', $out);
        self::assertStringContainsString('tables', $out);
        self::assertStringContainsString('sessions', $out);
        self::assertStringContainsString('total 3 · live 2 · archived 1 · subagents 1 · pinned 1 · named 2', $out);
        self::assertStringContainsString('age <=7d 3 · 8-30d 0 · 31-90d 0 · >90d 0 · unknown 0', $out);
        self::assertStringContainsString('retention OFF', $out);
        self::assertStringContainsString('storage', $out);
        self::assertStringContainsString('█', $out);
    }

    public function testStatusJsonCarriesTheSchemaOneEnvelopeAndCensusShape(): void
    {
        $this->gcSeedStore();

        [$rc, $out] = $this->gcDispatch(['status', '--format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);

        $doc = json_decode($out, true);
        self::assertIsArray($doc);
        self::assertSame(1, $doc['schema']);
        self::assertSame('gc', $doc['verb']);
        self::assertSame('status', $doc['action']);

        $result = $doc['result'];
        self::assertSame($this->dbPath, $result['db_path']);
        self::assertGreaterThan(0, $result['files']['db']);
        self::assertTrue($result['files']['present']);
        self::assertCount(14, $result['tables']);
        self::assertSame('sessions', $result['tables'][0]['name']);
        self::assertTrue($result['tables'][0]['present']);
        self::assertSame(3, $result['tables'][0]['rows']);

        self::assertSame(3, $result['sessions']['total']);
        self::assertSame(2, $result['sessions']['live']);
        self::assertSame(1, $result['sessions']['archived']);
        self::assertSame(1, $result['sessions']['subagents']);
        self::assertSame(1, $result['sessions']['pinned']);
        self::assertSame(2, $result['sessions']['named']);
        self::assertSame(3, array_sum($result['sessions']['age_buckets']));
        self::assertSame(3, $result['sessions']['age_buckets']['le7']);
        self::assertSame(0, $result['sessions']['age_buckets']['gt90']);

        self::assertIsInt($result['reclaimable_bytes']);
        self::assertSame(0, $result['pragma']['auto_vacuum']);
        self::assertSame('NONE', $result['pragma']['auto_vacuum_label']);
        self::assertIsBool($result['flags']['events_exceed_transcripts']);
        self::assertSame(30, $result['bar_cells']);
        self::assertSame(0, $result['retention_days']);
    }

    public function testStatusJsonHonorsTheGlobalOutputFormat(): void
    {
        $this->gcSeedStore();

        [$rc, $doc] = $this->gcJsonDoor(['status']);

        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertIsArray($doc);
        self::assertSame('gc', $doc['verb']);
        self::assertSame('status', $doc['action']);
    }

    public function testUnknownFormatSpellingIsAUsageError(): void
    {
        $this->gcSeedStore();

        [$rc, $doc] = $this->gcJsonDoor(['status', '--format', 'yaml']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc);
        self::assertIsArray($doc);
    }

    public function testNoActionIsAUsageError(): void
    {
        [$rc, $doc] = $this->gcJsonDoor([]);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc);
        self::assertIsArray($doc);
    }

    public function testLaterRungActionsAreUsageErrorsToday(): void
    {
        // B2/B3 rungs: `sessions`, `analyze` and `purge` arrive later in the
        // program. TODAY the honest answer is the unknown-action door.
        foreach (['sessions', 'analyze', 'purge'] as $action) {
            [$rc, $doc] = $this->gcJsonDoor([$action]);
            self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $action);
            self::assertIsArray($doc, $action);
        }
    }

    public function testFlagOnTheWrongActionIsAUsageError(): void
    {
        // `--check` belongs to `vacuum`, never to `status`.
        [$rc, $doc] = $this->gcJsonDoor(['status', '--check']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc);
        self::assertIsArray($doc);
    }

    // ── gc vacuum: the no-rewrite doors ───────────────────────────────────

    public function testVacuumWithoutForceReportsAndNeverRewrites(): void
    {
        $this->gcSeedStore();
        $this->gcFragmentDb();
        [$ino, $size] = $this->gcStat();

        [$rc, $doc] = $this->gcJsonDoor(['vacuum']);

        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $result = $doc['result'];
        self::assertFalse($result['performed']);
        self::assertFalse($result['check']);
        self::assertSame('full', $result['requested_mode']);
        self::assertGreaterThan(0, $result['files']['db']);
        self::assertArrayHasKey('reclaimable_bytes', $result);
        self::assertArrayHasKey('estimated_db_bytes_after', $result);
        self::assertIsBool($result['red_flag_events']);

        [$afterIno, $afterSize] = $this->gcStat();
        self::assertSame($ino, $afterIno, 'a no-force vacuum must not replace the file');
        self::assertSame($size, $afterSize, 'a no-force vacuum must not shrink the file');
    }

    public function testVacuumCheckReportsWithoutRewriting(): void
    {
        $this->gcSeedStore();
        $this->gcFragmentDb();
        [$ino, $size] = $this->gcStat();

        [$rc, $doc] = $this->gcJsonDoor(['vacuum', '--check']);

        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertFalse($doc['result']['performed']);
        self::assertTrue($doc['result']['check']);

        [$afterIno, $afterSize] = $this->gcStat();
        self::assertSame($ino, $afterIno);
        self::assertSame($size, $afterSize);
    }

    // ── gc vacuum: the rewrites ───────────────────────────────────────────

    public function testVacuumForceRewritesAndShrinksFragmentedDatabase(): void
    {
        $this->gcSeedStore();
        $this->gcFragmentDb();
        clearstatcache();
        $sizeBefore = (int) filesize($this->dbPath);
        self::assertGreaterThan(0, $sizeBefore);

        [$rc, $doc] = $this->gcJsonDoor(['vacuum', '--force']);

        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $result = $doc['result'];
        self::assertTrue($result['performed']);
        self::assertSame('full', $result['mode']);
        self::assertLessThan($result['freelist_before'], $result['freelist_after'],
            'the rewrite must clear the free pages it counted before');

        // The user-visible truth: the on-disk database file after the run
        // (the engine's `after` snapshot is taken mid-WAL, so the final file
        // size — not the snapshot totals — is what proves the shrink).
        clearstatcache();
        self::assertLessThan($sizeBefore, (int) filesize($this->dbPath),
            'a fragmented database must come back smaller');
    }

    public function testIncrementalForceFlipsAutoVacuumOnceThenDrains(): void
    {
        $this->gcSeedStore();
        $this->gcFragmentDb();

        [$rc, $doc] = $this->gcJsonDoor(['vacuum', '--incremental', '--force']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $first = $doc['result'];
        self::assertTrue($first['performed']);
        self::assertTrue($first['flipped'], 'the first run pays the one-time flip rewrite');
        self::assertSame('flip', $first['mode']);
        self::assertSame(0, $first['auto_vacuum_before']);
        self::assertSame(2, $first['auto_vacuum_after']);

        [$rc, $doc] = $this->gcJsonDoor(['vacuum', '--incremental', '--force']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $second = $doc['result'];
        self::assertTrue($second['performed']);
        self::assertFalse($second['flipped'], 'the regime is already incremental: no second flip');
        self::assertSame('drain', $second['mode']);
        self::assertSame(2, $second['auto_vacuum_before']);
        self::assertSame(2, $second['auto_vacuum_after']);
    }

    // ── the red flag and the vacuity pins ─────────────────────────────────

    public function testRedFlagRendersWhenEventsOutweighTranscripts(): void
    {
        $this->gcSeedStore();
        $this->gcEventsHeavy();

        [$rc, $doc] = $this->gcJsonDoor(['status']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertTrue($doc['result']['flags']['events_exceed_transcripts']);

        [$rc, $out] = $this->gcDispatch(['status']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('red flag', $out);
    }

    public function testReclaimableReflectsTheSeededFragmentation(): void
    {
        // The vacuity pin for the reclaimable figure: delete-fragmentation
        // must move it off zero, so an always-zero census cannot hide behind
        // this test.
        $this->gcSeedStore();
        $this->gcFragmentDb();

        [$rc, $doc] = $this->gcJsonDoor(['status']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertGreaterThan(0, $doc['result']['reclaimable_bytes']);
    }

    // ── the store doors: missing, corrupt ─────────────────────────────────

    public function testMissingStoreExitsOneAndSaysSo(): void
    {
        // No database at all (fresh install): exit 1, the store_unavailable
        // wording — never 255, never a silent zero report.
        [$rc, $doc] = $this->gcJsonDoor(['status']);

        self::assertSame(NonInteractive::EXIT_FAILURE, $rc);
        self::assertIsArray($doc);
        self::assertFalse($doc['result']['opened']);
        self::assertStringContainsString('cannot open the session store', (string) $doc['result']['reason']);

        [$textRc] = $this->gcDispatch(['status']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $textRc);
    }

    public function testCorruptStoreExitsOneNotAFatal(): void
    {
        $this->gcSeedStore();
        $this->gcCorruptDb();

        [$rc, $doc] = $this->gcJsonDoor(['status']);

        self::assertSame(1, $rc, 'a corrupt ledger answers the store door with 1, never 255');
        self::assertFalse($doc['result']['opened']);
        self::assertStringContainsString('cannot open the session store', (string) $doc['result']['reason']);
    }

    // ── the retention regime ──────────────────────────────────────────────

    public function testRetentionRegimePassesThroughTheInventory(): void
    {
        $this->gcSeedStore();
        putenv('CANDY_CODE_SESSION_RETENTION_DAYS=30');

        [$rc, $doc] = $this->gcJsonDoor(['status']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertSame(30, $doc['result']['retention_days']);

        [$rc, $out] = $this->gcDispatch(['status']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('retention 30 days', $out);
    }

    // ── helpers (gc-prefixed: unique across the suite by contract) ────────

    /**
     * @param  list<string>  $argv  operands and flags AFTER the verb word
     * @return array{0:int,1:string}
     */
    private function gcDispatch(array $argv): array
    {
        \ob_start();
        try {
            $rc = Subcommands::dispatch(ArgvParser::parse(\array_merge(['candy-code', 'gc'], $argv)));
        } finally {
            $out = (string) \ob_get_clean();
        }

        return [$rc, $out];
    }

    /**
     * @param  list<string>  $argv
     * @return array{0:int,1:?array<string,mixed>}
     */
    private function gcJsonDoor(array $argv): array
    {
        [$rc, $out] = $this->gcDispatch(\array_merge($argv, ['--output-format', 'json']));

        return [$rc, json_decode($out, true)];
    }

    /**
     * Three sessions, one archived, one pinned, one subagent, one turn —
     * the shape every count assertion reads. The store is released before
     * returning: the vacuum cases open their own connection.
     */
    private function gcSeedStore(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('gc-main', 'openai', 'gpt-4o', null, 'Named Main');
        $store->createSession('gc-arch', 'openai', 'gpt-4o', null, 'Old Archive');
        $store->archive('gc-arch');
        $store->createChildSession('gc-main', SessionKind::Subagent, 'explore', 'call-1', 'openai', 'gpt-4o');
        $store->setPinned('gc-main', true);
        $store->recordTurn('gc-main', 'make the login controller use the guard');
        unset($store);

        // The age histogram reads created_at in epoch seconds; give every
        // seeded row a timestamp one hour ago so the <=7d bucket is
        // deterministic instead of whatever the constructor default left.
        $connection = SqliteConnect::open($this->dbPath);
        $connection->prepare('UPDATE sessions SET created_at = ?, updated_at = ?')
            ->execute([time() - 3600, time() - 3600]);
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);
        \gc_collect_cycles();
    }

    /**
     * Delete-fragmentation the file can show: 120 fat rows in, all 120 out,
     * then the WAL checkpointed back so the MAIN database carries the free
     * pages the read-only planning connection reports.
     */
    private function gcFragmentDb(): void
    {
        $connection = SqliteConnect::open($this->dbPath);
        $insert = $connection->prepare('INSERT INTO sessions (id, created_at, updated_at, provider, model, metadata) VALUES (?, ?, ?, ?, ?, ?)');
        $now = time();
        for ($i = 0; $i < 120; ++$i) {
            $insert->execute([\sprintf('frag-%03d', $i), $now, $now, 'openai', 'gpt-4o', \str_repeat('x', 4096)]);
        }
        $connection->exec("DELETE FROM sessions WHERE id BETWEEN 'frag-000' AND 'frag-999'");
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);
        \gc_collect_cycles();
    }

    /**
     * Events heavier than the transcript ledger — the red-flag feed.
     */
    private function gcEventsHeavy(): void
    {
        $connection = SqliteConnect::open($this->dbPath);
        $insert = $connection->prepare('INSERT INTO session_events (session_id, seq, ts, type, payload) VALUES (?, ?, ?, ?, ?)');
        for ($seq = 1; $seq <= 60; ++$seq) {
            $insert->execute(['gc-main', $seq, time(), 'note', \str_repeat('e', 2048)]);
        }
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);
        \gc_collect_cycles();
    }

    private function gcCorruptDb(): void
    {
        \file_put_contents($this->dbPath, \str_repeat('not a database ', 400));
        clearstatcache();
    }

    /**
     * @return array{0:int,1:int} inode and byte size of the database file
     */
    private function gcStat(): array
    {
        clearstatcache();
        $stat = stat($this->dbPath);

        return [(int) ($stat['ino'] ?? 0), (int) ($stat['size'] ?? 0)];
    }
}
