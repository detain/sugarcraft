<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Truthful-stub pins (docs-fix-1023 lane N4).
 *
 * Before this change `candy-git-server serve` printed a startup banner and
 * exited 0 while binding no socket and accepting no connection — a deploy
 * script could not tell scaffold from server. The command now refuses loudly:
 * non-zero exit, honest stderr, nothing on stdout. These bounded child
 * probes pin that contract (argv-array proc_open per house law, wrapped in
 * `timeout -s KILL` so a regression that hangs can only fail fast, never
 * hang the suite).
 */
final class BinServeRefusalTest extends TestCase
{
    private string $bin;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $bin = \realpath(__DIR__ . '/../bin/candy-git-server');
        $this->assertNotFalse($bin, 'app binary must exist next to tests/');
        $this->bin = $bin;
        $this->tmpDir = \sys_get_temp_dir() . '/bin-serve-refusal-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    /**
     * @param list<string> $args
     *
     * @return array{rc: int, out: string, err: string}
     */
    private function runBin(array $args): array
    {
        $proc = \proc_open(
            ['timeout', '-s', 'KILL', '15', \PHP_BINARY, $this->bin, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);
        $out = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $rc = \proc_close($proc);

        return ['rc' => $rc, 'out' => $out, 'err' => $err];
    }

    public function testServeRefusesWithNonZeroExitAndHonestStderr(): void
    {
        $r = $this->runBin(['serve']);

        $this->assertSame(1, $r['rc'], 'serve must exit non-zero, never the old banner-and-zero');
        $this->assertStringContainsString('not implemented', $r['err']);
        $this->assertStringContainsString('scaffold', $r['err']);
        $this->assertSame('', $r['out'], 'no banner, no config summary, no repo listing on stdout');
    }

    public function testServeDaemonRefusesWithoutForkingOrWritingPidFile(): void
    {
        $pidFile = $this->tmpDir . '/should-never-exist.pid';
        $start = \microtime(true);
        $r = $this->runBin(['serve', '--daemon', '--pid-file', $pidFile]);

        $this->assertSame(1, $r['rc'], 'daemon mode is refused the same way — nothing forks');
        $this->assertStringContainsString('scaffold', $r['err']);
        $this->assertFileDoesNotExist($pidFile);
        // A real daemon launch returns only after forking; refusal is
        // immediate. Generous ceiling: this proves "did not daemonize",
        // not machine speed.
        $this->assertLessThan(5.0, \microtime(true) - $start);
    }

    public function testServeRefusesBeforeTouchingAnyConfigFile(): void
    {
        // A missing --config path used to be loaded before anything else;
        // the refusal must not degrade into a config error or a different
        // exit shape depending on flags.
        $r = $this->runBin(['serve', '--config', $this->tmpDir . '/definitely-not-here.yaml']);

        $this->assertSame(1, $r['rc']);
        $this->assertStringContainsString('not implemented', $r['err']);
        $this->assertStringContainsString('scaffold', $r['err']);
    }

    public function testRepoCreateRoundtripIsDiscoveryVisibleAndPromisesNoCloneUrl(): void
    {
        $r = $this->runBin(['repo', 'create', 'probe-repo', $this->tmpDir]);

        $this->assertSame(0, $r['rc'], 'repo create still works: ' . $r['err']);
        // M1 cure: the created repo must satisfy the very gate repo list/info
        // use (is_dir <path>/.git) — it used to create the bare AT <path>.
        $this->assertDirectoryExists($this->tmpDir . '/repositories/probe-repo/.git');
        // The live lie is gone: no fabricated clone URL, honest note instead.
        $this->assertStringNotContainsString('Clone URL', $r['out']);
        $this->assertStringContainsString('scaffold', $r['out']);

        $info = $this->runBin(['repo', 'info', 'probe-repo', $this->tmpDir]);
        $this->assertSame(0, $info['rc'], 'repo info must find a freshly created repo: ' . $info['err']);
        $this->assertStringContainsString('probe-repo', $info['out']);

        $list = $this->runBin(['repo', 'list', $this->tmpDir]);
        $this->assertSame(0, $list['rc']);
        $this->assertStringContainsString('probe-repo', $list['out']);
    }
}
