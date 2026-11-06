<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\ParsedArgs;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionKind;
use SugarCraft\Code\Support\SqliteConnect;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `candy-code gc status|sessions|analyze|vacuum` — the gc proof surface
 * (gc-cli-1106 B1+B2).
 *
 * In-process, the way {@see SessionSubcommandsTest} drives its verb:
 * ArgvParser -> Subcommands::dispatch with `HOME` pointed at a private
 * directory, so {@see Subcommands}' `gc` path reads the fixture database
 * this file seeds. `sessions` and `analyze` landed with B2 (the fold and
 * its analysis surface); `purge` is the one later rung of the same verb:
 * TODAY it must answer the unknown-action door at exit 2, and the case
 * below pins exactly that (it flips when the rung lands).
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
        // B3 rung: only `purge` remains unimplemented TODAY — the honest
        // answer is the unknown-action door. (`sessions` landed with B2 and
        // `analyze` lands with C3 of this same program; their pins live in
        // the sections below.)
        foreach (['purge'] as $action) {
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

    // ── gc sessions: the B2 fold ──────────────────────────────────────────

    public function testSessionsTextDefaultSortsBySizeDescAndHidesArchived(): void
    {
        $this->gcSeedStore();
        $this->gcSeedMessageBytes();

        [$rc, $out] = $this->gcDispatch(['sessions']);

        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('candy-code gc sessions', $out);
        self::assertStringContainsString('ID', $out);
        // The fat session leads; the archived one is hidden by default
        // (mirrors `session list --archived`).
        self::assertLessThan(
            (int) strpos($out, $this->gcChildClip()),
            (int) strpos($out, 'gc-main'),
            'default sort is size descending',
        );
        self::assertStringNotContainsString('gc-arch', $out, 'archives stay hidden without --archived');
        self::assertStringContainsString('of table census', $out);
    }

    public function testSessionsArchivedDoorRevealsTheArchive(): void
    {
        $this->gcSeedStore();

        [$rc, $doc] = $this->gcJsonDoor(['sessions', '--archived']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertSame(3, $doc['result']['session_count']);
        self::assertTrue($doc['result']['include_archived']);

        [$rc, $text] = $this->gcDispatch(['sessions', '--archived']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('gc-arch', $text);
        self::assertStringContainsString('root A', $text, 'the flags cell marks the revealed archive (not just its title)');
    }

    public function testSessionsChildrenRestrictsToSubagentRows(): void
    {
        $this->gcSeedStore();

        [$rc, $doc] = $this->gcJsonDoor(['sessions', '--children']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertSame(1, $doc['result']['shown']);
        self::assertSame('subagent', $doc['result']['rows'][0]['kind']);
        self::assertSame('gc-main', $doc['result']['rows'][0]['parent_id']);

        [$rc, $out] = $this->gcDispatch(['sessions', '--children']);
        self::assertStringNotContainsString('gc-main ', $out, 'root rows leave the --children listing');
    }

    public function testSessionsLimitKeepsTheHeadOfTheSortedList(): void
    {
        $this->gcSeedStore();
        $this->gcSeedMessageBytes();

        [$rc, $doc] = $this->gcJsonDoor(['sessions', '--limit', '1']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertSame(1, $doc['result']['shown']);
        self::assertSame(2, $doc['result']['session_count'], 'the limit slices, it does not filter');
        self::assertSame('gc-main', $doc['result']['rows'][0]['id'], 'the largest session leads');
    }

    public function testSessionsSortAgePutsOldestFirst(): void
    {
        $this->gcSeedStore();
        $child = $this->gcChildId();
        $connection = SqliteConnect::open($this->dbPath);
        $connection->prepare('UPDATE sessions SET created_at = ? WHERE id = ?')
            ->execute([time() - 100000, 'gc-main']);
        $connection->prepare('UPDATE sessions SET created_at = ? WHERE id = ?')
            ->execute([time() - 200, $child]);
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);

        [$rc, $out] = $this->gcDispatch(['sessions', '--sort', 'age']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertLessThan((int) strpos($out, \substr($child, 0, 12)), (int) strpos($out, 'gc-main'), 'age sorts oldest first');
        self::assertStringContainsString('1d', $out, 'the day-scale age cell rides the histogram vocabulary');
    }

    public function testSessionsSortNameIsAlphabeticalOnTheShownTitle(): void
    {
        $this->gcSeedStore();
        $child = $this->gcChildId();
        $connection = SqliteConnect::open($this->dbPath);
        $connection->prepare('UPDATE sessions SET name = ? WHERE id = ?')
            ->execute(['Alpha Child', $child]);
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);

        [$rc, $out] = $this->gcDispatch(['sessions', '--sort', 'name']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertLessThan((int) strpos($out, 'Named Main'), (int) strpos($out, 'Alpha Child'), 'name sorts A before N');
    }

    public function testSessionsClipIdAndTitle(): void
    {
        $this->gcSeedStore();
        $child = $this->gcChildId();
        $connection = SqliteConnect::open($this->dbPath);
        $connection->prepare('UPDATE sessions SET name = ? WHERE id = ?')
            ->execute([\str_repeat('n', 60), 'gc-main']);
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);

        [$rc, $out] = $this->gcDispatch(['sessions']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringNotContainsString($child, $out, 'a 32-hex id never prints unclipped');
        self::assertStringContainsString(\substr($child, 0, 12), $out, 'the clipped 12-char head prints');
        self::assertStringNotContainsString(\str_repeat('n', 60), $out, 'the 60-char title never prints raw');
        self::assertStringContainsString(\str_repeat('n', 39) . '…', $out, 'the title clips at 40 with an ellipsis');
    }

    public function testSessionsJsonReconcilesFoldAgainstTheCensus(): void
    {
        $this->gcSeedStore();

        [$rc, $doc] = $this->gcJsonDoor(['sessions']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertSame(1, $doc['schema']);
        self::assertSame('gc', $doc['verb']);
        self::assertSame('sessions', $doc['action']);

        $fold = $doc['result']['fold'];
        $census = (int) $doc['result']['totals']['census_table_bytes'];
        self::assertGreaterThan(0, $census);
        self::assertSame(
            $census,
            $fold['attributed_db_bytes'] + $fold['unattributed_db_bytes']
                + $fold['fts_residual_bytes'] + $fold['tombstone_bytes'],
            'the fold and the status census must reconcile exactly',
        );
        self::assertIsArray($doc['result']['rows'][0]);
        self::assertArrayHasKey('journal_bytes', $doc['result']['rows'][0]);
        self::assertArrayHasKey('shadow_dir_count', $doc['result']['rows'][0]);
    }

    public function testSessionsAttributeJournalAndShadowBytes(): void
    {
        $this->gcSeedStore();
        self::assertTrue(mkdir($this->home . '/.candy-code/transcripts/s-1', 0700, true));
        file_put_contents($this->home . '/.candy-code/transcripts/s-1/gc-main.jsonl', \str_repeat('j', 1024));

        $project = $this->home . '/proj';
        self::assertTrue(mkdir($project, 0700, true));
        $real = (string) realpath($project);
        $shadow = $this->home . '/.candy-code/checkpoints/' . \sha1($real);
        self::assertTrue(mkdir($shadow, 0700, true));
        file_put_contents($shadow . '/data', \str_repeat('s', 101));
        $connection = SqliteConnect::open($this->dbPath);
        $connection->prepare('UPDATE sessions SET cwd = ? WHERE id IN (?, ?)')
            ->execute([$real, 'gc-arch', 'gc-main']);
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);

        [$rc, $doc] = $this->gcJsonDoor(['sessions', '--archived']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $byId = [];
        foreach ($doc['result']['rows'] as $row) {
            $byId[(string) $row['id']] = $row;
        }
        self::assertSame(1024, (int) $byId['gc-main']['journal_bytes'], 'the journal file rides its session');
        // Even split of 101 bytes between the two owners sorted by id:
        // gc-arch is first, so the one-byte remainder lands there (pinned
        // scheme — see GcSessionSizes::splitShadowDirectories).
        self::assertSame(51, (int) $byId['gc-arch']['shadow_bytes']);
        self::assertSame(50, (int) $byId['gc-main']['shadow_bytes']);
        self::assertSame(1, (int) $byId['gc-main']['shadow_dir_count']);
        self::assertSame(0, (int) $byId['gc-arch']['journal_bytes']);
    }

    public function testSessionsBadSortLimitAndWrongFlagAreUsageErrors(): void
    {
        $this->gcSeedStore();

        foreach ([
            ['sessions', '--sort', 'weight'],
            ['sessions', '--limit', 'abc'],
            ['sessions', '--limit', '0.5'],
            ['sessions', '--force'],
            ['sessions', '--format', 'yaml'],
        ] as $argv) {
            [$rc, $doc] = $this->gcJsonDoor($argv);
            self::assertSame(NonInteractive::EXIT_CONFIG, $rc, \implode(' ', $argv));
            self::assertIsArray($doc, \implode(' ', $argv));
        }
    }

    public function testSessionsMissingStoreExitsOneThroughTheSharedFunnel(): void
    {
        [$rc, $doc] = $this->gcJsonDoor(['sessions']);

        self::assertSame(NonInteractive::EXIT_FAILURE, $rc);
        self::assertFalse($doc['result']['opened']);
        self::assertSame('sessions', $doc['action']);
        self::assertStringContainsString('cannot open the session store', (string) $doc['result']['reason']);
    }

    // ── gc analyze: the B2 analysis surface ─────────────────────────────────

    public function testAnalyzeTopNAndPercents(): void
    {
        $this->gcSeedStore();
        $this->gcSeedMessageBytes();
        [$rc, $doc] = $this->gcJsonDoor(['analyze', '--top', '1']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertIsArray($doc);
        $result = $doc['result'];
        self::assertCount(1, $result['top']);
        self::assertSame(3, $result['session_count']);
        self::assertGreaterThan(0, $result['grand_total_bytes']);
        self::assertGreaterThan(0.0, (float) $result['top'][0]['percent']);
        [$rc, $out] = $this->gcDispatch(['analyze', '--top', '1']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('candy-code gc analyze', $out);
        self::assertStringContainsString('top 1 of 3 sessions', $out);
    }

    public function testAnalyzeGrowthIsLabeledWithItsScope(): void
    {
        $this->gcSeedAnalyzeStore();
        [$rc, $doc] = $this->gcJsonDoor(['analyze']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $growth = $doc['result']['growth'];
        self::assertIsArray($growth);
        self::assertGreaterThanOrEqual(2, $growth['active_days']);
        self::assertGreaterThan(0, $growth['rate_bytes_per_day']);
        self::assertGreaterThan(0, $growth['measured_bytes']);
        [$rc, $out] = $this->gcDispatch(['analyze']);
        self::assertStringContainsString('growth (usage_records + session_events)', $out);
    }

    public function testAnalyzeGrowthAbsentOnFreshStore(): void
    {
        $this->gcSeedStore();
        [$rc, $doc] = $this->gcJsonDoor(['analyze']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertNull($doc['result']['growth']);
        [$rc, $out] = $this->gcDispatch(['analyze']);
        self::assertStringContainsString('not measurable', $out);
    }

    public function testAnalyzeOrphanCensusCountsTheSeededField(): void
    {
        $this->gcSeedOrphanField();
        [$rc, $doc] = $this->gcJsonDoor(['analyze']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $orphans = $doc['result']['orphans'];
        self::assertSame(1, $orphans['journals']['count']);
        self::assertSame(300, $orphans['journals']['bytes']);
        // Exactly ONE accused events row: 'gone-1' rides a fresh tombstone
        // inside the 30-day grace, so the suppression law excuses it; only
        // 'ghost-e' is orphaned. A census that ignored the tombstone would
        // answer 2 here — this pin is what makes that law load-bearing.
        self::assertSame(1, $orphans['events']['count']);
        self::assertGreaterThan(0, $orphans['events']['bytes']);
        self::assertSame(0, $orphans['usage_records']['count']);
        self::assertSame(1, $orphans['session_imports']['count']);
        self::assertSame(1, $orphans['stale_locks']['count']);
        self::assertSame(9, $orphans['stale_locks']['bytes']);
        self::assertSame(1, $orphans['stale_shares']['count']);
        self::assertSame(1, $orphans['abandoned_shadows']['count']);
        self::assertSame(77, $orphans['abandoned_shadows']['bytes']);
        self::assertSame(0, $orphans['expired_tombstones']['count']);
        self::assertSame(1, $orphans['teams']['count']);
        self::assertSame(5, $orphans['teams']['bytes']);
        [$rc, $out] = $this->gcDispatch(['analyze']);
        self::assertStringContainsString('orphan census (report-only', $out);
    }

    public function testAnalyzeOrphanCensusQuietOnCleanStore(): void
    {
        $this->gcSeedStore();
        [$rc, $doc] = $this->gcJsonDoor(['analyze']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $orphans = $doc['result']['orphans'];
        foreach (['journals', 'events', 'usage_records', 'session_imports', 'stale_locks', 'stale_shares', 'abandoned_shadows', 'expired_tombstones', 'teams'] as $family) {
            self::assertSame(0, $orphans[$family]['count'], $family);
        }
    }

    public function testAnalyzeTextCarriesBarsAndBothTables(): void
    {
        $this->gcSeedStore();
        [$rc, $out] = $this->gcDispatch(['analyze']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('per-table bytes', $out);
        self::assertStringContainsString('█', $out);
        self::assertStringContainsString('root vs subagent', $out);
        self::assertStringContainsString('per-storage bytes', $out);
    }

    public function testAnalyzeTeamsRowIsReportOnlyAndNoCleanupFlagExists(): void
    {
        $this->gcSeedStore();
        mkdir($this->home . '/.candy-code/teams/t-1', 0700, true);
        file_put_contents($this->home . '/.candy-code/teams/t-1/x', \str_repeat('t', 5));
        [$rc, $out] = $this->gcDispatch(['analyze']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('teams dirs (report-only)', $out);
        [$rc] = $this->gcJsonDoor(['analyze', '--force']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc);
        self::assertArrayNotHasKey('--clean-teams', ParsedArgs::SUBCOMMAND_FLAGS['gc'], 'owner ruling 4b: teams are report-only, no cleanup flag exists');
    }

    public function testAnalyzeBadTopIsAUsageError(): void
    {
        // NOTE the '=1' form for the negative: the space-separated
        // `--top -1` drops the value at the ARGV parser itself (a token
        // starting with '-' is never consumed as a value), so my door
        // never sees it — the '=' spelling is what reaches validation.
        foreach ([['analyze', '--top', '0'], ['analyze', '--top', 'abc'], ['analyze', '--top=-1']] as $argv) {
            [$rc, $doc] = $this->gcJsonDoor($argv);
            self::assertSame(NonInteractive::EXIT_CONFIG, $rc, \implode(' ', $argv));
            self::assertIsArray($doc, \implode(' ', $argv));
        }
    }

    public function testAnalyzeMissingStoreExitsOneThroughTheSharedFunnel(): void
    {
        [$rc, $doc] = $this->gcJsonDoor(['analyze']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc);
        self::assertFalse($doc['result']['opened']);
        self::assertSame('analyze', $doc['action']);
        self::assertStringContainsString('cannot open the session store', (string) $doc['result']['reason']);
    }

    public function testAnalyzeRootVsSubagentKinds(): void
    {
        $this->gcSeedStore();
        [$rc, $doc] = $this->gcJsonDoor(['analyze']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $kinds = $doc['result']['kinds'];
        self::assertSame(2, $kinds['root']['count']);
        self::assertGreaterThan(0, $kinds['root']['bytes']);
        self::assertSame(1, $kinds['subagent']['count']);
        self::assertGreaterThan(0, $kinds['subagent']['bytes']);
    }

    public function testAnalyzeJsonShapeAndReconciliation(): void
    {
        $this->gcSeedAnalyzeStore();
        [$rc, $doc] = $this->gcJsonDoor(['analyze']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertSame(1, $doc['schema']);
        self::assertSame('gc', $doc['verb']);
        self::assertSame('analyze', $doc['action']);
        $result = $doc['result'];
        $fold = $result['fold'];
        self::assertSame(
            $result['totals']['census_table_bytes'],
            $fold['attributed_db_bytes'] + $fold['unattributed_db_bytes'] + $fold['fts_residual_bytes'] + $fold['tombstone_bytes'],
        );
        self::assertArrayHasKey('percent', $result['top'][0]);
        self::assertGreaterThanOrEqual(0.0, (float) $result['totals']['db_share_percent']);
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
     * One fat message for the main session and a thin one for its child:
     * the default size-descending order then has a real byte gap to sort on
     * instead of two rows within one page of each other.
     */
    private function gcSeedMessageBytes(): void
    {
        $child = $this->gcChildId();
        $connection = SqliteConnect::open($this->dbPath);
        $insert = $connection->prepare('INSERT INTO messages (session_id, role, content, tool_calls, tool_results, model, tokens_used, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute(['gc-main', 'user', \str_repeat('a', 4000), '', '', 'gpt-4o', 10, time()]);
        $insert->execute([$child, 'user', \str_repeat('b', 100), '', '', 'gpt-4o', 1, time()]);
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);
    }

    /**
     * The three-session seed plus message payloads and two usage_records
     * rows on DISTINCT days — the growth legs read the ledger window, so
     * the rows must span two calendar days (UTC via DATE unixepoch).
     */
    private function gcSeedAnalyzeStore(): void
    {
        $this->gcSeedStore();
        $this->gcSeedMessageBytes();
        $connection = SqliteConnect::open($this->dbPath);
        $insert = $connection->prepare('INSERT INTO usage_records (session_id, ts, model, total_tokens, cost_usd, source) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute(['gc-main', \time() - 2 * 86400, 'gpt-4o', 1, 0.0, 'chat']);
        $insert->execute(['gc-main', \time() - 86400, 'gpt-4o', 1, 0.0, 'chat']);
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);
    }

    /**
     * One orphan per family the census walks, each with a byte shape the
     * assertions can count exactly. The tombstone-grace law gets its own
     * pair: 'gone-1' has an events row AND a fresh tombstone, so the
     * events census must accuse exactly one row (ghost-e), never two.
     */
    private function gcSeedOrphanField(): void
    {
        $this->gcSeedAnalyzeStore();
        $connection = SqliteConnect::open($this->dbPath);
        $event = $connection->prepare('INSERT INTO session_events (session_id, seq, ts, type, payload) VALUES (?, ?, ?, ?, ?)');
        $event->execute(['ghost-e', 1, \time(), 'note', 'x']);
        $connection->prepare('INSERT INTO session_tombstones (id, deleted_at) VALUES (?, ?)')->execute(['gone-1', \time() - 60]);
        $event->execute(['gone-1', 1, \time(), 'note', 'yyy']);
        $connection->exec('CREATE TABLE IF NOT EXISTS shares (token TEXT PRIMARY KEY, session_id TEXT, format TEXT, html TEXT, created_at DATETIME, expires_at INTEGER, revoked_at INTEGER, live INTEGER)');
        $now = (string) \time();
        $connection->exec('INSERT INTO shares (token, session_id, format, html, created_at, expires_at, revoked_at, live) VALUES (\'t1\', \'gc-main\', \'html\', \'x\', ' . $now . ', NULL, ' . (((int) $now) - 10) . ', 0)');
        $connection->exec('INSERT INTO shares (token, session_id, format, html, created_at, expires_at, revoked_at, live) VALUES (\'t2\', \'gc-main\', \'html\', \'y\', ' . $now . ', NULL, NULL, 1)');
        $connection->exec('CREATE TABLE IF NOT EXISTS session_imports (source_key TEXT PRIMARY KEY, session_id TEXT, items TEXT, digest TEXT, imported_at INTEGER)');
        $connection->exec('INSERT INTO session_imports (source_key, session_id, items, digest, imported_at) VALUES (\'k-ghost\', \'ghost-i\', \'[]\', \'d\', ' . $now . ')');
        $connection->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($connection);
        $configDir = $this->home . '/.candy-code';
        mkdir($configDir . '/transcripts/s-1', 0700, true);
        file_put_contents($configDir . '/transcripts/s-1/ghost-j.jsonl', \str_repeat('g', 300));
        mkdir($configDir . '/sessions', 0700, true);
        file_put_contents($configDir . '/sessions/ghost.lock', \str_repeat('l', 9));
        mkdir($configDir . '/checkpoints/' . \str_repeat('f', 40), 0700, true);
        file_put_contents($configDir . '/checkpoints/' . \str_repeat('f', 40) . '/data', \str_repeat('o', 77));
        touch($configDir . '/checkpoints/' . \str_repeat('f', 40), \time() - 200000);
        mkdir($configDir . '/teams/t-1', 0700, true);
        file_put_contents($configDir . '/teams/t-1/x', \str_repeat('t', 5));
    }

    /**
     * The seeded child's id — the listing cases need the 32-hex identity to
     * locate its (clipped) row.
     */
    private function gcChildId(): string
    {
        $connection = SqliteConnect::open($this->dbPath);
        $id = (string) $connection->query('SELECT id FROM sessions WHERE parent_id IS NOT NULL LIMIT 1')->fetchColumn();
        unset($connection);
        self::assertNotSame('', $id, 'gcSeedStore always leaves exactly one child row');

        return $id;
    }

    private function gcChildClip(): string
    {
        return \substr($this->gcChildId(), 0, 12);
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
