<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Support\ToolIpcFiles;

/**
 * Audit BG-2: a background session's IPC files are removed once it settles,
 * and the private directories left by TUIs that exited before their daemons
 * finished are reaped at startup.
 *
 * Before the fix the daemon unlinked only its socket. The `.buffer`, its
 * `.log` sidecar, the `.token` and the `sugar_crush_bg_<uid>_<hex>` directory
 * itself stayed in `/tmp` after every `/bg` task, and no sweep matched them.
 */
final class BackgroundIpcCleanupTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    /** Scratch parent directory standing in for the system temp dir. */
    private string $scratch = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir() . '/sc_bgipc_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->scratch, 0o700);
    }

    protected function tearDown(): void
    {
        if ($this->fixtureHome !== '') {
            $this->tearDownDaemonFixture();
        }
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Settling a session releases its files
    // -------------------------------------------------------------------------

    public function testASpawnedSessionThatCompletesLeavesNoIpcFilesBehind(): void
    {
        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }
        $this->setUpDaemonFixture();
        putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; printf BGDONE42');

        $supervisor = new BackgroundSupervisor();
        $session = $supervisor->spawnSession(
            name: 'bg task',
            agent: $this->agent(),
            task: 'finish quickly',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        );
        $ipc = $this->ipcOf($supervisor, $session->id);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
        $files = [$ipc['socketPath'], $ipc['bufferPath'], $ipc['bufferPath'] . '.log', $ipc['tokenPath'] ?? ''];
        array_push($this->fixtureFiles, ...$files);
        $dir = dirname($ipc['bufferPath']);
        $this->assertDirectoryExists($dir);
        $this->assertStringStartsWith(BackgroundSupervisor::IPC_DIR_PREFIX, basename($dir));

        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && $supervisor->getSession($session->id)?->isActive()) {
            $supervisor->tick();
            usleep(100_000);
        }

        $settled = $supervisor->getSession($session->id);
        $this->assertSame(BackgroundSessionStatus::Completed, $settled?->status, 'the task never settled');
        $this->assertStringContainsString('BGDONE42', $settled->output, 'the answer must be absorbed BEFORE the buffer is deleted');
        foreach ($files as $path) {
            $this->assertFileDoesNotExist($path, 'a settled session left an IPC file behind');
        }
        $this->assertDirectoryDoesNotExist($dir, 'the last settled session left its private directory behind');
        $this->assertSame('', $this->ipcDirOf($supervisor), 'the next spawn must get a fresh directory');
    }

    public function testThePrivateDirectoryStaysUntilItsLastSessionSettles(): void
    {
        $dir = $this->makeIpcDir();
        $supervisor = new BackgroundSupervisor();
        $this->setIpcDir($supervisor, $dir);

        $first = $this->injectSession($supervisor, 's1', $dir, $this->deadPid(), "first answer\n[session:task:completed]\n");
        $second = $this->injectSession($supervisor, 's2', $dir, (int) getmypid(), "[session:heartbeat] pid=1\n");

        $supervisor->tick();

        $this->assertSame(BackgroundSessionStatus::Completed, $supervisor->getSession('s1')->status);
        $this->assertSame("first answer\n", $supervisor->getSession('s1')->output);
        foreach ($first as $path) {
            $this->assertFileDoesNotExist($path);
        }
        foreach ($second as $path) {
            $this->assertFileExists($path, 'a still-running sibling lost its IPC files');
        }
        $this->assertDirectoryExists($dir);
        $this->assertSame($dir, $this->ipcDirOf($supervisor));

        // Now the second daemon is gone too: last one out removes the directory.
        $this->rewritePid($supervisor, 's2', $this->deadPid());
        file_put_contents($second[1], "second answer\n[session:task:completed]\n");
        $supervisor->tick();

        $this->assertSame(BackgroundSessionStatus::Completed, $supervisor->getSession('s2')->status);
        $this->assertSame("second answer\n", $supervisor->getSession('s2')->output);
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertSame('', $this->ipcDirOf($supervisor));
    }

    public function testAFailedSessionIsReleasedToo(): void
    {
        $dir = $this->makeIpcDir();
        $supervisor = new BackgroundSupervisor();
        $this->setIpcDir($supervisor, $dir);
        $files = $this->injectSession($supervisor, 's1', $dir, $this->deadPid(), "[session:task:start]\n[session:task:failed]\n");

        $supervisor->tick();

        $this->assertSame(BackgroundSessionStatus::Failed, $supervisor->getSession('s1')->status);
        foreach ($files as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testAPathOutsideTheSupervisorsOwnDirectoryIsNeverDeleted(): void
    {
        $ownDir = $this->makeIpcDir();
        $supervisor = new BackgroundSupervisor();
        $this->setIpcDir($supervisor, $ownDir);

        $foreign = $this->scratch . '/foreign.buffer';
        file_put_contents($foreign, "answer\n[session:task:completed]\n");
        file_put_contents($foreign . '.log', '');
        $supervisor->addSession($this->session('s1'));
        $this->setIpc($supervisor, 's1', [
            'socketPath' => $foreign . '.sock',
            'bufferPath' => $foreign,
            'tokenPath' => $foreign . '.token',
            'pid' => $this->deadPid(),
            'startTime' => null,
        ]);

        $supervisor->tick();

        $this->assertSame(BackgroundSessionStatus::Completed, $supervisor->getSession('s1')->status);
        $this->assertFileExists($foreign, 'the supervisor deleted a file it did not create');
        $this->assertFileExists($foreign . '.log');
        $this->assertDirectoryDoesNotExist($ownDir, 'an empty own directory is still released');
    }

    public function testReconnectReleasesAnExitedDaemonButNotALiveOneWhoseSocketVanished(): void
    {
        $dir = $this->makeIpcDir();
        $supervisor = new BackgroundSupervisor();
        $this->setIpcDir($supervisor, $dir);

        $exited = $this->injectSession($supervisor, 's1', $dir, $this->deadPid(), "done\n[session:task:completed]\n");
        $live = $this->injectSession($supervisor, 's2', $dir, (int) getmypid(), "[session:heartbeat] pid=1\n");
        @unlink($live[0]);

        $restored = $supervisor->reconnect();

        $this->assertSame(BackgroundSessionStatus::Completed, $restored['s1']->status);
        $this->assertSame("done\n", $restored['s1']->output);
        foreach ($exited as $path) {
            $this->assertFileDoesNotExist($path);
        }
        foreach (array_slice($live, 1) as $path) {
            $this->assertFileExists($path, 'reconnect deleted the files of a daemon that is still running');
        }
        $this->assertDirectoryExists($dir);
    }

    public function testAVanishedPrivateDirectoryIsReplacedNotReused(): void
    {
        $supervisor = new BackgroundSupervisor();
        $ensure = new \ReflectionMethod(BackgroundSupervisor::class, 'ensurePrivateIpcDir');

        $first = $ensure->invoke($supervisor);
        $this->assertDirectoryExists($first);
        rmdir($first);

        $second = $ensure->invoke($supervisor);
        try {
            $this->assertNotSame($first, $second);
            $this->assertDirectoryExists($second);
            $this->assertSame(0o700, fileperms($second) & 0o777);
        } finally {
            @rmdir($second);
        }
    }

    // -------------------------------------------------------------------------
    // Startup sweep
    // -------------------------------------------------------------------------

    public function testTheSweepRemovesAStaleDirectoryWithEveryKindOfEntryASessionLeaves(): void
    {
        $stale = $this->plantDir($this->scratch, $this->dirName(), 7200, withSocket: true);

        $this->assertSame(1, BackgroundSupervisor::sweepStaleIpcDirs($this->scratch, 3600));
        $this->assertDirectoryDoesNotExist($stale);
    }

    public function testTheSweepLeavesADirectoryWithOneFreshEntryAlone(): void
    {
        $dir = $this->plantDir($this->scratch, $this->dirName(), 7200);
        // A heartbeat just landed: the daemon is alive.
        touch($dir . '/sess.buffer');
        touch($dir, time() - 7200);

        $this->assertSame(0, BackgroundSupervisor::sweepStaleIpcDirs($this->scratch, 3600));
        $this->assertFileExists($dir . '/sess.buffer');
        $this->assertFileExists($dir . '/sess.token');
    }

    public function testTheSweepLeavesAFreshEmptyDirectoryAlone(): void
    {
        $dir = $this->scratch . '/' . $this->dirName();
        mkdir($dir, 0o700);

        $this->assertSame(0, BackgroundSupervisor::sweepStaleIpcDirs($this->scratch, 3600));
        $this->assertDirectoryExists($dir, 'a spawn in progress had its directory removed');
    }

    public function testTheSweepLeavesForeignShapesAlone(): void
    {
        $uid = $this->uid();
        $withSubdir = $this->plantDir($this->scratch, $this->dirName(), 7200);
        mkdir($withSubdir . '/nested');
        touch($withSubdir . '/nested', time() - 7200);
        touch($withSubdir, time() - 7200);

        $wrongShape = $this->plantDir($this->scratch, BackgroundSupervisor::IPC_DIR_PREFIX . $uid . '_notours', 7200);
        $otherUid = $this->plantDir($this->scratch, BackgroundSupervisor::IPC_DIR_PREFIX . ($uid + 1) . '_' . bin2hex(random_bytes(8)), 7200);

        $target = $this->plantDir($this->scratch, 'target', 7200);
        $link = $this->scratch . '/' . $this->dirName();
        symlink($target, $link);

        $plainFile = $this->scratch . '/' . $this->dirName();
        file_put_contents($plainFile, 'not a directory');
        touch($plainFile, time() - 7200);

        $this->assertSame(0, BackgroundSupervisor::sweepStaleIpcDirs($this->scratch, 3600));
        $this->assertDirectoryExists($withSubdir . '/nested', 'a directory holding something a session never writes is not ours');
        $this->assertFileExists($withSubdir . '/sess.buffer');
        $this->assertDirectoryExists($wrongShape);
        $this->assertDirectoryExists($otherUid);
        $this->assertTrue(is_link($link), 'a planted symlink was removed');
        $this->assertFileExists($target . '/sess.buffer', 'the sweep followed a symlink');
        $this->assertFileExists($plainFile);
    }

    public function testTheDefaultCutoffIsTheDocumentedOne(): void
    {
        $cutoff = BackgroundSupervisor::STALE_IPC_DIR_SECONDS;
        $this->assertGreaterThan(
            BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS * 100,
            $cutoff,
            'the cutoff must dwarf the heartbeat cadence it uses as a liveness signal',
        );

        $young = $this->plantDir($this->scratch, $this->dirName(), $cutoff - 120);
        $old = $this->plantDir($this->scratch, $this->dirName(), $cutoff + 120);

        $this->assertSame(1, BackgroundSupervisor::sweepStaleIpcDirs($this->scratch));
        $this->assertDirectoryExists($young);
        $this->assertDirectoryDoesNotExist($old);
    }

    public function testTheStartupSweepReapsStaleIpcDirectories(): void
    {
        $stale = $this->plantDir($this->scratch, $this->dirName(), BackgroundSupervisor::STALE_IPC_DIR_SECONDS + 600);
        $payload = $this->scratch . '/' . ToolIpcFiles::CHAT_PREFIX . 'bgsweep.json';
        file_put_contents($payload, '{}');
        touch($payload, time() - 86_400);

        $latch = new \ReflectionProperty(ToolIpcFiles::class, 'swept');
        $wasSwept = $latch->getValue();
        try {
            $latch->setValue(null, false);
            $this->assertSame(2, ToolIpcFiles::sweepOnce($this->scratch), 'one payload file plus one IPC directory');
        } finally {
            $latch->setValue(null, $wasSwept);
        }
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertFileDoesNotExist($payload);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function uid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : 0;
    }

    private function dirName(): string
    {
        return BackgroundSupervisor::IPC_DIR_PREFIX . $this->uid() . '_' . bin2hex(random_bytes(8));
    }

    /**
     * A private IPC directory whose entries and own mtime are all $ageSeconds old.
     */
    private function plantDir(string $parent, string $name, int $ageSeconds, bool $withSocket = false): string
    {
        $dir = $parent . '/' . $name;
        mkdir($dir, 0o700);
        $entries = ['sess.buffer', 'sess.buffer.log', 'sess.token'];
        foreach ($entries as $entry) {
            file_put_contents($dir . '/' . $entry, 'x');
        }
        if ($withSocket) {
            $server = stream_socket_server('unix://' . $dir . '/sess.sock', $errno, $errstr);
            $this->assertNotFalse($server, $errstr);
            fclose($server);
            $this->assertSame(0o140000, fileperms($dir . '/sess.sock') & 0o170000, 'the fixture must leave a real socket file');
            $entries[] = 'sess.sock';
        }
        foreach ($entries as $entry) {
            touch($dir . '/' . $entry, time() - $ageSeconds);
        }
        touch($dir, time() - $ageSeconds);

        return $dir;
    }

    /** An owner-private directory inside the scratch parent, used as a supervisor's own. */
    private function makeIpcDir(): string
    {
        $dir = $this->scratch . '/' . $this->dirName();
        mkdir($dir, 0o700);

        return $dir;
    }

    /**
     * Register a session whose four IPC files exist in $dir.
     *
     * @return list<string> socket, buffer, log, token
     */
    private function injectSession(BackgroundSupervisor $supervisor, string $id, string $dir, int $pid, string $buffer): array
    {
        $base = $dir . '/' . $id;
        $files = [$base . '.sock', $base . '.buffer', $base . '.buffer.log', $base . '.token'];
        file_put_contents($files[0], '');
        file_put_contents($files[1], $buffer);
        file_put_contents($files[2], '');
        file_put_contents($files[3], 'token');

        $supervisor->addSession($this->session($id));
        $this->setIpc($supervisor, $id, [
            'socketPath' => $files[0],
            'bufferPath' => $files[1],
            'tokenPath' => $files[3],
            'pid' => $pid,
            'startTime' => BackgroundSupervisor::procStartTime($pid),
        ]);

        return $files;
    }

    /**
     * @param array{socketPath: string, bufferPath: string, pid: int, tokenPath?: string, startTime?: int|null} $ipc
     */
    private function setIpc(BackgroundSupervisor $supervisor, string $id, array $ipc): void
    {
        $prop = new \ReflectionProperty(BackgroundSupervisor::class, 'sessionIpc');
        $all = $prop->getValue($supervisor);
        $all[$id] = $ipc;
        $prop->setValue($supervisor, $all);
    }

    private function rewritePid(BackgroundSupervisor $supervisor, string $id, int $pid): void
    {
        $ipc = $this->ipcOf($supervisor, $id);
        $ipc['pid'] = $pid;
        $ipc['startTime'] = BackgroundSupervisor::procStartTime($pid);
        $this->setIpc($supervisor, $id, $ipc);
    }

    private function setIpcDir(BackgroundSupervisor $supervisor, string $dir): void
    {
        (new \ReflectionProperty(BackgroundSupervisor::class, 'ipcDir'))->setValue($supervisor, $dir);
    }

    private function ipcDirOf(BackgroundSupervisor $supervisor): string
    {
        return (new \ReflectionProperty(BackgroundSupervisor::class, 'ipcDir'))->getValue($supervisor);
    }

    private function session(string $id): BackgroundSession
    {
        return (new BackgroundSession(
            id: $id,
            name: $id,
            agent: $this->agent(),
            task: 'task',
            workingDirectory: $this->scratch,
        ))->withStatus(BackgroundSessionStatus::Running);
    }

    private function agent(): Agent
    {
        return new Agent(
            name: 'bg-agent',
            description: 'Background agent',
            prompt: '',
            model: '',
            provider: '',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    private function deadPid(): int
    {
        $proc = proc_open([PHP_BINARY, '-r', 'exit(0);'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);
        $this->assertIsResource($proc);
        $pid = (int) proc_get_status($proc)['pid'];
        proc_close($proc);

        return $pid;
    }
}
