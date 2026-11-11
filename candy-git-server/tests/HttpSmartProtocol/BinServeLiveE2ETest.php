<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;
use SugarCraft\GitServer\UserStore;
use SugarCraft\GitServer\Visibility;

/**
 * FW-4 (owner ruling D5a): the launched bin IS the product, so the e2e bar is
 * a REAL PROCESS — `php bin/candy-git-server serve --http ...` spawned as a
 * child, an unmodified git(1) cloning/fetching/pushing against its live
 * socket, and a SIGTERM that returns exit 0. The in-process pins
 * (SmartHttpCloneTest, HttpPushTest) pump a listener the test itself owns;
 * none of them ever crossed the bin boundary — argv parsing, config load,
 * account-store hydration, repo scan with marker visibility, banner, signal
 * wiring. This file closes that gap for the whole boot path.
 *
 * Every child here is KILL-leashed (the server under `timeout -s KILL`, the
 * git fixtures synchronous) and every wait is bounded by a deadline; the
 * server handle is reaped in tearDown even when an assertion throws
 * mid-flight (TERM → bounded poll → KILL → proc_close, the escalation
 * ladder). Requires the git binary (host requirement, see README); the skip
 * is loud.
 */
final class BinServeLiveE2ETest extends TestCase
{
    /** Hard ceiling on the whole server child's life — fires only if a test forgot to stop it. */
    private const SERVER_LEASH_SECONDS = 120;

    /** Wall deadline for the listener banner; the boot path prints it right after a successful bind. */
    private const BANNER_DEADLINE_SECONDS = 15.0;

    /** How long a SIGTERMed server gets to run its handler before the ladder escalates to KILL. */
    private const TERM_GRACE_SECONDS = 10.0;

    /** Seconds any single git child is allowed to run before it is killed. */
    private const GIT_LEASH_SECONDS = 60;

    private string $tmpDir = '';
    private string $repoRoot = '';
    private string $configPath = '';
    private string $pubBarePath = '';
    private string $workPath = '';

    /** @var resource|null */
    private $serverProc = null;

    /** @var array<int,resource> */
    private array $serverPipes = [];

    private string $serverStdout = '';
    private string $serverStderr = '';
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\is_executable('/usr/bin/git')) {
            self::markTestSkipped('e2e pins require the system git binary (host requirement, see README)');
        }

        $this->tmpDir = \sys_get_temp_dir() . '/bin-serve-e2e-' . \uniqid();
        \mkdir($this->tmpDir . '/repositories', 0755, true);
        \mkdir($this->tmpDir . '/data', 0755, true);

        // Seed: work repo with one commit → pushed into the public bare.
        $this->workPath = $this->tmpDir . '/work';
        $this->pubBarePath = $this->tmpDir . '/repositories/pub.git';
        \mkdir($this->workPath, 0755, true);
        $this->git(['init', '-q', '-b', 'main', $this->workPath]);
        \file_put_contents($this->workPath . '/a.txt', "hello\n");
        $this->git(['-C', $this->workPath, 'add', 'a.txt']);
        $this->git(['-C', $this->workPath, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'seed']);
        $this->git(['init', '-q', '--bare', '-b', 'main', $this->pubBarePath]);
        $this->git(['-C', $this->workPath, 'push', '-q', $this->pubBarePath, 'main']);
        // Publication is the marker's job: directory presence never is.
        \file_put_contents($this->pubBarePath . '/' . Repo::VISIBILITY_MARKER, Visibility::Public->value . "\n");

        // A second bare with NO marker: the scan must fail it closed to private.
        $privBare = $this->tmpDir . '/repositories/priv.git';
        $this->git(['init', '-q', '--bare', '-b', 'main', $privBare]);
        $this->git(['-C', $this->workPath, 'push', '-q', $privBare, 'main']);

        // Anonymous reads ON for public repos; an admin account so a real
        // push has a grant to succeed against. Both land where the bin will
        // read them: config.yaml at --config, users.json beside it (dataPath
        // is the config file's directory).
        $this->configPath = $this->tmpDir . '/data/config.yaml';
        \file_put_contents($this->configPath, "git:\n  allow_anonymous_read: true\n");
        $store = UserStore::forDataPath($this->tmpDir . '/data');
        $store->add(
            User::new('pusher')
                ->withAdmin(true)
                ->withPasswordHash(\password_hash('live-serve-pw', \PASSWORD_DEFAULT))
        );
        $store->save();
        $this->repoRoot = $this->tmpDir . '/repositories';

        $this->launchServer();
    }

    protected function tearDown(): void
    {
        $this->reapServer();
        if (\is_dir($this->tmpDir)) {
            \exec('rm -rf ' . \escapeshellarg($this->tmpDir));
        }
        parent::tearDown();
    }

    public function testRealGitCloneAgainstTheLaunchedEndpoint(): void
    {
        $clonePath = $this->tmpDir . '/clone';
        [$rc, , $err] = $this->gitChild(['git', 'clone', $this->baseUrl() . '/pub.git', $clonePath]);
        self::assertSame(0, $rc, 'clone from the launched server failed: ' . $err);
        self::assertSame("hello\n", \file_get_contents($clonePath . '/a.txt'));
    }

    public function testFetchPicksUpAnUpstreamCommitOverTheLiveSocket(): void
    {
        $clonePath = $this->tmpDir . '/clone';
        [$rc, , $err] = $this->gitChild(['git', 'clone', $this->baseUrl() . '/pub.git', $clonePath]);
        self::assertSame(0, $rc, 'clone prelude failed: ' . $err);

        // Upstream commit lands in the bare out-of-band (local push into the
        // repo dir), then the existing clone must negotiate it over HTTP.
        \file_put_contents($this->workPath . '/b.txt', "world\n");
        $this->git(['-C', $this->workPath, 'add', 'b.txt']);
        $this->git(['-C', $this->workPath, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'second']);
        $this->git(['-C', $this->workPath, 'push', '-q', $this->pubBarePath, 'main']);
        $newHead = \trim($this->git(['-C', $this->pubBarePath, 'rev-parse', 'refs/heads/main'])[1]);

        [$fetchRc, , $fetchErr] = $this->gitChild(['git', '-C', $clonePath, 'fetch', '-q', 'origin', 'main']);
        self::assertSame(0, $fetchRc, 'fetch from the launched server failed: ' . $fetchErr);
        self::assertSame(
            $newHead,
            \trim($this->git(['-C', $clonePath, 'rev-parse', 'FETCH_HEAD'])[1]),
            'fetch negotiated but did not land the new commit'
        );
    }

    public function testAuthenticatedPushMovesTheBareRefOnTheLaunchedServer(): void
    {
        $clonePath = $this->tmpDir . '/pusher-clone';
        [$rc, , $err] = $this->gitChild(['git', 'clone', $this->baseUrl() . '/pub.git', $clonePath]);
        self::assertSame(0, $rc, 'clone prelude failed: ' . $err);

        \file_put_contents($clonePath . '/c.txt', "pushed\n");
        $this->git(['-C', $clonePath, 'add', 'c.txt']);
        $this->git(['-C', $clonePath, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'live-push']);

        // The receive-pack advertisement 401-challenges the anonymous first
        // GET; the credential answers through GIT_ASKPASS so it never lands
        // in the child argv (same law as SmartHttpCloneTest).
        [$pushRc, , $pushErr] = $this->gitChild(
            ['git', '-C', $clonePath, 'push', '-q', $this->baseUrl() . '/pub.git', 'main'],
            $this->askPassEnv('pusher', 'live-serve-pw')
        );
        self::assertSame(0, $pushRc, 'authenticated push against the launched server failed: ' . $pushErr);

        $bareHead = \trim($this->git(['-C', $this->pubBarePath, 'rev-parse', 'refs/heads/main'])[1]);
        $pushedHead = \trim($this->git(['-C', $clonePath, 'rev-parse', 'HEAD'])[1]);
        self::assertSame($pushedHead, $bareHead, 'push rode the wire but the bare ref never moved');
    }

    public function testAnonymousCloneOfAPrivateRepoIsRefusedOnTheLiveSocket(): void
    {
        [$rc, , $err] = $this->gitChild(
            ['git', 'clone', $this->baseUrl() . '/priv.git', $this->tmpDir . '/nope'],
            ['GIT_TERMINAL_PROMPT=0']
        );
        self::assertNotSame(0, $rc, 'anonymous clone of an unmarked repo must fail');
        self::assertStringContainsString('could not read Username', $err);
        self::assertFileDoesNotExist($this->tmpDir . '/nope/a.txt', 'refused clone still materialized content');
    }

    public function testSigTermStopsTheLaunchedServerGracefullyWithExitZero(): void
    {
        if (!\function_exists('pcntl_signal')) {
            self::markTestSkipped('graceful-signal pin exercises the bin pcntl wiring');
        }

        // The server child is wrapped by timeout(1), which forwards SIGTERM
        // to the php process (verified GNU-coreutils behaviour); the bin's
        // handler stops the listener and the loop, so the process exits 0
        // and timeout propagates that 0. An unhandled TERM would surface as
        // 143 — a KILL as 137 — neither is "graceful".
        \proc_terminate($this->serverProc, 15);
        $exited = $this->pollServerExit(self::TERM_GRACE_SECONDS);
        if (!$exited) {
            $this->escalateKill();
        }
        $rc = $this->closeServer();
        self::assertTrue($exited, 'SIGTERM did not stop the listener within ' . self::TERM_GRACE_SECONDS . 's');
        self::assertSame(0, $rc, 'graceful SIGTERM shutdown must exit 0, not ' . $rc);

        // The port is really closed: a fresh connection now is refused.
        $probe = @\stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 2.0);
        self::assertFalse($probe, 'socket still accepts connections after a clean stop: ' . $errstr);
    }

    /**
     * Structural twin of the exit-0 pin above: the readiness banner is the
     * launchers' contract that SIGTERM will be HANDLED, so the pcntl arms must
     * textually precede the banner write in the bin. 2026-11-11 CI race class:
     * on loaded runners the child was descheduled between the banner fwrite
     * and pcntl_signal(), a TERM landed on the default disposition, and the
     * live pin died with 143-vs-0 while every quiet machine passed. The
     * reorder fixed it; this pin keeps the ordering from silently drifting
     * back (it is byte-order logic, so it runs without spawning anything).
     */
    public function testSignalHandlersAreArmedBeforeTheReadinessBanner(): void
    {
        $bin = (string) \realpath(__DIR__ . '/../../bin/candy-git-server');
        self::assertNotFalse($bin, 'bin/candy-git-server missing next to the lib');
        $source = (string) \file_get_contents($bin);

        $arms = \strpos($source, '\\pcntl_signal(15, $stop)');
        $banner = \strpos($source, "Lang::t('cli.serve_http_banner'");
        self::assertIsInt($arms, 'the SIGTERM arm vanished from bin serve --http');
        self::assertIsInt($banner, 'the readiness banner vanished from bin serve --http');
        self::assertLessThan($banner, $arms, 'SIGTERM must be armed BEFORE the banner proves the server is stoppable');
    }

    // -------------------------------------------------------------------------
    // Launched-server plumbing
    // -------------------------------------------------------------------------

    /**
     * Boot `serve --http` as a child and wait for its banner. A port race is
     * retried on a fresh ephemeral port, bounded; any other early death or a
     * silent boot fails LOUD with the captured stderr.
     */
    private function launchServer(): void
    {
        $bin = (string) \realpath(__DIR__ . '/../../bin/candy-git-server');
        self::assertNotFalse($bin, 'bin/candy-git-server missing next to the lib');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->port = $this->reserveFreePort();
            $this->serverProc = \proc_open(
                [
                    'timeout', '-s', 'KILL', (string) self::SERVER_LEASH_SECONDS,
                    \PHP_BINARY, $bin, 'serve', '--http',
                    '--repo-root', $this->repoRoot,
                    '--http-port', (string) $this->port,
                    '--config', $this->configPath,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->tmpDir,
                [
                    'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
                    'HOME' => $this->tmpDir,
                    'CANDY_GIT_SERVER_LANG' => 'en',
                ]
            );
            self::assertIsResource($this->serverProc, 'failed to spawn the server child');
            $this->serverPipes = $pipes;
            $this->serverStdout = '';
            $this->serverStderr = '';
            \stream_set_blocking($this->serverPipes[1], false);
            \stream_set_blocking($this->serverPipes[2], false);

            $deadline = \hrtime(true) + (int) (self::BANNER_DEADLINE_SECONDS * 1e9);
            while (\hrtime(true) < $deadline) {
                $this->drainServer();
                if (\str_contains($this->serverStdout, 'smart-HTTP listener on')) {
                    return; // booted and bound
                }
                $status = \proc_get_status($this->serverProc);
                if (!$status['running']) {
                    // A refused bind is the one death worth retrying: the
                    // ephemeral-port reservation above has an unavoidable
                    // race window. Everything else must fail with evidence.
                    $this->closeServer();
                    if (\str_contains($this->serverStderr, 'failed to bind')) {
                        break; // next attempt, next port
                    }
                    self::fail('serve --http died before its banner (rc=' . ($status['code'] ?? -1) . "):\n"
                        . $this->serverStderr . $this->serverStdout);
                }
                \usleep(5000);
            }

            if ($this->serverProc !== null) {
                if (\proc_get_status($this->serverProc)['running']) {
                    self::fail('serve --http printed no banner within ' . self::BANNER_DEADLINE_SECONDS . "s:\n"
                        . $this->serverStderr . $this->serverStdout);
                }
                // Died exactly on the deadline edge: reap, then retry fresh.
                $this->closeServer();
            }
        }

        self::fail('serve --http refused the bind on 3 consecutive ports (last stderr): ' . $this->serverStderr);
    }

    private function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    private function reserveFreePort(): int
    {
        $socket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($socket, 'could not reserve an ephemeral port: ' . $errstr);
        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);
        $colon = \strrpos($name, ':');
        self::assertNotFalse($colon, 'unexpected socket name shape: ' . $name);

        return (int) \substr($name, $colon + 1);
    }

    /** Non-blocking drain so the server log pipes can never fill and stall it. */
    private function drainServer(): void
    {
        foreach ([1, 2] as $i) {
            if (isset($this->serverPipes[$i])) {
                $chunk = @\fread($this->serverPipes[$i], 65536);
                if ($chunk !== false && $chunk !== '') {
                    if ($i === 1) {
                        $this->serverStdout .= $chunk;
                    } else {
                        $this->serverStderr .= $chunk;
                    }
                }
            }
        }
    }

    /** @return bool true when the child exited inside the budget */
    private function pollServerExit(float $seconds): bool
    {
        $deadline = \hrtime(true) + (int) ($seconds * 1e9);
        while (\hrtime(true) < $deadline) {
            $this->drainServer();
            $status = \proc_get_status($this->serverProc);
            if (!$status['running']) {
                return true;
            }
            \usleep(5000);
        }
        $this->drainServer();

        return !\proc_get_status($this->serverProc)['running'];
    }

    private function escalateKill(): void
    {
        @\proc_terminate($this->serverProc, 9); // 9 = SIGKILL, no pcntl constant needed
        $deadline = \hrtime(true) + (int) (self::TERM_GRACE_SECONDS * 1e9);
        while (\hrtime(true) < $deadline && \proc_get_status($this->serverProc)['running']) {
            \usleep(5000);
        }
    }

    /** Drain-then-reap: proc_close is the single authoritative rc read. */
    private function closeServer(): int
    {
        $this->drainServer();
        foreach ($this->serverPipes as $pipe) {
            @\fclose($pipe);
        }
        $this->serverPipes = [];
        $rc = \proc_close($this->serverProc);
        $this->serverProc = null;

        return $rc;
    }

    /**
     * Idempotent safety net: whatever a test left behind, the escalation
     * ladder runs to the end and the child is reaped. Never leaks.
     */
    private function reapServer(): void
    {
        if ($this->serverProc === null) {
            return;
        }
        if (\proc_get_status($this->serverProc)['running']) {
            @\proc_terminate($this->serverProc, 15);
            if (!$this->pollServerExit(2.0)) {
                $this->escalateKill();
            }
        }
        $this->closeServer();
    }

    // -------------------------------------------------------------------------
    // Git plumbing (fixture-side, synchronous, KILL-leashed)
    // -------------------------------------------------------------------------

    /**
     * Synchronous git helper for fixture plumbing (NOT under test): argv
     * array, KILL-leashed, throws on failure unless $ok.
     *
     * @param list<string> $argv
     * @return array{0:int,1:string} [rc, stdout]
     */
    private function git(array $argv, bool $ok = false): array
    {
        $cmd = ['timeout', '-s', 'KILL', (string) self::GIT_LEASH_SECONDS, '/usr/bin/git', ...$argv];
        $proc = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertNotFalse($proc, 'failed to spawn git fixture child');
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $rc = \proc_close($proc);
        if (!$ok && $rc !== 0) {
            self::fail('fixture git command failed (' . $rc . '): ' . \implode(' ', $argv) . "\n" . $err);
        }

        return [$rc, $out];
    }

    /**
     * A git child on the UNDER-TEST path (talks to the live server). It runs
     * synchronously — the server is a separate process, nothing to pump —
     * still KILL-leashed and always reaped. $envLines are NAME=value lines
     * handed through env(1), never the child argv.
     *
     * @param list<string> $argv
     * @param list<string> $envLines
     * @return array{0:int,1:string,2:string} [rc, stdout, stderr]
     */
    private function gitChild(array $argv, array $envLines = []): array
    {
        $cmd = $envLines === []
            ? ['timeout', '-s', 'KILL', (string) self::GIT_LEASH_SECONDS, ...$argv]
            : ['timeout', '-s', 'KILL', (string) self::GIT_LEASH_SECONDS, 'env', ...$envLines, ...$argv];
        $proc = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertNotFalse($proc, 'failed to spawn git child: ' . \implode(' ', $cmd));
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $out, $err];
    }

    /**
     * A throwaway GIT_ASKPASS script answering git's two credential prompts
     * (username, then password) plus the no-prompt guard — the house
     * credential-out-of-argv shape from SmartHttpCloneTest.
     *
     * @return list<string>
     */
    private function askPassEnv(string $username, string $password): array
    {
        $path = $this->tmpDir . '/askpass-' . \uniqid() . '.sh';
        \file_put_contents(
            $path,
            "#!/bin/sh\ncase \"$1\" in\n*sername*) printf '%s\\n' " . \escapeshellarg($username) . ";; *) printf '%s\\n' " . \escapeshellarg($password) . ";;\nesac\n"
        );
        \chmod($path, 0755);

        return ['GIT_TERMINAL_PROMPT=0', 'GIT_ASKPASS=' . $path];
    }
}
