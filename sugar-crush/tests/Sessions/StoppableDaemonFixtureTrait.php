<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Real `/bg` daemons that hold still long enough to be stopped (audit BG-1).
 *
 * The backend is `SUGARCRUSH_BACKEND_CMD`, the same shell-out tier the sibling
 * supervisor tests use, so no provider is contacted. It writes its own pid and
 * then sleeps, which gives the test (a) a readiness signal — the pid file only
 * appears once the worker has built its backend, long after the daemon has
 * re-bound its socket — and (b) a pid to clean up by number, since the
 * backend command runs `setsid`-contained in a session of its own.
 *
 * HOME is a fresh temp directory for every spawn: the daemon resolves its
 * whole config from HOME, and a test must never run one against the user's
 * real `~/.sugar-crush`. Every process and file the fixture creates is
 * recorded and removed in {@see tearDownDaemonFixture()} — by pid, never by
 * pattern.
 */
trait StoppableDaemonFixtureTrait
{
    use HomeSandboxTrait;

    private string $fixtureHome = '';

    /** @var array<string, string|false> env values to put back */
    private array $savedEnv = [];

    /** @var list<array{pid: int, startTime: ?int}> daemons to kill if a test left them */
    private array $fixtureDaemons = [];

    /** @var list<string> pid files the backend command writes */
    private array $fixturePidFiles = [];

    /** @var list<string> IPC files to unlink */
    private array $fixtureFiles = [];

    private function setUpDaemonFixture(): void
    {
        // Both HOME spellings, via the shared trait: the daemon inherits the
        // environment, and this process's own readers use either.
        $this->fixtureHome = $this->useHomeSandbox(
            sys_get_temp_dir() . '/sc_bgstop_home_' . getmypid() . '_' . bin2hex(random_bytes(4)),
        );

        foreach (['SUGARCRUSH_PROVIDER', 'SUGARCRUSH_BACKEND_CMD'] as $name) {
            $this->savedEnv[$name] = getenv($name);
        }
        putenv('SUGARCRUSH_PROVIDER=');
    }

    private function tearDownDaemonFixture(): void
    {
        foreach ($this->fixtureDaemons as $daemon) {
            // Only while the pid still carries the start time it had when we
            // spawned it: a number recycled since is somebody else's process.
            if ($daemon['startTime'] !== null
                && BackgroundSupervisor::procStartTime($daemon['pid']) === $daemon['startTime']
            ) {
                @posix_kill($daemon['pid'], 9);
            }
        }
        foreach ($this->fixturePidFiles as $pidFile) {
            $pid = (int) trim((string) @file_get_contents($pidFile));
            if ($pid > 1 && $this->isSleepProcess($pid)) {
                @posix_kill($pid, 9);
            }
            $this->fixtureFiles[] = $pidFile;
        }
        foreach ($this->fixtureFiles as $file) {
            @unlink($file);
        }
        foreach (array_unique(array_map('dirname', $this->fixtureFiles)) as $dir) {
            if (str_contains(basename($dir), 'sugar_crush_bg_')) {
                @rmdir($dir);
            }
        }
        $this->removeTree($this->fixtureHome);
        $this->restoreHomeSandbox();

        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        $this->fixtureDaemons = [];
        $this->fixturePidFiles = [];
        $this->fixtureFiles = [];
        $this->savedEnv = [];
    }

    /**
     * Spawn a real daemon whose task sleeps for $sleepSeconds, and wait until
     * its worker is running.
     *
     * @return array{session: BackgroundSession, ipc: array{socketPath: string, bufferPath: string, pid: int, tokenPath?: string, startTime?: int|null}, backendPidFile: string, sid: int}
     */
    private function spawnSleepingDaemon(BackgroundSupervisor $supervisor, int $sleepSeconds = 20): array
    {
        $pidFile = $this->fixtureHome . '/backend-' . bin2hex(random_bytes(4)) . '.pid';
        $this->fixturePidFiles[] = $pidFile;
        putenv('SUGARCRUSH_BACKEND_CMD=echo $$ > ' . escapeshellarg($pidFile) . '; cat >/dev/null; exec sleep ' . $sleepSeconds);

        $session = $supervisor->spawnSession(
            name: 'stoppable',
            agent: new Agent(
                name: 'bg-agent',
                description: 'Background agent',
                prompt: '',
                model: '',
                provider: '',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'hold still until stopped',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 60,
        );

        $ipc = $this->ipcOf($supervisor, $session->id);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
        foreach (['socketPath', 'bufferPath', 'tokenPath'] as $key) {
            if (isset($ipc[$key])) {
                $this->fixtureFiles[] = $ipc[$key];
            }
        }
        $this->fixtureFiles[] = $ipc['bufferPath'] . '.log';

        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline && trim((string) @file_get_contents($pidFile)) === '') {
            usleep(50_000);
        }
        if (trim((string) @file_get_contents($pidFile)) === '') {
            self::fail('the background worker never started its backend; buffer: '
                . (string) @file_get_contents($ipc['bufferPath']));
        }

        $stat = ProcessTree::stat($ipc['pid']);
        self::assertNotNull($stat, 'the daemon was gone before the test could stop it');

        return ['session' => $session, 'ipc' => $ipc, 'backendPidFile' => $pidFile, 'sid' => $stat['sid']];
    }

    /**
     * @return array{socketPath: string, bufferPath: string, pid: int, tokenPath?: string, startTime?: int|null}
     */
    private function ipcOf(BackgroundSupervisor $supervisor, string $sessionId): array
    {
        return (new \ReflectionProperty(BackgroundSupervisor::class, 'sessionIpc'))->getValue($supervisor)[$sessionId];
    }

    /**
     * Non-zombie processes still in the daemon's session — the daemon and its
     * worker live there; the backend command is setsid'd out of it. Polled,
     * because a killed member is briefly a zombie awaiting init's reap.
     *
     * @return list<int>
     */
    private function liveSessionMembers(int $sid, float $waitSeconds = 3.0): array
    {
        $deadline = microtime(true) + $waitSeconds;
        do {
            $members = [];
            foreach (ProcessTree::snapshot() ?? [] as $pid => $row) {
                if ($row['sid'] === $sid && $row['state'] !== 'Z' && $row['state'] !== 'X') {
                    $members[] = $pid;
                }
            }
            if ($members === []) {
                return [];
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return $members;
    }

    private function isSleepProcess(int $pid): bool
    {
        return trim((string) @file_get_contents('/proc/' . $pid . '/comm')) === 'sleep';
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
