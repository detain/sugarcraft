<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Server\DiscoveryFile;
use SugarCraft\Crush\Server\StateDir;
use SugarCraft\Crush\Support\Daemonize;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * `sugarcrush serve --detach` and `serve status|stop|logs|url|token`, end to
 * end through the real binary (Appendix O §4.6; roadmap O-4a): the background
 * server reports a URL that answers, publishes a record `status` verifies by
 * pid and start time, hands out fresh sign-in codes over its control socket,
 * refuses a second server on the same state directory, and drains on `stop`.
 *
 * Every server a test starts is killed in tearDown if the test did not stop it,
 * so a red never leaves a daemon behind.
 */
final class ServeStatusStopTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    /** Bound on any one `bin/sugarcrush` run. */
    private const RUN_SECONDS = 30.0;

    private string $base = '';

    private string $stateDir = '';

    /** @var list<resource> foreground servers still running */
    private array $foreground = [];

    protected function setUp(): void
    {
        if (Daemonize::unavailableReason() !== null || !\function_exists('posix_kill') || !\is_dir('/proc/self')) {
            self::markTestSkipped('needs pcntl, posix, ffi and procfs: ' . (Daemonize::unavailableReason() ?? 'no /proc'));
        }

        $this->base = \sys_get_temp_dir() . '/serve-' . \bin2hex(\random_bytes(4));
        \mkdir($this->base . '/home', 0o700, true);
        $this->stateDir = $this->base . '/state';
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        $state = @\is_dir($this->stateDir) ? StateDir::existing($this->stateDir) : null;
        $record = $state === null ? null : DiscoveryFile::read($state);
        if ($record !== null && $record->isLive()) {
            @\posix_kill($record->pid, 9);
        }
        foreach ($this->foreground as $process) {
            @\proc_terminate($process, 9);
            @\proc_close($process);
        }
        $this->removeTree($this->base);
    }

    public function testDetachStatusUrlLogsAndStopRoundTrip(): void
    {
        $before = $this->bin(['serve', 'status']);
        self::assertSame(1, $before['status'], $before['stderr']);
        self::assertSame("not running\n", $before['stdout']);
        self::assertDirectoryDoesNotExist($this->stateDir, 'asking must not create the state directory');

        $started = $this->bin(['--output-format', 'json', 'serve', '--detach', '--port', '0', '--no-web']);
        self::assertSame(0, $started['status'], $started['stderr']);
        $report = \json_decode($started['stdout'], true)['result'] ?? null;
        self::assertIsArray($report, $started['stdout']);
        self::assertTrue($report['detached']);
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#', $report['url']);
        self::assertMatchesRegularExpression('#^' . \preg_quote($report['url'], '#') . '/\#code=[0-9a-f]+$#', $report['loginUrl']);
        self::assertSame($this->stateDir . '/server.log', $report['log']);
        self::assertNotSame(0, $report['pid']);

        $record = DiscoveryFile::read(StateDir::existing($this->stateDir));
        self::assertNotNull($record);
        self::assertSame($report['pid'], $record->pid);
        self::assertSame($report['url'], $record->url);
        self::assertTrue($record->isLive());
        self::assertSame(0o600, \fileperms($this->stateDir . '/server.json') & 0o777);
        self::assertSame(0o700, \fileperms($this->stateDir) & 0o777);
        self::assertStringNotContainsString((string) \file_get_contents($this->stateDir . '/token'), (string) \file_get_contents($this->stateDir . '/server.json'));

        $status = $this->bin(['--output-format', 'json', 'serve', 'status']);
        self::assertSame(0, $status['status'], $status['stderr']);
        $result = \json_decode($status['stdout'], true)['result'];
        self::assertTrue($result['running']);
        self::assertSame($report['pid'], $result['pid']);
        self::assertSame('ok', $result['health'], 'GET /api/health did not answer');
        self::assertTrue($result['detached']);
        self::assertFalse($result['staleRecord']);

        $again = $this->bin(['serve', '--detach', '--port', '0', '--no-web']);
        self::assertSame(1, $again['status']);
        self::assertStringContainsString('a server is already running from ' . $this->stateDir . ' (pid ' . $report['pid'], $again['stderr']);

        $url = $this->bin(['serve', 'url']);
        self::assertSame(0, $url['status'], $url['stderr']);
        self::assertMatchesRegularExpression('#^' . \preg_quote($report['url'], '#') . '/\#code=[0-9a-f]+\n$#', $url['stdout']);
        self::assertNotSame($report['loginUrl'] . "\n", $url['stdout'], 'every serve url mints a fresh code');

        $logs = $this->bin(['serve', 'logs']);
        self::assertSame(0, $logs['status'], $logs['stderr']);
        self::assertStringContainsString('listening on ' . $report['url'], $logs['stdout']);
        self::assertStringNotContainsString('#code=', $logs['stdout'], 'the sign-in code never reaches the log');

        $stop = $this->bin(['serve', 'stop']);
        self::assertSame(0, $stop['status'], $stop['stderr']);
        self::assertSame(\sprintf("stopped the server (pid %d)\n", $report['pid']), $stop['stdout']);
        self::assertFalse($record->isLive());
        self::assertFileDoesNotExist($this->stateDir . '/server.json', 'a drained server removes its record');
        self::assertFileDoesNotExist($this->stateDir . '/control.sock');
        self::assertStringContainsString('stopping (SIGTERM)', (string) \file_get_contents($this->stateDir . '/server.log'));

        $after = $this->bin(['serve', 'status']);
        self::assertSame(1, $after['status']);
        $none = $this->bin(['serve', 'url']);
        self::assertSame(1, $none['status']);
        self::assertStringContainsString('no server is running', $none['stderr']);
    }

    public function testAForegroundServerIsManagedThroughTheSameState(): void
    {
        $process = $this->spawn(['serve', '--port', '0', '--no-web'], $pipes);
        $this->foreground[] = $process;

        $record = $this->waitForRecord();
        self::assertSame(\proc_get_status($process)['pid'], $record->pid);
        self::assertFalse($record->detached);
        self::assertNull($record->log);

        $stop = $this->bin(['--output-format', 'json', 'serve', 'stop']);
        self::assertSame(0, $stop['status'], $stop['stderr']);
        self::assertSame(['stopped' => true, 'pid' => $record->pid, 'signal' => 'TERM'], \json_decode($stop['stdout'], true)['result']);

        $exit = $this->waitForExit($process);
        self::assertSame(0, $exit, 'a drained foreground server exits 0');
        self::assertFileDoesNotExist($this->stateDir . '/server.json');
        $logs = $this->bin(['serve', 'logs']);
        self::assertSame(1, $logs['status'], 'a foreground server writes no server.log');
    }

    public function testStopForceAndAStaleRecord(): void
    {
        $started = $this->bin(['serve', '--detach', '--port', '0', '--no-web']);
        self::assertSame(0, $started['status'], $started['stderr']);
        $record = DiscoveryFile::read(StateDir::existing($this->stateDir));
        self::assertNotNull($record);

        $stop = $this->bin(['--output-format', 'json', 'serve', 'stop', '--force']);
        self::assertSame(0, $stop['status'], $stop['stderr']);
        self::assertSame('KILL', \json_decode($stop['stdout'], true)['result']['signal']);
        self::assertFalse($record->isLive());
        self::assertFileDoesNotExist($this->stateDir . '/server.json', 'stop removes the record a killed server could not');

        // A record whose process is gone: what a SIGKILL from elsewhere leaves.
        $stale = DiscoveryFile::fromArray(['pid' => $record->pid, 'procStartTime' => $record->procStartTime, 'url' => $record->url] + $record->toArray());
        $stale->write(StateDir::existing($this->stateDir));

        $status = $this->bin(['--output-format', 'json', 'serve', 'status']);
        self::assertSame(1, $status['status']);
        $result = \json_decode($status['stdout'], true)['result'];
        self::assertFalse($result['running']);
        self::assertTrue($result['staleRecord']);
        self::assertNull($result['pid'], 'a stale pid is never reported as the server');

        $again = $this->bin(['serve', 'stop']);
        self::assertSame(1, $again['status']);
        self::assertStringContainsString('no server is running', $again['stderr']);
        self::assertFileDoesNotExist($this->stateDir . '/server.json', 'stop clears a stale record');
    }

    public function testTheServerStopsWhenItsParentPidExits(): void
    {
        $parent = $this->forkTracked();
        if ($parent === 0) {
            \usleep(1_500_000);
            ForkedChild::exitNow(0);
        }

        $started = $this->bin(['serve', '--detach', '--port', '0', '--no-web', '--parent-pid', (string) $parent]);
        self::assertSame(0, $started['status'], $started['stderr']);
        $record = DiscoveryFile::read(StateDir::existing($this->stateDir));
        self::assertNotNull($record);

        $this->reapTrackedForkedChildren(5.0);
        $deadline = \microtime(true) + 10.0;
        while ($record->isLive() && \microtime(true) < $deadline) {
            \usleep(50_000);
        }

        self::assertFalse($record->isLive(), 'the server outlived the parent it was told to watch');
        self::assertFileDoesNotExist($this->stateDir . '/server.json');
        self::assertStringContainsString(\sprintf('parent process %d is gone', $parent), (string) \file_get_contents($this->stateDir . '/server.log'));
    }

    public function testTokenPrintsAndRotatesTheStoredTokenOnly(): void
    {
        $first = $this->bin(['serve', 'token']);
        self::assertSame(0, $first['status'], $first['stderr']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\n$/', $first['stdout']);
        self::assertSame(\trim($first['stdout']), \trim((string) \file_get_contents($this->stateDir . '/token')));
        self::assertSame($first['stdout'], $this->bin(['serve', 'token'])['stdout'], 'printing does not mint');

        $rotated = $this->bin(['--output-format', 'json', 'serve', 'token', '--rotate']);
        self::assertSame(0, $rotated['status'], $rotated['stderr']);
        $result = \json_decode($rotated['stdout'], true)['result'];
        self::assertTrue($result['rotated']);
        self::assertNotSame(\trim($first['stdout']), $result['token']);

        $override = $this->bin(['serve', 'token'], ['SUGARCRUSH_SERVER_TOKEN' => \str_repeat('a', 40)]);
        self::assertSame(2, $override['status']);
        self::assertStringNotContainsString(\str_repeat('a', 40), $override['stdout'] . $override['stderr']);
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function bin(array $args, array $env = []): array
    {
        $process = $this->spawn($args, $pipes, $env);
        $stdout = '';
        $stderr = '';
        $deadline = \microtime(true) + self::RUN_SECONDS;
        while (true) {
            $stdout .= (string) \stream_get_contents($pipes[1]);
            $stderr .= (string) \stream_get_contents($pipes[2]);
            $status = \proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (\microtime(true) > $deadline) {
                \proc_terminate($process, 9);
                \proc_close($process);
                self::fail('bin/sugarcrush ' . \implode(' ', $args) . ' did not exit; stderr: ' . $stderr);
            }
            \usleep(10_000);
        }
        // A detached run returns while the daemon lives on: its stdio is the
        // log, never these pipes, so they are at EOF now.
        $stdout .= (string) \stream_get_contents($pipes[1]);
        $stderr .= (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);

        return ['status' => (int) $status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param list<string>          $args
     * @param array<int, resource>  $pipes
     * @param array<string, string> $env
     *
     * @return resource
     */
    private function spawn(array $args, ?array &$pipes, array $env = [])
    {
        $root = \dirname(__DIR__, 2);
        $process = \proc_open(
            [\PHP_BINARY, $root . '/bin/sugarcrush', ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            [
                'PATH' => (string) (\getenv('PATH') ?: '/usr/bin:/bin'),
                'HOME' => $this->base . '/home',
                'TMPDIR' => \sys_get_temp_dir(),
                'SUGARCRUSH_SERVER_DIR' => $this->stateDir,
                'SUGARCRUSH_MCP_DISABLE' => '1',
            ] + $env,
        );
        self::assertIsResource($process, 'failed to spawn bin/sugarcrush');
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        return $process;
    }

    private function waitForRecord(): DiscoveryFile
    {
        $deadline = \microtime(true) + self::RUN_SECONDS;
        while (\microtime(true) < $deadline) {
            $state = @\is_dir($this->stateDir) ? StateDir::existing($this->stateDir) : null;
            $record = $state === null ? null : DiscoveryFile::read($state);
            if ($record !== null && $record->isLive()) {
                return $record;
            }
            \usleep(20_000);
        }
        self::fail('the server never published server.json');
    }

    /** @param resource $process */
    private function waitForExit($process): int
    {
        $deadline = \microtime(true) + self::RUN_SECONDS;
        while (\microtime(true) < $deadline) {
            $status = \proc_get_status($process);
            if (!$status['running']) {
                $this->foreground = \array_values(\array_filter($this->foreground, static fn ($p): bool => $p !== $process));
                \proc_close($process);

                return (int) $status['exitcode'];
            }
            \usleep(20_000);
        }
        self::fail('the foreground server did not exit');
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !\file_exists($path) && !\is_link($path)) {
            return;
        }
        if (\is_dir($path) && !\is_link($path)) {
            @\chmod($path, 0o700);
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            @\rmdir($path);

            return;
        }
        @\unlink($path);
    }
}
