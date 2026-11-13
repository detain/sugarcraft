<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Ignore\SugarTrackIgnore;
use SugarCraft\CodeTime\Support\Version;

/**
 * End-to-end tests for the `bin/candy-codetime` CLI that prove the shipped
 * features are wired into the real command paths:
 *
 *   - `.sugartrackignore` is consulted by `push`
 *   - `--backend=sqlite` selects the SQLite backend for push/export
 *   - `milestone add|list` manages Milestone value objects
 *
 *   - storage failures abort `push` with exit 1 instead of printing false success
 *   - `--version`, duration validation, and translated diagnostics
 *
 * Each test drives the binary in a subprocess so it exercises exactly what
 * a user (or editor plugin) runs.
 */
final class CliTest extends TestCase
{
    private string $dataDir;
    private string $workDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/candy-codetime-cli-' . bin2hex(random_bytes(4));
        $this->dataDir = $base . '-data';
        $this->workDir = $base . '-work';
        mkdir($this->dataDir, 0755, true);
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dataDir, $this->workDir] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
                foreach (glob($dir . '/.*') ?: [] as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
                rmdir($dir);
            }
        }
    }

    /**
     * Run `bin/candy-codetime` with the given args.
     *
     * @param list<string>          $args
     * @param array<string, string> $env  extra env vars (merged over the parent env)
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runCli(array $args, ?string $cwd = null, array $env = [], ?string $stdin = null): array
    {
        $bin = dirname(__DIR__) . '/bin/candy-codetime';
        $cmd = array_merge([PHP_BINARY, $bin], $args);

        $parentEnv = getenv();
        $fullEnv = array_merge(is_array($parentEnv) ? $parentEnv : [], [
            'CANDY_CODETIME_DIR' => $this->dataDir,
        ], $env);

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if ($stdin !== null) {
            $descriptors[0] = ['pipe', 'r'];
        }
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd ?? $this->workDir, $fullEnv);
        $this->assertIsResource($proc);
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'exit'   => $exit,
        ];
    }

    // --- SugarTrackIgnore wiring --------------------------------------------

    public function testPushSkipsFileMatchingSugarTrackIgnore(): void
    {
        file_put_contents($this->workDir . '/.sugartrackignore', "*.log\n");

        // Ignored file: should be skipped, not stored.
        $ignored = $this->runCli(['push', 'proj', 'php', 'debug.log']);
        $this->assertSame(0, $ignored['exit'], $ignored['stderr']);
        $this->assertStringContainsString('skipped', $ignored['stdout']);

        // Non-matching file: should be tracked normally.
        $tracked = $this->runCli(['push', 'proj', 'php', 'main.php']);
        $this->assertSame(0, $tracked['exit'], $tracked['stderr']);
        $this->assertStringContainsString('recorded', $tracked['stdout']);

        // Read the store back via export: only main.php should have landed.
        $export = $this->runCli(['export', 'json', '1']);
        $this->assertSame(0, $export['exit'], $export['stderr']);
        $beats = json_decode($export['stdout'], true);
        $this->assertIsArray($beats);
        $files = array_column($beats, 'file');
        $this->assertContains('main.php', $files);
        $this->assertNotContains('debug.log', $files, 'ignored file must not be tracked');
        $this->assertCount(1, $beats);
    }

    /**
     * Security review m4 (owner-lane fix): an ignore file checked into a repo
     * is untrusted input, and a wildcard-only pattern in it zeroes the whole
     * project's recorded time. Such a skip must shout on STDERR — naming the
     * file, its pattern count and the project — instead of hiding in the
     * success stream. The opt-out itself still works: exit 0, nothing stored.
     */
    public function testAWholesaleIgnoreFileIsReportedOnStderr(): void
    {
        file_put_contents($this->workDir . '/.sugartrackignore', chr(42) . "\n");

        $res = $this->runCli(['push', 'victim', 'php', 'main.php']);
        $this->assertSame(0, $res['exit']);
        $this->assertStringContainsString('.sugartrackignore', $res['stderr'], 'the notice names the file');
        $this->assertStringContainsString($this->workDir, $res['stderr'], 'with its full path');
        $this->assertStringContainsString('victim', $res['stderr'], 'and the affected project');
        $this->assertStringContainsString('records nothing', $res['stderr'], 'the notice is loud, not a routine skip');
        $this->assertStringContainsString('(1 patterns)', $res['stderr'], 'and discloses the pattern count');
        $this->assertSame('', $res['stdout'], 'the wholesale skip leaves nothing in the success stream');

        // The feature is honored, not refused: the heartbeat never landed.
        $export = $this->runCli(['export', 'json', '1']);
        $this->assertSame('[]', trim($export['stdout']));
    }

    /**
     * Behavior-preserving half: an honest opt-out pattern keeps the
     * historical stdout notice and a silent stderr.
     */
    public function testAnHonestIgnoreFileSkipsQuietlyOnStdout(): void
    {
        file_put_contents($this->workDir . '/.sugartrackignore', chr(42) . ".log\n");

        $res = $this->runCli(['push', 'proj', 'php', 'debug.log']);
        $this->assertSame(0, $res['exit']);
        $this->assertStringContainsString('skipped', $res['stdout']);
        $this->assertSame('', $res['stderr'], 'honest patterns never trip the wholesale channel');
    }

    /**
     * Step 8 (docs-fix m10/M-5): an oversized `.sugartrackignore` is refused
     * by load() with a RuntimeException — unpatched, that fatal (exit 255)
     * killed the whole push. The storage-failure convention must hold: a
     * one-line stderr diagnosis and exit 1, never a bare PHP fatal.
     */
    public function testAnOversizedIgnoreFileRefusesWithOneLineAndExitOne(): void
    {
        file_put_contents(
            $this->workDir . '/.sugartrackignore',
            str_repeat('#', SugarTrackIgnore::MAX_IGNORE_BYTES + 1),
        );

        $res = $this->runCli(['push', 'proj', 'php', 'main.php']);
        $this->assertSame(1, $res['exit'], 'refused at the load door, not a 255 fatal');
        $this->assertStringContainsString('over the', $res['stderr'], 'the refusal names the byte limit breach');
        $this->assertStringContainsString('.sugartrackignore', $res['stderr'], 'and the offending file');
        $this->assertCount(1, array_filter(explode("\n", $res['stderr'])), 'a one-line diagnosis');
        $this->assertStringNotContainsString('Fatal error', $res['stderr']);
        $this->assertStringNotContainsString('recorded', $res['stdout']);
    }

    // --- SqliteBackend / --backend=sqlite wiring ----------------------------

    public function testSqliteBackendPersistsAndReads(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }

        $push = $this->runCli(['--backend=sqlite', 'push', 'proj', 'php', 'a.php', '120']);
        $this->assertSame(0, $push['exit'], $push['stderr']);
        $this->assertStringContainsString('recorded', $push['stdout']);

        // The SQLite db file is created inside the data dir.
        $this->assertFileExists($this->dataDir . '/candy-codetime.db');

        // Reading back through the sqlite backend returns the heartbeat.
        $export = $this->runCli(['--backend=sqlite', 'export', 'json', '1']);
        $this->assertSame(0, $export['exit'], $export['stderr']);
        $beats = json_decode($export['stdout'], true);
        $this->assertIsArray($beats);
        $this->assertCount(1, $beats);
        $this->assertSame('a.php', $beats[0]['file']);
        $this->assertSame('proj', $beats[0]['project']);
        $this->assertSame(120, $beats[0]['duration']);

        // The file backend must NOT see the sqlite-only heartbeat — proves the
        // flag actually routed the write to sqlite rather than the JSONL store.
        $fileExport = $this->runCli(['export', 'json', '1']);
        $this->assertSame('[]', trim($fileExport['stdout']));
    }

    public function testInvalidBackendIsRejected(): void
    {
        $res = $this->runCli(['--backend=bogus', 'push', 'proj', 'php', 'a.php']);
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('invalid backend', $res['stderr']);
    }

    // --- Milestone subcommand -----------------------------------------------

    public function testMilestoneAddAndList(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }

        $add = $this->runCli(['milestone', 'add', 'v1.0', 'First stable release']);
        $this->assertSame(0, $add['exit'], $add['stderr']);
        $this->assertStringContainsString('milestone added: v1.0', $add['stdout']);

        $list = $this->runCli(['milestone', 'list']);
        $this->assertSame(0, $list['exit'], $list['stderr']);
        $this->assertStringContainsString('Milestones:', $list['stdout']);
        $this->assertStringContainsString('v1.0', $list['stdout']);
        $this->assertStringContainsString('First stable release', $list['stdout']);
    }

    public function testMilestoneListEmpty(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }

        $list = $this->runCli(['milestone', 'list']);
        $this->assertSame(0, $list['exit'], $list['stderr']);
        $this->assertStringContainsString('No milestones recorded', $list['stdout']);
    }

    public function testMilestoneAddMissingNameErrors(): void
    {
        $res = $this->runCli(['milestone', 'add']);
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('missing required argument', $res['stderr']);
    }
    // --- Version surface ------------------------------------------------------

    public function testVersionFlagPrintsTheSingleSourceConstant(): void
    {
        $res = $this->runCli(['--version']);
        $this->assertSame(0, $res['exit'], $res['stderr']);
        $this->assertSame('candy-codetime ' . Version::CURRENT . "\n", $res['stdout']);

        $short = $this->runCli(['-v']);
        $this->assertSame(0, $short['exit'], $short['stderr']);
        $this->assertStringContainsString(Version::CURRENT, $short['stdout']);
    }

    // --- Duration boundary parse ----------------------------------------------

    public function testPushRejectsNonNumericDurationInsteadOfRecordingZero(): void
    {
        $res = $this->runCli(['push', 'proj', 'php', 'a.php', 'abc']);
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('duration', $res['stderr']);
        $this->assertStringNotContainsString('recorded', $res['stdout']);

        // The numeric path still succeeds.
        $ok = $this->runCli(['push', 'proj', 'php', 'a.php', '120']);
        $this->assertSame(0, $ok['exit'], $ok['stderr']);
    }

    // --- CT-S02 capture seams: --time backfill + stdin batch -------------------

    public function testPushWithBackfillTimeLandsInTheNamedDayFileAndIsExportable(): void
    {
        $ts = 1714536000; // a fixed point in the past: the day it belongs to
        $res = $this->runCli(['push', '--time=' . $ts, 'proj', 'php', '/x/a.php', '90']);
        $this->assertSame(0, $res['exit'], $res['stderr']);
        $this->assertStringContainsString('recorded 90s of proj', $res['stdout']);

        $day = $this->dataDir . '/' . date('Y-m-d', $ts) . '.jsonl';
        $this->assertFileExists($day, 'a backfilled push must land in its OWN day file, not today\'s');
        $row = json_decode(trim((string) file_get_contents($day)), true);
        $this->assertSame($ts, $row['time'], 'the stored row keeps the supplied timestamp');

        $days = (string) (intdiv(time() - $ts, 86400) + 2);
        $exp = $this->runCli(['export', 'json', $days]);
        $this->assertSame(0, $exp['exit'], $exp['stderr']);
        $beats = json_decode($exp['stdout'], true);
        $this->assertContains($ts, array_column((array) $beats, 'time'), 'the backfilled beat is reachable through export');
    }

    public function testPushTimeDoorRefusesNonNumericWithoutCoercion(): void
    {
        $res = $this->runCli(['push', '--time=banana', 'proj', 'php', '/x/a.php']);
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('whole number of unix seconds', $res['stderr']);
        $this->assertStringNotContainsString('recorded', $res['stdout']);
    }

    public function testStdinBatchIngestsRowsWithTheirOwnTimestampsAndFailsLoudlyOnGarbage(): void
    {
        $t1 = 1714536000;
        $t2 = 1714622400;
        $batch = json_encode(['time' => $t1, 'project' => 'alpha', 'language' => 'php', 'file' => '/x/one.php', 'duration' => 60]) . "\n"
            . "not-json\n"
            . json_encode(['time' => $t2, 'project' => 'beta', 'language' => 'go', 'file' => '/x/two.go', 'duration' => 30]) . "\n";

        $res = $this->runCli(['push', '--'], null, [], $batch);
        $this->assertSame(1, $res['exit'], 'the malformed line must fail the batch loudly');
        $this->assertStringContainsString('stdin line 2', $res['stderr'], 'the bad line is named with its number');
        $this->assertStringContainsString('recorded 2 heartbeats', $res['stdout'], 'the good rows still landed');
        $this->assertFileExists($this->dataDir . '/' . date('Y-m-d', $t1) . '.jsonl');
        $this->assertFileExists($this->dataDir . '/' . date('Y-m-d', $t2) . '.jsonl');
    }

    public function testStdinSingleRowTakesTheSingularSummary(): void
    {
        $res = $this->runCli(['push', '--'], null, [], json_encode(['time' => 1714536000, 'project' => 'solo', 'language' => 'php', 'file' => '/x/s.php', 'duration' => 15]) . "\n");
        $this->assertSame(0, $res['exit'], $res['stdout'] . $res['stderr']);
        $this->assertStringContainsString('recorded 1 heartbeat', $res['stdout']);
        $this->assertStringNotContainsString('1 heartbeats', $res['stdout']);
    }

    public function testStdinBatchStillHonoursTheIgnoreDoorPerRow(): void
    {
        file_put_contents($this->workDir . '/.sugartrackignore', "*.log\n");
        $res = $this->runCli(['push', '--'], null, [],
            json_encode(['time' => 1714536000, 'project' => 'p', 'language' => 'php', 'file' => 'secret.log', 'duration' => 60]) . "\n"
            . json_encode(['time' => 1714536000, 'project' => 'p', 'language' => 'php', 'file' => 'ok.php', 'duration' => 60]) . "\n");
        $this->assertSame(0, $res['exit'], $res['stdout'] . $res['stderr']);
        $this->assertStringContainsString('recorded 1 heartbeat', $res['stdout']);

        $contents = (string) file_get_contents($this->dataDir . '/' . date('Y-m-d', 1714536000) . '.jsonl');
        $this->assertStringNotContainsString('secret.log', $contents, 'the ignore door guards the batch too');
        $this->assertStringContainsString('ok.php', $contents);
    }

    // --- Translated diagnostics -----------------------------------------------

    public function testUnknownCommandGoesThroughLang(): void
    {
        $res = $this->runCli(['frobnicate']);
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('unknown command: frobnicate', $res['stderr']);
    }

    // --- Fail-loud storage ------------------------------------------------------

    public function testPushReportsStorageFailureAndExitsNonZero(): void
    {
        // A plain FILE where the data directory wants to be: creation must fail,
        // and push must NOT print the success line over a lost row.
        $blocker = $this->workDir . '/blocked-dir';
        file_put_contents($blocker, 'not a directory');

        $res = $this->runCli(
            ['push', 'proj', 'php', 'a.php'],
            null,
            ['CANDY_CODETIME_DIR' => $blocker],
        );
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('cannot create data directory', $res['stderr']);
        $this->assertStringNotContainsString('recorded', $res['stdout']);
    }

    // --- MAJOR-3 routing pin -----------------------------------------------------

    public function testDashboardLaunchIsRoutedThroughTheSelectedBackend(): void
    {
        // The TUI used to open the JSONL store unconditionally, so `--backend=sqlite`
        // users saw an empty dashboard. Pin the routing in the shipped entry point.
        $bin = (string) file_get_contents(dirname(__DIR__) . '/bin/candy-codetime');
        $this->assertStringContainsString(
            '$reader = $backend === \'sqlite\' ? $sqlite() : $store;',
            $bin,
            'dashboard must pick its reader from the parsed --backend option',
        );
        $this->assertStringContainsString('Dashboard::start($reader', $bin);
    }

    // --- Locale detection (dormant i18n fix) ---------------------------------

    public function testTheEnvironmentLocaleDrivesCliMessages(): void
    {
        $german = $this->runCli(['--help'], null, ['LC_ALL' => 'de_DE.UTF-8']);
        $this->assertSame(0, $german['exit'], $german['stderr']);
        $this->assertStringContainsString('Verwendung:', $german['stdout'],
            'a German environment must produce the German usage text');

        $plain = $this->runCli(['--help']);
        $this->assertStringContainsString('Usage:', $plain['stdout']);
    }

    // --- Non-TTY dashboard refusal -------------------------------------------

    public function testDashboardRefusesAWorldWithoutATerminal(): void
    {
        // Piped stdin/stdout used to mean an alt-screen dump and a process
        // waiting on keypresses that never arrive (cron hung forever).
        $res = $this->runCli(['dashboard']);
        $this->assertSame(1, $res['exit']);
        $this->assertStringContainsString('interactive terminal', $res['stderr']);
        $this->assertStringContainsString('export', $res['stderr'],
            'the refusal must point at the headless output door');
        $this->assertSame('', $res['stdout'], 'no escape sequences may leak to stdout');
    }

    // --- --backend space form -------------------------------------------------

    public function testBackendOptionAcceptsTheSpaceForm(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }

        $push = $this->runCli(['--backend', 'sqlite', 'push', 'spaced', 'php', 'a.php', '30']);
        $this->assertSame(0, $push['exit'], $push['stderr']);

        $back = $this->runCli(['--backend', 'sqlite', 'export', 'json', '1']);
        $this->assertSame(0, $back['exit'], $back['stderr']);
        $rows = json_decode($back['stdout'], true);
        $this->assertIsArray($rows);
        $this->assertContains('spaced', array_column($rows, 'project'),
            'the space form must select the same backend as --backend=sqlite');

        // The file backend must NOT have received the row: it never lands in JSONL.
        $file = $this->runCli(['export', 'json', '1']);
        $this->assertSame(0, $file['exit'], $file['stderr']);
        $this->assertSame([], json_decode($file['stdout'], true));

        $dangling = $this->runCli(['push', 'proj', 'php', 'b.php', '--backend']);
        $this->assertSame(1, $dangling['exit']);
        $this->assertStringContainsString('invalid backend', $dangling['stderr']);
    }

    // --- Gaps across midnight --------------------------------------------------

    public function testAGapEndingTheNextDayCarriesItsDate(): void
    {
        $beats = [
            strtotime('-3 days 10:00'),
            strtotime('-3 days 14:00'),
            strtotime('-2 days 22:00'),
            strtotime('-1 day 02:00'),
        ];
        $byDay = [];
        foreach ($beats as $ts) {
            $byDay[date('Y-m-d', $ts)][] = json_encode([
                'time' => $ts, 'project' => 'p', 'language' => 'php',
                'file' => 'a.php', 'duration' => 60, 'tags' => [],
            ]);
        }
        foreach ($byDay as $day => $rows) {
            file_put_contents($this->dataDir . '/' . $day . '.jsonl', implode("\n", $rows) . "\n");
        }

        $res = $this->runCli(['gaps', '5']);
        $this->assertSame(0, $res['exit'], $res['stderr']);

        // Same-day gap keeps the short clock form…
        $this->assertMatchesRegularExpression('/\x{2013} 14:00  \(\d+s untracked\)/u', $res['stdout']);
        // …a next-day gap must print its own month+day, or the range reads backwards.
        $this->assertStringContainsString(date('M j', strtotime('-1 day 02:00')) . ' 02:00', $res['stdout']);
    }

    public function testStoredControlBytesNeverPaintTheTerminal(): void
    {
        // A doctored store (or argv) must not be able to emit escape
        // sequences through the CLI's own lines. The record at rest keeps
        // its bytes — only the way out is swept.
        $dirty = "evil" . chr(27) . "[31mred" . chr(7);

        $push = $this->runCli(['push', $dirty, 'php', 'a.php', '5']);
        $this->assertSame(0, $push['exit'], $push['stderr']);
        $this->assertStringNotContainsString(chr(27), $push['stdout'], 'push echo must carry no ESC byte');
        $this->assertStringNotContainsString(chr(7), $push['stdout'], 'push echo must carry no BEL byte');

        $json = $this->runCli(['export', 'json', '1']);
        $this->assertSame(0, $json['exit'], $json['stderr']);
        $this->assertStringNotContainsString(chr(27), $json['stdout'], 'json export must not leak a raw ESC byte');
        $rows = json_decode($json['stdout'], true);
        $this->assertSame($dirty, $rows[0]['project'], 'the store keeps the exact bytes for data fidelity');

        $csv = $this->runCli(['export', 'csv', '1']);
        $this->assertSame(0, $csv['exit'], $csv['stderr']);
        $this->assertStringNotContainsString(chr(27), $csv['stdout'], 'csv export must not leak a raw ESC byte');
    }

    public function testMilestoneNamesAreSweptOnTheWayOut(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension unavailable');
        }
        $dirty = "v1" . chr(27) . "]0;title" . chr(7);
        $add = $this->runCli(['--backend', 'sqlite', 'milestone', 'add', $dirty, 'plain desc']);
        $this->assertSame(0, $add['exit'], $add['stderr']);
        $this->assertStringNotContainsString(chr(27), $add['stdout']);

        $list = $this->runCli(['--backend', 'sqlite', 'milestone', 'list']);
        $this->assertSame(0, $list['exit'], $list['stderr']);
        $this->assertStringNotContainsString(chr(27), $list['stdout'], 'stored milestone names must not carry escapes');
    }

    public function testAnUnknownCommandCannotPaintTheTerminal(): void
    {
        // The command token is argv — attacker-influenced whenever a wrapper
        // forwards a repo name. It reaches stderr on the unknown-command
        // route, so it must ride the same sweep as every other echo.
        $dirty = 'w' . chr(27) . ']0;PWNED' . chr(7) . 'ake';
        $res = $this->runCli([$dirty]);
        $this->assertSame(1, $res['exit']);
        $this->assertStringNotContainsString(chr(27), $res['stderr'], 'unknown-command echo must carry no ESC byte');
        $this->assertStringContainsString('wake', $res['stderr'], 'the swept name must stay legible');
    }

    /**
     * CT-S49 (finding candy-codetime-061): the two-key plural. The old single
     * `backup_done` key printed "archived 1 files" on the common first run;
     * the selector now walks `cli.backup_done_one` at exactly one file.
     */
    public function testBackupArchivalCountIsGrammaticalForOneAndMany(): void
    {
        $row = json_encode(['time' => 1714536000, 'project' => 'p', 'language' => 'php', 'file' => '/x/a.php', 'duration' => 60, 'tags' => []]) . "\n";
        $old = time() - 90 * 86400;

        $single = $this->dataDir . '/plural-one';
        mkdir($single, 0o700);
        try {
            file_put_contents($single . '/2024-05-01.jsonl', $row);
            touch($single . '/2024-05-01.jsonl', $old);
            $one = $this->runCli(['backup'], null, ['CANDY_CODETIME_DIR' => $single]);
            $this->assertSame(0, $one['exit'], $one['stdout'] . $one['stderr']);
            $this->assertStringContainsString('archived 1 file', $one['stdout']);
            $this->assertStringNotContainsString('1 files', $one['stdout'], 'the one-file run must take the _one key');
        } finally {
            // The archive dir is a sibling of the data dir; the glob-based
            // tearDown only empties files, so sweep both shapes here.
            foreach ([$single, $single . '-backups'] as $d) {
                foreach (glob($d . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }

        $many = $this->dataDir . '/plural-many';
        mkdir($many, 0o700);
        try {
            file_put_contents($many . '/2024-05-01.jsonl', $row);
            file_put_contents($many . '/2024-05-02.jsonl', $row);
            touch($many . '/2024-05-01.jsonl', $old);
            touch($many . '/2024-05-02.jsonl', $old);
            $res = $this->runCli(['backup'], null, ['CANDY_CODETIME_DIR' => $many]);
            $this->assertSame(0, $res['exit'], $res['stdout'] . $res['stderr']);
            $this->assertStringContainsString('archived 2 files', $res['stdout']);
        } finally {
            foreach ([$many, $many . '-backups'] as $d) {
                foreach (glob($d . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }
    }

    public function testBackupFailureCannotPaintTheTerminal(): void
    {
        // The backup arm prints the database path and rotate() failures
        // verbatim; a data directory planted with escape bytes in its name
        // (attacker-controlled CANDY_CODETIME_DIR) must still not emit them.
        $dir = $this->dataDir . '/w' . chr(27) . 'd';
        mkdir($dir, 0o700);
        file_put_contents(
            $dir . '/2024-05-01.jsonl',
            json_encode(['time' => 1714536000, 'project' => 'p', 'language' => 'php', 'file' => '/x/a.php', 'duration' => 60, 'tags' => []]) . "\n",
        );
        // Two ESC carriers at once: the sqlite notice prints the database
        // path, and a plain file standing where <dir>-backups must be makes
        // rotate() throw with that same poisoned path in its message.
        touch($dir . '/2024-05-01.jsonl', time() - 90 * 86400); // age it past the 30-day window
        file_put_contents($dir . '/candy-codetime.db', 'not a database');
        file_put_contents($dir . '-backups', 'blocking file');

        try {
            $res = $this->runCli(['backup'], null, ['CANDY_CODETIME_DIR' => $dir]);
        } finally {
            // The escape in the directory name would trip a glob-based
            // tearDown; sweep the planted paths explicitly.
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @unlink($dir . '-backups');
            @rmdir($dir);
        }
        $this->assertSame(1, $res['exit'], $res['stdout'] . $res['stderr']);
        $this->assertStringContainsString('backup', $res['stderr']);
        $this->assertStringNotContainsString(chr(27), $res['stderr'], 'backup failure lines must not carry raw ESC bytes');
    }

    public function testANonNumericDaysCountIsRefusedNotCoerced(): void
    {
        // (int) "banana" used to be 0, which the clamp rounded up to one
        // silent day; the push duration door's law must cover [days] too.
        $export = $this->runCli(['export', 'json', 'banana']);
        $this->assertSame(1, $export['exit'], $export['stdout'] . $export['stderr']);
        $this->assertStringContainsString('whole number', $export['stderr']);
        $this->assertStringContainsString('banana', $export['stderr']);
        $this->assertSame('', $export['stdout'], 'a refused count must export nothing');

        $gaps = $this->runCli(['gaps', 'banana']);
        $this->assertSame(1, $gaps['exit'], $gaps['stdout'] . $gaps['stderr']);
        $this->assertStringContainsString('whole number', $gaps['stderr']);
        $this->assertStringContainsString('banana', $gaps['stderr']);
        $this->assertSame('', $gaps['stdout'], 'a refused count must print no report');
    }

    /** Run examples/plugin-heartbeat.example.sh with extra env over the default data dir. */
    private function runHook(string $hookBin, string $file, string $project, string $language): array
    {
        $script = dirname(__DIR__) . '/examples/plugin-heartbeat.example.sh';
        $parentEnv = getenv();
        $fullEnv = array_merge(is_array($parentEnv) ? $parentEnv : [], [
            'CANDY_CODETIME_DIR' => $this->dataDir,
            'CODETIME_HOOK_BIN'  => $hookBin,
        ]);
        $proc = proc_open(['sh', $script, $file, $project, $language], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->workDir, $fullEnv);
        $this->assertIsResource($proc);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($proc)];
    }

    public function testExampleHookScriptPushesThroughTheCliDoor(): void
    {
        $shim = $this->workDir . '/ct-shim';
        file_put_contents(
            $shim,
            "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/candy-codetime') . " \"$@\"\n"
        );
        chmod($shim, 0755);
        file_put_contents($this->workDir . '/.sugartrackignore', "*.log\n");
        $res = $this->runHook($shim, '/home/dev/myproj/src/Main.php', 'myproj', 'php');
        $this->assertSame(0, $res['exit'], $res['stdout'] . $res['stderr']);
        $skipped = $this->runHook($shim, '/home/dev/myproj/scratch.log', 'myproj', 'php');
        $this->assertSame(0, $skipped['exit'], $skipped['stdout'] . $skipped['stderr']);
        $export = $this->runCli(['export', 'json', '2']);
        $this->assertSame(0, $export['exit'], $export['stderr']);
        $this->assertStringContainsString('"project": "myproj"', $export['stdout'], 'the CLI door must land a readable beat');
        $this->assertStringContainsString('/home/dev/myproj/src/Main.php', $export['stdout']);
        $this->assertStringNotContainsString('scratch.log', $export['stdout'], 'the push arm must honour the ignore door — a raw echo would not');
        unlink($shim);
    }

    public function testExampleHookScriptFallsBackToRawAppend(): void
    {
        $res = $this->runHook('candy-codetime-not-on-this-path', '/home/dev/myproj/src/Main.php', 'myproj', 'php');
        $this->assertSame(0, $res['exit'], $res['stdout'] . $res['stderr']);
        $this->assertFileExists($this->dataDir . '/' . date('Y-m-d') . '.jsonl', 'the raw arm files under the local calendar date');
        $export = $this->runCli(['export', 'json', '2']);
        $this->assertSame(0, $export['exit'], $export['stderr']);
        $this->assertStringContainsString('"project": "myproj"', $export['stdout'], 'a raw-echoed line must be readable by the store');
        $this->assertStringContainsString('"duration": 60', $export['stdout']);
    }

    public function testSqlitePushRefusesInvalidUtf8LikeTheFileDoor(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }
        $res = $this->runCli(['--backend=sqlite', 'push', "\xff\xfebad", 'php', 'src/x.php', '60']);
        $this->assertSame(1, $res['exit'], 'the sqlite door must refuse what the file door refuses: ' . $res['stdout'] . $res['stderr']);
        $this->assertStringContainsString('storage error', $res['stderr']);
    }

    public function testExportOfPoisonedLegacyDatabaseIsALineErrorNeverAFatal(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }
        // Poison the store the way the pre-fix lib itself did: raw bytes past
        // the bind legs, bypassing every validation added since.
        $db = new \SQLite3($this->dataDir . '/candy-codetime.db');
        $db->exec('CREATE TABLE heartbeats (id INTEGER PRIMARY KEY, time INTEGER, project TEXT, language TEXT, file TEXT, duration INTEGER, tags TEXT)');
        $stmt = $db->prepare('INSERT INTO heartbeats (time, project, language, file, duration, tags) VALUES (:t, :p, :l, :f, :d, :g)');
        $stmt->bindValue(':t', time() - 3600, SQLITE3_INTEGER);
        $stmt->bindValue(':p', "\xff\xfebad");
        $stmt->bindValue(':l', 'php');
        $stmt->bindValue(':f', 'src/x.php');
        $stmt->bindValue(':d', 60, SQLITE3_INTEGER);
        $stmt->bindValue(':g', '[]');
        $stmt->execute();
        $db->close();

        $res = $this->runCli(['--backend=sqlite', 'export', 'json', '30']);
        $this->assertNotSame(255, $res['exit'], 'a poisoned row must never surface as a bare PHP fatal');
        $this->assertSame(1, $res['exit'], $res['stdout'] . $res['stderr']);
        $this->assertStringContainsString('storage error', $res['stderr']);
        $this->assertSame(1, substr_count($res['stderr'], "\n"), 'exactly one error line: ' . $res['stderr']);
        $this->assertSame('', $res['stdout'], 'a failed export writes no partial payload');
    }
}
