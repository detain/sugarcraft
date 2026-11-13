<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\GitDaemon;
use SugarCraft\GitServer\Git\PktLine;
use SugarCraft\GitServer\Git\StatelessRpc;
use SugarCraft\GitServer\Git\StdioPipe;
use SugarCraft\GitServer\Git\UploadPack;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;

/**
 * E721 child-lifetime census: every git child candy-git-server spawns is
 * REQUEST-scoped — drained, terminated-if-undrained, and proc_close()d
 * inside the handler that spawned it — so neither daemon stop nor an
 * unwinding request can leave a survivor.
 *
 * The census is a live-descendant ps scan of THIS process (plus a
 * pcntl_waitpid drain check where ext-pcntl is loaded), and it carries a
 * known-positive arm: a planted running child MUST be visible, so a zero
 * result is evidence and not a blind reader. The delegated git services
 * take explicit stream handles (no process-STDOUT constants), so every
 * descendant census runs against this process directly.
 *
 * @covers \SugarCraft\GitServer\Git\GitDaemon
 * @covers \SugarCraft\GitServer\Git\StatelessRpc
 * @covers \SugarCraft\GitServer\Git\StdioPipe
 * @covers \SugarCraft\GitServer\Git\UploadPack
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 */
final class ChildProcessLifetimeCensusTest extends TestCase
{
    /** Seconds the stalled fake-git child outlives its terminated teardown. */
    private const STALL_SECONDS = 30;

    /** Ceiling for "the terminate fired" timing arms; the stall is 30s. */
    private const BOUNDED_TEARDOWN_CEILING_SECONDS = 8.0;

    /** Every proc_open site the census accounted for, by file. */
    private const SPAWN_SITES_BY_FILE = [
        'Git/StdioPipe.php' => 1, // run() — the shared bounded pump behind the daemon + SSH services
        'Git/StatelessRpc.php' => 2, // advertise(), negotiate() (smart-HTTP children)
        'Support/BoundedCommand.php' => 1, // run() — the deadline-bound argv runner behind Repo reads, keygen and mirror fetch
    ];

    private string $tmpDir;
    private Config $config;
    private ?string $savedPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/serve-child-lifetime-' . \uniqid();
        \mkdir($this->tmpDir . '/repositories', 0755, true);

        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: \"Test\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 3, max_connections: 8, allow_anonymous_read: true }\n"
        );
        $this->config = Config::load($this->tmpDir . '/config.yaml');

        // The daemon arms below exercise anonymous READ of a published repo
        // and the smart-HTTP upload path — the opt-in world of
        // git.allow_anonymous_read (fail-closed default pinned in
        // AnonymousReadLeverTest). The lever is part of the parsed config
        // above (the daemon snapshots it at construction); the singleton
        // arm covers the direct-Server pins.
        \SugarCraft\GitServer\AccessControl::instance()->setAllowAnonymousRead(true);
    }

    protected function tearDown(): void
    {
        \SugarCraft\GitServer\AccessControl::instance()->setAllowAnonymousRead(false);
        if ($this->savedPath !== null) {
            \putenv('PATH=' . $this->savedPath);
            $this->savedPath = null;
        }
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Static census — every spawn site carries its reaper IN THE SAME FUNCTION
    // -------------------------------------------------------------------------

    public function testEveryProcOpenSiteInSrcCarriesItsFinallyReaper(): void
    {
        $expected = 0;
        foreach (self::SPAWN_SITES_BY_FILE as $count) {
            $expected += $count;
        }

        $derived = 0;
        foreach ($this->srcFiles() as $relative => $source) {
            $perFile = 0;
            $bodies = $this->functionBodies($source);
            foreach ($bodies as $name => $body) {
                $spawned = \preg_match_all('/\bproc_open\s*\(/', $body) ?: 0;
                if ($spawned === 0) {
                    continue;
                }
                $perFile += $spawned;
                self::assertStringContainsString(
                    'finally',
                    $body,
                    "{$relative}::{$name} reaps outside a finally (E721 contract)"
                );
                // The ladder may be inline or delegated to a same-file
                // reaper (StatelessRpc::reapBounded); either way the spawn
                // site's finally must reach a scope that terminates and
                // proc_close()s. Delegation is accepted ONLY when the
                // callee lives in the same file and carries both halves —
                // testTheDelegatedReaperClosesAndTerminates pins the callee.
                $reaps = \preg_match_all('/\bproc_close\s*\(/', $body) > 0
                    && \preg_match('/\bproc_terminate\s*\(/', $body) === 1;
                if (!$reaps) {
                    foreach ($bodies as $helper => $hbody) {
                        if ($helper === $name
                            || !\preg_match('/self::' . \preg_quote($helper, '/') . '\s*\(/', $body)) {
                            continue;
                        }
                        if (\preg_match_all('/\bproc_close\s*\(/', $hbody) > 0
                            && \preg_match('/\bproc_terminate\s*\(/', $hbody) === 1) {
                            $reaps = true;

                            break;
                        }
                    }
                }
                self::assertTrue(
                    $reaps,
                    "{$relative}::{$name} neither reaps inline nor delegates its finally to a same-file bounded reaper (E721)"
                );
            }
            self::assertSame(
                self::SPAWN_SITES_BY_FILE[$relative] ?? 0,
                $perFile,
                "proc_open site count drifted in {$relative} — re-census and update the roster (E721)"
            );
            $derived += $perFile;
        }

        self::assertSame($expected, $derived, 'total proc_open census drifted (E721)');

        // The census's negative half: the string-form pipeline is the ONLY
        // child vocabulary in src/. popen/passthru/system/shell_exec would
        // each be a new reap story and must not appear un-censused.
        foreach ($this->srcFiles() as $relative => $source) {
            foreach (['popen', 'passthru', 'shell_exec', 'system'] as $ban) {
                self::assertDoesNotMatchRegularExpression(
                    '/\b' . \preg_quote($ban, '/') . '\s*\(/',
                    $this->codeOnly($source),
                    "unexpected {$ban}() child spawn in {$relative}"
                );
            }
        }
    }

    /**
     * The delegation the census above accepts must be real: reapBounded()
     * is only a lawful reaper because it terminates and proc_close()s with a
     * bounded wait, and only from the spawn sites' finally blocks.
     */
    public function testTheDelegatedReaperClosesAndTerminates(): void
    {
        $bodies = $this->functionBodies($this->srcFiles()['Git/StatelessRpc.php']);
        self::assertArrayHasKey('reapBounded', $bodies, 'the delegated reaper vanished — spawn sites would hold unclosable children');
        $reaper = $bodies['reapBounded'];
        self::assertGreaterThan(0, \preg_match_all('/\bproc_close\s*\(/', $reaper), 'reapBounded must collect the exit code via proc_close');
        self::assertMatchesRegularExpression('/\bproc_terminate\s*\(/', $reaper, 'reapBounded must run the TERM/KILL ladder');
        self::assertStringContainsString('waitForExit', $reaper, 'the ladder must poll with a bound, never block an unbounded wait on a wedged child');
        // The TERM rung alone cannot end a child that ignores SIGTERM
        // (trap-"" dispositions survive exec) — the ladder must carry an
        // EXPLICIT SIGKILL rung, itself followed by a bounded wait so the
        // escalation can never degrade into an unbounded proc_close.
        self::assertMatchesRegularExpression('/\bproc_terminate\(\s*\$proc\s*,\s*9\s*\)/', $reaper, 'reapBounded must escalate to an explicit SIGKILL (9) for TERM-immune children');
        self::assertMatchesRegularExpression('/waitForExit\(\s*\$proc\s*,\s*self::KILL_WAIT_SECONDS/', $reaper, 'the SIGKILL rung must be followed by its own bounded wait');
        foreach (['advertise', 'negotiate'] as $spawner) {
            self::assertMatchesRegularExpression(
                '/finally\s*\{[^{}]*self::reapBounded\s*\(/s',
                $bodies[$spawner],
                "{$spawner}() must hand its child to the bounded reaper from the finally block"
            );
        }
    }

    /**
     * Behavioral pin for the KILL rung the static census above demands:
     * a child whose SIGTERM disposition is IGNORED (trap "" TERM is
     * inherited across exec, so the exec'd sleep cannot be TERM'd) must
     * still end up dead and reaped within a bounded wall. This is the
     * E721 contract under the only input class that TERM-based fixtures
     * cannot falsify — a neutered escalation would leave the stall alive
     * and proc_close() would block out the ceiling.
     */
    public function testTheLadderEscalatesToKillWhenTheChildIgnoresTerm(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('the survivor census reads /proc (Linux only)');
        }

        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = \proc_open(
            ['bash', '-c', 'trap "" TERM; echo ready; exec sleep ' . self::STALL_SECONDS],
            $desc,
            $pipes
        );
        $this->assertIsResource($proc);

        // Determinism: TERM must arrive AFTER the trap is installed — if it
        // raced ahead of the disposition (bash still default-handling), the
        // child would die instantly and this pin would go vacuously green.
        // The ready line is emitted strictly after `trap "" TERM`, so reading
        // it gates the ladder on the immunity actually being in place.
        $ready = \stream_get_line($pipes[1], 16, "\n");
        self::assertSame('ready', $ready, 'the TERM-immune fixture never came up');

        $reap = new \ReflectionMethod(StatelessRpc::class, 'reapBounded');
        $reap->setAccessible(true);

        $start = \hrtime(true);
        try {
            $result = $reap->invoke(null, $proc, $pipes);
            self::assertIsArray($result);
            self::assertArrayHasKey('exitCode', $result);
            self::assertArrayHasKey('signal', $result);
        } finally {
            $this->assertNoLiveChildren('after the TERM-immune child went through reapBounded');
        }
        $elapsedSeconds = (\hrtime(true) - $start) / 1e9;

        self::assertLessThan(
            self::BOUNDED_TEARDOWN_CEILING_SECONDS,
            $elapsedSeconds,
            'the ladder waited on a TERM-immune child — the KILL rung never fired (E721)'
        );
    }

    // -------------------------------------------------------------------------
    // Known positive — the census can actually see a survivor
    // -------------------------------------------------------------------------

    public function testTheSurvivorCensusSeesAPlantedRunningChild(): void
    {
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = \proc_open('sleep ' . self::STALL_SECONDS, $desc, $pipes);
        $this->assertIsResource($proc);
        $pid = \proc_get_status($proc)['pid'];

        try {
            $children = $this->liveChildren();
            $seen = \array_values(\array_filter(
                $children,
                static fn (array $row): bool => $row['pid'] === $pid
            ));
            self::assertCount(1, $seen, 'the census must see a planted running child — otherwise every zero-survivor pin is blind');
            self::assertStringNotContainsString('Z', $seen[0]['stat']);
        } finally {
            @\proc_terminate($proc);
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
            \proc_close($proc);
        }

        $this->assertNoLiveChildren('after reaping the planted child');
    }

    // -------------------------------------------------------------------------
    // StdioPipe — the daemon's only git spawn path
    // -------------------------------------------------------------------------

    public function testStdioPipeReapsItsGitChildWithZeroSurvivors(): void
    {
        $repo = $this->createGitRepo('pipe-reap');
        $head = $repo->refs()['refs/heads/main'] ?? null;
        $this->assertIsString($head);

        $in = \fopen('php://memory', 'r+');
        $out = \fopen('php://memory', 'r+');
        $this->assertIsResource($in);
        $this->assertIsResource($out);

        try {
            // One-shot delegation shape the daemon actually uses: the
            // child advertises and exits; the empty memory input reaches
            // feof immediately, so the pump must finish without ever
            // waiting a deadline.
            $rc = StdioPipe::run($in, $out, ['git', 'upload-pack', '--advertise-refs', '.'], $repo->path());

            \rewind($out);
            $written = (string) \stream_get_contents($out);
        } finally {
            if (\is_resource($in)) {
                \fclose($in);
            }
            if (\is_resource($out)) {
                \fclose($out);
            }
        }

        self::assertSame(0, $rc, 'a one-shot advertisement child must exit clean');
        self::assertStringContainsString("{$head} refs/heads/main", $written, 'advertisement must reach the out stream');
        self::assertSame('0000', \substr($written, -4), 'advertisement ends with the flush packet');
        $this->assertNoLiveChildren('after StdioPipe::run drained its child');
    }

    public function testStdioPipeTerminatesAWedgedChildOnTheIdleDeadline(): void
    {
        // Fake git: consume stdin, produce nothing, then stall forever. A
        // tight idle deadline must break the pump and the teardown ladder
        // must TERM/KILL the wedged child instead of reaping into the stall
        // — the same E721 contract the removed daemon handlers used to pin.
        $this->withFakeGitOnPath('cat >/dev/null; exec sleep ' . self::STALL_SECONDS);

        // Empty memory stream: feof at once, so the pump's input side is
        // done and only the stalled child keeps the loop alive.
        $in = \fopen('php://memory', 'r+');
        $out = \fopen('php://memory', 'r+');

        $start = \hrtime(true);
        try {
            $rc = StdioPipe::run($in, $out, ['git', 'upload-pack', '.'], $this->tmpDir, '', 0.3, 30.0);
        } finally {
            if (\is_resource($in)) {
                \fclose($in);
            }
            if (\is_resource($out)) {
                \fclose($out);
            }
        }
        $elapsedSeconds = (\hrtime(true) - $start) / 1e9;

        self::assertNotSame(0, $rc, 'a wedged child must not report success');
        self::assertLessThan(
            self::BOUNDED_TEARDOWN_CEILING_SECONDS,
            $elapsedSeconds,
            'the pump waited out the stalled child — no idle break / terminate fired (E721)'
        );
        $this->assertNoLiveChildren('after the wedged-StdioPipe teardown');
    }

    // -------------------------------------------------------------------------
    // Daemon stop — the acceptance headline
    // -------------------------------------------------------------------------

    public function testDaemonStopLeavesNoGitChildren(): void
    {
        $repo = $this->createGitRepo('stop-census');
        $head = $repo->refs()['refs/heads/main'] ?? null;
        $this->assertIsString($head);

        $fdBefore = $this->openFdCount();

        $daemon = new GitDaemon($this->config);
        $daemon->registerRepo($repo);

        $loop = new StreamSelectLoop();
        $promise = $daemon->serveAsync($loop);

        $errno = 0;
        $errstr = '';
        $client = \stream_socket_client('tcp://' . (string) $daemon->listenAddress(), $errno, $errstr, 2.0);
        $this->assertIsResource($client, "connect failed: {$errstr}");
        \stream_set_blocking($client, false);
        // Real wire framing: the pkt request line plus pkt-framed wants,
        // then a half-close so the delegated `git upload-pack` child sees
        // EOF and exits after streaming (a bare 'want' line is not what a
        // git client sends — the daemon parses both shapes for fixtures).
        \fwrite($client, PktLine::encode("git-upload-pack /stop-census\0host=localhost\0")
            . PktLine::encode("want {$head}\n")
            . PktLine::encode("done\n")
            . PktLine::FLUSH);
        \stream_socket_shutdown($client, \STREAM_SHUT_WR);

        $response = '';
        $loop->addReadStream($client, static function () use (&$response, $client, $loop): void {
            $chunk = \fread($client, 65536);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;

                return;
            }
            if ($chunk === false || \feof($client)) {
                $loop->removeReadStream($client);
                $loop->stop();
            }
        });
        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);
        \fclose($client);

        // The request drove a real one-shot upload-pack child end to
        // end: the advertisement is git's own bytes, and the pump's
        // reap ladder runs before the handler returns...
        self::assertStringContainsString('refs/heads/main', $response);
        self::assertSame('0000', \substr($response, -4), 'advertisement ends with the flush packet');
        $this->assertNoLiveChildren('after the served request');

        // ...and stopping the daemon leaves nothing behind: every reap
        // already happened inside the handler, so shutdown has no children
        // to chase and no descriptors to shed.
        $resolved = false;
        $promise->then(static function () use (&$resolved): void {
            $resolved = true;
        });
        $daemon->shutdown();
        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($safety);

        self::assertTrue($resolved, 'serveAsync promise must resolve on shutdown');
        self::assertFalse($daemon->isRunning());
        $this->assertNoLiveChildren('after daemon stop');

        $fdAfter = $this->openFdCount();
        if ($fdBefore !== null && $fdAfter !== null) {
            self::assertSame($fdBefore, $fdAfter, 'fd census across a daemon session must be flat');
        }
    }

    // -------------------------------------------------------------------------
    // HTTP smart protocol — request-scoped reaps
    // -------------------------------------------------------------------------

    public function testHttpUploadPackRequestReapsWithinScope(): void
    {
        // Fake git proves the spawn happened (marker) and exits fast; the
        // census proves the child is gone when the SYNCHRONOUS
        // handleRequest() returns — the request-scoped reap contract.
        $repo = $this->createGitRepo('http-upload');
        $marker = $this->tmpDir . '/upload-spawned';
        $this->withFakeGitOnPath('echo ok > ' . \escapeshellarg($marker) . '; cat >/dev/null');

        $server = new Server($this->config);
        // The route names repos by the full path segment — "X.git" (house
        // convention, cf. ServerTest's testrepo.git).
        $server->registerRepo(Repo::new('http-upload.git', $repo->path())->withPublic(true));

        $response = $server->handleRequest(
            'POST',
            '/http-upload.git/git-upload-pack',
            '',
            [],
            "003dwant " . \str_repeat('f', 40) . "\n0000"
        );

        self::assertSame(200, $response['status']);
        self::assertFileExists($marker, 'upload-pack child never spawned — the census would be vacuous');
        $this->assertNoLiveChildren('after handleUploadPack returned');
    }

    /**
     * Changed 2026-11-03 (push wired): POST git-receive-pack answers the
     * pre-auth 401 challenge and spawns nothing for an anonymous client,
     * so the router-side census arm below pins the challenge-and-refuse
     * shape. The receive-pack CHILD-LIFETIME story for the wired push path
     * lives in the direct StatelessRpc::negotiate() arm below, keeping the
     * shared spawn code census-honest now that push is live.
     */
    public function testHttpPostReceivePackRefusesWithoutSpawning(): void
    {
        $repo = $this->createGitRepo('http-recv');
        $marker = $this->tmpDir . '/recv-spawned';
        $this->withFakeGitOnPath('echo ok > ' . \escapeshellarg($marker) . '; cat >/dev/null');

        $server = new Server($this->config);
        $server->registerRepo(Repo::new('http-recv.git', $repo->path())->withPublic(true));
        $server->registerUser(User::new('pusher')->withAdmin(true)->withPasswordHash(\password_hash('pushpass', \PASSWORD_DEFAULT)));

        $response = $server->handleRequest(
            'POST',
            '/http-recv.git/git-receive-pack',
            '',
            [],
            "junk-not-a-negotiation\n"
        );

        // Push is wired now: the authorization gate answers an anonymous
        // POST with a 401 challenge BEFORE the body is examined, so no
        // receive-pack child is ever spawned for a client that has not
        // proven write access.
        self::assertSame(401, $response['status']);
        self::assertSame('Basic realm="candy-git-server"', $response['headers']['WWW-Authenticate'] ?? null);
        self::assertFileDoesNotExist($marker, 'the pre-auth challenge must not spawn a receive-pack child');
        $this->assertNoLiveChildren('after the 401 challenge');
    }

    public function testStatelessRpcNegotiateReapsReceivePackChildren(): void
    {
        // Same request-scoped contract, exercised DIRECTLY on the shared
        // spawn path (push delegation is the phase-2 consumer).
        $repo = $this->createGitRepo('rpc-recv');
        $marker = $this->tmpDir . '/rpc-recv-spawned';
        $this->withFakeGitOnPath('echo ok > ' . \escapeshellarg($marker) . '; cat >/dev/null');

        $start = \hrtime(true);
        $out = StatelessRpc::negotiate('git-receive-pack', $repo->path(), "junk\n", 1024);
        $elapsedSeconds = (\hrtime(true) - $start) / 1e9;

        self::assertNotNull($out);
        self::assertLessThan(self::BOUNDED_TEARDOWN_CEILING_SECONDS, $elapsedSeconds);
        self::assertFileExists($marker, 'receive-pack child never spawned — the census would be vacuous');
        $this->assertNoLiveChildren('after StatelessRpc::negotiate returned');
    }

    public function testHttpCappedOverflowTerminatesTheChildBounded(): void
    {
        // Fake git: swallow the body, emit far more than the cap, then stall.
        // The capped read overflows => the finally must TERMINATE the live
        // child instead of proc_close() waiting out the stall.
        $repo = $this->createGitRepo('http-cap');

        $this->withFakeGitOnPath(
            'cat >/dev/null; head -c 4096 /dev/zero; exec sleep ' . self::STALL_SECONDS
        );

        $dir = $this->tmpDir . '/capped';
        \mkdir($dir, 0755, true);
        \file_put_contents(
            $dir . '/config.yaml',
            "git: { listen_addr: \"127.0.0.1:0\", allow_anonymous_read: true }\nhttp: { max_pack_bytes: 64 }\n"
        );
        $server = new Server(Config::load($dir . '/config.yaml'));
        $server->registerRepo(Repo::new('http-cap.git', $repo->path())->withPublic(true));

        $start = \hrtime(true);
        $response = $server->handleRequest('POST', '/http-cap.git/git-upload-pack', '', [], 'want');
        $elapsedSeconds = (\hrtime(true) - $start) / 1e9;

        self::assertSame(413, $response['status']);
        self::assertLessThan(
            self::BOUNDED_TEARDOWN_CEILING_SECONDS,
            $elapsedSeconds,
            'the overflow path waited on a live child — no terminate fired (E721)'
        );
        $this->assertNoLiveChildren('after the capped 413 response');
    }

    // -------------------------------------------------------------------------
    // UploadPack delegated service — spawn, negotiation bytes, zero survivors
    // -------------------------------------------------------------------------

    public function testUploadPackServeCompletesNegotiationAndReapsItsChild(): void
    {
        $repo = $this->createGitRepo('serve-census');
        $head = $repo->refs()['refs/heads/main'] ?? null;
        $this->assertIsString($head);

        $inPath = $this->tmpDir . '/serve-in.bin';
        $outPath = $this->tmpDir . '/serve-out.bin';
        file_put_contents(
            $inPath,
            PktLine::encode("want {$head}\n") . PktLine::FLUSH . PktLine::encode("done\n")
        );

        $pack = new UploadPack($repo, User::new('probe')->withAdmin(true));
        $in = fopen($inPath, 'rb');
        $out = fopen($outPath, 'wb');
        $this->assertIsResource($in);
        $this->assertIsResource($out);

        self::assertSame(0, $pack->serve($in, $out));
        fclose($in);
        fclose($out);

        $bytes = (string) file_get_contents($outPath);
        self::assertMatchesRegularExpression(
            '/PACK\x00\x00\x00[\x02\x03]/',
            $bytes,
            'the delegated upload-pack must have streamed a real pack'
        );
        $this->assertNoLiveChildren('after the delegated upload-pack served a fetch');
    }

    // -------------------------------------------------------------------------
    // Census helpers
    // -------------------------------------------------------------------------

    /**
     * @return list<array{pid: int, stat: string, comm: string}>
     */
    private function liveChildren(): array
    {
        // Hermetic: read /proc directly. An exec()/ps census would observe
        // the very shell that carries the census as a phantom survivor.
        if (\PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('the survivor census reads /proc (Linux only)');
        }

        $rows = [];
        foreach ((array) \glob('/proc/[0-9]*') as $dir) {
            $stat = @\file_get_contents($dir . '/stat');
            if ($stat === false) {
                continue; // exited between glob and read
            }
            $open = \strpos($stat, '(');
            $close = \strrpos($stat, ')');
            if ($open === false || $close === false) {
                continue;
            }
            $fields = \preg_split('/\s+/', \trim(\substr($stat, $close + 1)));
            // fields after comm: state ppid ...
            if ((int) ($fields[1] ?? -1) !== \getmypid()) {
                continue;
            }
            $rows[] = [
                'pid' => (int) \basename($dir),
                'stat' => $fields[0] ?? '?',
                'comm' => \substr($stat, $open + 1, $close - $open - 1),
            ];
        }

        return $rows;
    }

    private function assertNoLiveChildren(string $stage): void
    {
        $children = $this->liveChildren();
        if ($children !== []) {
            $dump = \json_encode($children);
            self::fail("survivor(s) {$stage}: {$dump}");
        }

        if (\function_exists('pcntl_waitpid')) {
            $status = 0;
            $flags = \WNOHANG | (\defined('WUNTRACED') ? \WUNTRACED : 0);
            // -1/ECHILD is the ONLY acceptable answer: no child of this
            // process is waiting to be reaped.
            self::assertSame(
                -1,
                \pcntl_waitpid(-1, $status, $flags),
                "an un-reaped child existed {$stage}"
            );
        }
    }

    private function openFdCount(): ?int
    {
        if (!\is_dir('/proc/self/fd')) {
            return null;
        }

        return \count(\glob('/proc/self/fd/*') ?: []);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function withFakeGitOnPath(string $program): void
    {
        $bin = $this->tmpDir . '/fakebin';
        \mkdir($bin, 0755, true);
        \file_put_contents($bin . '/git', "#!/bin/sh\n" . $program . "\n");
        \chmod($bin . '/git', 0755);

        $this->savedPath = (string) \getenv('PATH');
        \putenv('PATH=' . $bin . ':' . $this->savedPath);
    }

    /** Create a real git repo with one commit so pack-objects has a target. */
    private function createGitRepo(string $name): Repo
    {
        $path = $this->tmpDir . '/repositories/' . $name;
        \mkdir($path, 0755, true);
        $escaped = \escapeshellarg($path);
        \exec("git -C {$escaped} -c init.defaultBranch=main init 2>&1");
        \file_put_contents($path . '/hello.txt', "hello\n");
        \exec("git -C {$escaped} add hello.txt 2>&1");
        \exec("git -C {$escaped} -c user.email=test@example.com -c user.name=Test commit -m init 2>&1");

        return Repo::new($name, $path)->withPublic(true);
    }

    /**
     * Self-contained packfile of a throwaway repo's single commit — same
     * builder shape as GitDaemonReceivePackTest (index-pack input).
     *
     * @return array{commit: string, pack: string}
     */
    private function buildSelfContainedPack(): array
    {
        $w = $this->tmpDir . '/work';
        \mkdir($w, 0755, true);
        \exec('git -C ' . \escapeshellarg($w) . ' -c init.defaultBranch=main init 2>&1');
        \file_put_contents($w . '/file.txt', "content\n");
        \exec('git -C ' . \escapeshellarg($w) . ' add file.txt 2>&1');
        \exec('git -C ' . \escapeshellarg($w) . ' -c user.email=t@example.com -c user.name=T commit -m c 2>&1');
        $commit = \trim((string) \shell_exec('git -C ' . \escapeshellarg($w) . ' rev-parse HEAD 2>/dev/null'));

        $revs = (string) \shell_exec('git -C ' . \escapeshellarg($w) . ' rev-list HEAD 2>/dev/null');
        $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = \proc_open(
            'git -C ' . \escapeshellarg($w) . ' pack-objects --stdout --revs 2>/dev/null',
            $desc,
            $pipes
        );
        $this->assertIsResource($proc);
        \fwrite($pipes[0], $revs);
        \fclose($pipes[0]);
        $pack = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);
        $this->assertStringStartsWith('PACK', $pack);

        return ['commit' => $commit, 'pack' => $pack];
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
     * @return array<string, string> relative src path => source
     */
    private function srcFiles(): array
    {
        $root = \dirname(__DIR__) . '/src';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[\substr($file->getPathname(), strlen($root) + 1)] = (string) \file_get_contents($file->getPathname());
            }
        }
        \ksort($files);

        return $files;
    }

    /**
     * Function/method bodies keyed by name, brace-matched from tokens so
     * strings and comments cannot skew the slice.
     *
     * @return array<string, string>
     */
    private function functionBodies(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $bodies = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_FUNCTION)) {
                continue;
            }
            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->is(T_STRING)) {
                    $name = $tokens[$j]->text;

                    break;
                }
                if ($tokens[$j]->is('(')) {
                    break; // closure: not a named site
                }
            }
            if ($name === null) {
                continue;
            }

            // Find the body's opening brace (skip the parameter list).
            $depth = 0;
            $bodyStart = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->is('(')) {
                    $depth++;
                } elseif ($tokens[$j]->is(')')) {
                    $depth--;
                } elseif ($depth === 0 && $tokens[$j]->is('{')) {
                    $bodyStart = $j;

                    break;
                }
            }
            if ($bodyStart === null) {
                continue; // abstract/interface member
            }

            $depth = 0;
            $text = '';
            for ($j = $bodyStart; $j < $count; $j++) {
                $text .= $tokens[$j]->text;
                if ($tokens[$j]->is('{')) {
                    $depth++;
                } elseif ($tokens[$j]->is('}')) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $bodies[$name] = $text;
        }

        return $bodies;
    }

    /** Strip comments so prose mentions of banned calls do not accuse. */
    private function codeOnly(string $source): string
    {
        $out = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            $out .= $token->text;
        }

        return $out;
    }
}
