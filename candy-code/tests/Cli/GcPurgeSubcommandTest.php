<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\ParsedArgs;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionKind;
use SugarCraft\Code\Session\SessionSearchIndex;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `candy-code gc purge` — the spec-10 behaviour matrix (gc-cli-1106 B3b).
 *
 * Every door, every exit code, and the three laws the destructive family
 * lives by: deletion is filter-gated and exemption-guarded, strips keep
 * their rows, cleans only ever follow the orphan census they display. The
 * verb-dispatch and status/sessions/analyze proof surface stays in
 * {@see GcSubcommandTest}; this file owns the purge behaviour contract.
 *
 * In-process drives like the sibling: HOME in a private sandbox, ArgvParser
 * -> Subcommands::dispatch, stdout ob-captured. stderr is not capturable
 * in-process, so failure arms assert through the `--output-format json`
 * document and success arms read the unwrapped `result` payload.
 */
final class GcPurgeSubcommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->home = (string) realpath(sys_get_temp_dir()) . '/gc-purge-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->home . '/.candy-code', 0700, true));
        chmod($this->home, 0700);
        $this->useHomeSandbox($this->home);
        putenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        $this->dbPath = $this->home . '/.candy-code/session.db';
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        self::gcPurgeRemoveTree($this->home);
    }

    // ------------------------------------------------------------------
    // Doors and exit codes
    // ------------------------------------------------------------------

    public function testTextDryRunCarriesPlanHeaderAndFooter(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        [$rc, $stdout] = self::gcPurgeDispatch(['purge', '--older-than=30d', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString('candy-code gc purge', $stdout);
        self::assertStringContainsString('total planned', $stdout, 'the text plan carries the totals row');
    }

    public function testZeroOperationExitsUsage(): void
    {
        $doc = self::gcPurgeJsonDoor(['purge', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $doc['__rc']);
        self::assertSame('usage', $doc['error']['type']);
        self::assertSame(Lang::t('cli.gc.purge.zero_op'), $doc['error']['message']);
    }

    public function testMalformedOlderThanExitsUsage(): void
    {
        $doc = self::gcPurgeJsonDoor(['purge', '--older-than=1x', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $doc['__rc']);
        self::assertSame(Lang::t('cli.gc.purge.bad_older_than', ['value' => '1x']), $doc['error']['message']);
    }

    public function testMalformedLargerThanExitsUsage(): void
    {
        $doc = self::gcPurgeJsonDoor(['purge', '--larger-than=12x', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $doc['__rc']);
        self::assertSame(Lang::t('cli.gc.purge.bad_larger_than', ['value' => '12x']), $doc['error']['message']);
    }

    public function testMalformedKeepLatestExitsUsage(): void
    {
        $doc = self::gcPurgeJsonDoor(['purge', '--keep-latest=-1', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $doc['__rc']);
        self::assertSame(Lang::t('cli.gc.purge.bad_keep_latest', ['value' => '-1']), $doc['error']['message']);
    }

    public function testJsonWithoutModeExitsUsageBothSpellings(): void
    {
        $doc = self::gcPurgeJsonDoor(['purge', '--older-than=30d']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $doc['__rc']);
        self::assertSame(Lang::t('cli.gc.purge.json_needs_mode'), $doc['error']['message']);

        $flags = self::gcPurgeDispatch(['purge', '--older-than=30d', '--format', 'json']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $flags[0], 'the --format json spelling must hit the same door');
    }

    public function testUnknownSessionTokenExitsNotFound(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--session=never-was', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $doc['__rc']);
        self::assertSame('not-found', $doc['error']['type']);
    }

    public function testAmbiguousPrefixExitsUsage(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('dup-aa', 'openai', 'gpt-4o');
        $store->createSession('dup-bb', 'openai', 'gpt-4o');
        $doc = self::gcPurgeJsonDoor(['purge', '--session=dup', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $doc['__rc']);
        self::assertSame('usage', $doc['error']['type']);
    }

    public function testCorruptStoreExitsOneNotCrash(): void
    {
        self::gcPurgeCorruptDb($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--older-than=30d', '--dry-run']);
        self::assertSame(1, $doc['__rc'], 'a corrupt store costs exit 1, never a 255 crash');
        self::assertFalse($doc['error'] ?? false, 'a corrupt store is a funnel answer, not a usage error');
        self::assertStringContainsString('session store', (string) ($doc['reason'] ?? ''), 'the funnel says WHY in its reason line');
    }

    public function testConfirmRefusalKeepsEverything(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $before = self::gcPurgeCount($this->dbPath, 'sessions');
        [$rc, $stdout] = self::gcPurgeDispatch(['purge', '--older-than=30d']);
        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertStringContainsString(Lang::t('cli.gc.purge.confirm_required'), $stdout);
        self::assertSame($before, self::gcPurgeCount($this->dbPath, 'sessions'), 'a refused confirm deletes nothing');
    }

    // ------------------------------------------------------------------
    // Selection, exemptions, keep-latest
    // ------------------------------------------------------------------

    public function testFilterAndAgeSelectorLandsOnTheUnnamedOldSession(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--session=old', '--older-than=30d', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_OK, $doc['__rc']);
        self::assertSame(['old'], $doc['plan']['target_sessions'], 'id AND age land on the unnamed old row');
        self::assertSame([], $doc['plan']['exemptions']);

        $missed = self::gcPurgeJsonDoor(['purge', '--session=young', '--older-than=30d', '--dry-run']);
        self::assertSame([], $missed['plan']['target_sessions'], 'young is resolved but the age filter excludes it');
        self::assertSame([], $missed['plan']['exemptions']);
    }

    public function testKeepLatestOneSparesTheNewestMatch(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--older-than=30d', '--keep-latest=1', '--include-named', '--dry-run']);
        self::assertSame(['old'], $doc['plan']['target_sessions'], 'named (35d) is newer than old (40d) and is kept');
    }

    public function testKeepLatestZeroDeletesEveryMatch(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--older-than=30d', '--keep-latest=0', '--include-named', '--dry-run']);
        self::assertSame(['old', 'named'], $doc['plan']['target_sessions']);
    }

    public function testNamedSessionsAreExemptWithoutIncludeNamed(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        // A PINNED row with NO name: only the 'pinned <> 0' leg of
        // gcPurgeExemptIds can spare it ('named' carries both legs, so it
        // alone never discriminates the pinned one). Seeded in-method so
        // the shared-store pins ('four seeded' counts, include-named
        // sweeps) stay untouched.
        $store->createSession('kept', 'openai', 'gpt-4o');
        $store->setPinned('kept', true);
        $epoch = \time() - 3_500_000;
        $pdo->exec('UPDATE sessions SET created_at = ' . $epoch . ", updated_at = " . $epoch . " WHERE id = 'kept'");
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        $doc = self::gcPurgeJsonDoor(['purge', '--older-than=30d', '--dry-run']);
        self::assertSame(['old'], $doc['plan']['target_sessions'], 'the pinned-unnamed kept row must not fall into the acted set');
        self::assertSame(['named', 'kept'], $doc['plan']['exemptions'], 'pinned and named rows both ride the exemption list, in plan order');
        self::assertStringContainsString('--include-named', self::gcPurgeText(['purge', '--older-than=30d', '--dry-run']), 'the exemption line must disclose its override');
    }

    public function testIncludeNamedSweepsNamedIntoThePlan(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--older-than=30d', '--include-named', '--dry-run']);
        self::assertSame(['old', 'named'], $doc['plan']['target_sessions']);
        self::assertSame([], $doc['plan']['exemptions']);
    }

    public function testCommaJoinedSessionListSplitsBackApart(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--session=old,named', '--dry-run']);
        self::assertSame(['old'], $doc['plan']['target_sessions'], 'the comma-joined list must split into two resolved ids');
        self::assertSame(['named'], $doc['plan']['exemptions']);
    }

    // ------------------------------------------------------------------
    // Strips: rows survive, families empty
    // ------------------------------------------------------------------

    public function testStripModeKeepsRowsAndEmptiesFamilies(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        $pdo->exec("INSERT INTO session_events (session_id, seq, ts, type, payload) VALUES ('old', 1, " . \time() . ", 'note', 'strip-me')");
        $pdo->exec("INSERT INTO session_events (session_id, seq, ts, type, payload) VALUES ('young', 1, " . \time() . ", 'note', 'keep-me')");
        $pdo->exec("INSERT INTO usage_records (session_id, ts, model, total_tokens, input_tokens, output_tokens, cost_usd) VALUES ('old', " . \time() . ", 'gpt-4o', 30, 10, 20, 0.02)");
        $pdo->exec("INSERT INTO usage_records (session_id, ts, model, total_tokens, input_tokens, output_tokens, cost_usd) VALUES ('young', " . \time() . ", 'gpt-4o', 2, 1, 1, 0.01)");
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($store);

        $plan = self::gcPurgeJsonDoor(['purge', '--session=old', '--strip-events', '--strip-usage', '--dry-run']);
        self::assertSame('strip', $plan['mode']);
        self::assertSame(['old'], $plan['plan']['strip_sessions']);
        self::assertSame([], $plan['plan']['target_sessions'], 'strip runs never delete rows');

        $run = self::gcPurgeJsonDoor(['purge', '--session=old', '--strip-events', '--strip-usage', '--force']);
        self::assertSame(NonInteractive::EXIT_OK, $run['__rc']);
        self::assertSame(4, self::gcPurgeCount($this->dbPath, 'sessions'), 'strip mode never deletes rows — all four seeded sessions survive');
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM session_events WHERE session_id = 'old'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM session_events WHERE session_id = 'young'")->fetchColumn(), 'other sessions keep their events');
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM usage_records WHERE session_id = 'old'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM usage_records WHERE session_id = 'young'")->fetchColumn());
    }

    public function testStripModeWithNoMatchesPlansNothing(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--session=young', '--older-than=99999d', '--strip-events', '--dry-run']);
        self::assertSame(NonInteractive::EXIT_OK, $doc['__rc']);
        self::assertSame([], $doc['plan']['strip_sessions']);
        self::assertSame([], $doc['plan']['target_sessions']);
    }

    public function testStripUsageCarriesTheDisclosureLine(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        $pdo->exec("INSERT INTO usage_records (session_id, ts, model, total_tokens, input_tokens, output_tokens, cost_usd) VALUES ('old', " . \time() . ", 'gpt-4o', 30, 10, 20, 0.02)");
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($store);
        $stdout = self::gcPurgeText(['purge', '--session=old', '--strip-usage', '--force']);
        self::assertStringContainsString(Lang::t('cli.gc.purge.usage_strip_disclosure'), $stdout, 'stripping usage must disclose the cost it carries');
    }

    public function testStripJournalUnlinksTranscriptJournals(): void
    {
        [$store] = self::gcPurgeSeedStore($this->dbPath);
        $dir = $this->home . '/.candy-code/transcripts/s-1';
        self::assertTrue(mkdir($dir, 0700, true));
        self::assertTrue(file_put_contents($dir . '/old.jsonl', \str_repeat('j', 120)) !== false);
        unset($store);

        $run = self::gcPurgeJsonDoor(['purge', '--session=old', '--strip-journal', '--force']);
        self::assertSame(NonInteractive::EXIT_OK, $run['__rc']);
        self::assertFileDoesNotExist($dir . '/old.jsonl', 'strip-journal unlinks the target transcript journal');
        self::assertSame(4, self::gcPurgeCount($this->dbPath, 'sessions'), 'the journal-stripped row survives among all four seeded rows');
    }

    public function testStripFtsBlindsTheSearchIndex(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        SessionSearchIndex::new($pdo)->syncMessages('old', ['searchable body about purging']);
        $hits = (int) $pdo->query("SELECT COUNT(*) FROM session_messages_fts WHERE session_messages_fts MATCH 'purging'")->fetchColumn();
        self::assertGreaterThan(0, $hits, 'the seeded body must be findable before the strip');
        unset($store);

        $run = self::gcPurgeJsonDoor(['purge', '--session=old', '--strip-fts', '--force']);
        self::assertSame(NonInteractive::EXIT_OK, $run['__rc']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM session_messages_fts WHERE session_messages_fts MATCH 'purging'")->fetchColumn(), 'strip-fts blinds the index for the target');
    }

    // ------------------------------------------------------------------
    // Cleans: display and execution share one source
    // ------------------------------------------------------------------

    public function testDryRunLeavesTheDatabaseByteUntouched(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        self::gcPurgeSeedOrphanField($pdo, $this->home);
        unset($store);
        $before = self::gcPurgeStat($this->dbPath);
        $counts = [
            'sessions' => self::gcPurgeCount($this->dbPath, 'sessions'),
            'events' => self::gcPurgeCount($this->dbPath, 'session_events'),
        ];

        $doc = self::gcPurgeJsonDoor(array_merge(['purge'], self::gcPurgeAllCleans(), ['--dry-run']));
        self::assertSame(NonInteractive::EXIT_OK, $doc['__rc']);

        $after = self::gcPurgeStat($this->dbPath);
        self::assertSame($before['ino'], $after['ino'], 'the plan may not even swap the database file');
        self::assertSame($before['size'], $after['size']);
        self::assertSame($counts, [
            'sessions' => self::gcPurgeCount($this->dbPath, 'sessions'),
            'events' => self::gcPurgeCount($this->dbPath, 'session_events'),
        ]);
    }

    public function testCleansFollowTheCensusExactly(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        self::gcPurgeSeedOrphanField($pdo, $this->home);
        unset($store);

        $plan = self::gcPurgeJsonDoor(array_merge(['purge'], self::gcPurgeAllCleans(), ['--dry-run']));
        self::assertSame(
            6,
            $plan['plan']['clean_totals']['count'],
            'the displayed census total must equal the seeded orphan field (journals+events+imports+locks+shares+shadows)'
        );

        $run = self::gcPurgeJsonDoor(array_merge(['purge'], self::gcPurgeAllCleans(), ['--force']));
        self::assertSame(NonInteractive::EXIT_OK, $run['__rc']);
        $configDir = $this->home . '/.candy-code';
        self::assertFileDoesNotExist($configDir . '/transcripts/s-1/ghost-j.jsonl');
        self::assertFileDoesNotExist($configDir . '/sessions/ghost.lock');
        self::assertFileDoesNotExist($configDir . '/checkpoints/' . \str_repeat('f', 40) . '/data');
        self::assertFileExists($configDir . '/checkpoints/' . \str_repeat('b', 40) . '/data', 'a fresh shadow directory is not yet stale');
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM session_events WHERE session_id = 'ghost-e'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM session_events WHERE session_id = 'gone-1'")->fetchColumn(), 'an event whose owner has an in-grace tombstone survives');
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM session_imports WHERE source_key = 'k-ghost'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM shares WHERE token = 't1'")->fetchColumn(), 'the revoked share row is inert and goes');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM shares WHERE token = 't2'")->fetchColumn(), 'the live share row survives');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM session_tombstones WHERE id = 'gone-1'")->fetchColumn(), 'an in-grace tombstone is not expired');
    }

    public function testStaleLocksCleanerSparesHeldLocks(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        self::gcPurgeSeedOrphanField($pdo, $this->home);
        $held = $this->home . '/.candy-code/sessions/held.lock';
        self::assertTrue(file_put_contents($held, \str_repeat('h', 9)) !== false);
        unset($store);

        $child = self::gcPurgeTakeFlock($held);
        try {
            $settled = false;
            for ($i = 0; $i < 100; ++$i) {
                \usleep(50_000);
                $probe = @fopen($held, 'a');
                if ($probe === false) {
                    continue;
                }
                if (!flock($probe, LOCK_EX | LOCK_NB)) {
                    fclose($probe);
                    $settled = true;
                    break;
                }
                flock($probe, LOCK_UN);
                fclose($probe);
            }
            self::assertTrue($settled, 'the flock child never took the lock');

            $run = self::gcPurgeJsonDoor(['purge', '--clean-locks', '--force']);
            self::assertSame(NonInteractive::EXIT_OK, $run['__rc']);
            self::assertFileDoesNotExist($this->home . '/.candy-code/sessions/ghost.lock', 'the unheld stale lock goes');
            self::assertFileExists($held, 'a lock an honest holder is sitting on never gets swept');
        } finally {
            proc_terminate($child, 9);
            proc_close($child);
        }
    }

    public function testLockedSessionSparedWithoutForceAndCrossedWithIt(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        $lockDir = $this->home . '/.candy-code/sessions';
        self::assertTrue(mkdir($lockDir, 0700, true));
        $held = $lockDir . '/old.lock';
        self::assertTrue(file_put_contents($held, \str_repeat('l', 9)) !== false);
        unset($store);

        $child = self::gcPurgeTakeFlock($held);
        try {
            $settled = false;
            for ($i = 0; $i < 100; ++$i) {
                \usleep(50_000);
                $probe = @fopen($held, 'a');
                if ($probe === false) {
                    continue;
                }
                if (!flock($probe, LOCK_EX | LOCK_NB)) {
                    fclose($probe);
                    $settled = true;
                    break;
                }
                flock($probe, LOCK_UN);
                fclose($probe);
            }
            self::assertTrue($settled, 'the flock child never took the lock');

            $plan = self::gcPurgeJsonDoor(['purge', '--older-than=30d', '--dry-run']);
            self::assertSame(['old'], $plan['plan']['locked'], 'a session under an honest live lock is displayed as locked');

            $run = self::gcPurgeJsonDoor(['purge', '--older-than=30d', '--force']);
            self::assertSame(NonInteractive::EXIT_OK, $run['__rc']);
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'old'")->fetchColumn(), '--force crosses the live-lock wall by owner ruling');
        } finally {
            proc_terminate($child, 9);
            proc_close($child);
        }
    }

    // ------------------------------------------------------------------
    // Execution: tombstones, inert rows, prune law, vacuum hint
    // ------------------------------------------------------------------

    public function testForceDeleteWritesTombstoneAndLeavesUsageInert(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        $pdo->exec("INSERT INTO usage_records (session_id, ts, model, total_tokens, input_tokens, output_tokens, cost_usd) VALUES ('old', " . \time() . ", 'gpt-4o', 30, 10, 20, 0.02)");
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($store);

        $run = self::gcPurgeJsonDoor(['purge', '--older-than=30d', '--force']);
        self::assertSame(NonInteractive::EXIT_OK, $run['__rc']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'old'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM session_tombstones WHERE id = 'old'")->fetchColumn(), 'a delete leaves a grace tombstone');
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM usage_records WHERE session_id = 'old'")->fetchColumn(), 'deleteSession rides the house FK (usage_records cascades on the sessions DELETE, SessionStore.php PRAGMA foreign_keys=ON) — strip-usage is for surviving sessions');
    }

    public function testCleanOnlyPurgeNeverRetentionPrunes(): void
    {
        [$store, $pdo] = self::gcPurgeSeedStore($this->dbPath);
        $rows = (string) $pdo->query('SELECT id || created_at FROM sessions ORDER BY id')->fetchColumn();
        $all = (string) $pdo->query('SELECT group_concat(id || created_at) FROM sessions')->fetchColumn();
        unset($store);

        putenv('CANDY_CODE_SESSION_RETENTION_DAYS=1');
        $run = self::gcPurgeJsonDoor(['purge', '--clean-shadows', '--force']);
        self::assertSame(NonInteractive::EXIT_OK, $run['__rc']);
        self::assertSame($all, (string) $pdo->query('SELECT group_concat(id || created_at) FROM sessions')->fetchColumn(), 'purge opens the store prune:false — an aggressive retention window must never sweep sessions on a clean-only run');
        self::assertNotSame('', $rows);
    }

    public function testVacuumHintFollowsRealReclaimNotStrips(): void
    {
        [$store] = self::gcPurgeSeedStore($this->dbPath);
        $store->addMessage('old', ['role' => 'user', 'content' => \str_repeat('F', 400 * 1024)]);
        $store = null;

        $deleted = self::gcPurgeText(['purge', '--older-than=30d', '--force']);
        self::assertStringContainsString('vacuum', $deleted, 'a delete run that actually frees pages must print the vacuum hint');

        $stripped = self::gcPurgeText(['purge', '--session=young', '--strip-events', '--force']);
        self::assertStringNotContainsString('vacuum', $stripped, 'a strip run deletes nothing and never hints at vacuum');
    }

    // ------------------------------------------------------------------
    // Contract pins
    // ------------------------------------------------------------------

    public function testEveryPurgeFlagIsParserAdmitted(): void
    {
        $admitted = ParsedArgs::SUBCOMMAND_FLAGS['gc'];
        foreach ([
            '--older-than', '--session', '--subagents', '--larger-than', '--keep-latest',
            '--archived', '--include-named',
            '--strip-events', '--strip-usage', '--strip-journal', '--strip-fts', '--strip-checkpoints',
            '--clean-orphan-journals', '--clean-orphan-events', '--clean-orphan-usage', '--clean-imports',
            '--clean-expired-tombstones', '--clean-locks', '--clean-shares', '--clean-shadows',
            '--dry-run', '-n', '--force', '-f',
        ] as $flag) {
            self::assertArrayHasKey($flag, $admitted, $flag . ' must be parser-admitted for gc');
        }
    }

    public function testJsonDryRunCarriesTheFiltersShape(): void
    {
        self::gcPurgeSeedStore($this->dbPath);
        $doc = self::gcPurgeJsonDoor(['purge', '--session=old', '--older-than=30d', '--larger-than=1k', '--keep-latest=2', '--dry-run']);
        self::assertTrue($doc['dry_run']);
        self::assertFalse($doc['executed']);
        self::assertSame(['old'], $doc['filters']['sessions']);
        self::assertSame(2_592_000, $doc['filters']['older_than_seconds']);
        self::assertSame(1024, $doc['filters']['larger_than_bytes']);
        self::assertSame(2, $doc['filters']['keep_latest']);
    }

    // ------------------------------------------------------------------
    // Helpers — gcPurge*-named, the helper-drift guard sees them as one family
    // ------------------------------------------------------------------

    /**
     * @param list<string> $argv
     *
     * @return array{0:int,1:string}
     */
    private static function gcPurgeDispatch(array $argv): array
    {
        \ob_start();
        try {
            $rc = Subcommands::dispatch(ArgvParser::parse(array_merge(['candy-code', 'gc'], $argv)));
        } finally {
            $stdout = (string) \ob_get_clean();
        }

        return [$rc, $stdout];
    }

    /**
     * @param list<string> $argv
     */
    private static function gcPurgeText(array $argv): string
    {
        return self::gcPurgeDispatch($argv)[1];
    }

    /**
     * @param list<string> $argv
     *
     * @return array<string,mixed>
     */
    private static function gcPurgeJsonDoor(array $argv): array
    {
        [$rc, $stdout] = self::gcPurgeDispatch(array_merge($argv, ['--output-format', 'json']));
        $doc = \json_decode($stdout, true);
        self::assertIsArray($doc, 'the json door must print exactly one document: ' . $stdout);
        if (($doc['error'] ?? null) === null && \is_array($doc['result'] ?? null)) {
            $doc = $doc['result'];
        }
        $doc['__rc'] = $rc;

        return $doc;
    }

    /**
     * @return array{0:EnhancedSessionStore,1:PDO}
     */
    private static function gcPurgeSeedStore(string $dbPath): array
    {
        $store = new EnhancedSessionStore($dbPath);
        $store->createSession('old', 'openai', 'gpt-4o');
        $store->createSession('young', 'openai', 'gpt-4o');
        $store->createSession('named', 'anthropic', 'claude-3', null, 'Has Name');
        $store->createChildSession('named', SessionKind::Subagent, 'explore', 'call-1', 'anthropic', 'claude-3');
        $store->setPinned('named', true);
        $store->recordTurn('old', 'old session body');
        $store->recordTurn('young', 'young session body');

        $pdo = self::gcPurgeHandle($dbPath);
        $now = \time();
        $pdo->exec('UPDATE sessions SET created_at = ' . ($now - 3_456_000) . ", updated_at = " . ($now - 3_456_000) . " WHERE id = 'old'");
        $pdo->exec('UPDATE sessions SET created_at = ' . ($now - 3_024_000) . ", updated_at = " . ($now - 3_024_000) . " WHERE id = 'named'");
        $pdo->exec('UPDATE sessions SET created_at = ' . ($now - 3_600) . ", updated_at = " . ($now - 3_600) . " WHERE id = 'young'");
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        return [$store, $pdo];
    }

    /**
     * The orphan field: exactly six censusable items (one ghost journal,
     * one ghost event, one dead import row, one unheld stale lock, one
     * revoked share, one abandoned shadow) plus the survivors that prove
     * the accuser does not over-reach.
     */
    private static function gcPurgeSeedOrphanField(PDO $pdo, string $home): void
    {
        $now = \time();
        $event = $pdo->prepare('INSERT INTO session_events (session_id, seq, ts, type, payload) VALUES (?, ?, ?, ?, ?)');
        $event->execute(['ghost-e', 1, $now, 'note', 'x']);
        $pdo->prepare('INSERT INTO session_tombstones (id, deleted_at) VALUES (?, ?)')->execute(['gone-1', $now - 60]);
        $event->execute(['gone-1', 1, $now, 'note', 'yyy']);
        $pdo->exec('CREATE TABLE IF NOT EXISTS shares (token TEXT PRIMARY KEY, session_id TEXT, format TEXT, html TEXT, created_at DATETIME, expires_at INTEGER, revoked_at INTEGER, live INTEGER)');
        $pdo->exec("INSERT INTO shares (token, session_id, format, html, created_at, expires_at, revoked_at, live) VALUES ('t1', 'named', 'html', 'x', $now, NULL, " . ($now - 10) . ', 0)');
        $pdo->exec("INSERT INTO shares (token, session_id, format, html, created_at, expires_at, revoked_at, live) VALUES ('t2', 'named', 'html', 'y', $now, NULL, NULL, 1)");
        $pdo->exec('CREATE TABLE IF NOT EXISTS session_imports (source_key TEXT PRIMARY KEY, session_id TEXT, items TEXT, digest TEXT, imported_at INTEGER)');
        $pdo->exec("INSERT INTO session_imports (source_key, session_id, items, digest, imported_at) VALUES ('k-ghost', 'ghost-i', '[]', 'd', $now)");
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

        $configDir = $home . '/.candy-code';
        self::assertTrue(mkdir($configDir . '/transcripts/s-1', 0700, true));
        \file_put_contents($configDir . '/transcripts/s-1/ghost-j.jsonl', \str_repeat('g', 300));
        self::assertTrue(mkdir($configDir . '/sessions', 0700, true));
        \file_put_contents($configDir . '/sessions/ghost.lock', \str_repeat('l', 9));
        $stale = $configDir . '/checkpoints/' . \str_repeat('f', 40);
        self::assertTrue(mkdir($stale, 0700, true));
        \file_put_contents($stale . '/data', \str_repeat('o', 77));
        touch($stale, $now - 200000);
        $fresh = $configDir . '/checkpoints/' . \str_repeat('b', 40);
        self::assertTrue(mkdir($fresh, 0700, true));
        \file_put_contents($fresh . '/data', \str_repeat('n', 77));
    }

    /**
     * @return list<string>
     */
    private static function gcPurgeAllCleans(): array
    {
        return [
            '--clean-orphan-journals', '--clean-orphan-events', '--clean-orphan-usage',
            '--clean-imports', '--clean-expired-tombstones', '--clean-locks',
            '--clean-shares', '--clean-shadows',
        ];
    }

    private static function gcPurgeTakeFlock(string $path): mixed
    {
        $pipes = [];
        $child = \proc_open(
            ['php', '-r', '$f=fopen($argv[1],"a");flock($f,LOCK_EX);sleep(30);', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null
        );
        self::assertIsResource($child);

        return $child;
    }

    private static function gcPurgeHandle(string $dbPath): PDO
    {
        return new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * @return array{ino:int,size:int}
     */
    private static function gcPurgeStat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = stat($path);
        self::assertIsArray($stat);

        return ['ino' => (int) $stat['ino'], 'size' => (int) $stat['size']];
    }

    private static function gcPurgeCount(string $dbPath, string $table): int
    {
        $pdo = self::gcPurgeHandle($dbPath);

        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private static function gcPurgeCorruptDb(string $dbPath): void
    {
        $dir = (string) dirname($dbPath);
        if (!\is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0700, true));
        }
        file_put_contents($dbPath, 'this-is-not-sqlite' . \str_repeat("\0", 64));
    }

    private static function gcPurgeRemoveTree(string $path): void
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
            self::gcPurgeRemoveTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
