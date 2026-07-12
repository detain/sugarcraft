<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the `bin/sugar-tick` CLI that prove the three
 * orphaned features are wired into the real command paths:
 *
 *   - `.sugartrackignore` is consulted by `push`
 *   - `--backend=sqlite` selects the SQLite backend for push/export
 *   - `milestone add|list` manages Milestone value objects
 *
 * Each test drives the binary in a subprocess so it exercises exactly what
 * a user (or editor plug-in) runs.
 */
final class CliTest extends TestCase
{
    private string $dataDir;
    private string $workDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/sugar-tick-cli-' . bin2hex(random_bytes(4));
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
     * Run `bin/sugar-tick` with the given args.
     *
     * @param list<string>          $args
     * @param array<string, string> $env  extra env vars (merged over the parent env)
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runCli(array $args, ?string $cwd = null, array $env = []): array
    {
        $bin = dirname(__DIR__) . '/bin/sugar-tick';
        $cmd = array_merge([PHP_BINARY, $bin], $args);

        $parentEnv = getenv();
        $fullEnv = array_merge(is_array($parentEnv) ? $parentEnv : [], [
            'SUGARTICK_DIR' => $this->dataDir,
        ], $env);

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd ?? $this->workDir, $fullEnv);
        $this->assertIsResource($proc);

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
        $this->assertStringContainsString('tick pushed', $tracked['stdout']);

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

    // --- SqliteBackend / --backend=sqlite wiring ----------------------------

    public function testSqliteBackendPersistsAndReads(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }

        $push = $this->runCli(['--backend=sqlite', 'push', 'proj', 'php', 'a.php', '120']);
        $this->assertSame(0, $push['exit'], $push['stderr']);
        $this->assertStringContainsString('tick pushed', $push['stdout']);

        // The SQLite db file is created inside the data dir.
        $this->assertFileExists($this->dataDir . '/sugar-tick.db');

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
}
