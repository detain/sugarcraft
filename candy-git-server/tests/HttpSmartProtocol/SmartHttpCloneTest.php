<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\HttpListener;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\Visibility;

/**
 * THE acceptance bar for owner ruling 1b (r1024/githttp): the system git
 * binary, unmodified, clones from and fetches from candy-git-server over
 * the real socket — no router shortcut, no hand-written client.
 *
 * The listener runs on a PRIVATE StreamSelectLoop pumped by this test
 * while the git children (leashed through `timeout -s KILL`) talk TCP to
 * 127.0.0.1 on an ephemeral port; every child is reaped in a finally
 * (request-scoped discipline extended to the test side). Requires the git
 * binary — declared host requirement (README "Requirements"); the skip is
 * loud, and this box has git.
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\HttpListener
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 */
final class SmartHttpCloneTest extends TestCase
{
    /** Seconds any single git child is allowed to run before it is killed. */
    private const CHILD_LEASH_SECONDS = 60;

    /** Wall-ceiling for the loop pump; the leash above fires first. */
    private const PUMP_CEILING_SECONDS = 90.0;

    private StreamSelectLoop $loop;
    private HttpListener $listener;
    private Server $router;
    private string $tmpDir;
    private string $barePath = '';
    private string $workPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!\is_executable('/usr/bin/git')) {
            self::markTestSkipped('e2e pins require the system git binary (host requirement, see README)');
        }

        $this->tmpDir = \sys_get_temp_dir() . '/smart-clone-e2e-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);

        // Seed: work repo with one commit → pushed into a bare repo.
        $this->workPath = $this->tmpDir . '/work';
        $this->barePath = $this->tmpDir . '/fixture.git';
        \mkdir($this->workPath, 0755, true);
        $this->git(['init', '-q', '-b', 'main', $this->workPath]);
        \file_put_contents($this->workPath . '/a.txt', "hello\n");
        $this->git(['-C', $this->workPath, 'add', 'a.txt']);
        $this->git(['-C', $this->workPath, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'seed']);
        $this->git(['init', '-q', '--bare', '-b', 'main', $this->barePath]);
        $this->git(['-C', $this->workPath, 'push', '-q', $this->barePath, 'main']);

        $this->router = new Server(Config::fromDefaults());
        $this->router->registerRepo(Repo::new('fixture.git', $this->barePath));

        $this->loop = new StreamSelectLoop();
        $this->listener = new HttpListener($this->router, '127.0.0.1', 0, $this->loop);
        $this->listener->start();
    }

    protected function tearDown(): void
    {
        $this->listener->stop();
        if (\is_dir($this->tmpDir)) {
            \exec('rm -rf ' . \escapeshellarg($this->tmpDir));
        }
        parent::tearDown();
    }

    public function testGitCloneAndFetchOverTheRealSocket(): void
    {
        $clonePath = $this->tmpDir . '/clone';

        [$rc, , $err] = $this->runChild(
            ['git', 'clone', $this->baseUrl() . '/fixture.git', $clonePath]
        );
        self::assertSame(0, $rc, 'git clone failed: ' . $err);
        self::assertFileExists($clonePath . '/a.txt');
        self::assertSame("hello\n", \file_get_contents($clonePath . '/a.txt'));

        // Round two: a fresh commit upstream, `git fetch` in the existing
        // clone — the stateless-rpc property (each request is a complete
        // negotiation) is exactly what makes fetch work against this server.
        \file_put_contents($this->workPath . '/b.txt', "world\n");
        $this->git(['-C', $this->workPath, 'add', 'b.txt']);
        $this->git(['-C', $this->workPath, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'second']);
        $this->git(['-C', $this->workPath, 'push', '-q', $this->barePath, 'main']);
        $newHead = \trim($this->git(['-C', $this->barePath, 'rev-parse', 'refs/heads/main'])[1]);

        [$fetchRc, , $fetchErr] = $this->runChild(
            ['git', '-C', $clonePath, 'fetch', '-q', 'origin', 'main']
        );
        self::assertSame(0, $fetchRc, 'git fetch failed: ' . $fetchErr);
        self::assertSame(
            $newHead,
            \trim($this->git(['-C', $clonePath, 'rev-parse', 'FETCH_HEAD'])[1]),
            'fetch negotiated but did not land the new commit'
        );

        // And the objects are really there: a clean checkout of FETCH_HEAD.
        $this->git(['-C', $clonePath, 'checkout', '-q', 'FETCH_HEAD']);
        self::assertSame("world\n", \file_get_contents($clonePath . '/b.txt'));
    }

    public function testCloneOfAPrivateRepoRefusesAnonymousCredentials(): void
    {
        $secret = $this->tmpDir . '/secret.git';
        $this->git(['init', '-q', '--bare', $secret]);
        $this->git(['-C', $this->workPath, 'push', '-q', $secret, 'main']);
        $this->router->registerRepo(
            Repo::new('secret.git', $secret)->withVisibility(Visibility::Private)
        );

        [$rc, , $err] = $this->runChild(
            ['git', 'clone', $this->baseUrl() . '/secret.git', $this->tmpDir . '/nope']
        );
        self::assertNotSame(0, $rc, 'anonymous clone of a private repo must fail');
        self::assertStringContainsString('403', $err);
        self::assertFileDoesNotExist($this->tmpDir . '/nope/a.txt', 'refused clone still materialized content');
    }

    public function testPushOverTheSocketIsRefusedOnTheWire(): void
    {
        // An anonymous push meets the 403 advertisement gate on its very
        // first request (canWrite is fail-closed for anonymous callers);
        // even a write-granted user would hit the 501 phase-2 refusal at
        // the POST — pinned at the router level in ServerTest and the
        // ChildProcessLifetimeCensus refusal arm. Here we pin the wire
        // outcome real git reports: a non-zero push.
        $clonePath = $this->tmpDir . '/clone';
        [$rc, , $err] = $this->runChild(
            ['git', 'clone', $this->baseUrl() . '/fixture.git', $clonePath]
        );
        self::assertSame(0, $rc, 'clone prelude failed: ' . $err);

        \file_put_contents($clonePath . '/c.txt', "pushed\n");
        $this->git(['-C', $clonePath, 'add', 'c.txt']);
        $this->git(['-C', $clonePath, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'push-attempt']);

        [$pushRc, , $pushErr] = $this->runChild(
            ['git', '-C', $clonePath, 'push', 'origin', 'main']
        );
        self::assertNotSame(0, $pushRc, 'push must not succeed in phase 1');
        // The anonymous wire sees 403 first; the 501 phase-2 refusal is the
        // authenticated shape, pinned at the router level (see comment above).
        self::assertStringContainsString('403', $pushErr);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseUrl(): string
    {
        $addr = (string) $this->listener->listenAddress();
        $colon = \strrpos($addr, ':');
        $port = $colon === false ? '0' : \substr($addr, $colon + 1);

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * Synchronous git helper for fixture plumbing (NOT under test): argv
     * array, KILL-leashed, throws on failure unless $ok (assertion helpers
     * keep their own evidence instead).
     *
     * @param list<string> $argv
     * @return array{0:int,1:string} [rc, stdout]
     */
    private function git(array $argv, bool $ok = false): array
    {
        $cmd = ['timeout', '-s', 'KILL', '30', '/usr/bin/git', ...$argv];
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
     * Spawn a KILL-leashed git child while the PRIVATE loop pumps the
     * listener; both child pipes are drained to memory every tick so a
     * chatty client can never deadlock on a full pipe buffer. Returns
     * [rc, stdout, stderr]; a hung child is TERM-then-KILLed, never leaked.
     *
     * @param list<string> $argv
     * @return array{0:int,1:string,2:string}
     */
    private function runChild(array $argv): array
    {
        $cmd = ['timeout', '-s', 'KILL', (string) self::CHILD_LEASH_SECONDS, ...$argv];
        $desc = [
            ['pipe', 'r'],  // stdin — closed immediately (no client reads)
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $desc, $pipes);
        self::assertNotFalse($proc, 'failed to spawn ' . \implode(' ', $cmd));
        \fclose($pipes[0]);
        unset($pipes[0]);
        foreach ([1, 2] as $i) {
            \stream_set_blocking($pipes[$i], false);
        }

        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $deadline = \hrtime(true) + (int) (self::PUMP_CEILING_SECONDS * 1e9);

        $pump = $this->loop->addPeriodicTimer(0.002, function () use ($proc, $pipes, &$stdout, &$stderr, &$exitCode): void {
            $stdout .= (string) @\fread($pipes[1], 65536);
            $stderr .= (string) @\fread($pipes[2], 65536);
            $status = \proc_get_status($proc);
            // 'code' is present only until PHP reaps the child — a second
            // read after death has no key at all. A reaped child without a
            // readable code gets the proc_close() fallback in finally.
            if (!$status['running']) {
                $exitCode = $status['code'] ?? $exitCode;
                $this->loop->stop();
            }
            if (\feof($pipes[1]) && \feof($pipes[2])) {
                // EOF on both channels: nothing more can arrive; stop() is
                // idempotent and harmless before the child is reaped, and it
                // guards the window where loop->run() would otherwise return
                // early once the event sources drain while the child dies.
                $this->loop->stop();
            }
        });
        $guillotine = $this->loop->addTimer(self::PUMP_CEILING_SECONDS, function () use ($proc, &$exitCode): void {
            @\proc_terminate($proc);        // TERM
            @\proc_terminate($proc, 9);     // 9 = SIGKILL, no pcntl constant needed
            $exitCode = -1;
            $this->loop->stop();
        });

        try {
            $this->loop->run();
        } finally {
            $this->loop->cancelTimer($pump);
            $this->loop->cancelTimer($guillotine);
            $status = \proc_get_status($proc);
            if ($status['running']) {
                @\proc_terminate($proc);
                @\proc_terminate($proc, 9);
            }
            foreach ($pipes as $pipe) {
                @\fclose($pipe);
            }
            $reaped = \proc_close($proc); // always reaps; never blocks (child is dead)
            if ($exitCode === null) {
                $exitCode = $reaped;
            }
        }

        return [(int) $exitCode, $stdout, $stderr];
    }
}
