<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\Git\GitDaemon;
use SugarCraft\Serve\Git\UploadPack;
use SugarCraft\Serve\HttpSmartProtocol\Server;
use SugarCraft\Serve\Repo;
use SugarCraft\Serve\User;

/**
 * E721 child-lifetime census: every git child candy-serve spawns is
 * REQUEST-scoped — drained, terminated-if-undrained, and proc_close()d
 * inside the handler that spawned it — so neither daemon stop nor an
 * unwinding request can leave a survivor.
 *
 * The census is a live-descendant ps scan of THIS process (plus a
 * pcntl_waitpid drain check where ext-pcntl is loaded), and it carries a
 * known-positive arm: a planted running child MUST be visible, so a zero
 * result is evidence and not a blind reader. The UploadPack path writes to
 * the process STDOUT constant and therefore runs in a probe php child that
 * censuses its own descendants and reports on stderr.
 *
 * @covers \SugarCraft\Serve\Git\GitDaemon
 * @covers \SugarCraft\Serve\Git\UploadPack
 * @covers \SugarCraft\Serve\HttpSmartProtocol\Server
 */
final class ChildProcessLifetimeCensusTest extends TestCase
{
    /** Seconds the stalled fake-git child outlives its terminated teardown. */
    private const STALL_SECONDS = 30;

    /** Ceiling for "the terminate fired" timing arms; the stall is 30s. */
    private const BOUNDED_TEARDOWN_CEILING_SECONDS = 8.0;

    /** Every proc_open site the E721 census accounted for, by file. */
    private const SPAWN_SITES_BY_FILE = [
        'Git/GitDaemon.php' => 2,          // sendPack(), unpackObjects()
        'Git/UploadPack.php' => 1,         // sendPack()
        'HttpSmartProtocol/Server.php' => 3, // two live handlers + dormant generatePackData
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
            "name: \"Test\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 3, max_connections: 8 }\n"
        );
        $this->config = Config::load($this->tmpDir . '/config.yaml');
    }

    protected function tearDown(): void
    {
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
            foreach ($this->functionBodies($source) as $name => $body) {
                $spawned = \preg_match_all('/\bproc_open\s*\(/', $body) ?: 0;
                if ($spawned === 0) {
                    continue;
                }
                $perFile += $spawned;
                self::assertGreaterThan(
                    0,
                    \preg_match_all('/\bproc_close\s*\(/', $body),
                    "{$relative}::{$name} spawns a child without proc_close() in scope"
                );
                self::assertStringContainsString(
                    'finally',
                    $body,
                    "{$relative}::{$name} reaps outside a finally (E721 contract)"
                );
                self::assertStringContainsString(
                    'proc_terminate',
                    $body,
                    "{$relative}::{$name} has no bounded early-exit for a stalled child (E721)"
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
    // GitDaemon::sendPack — normal drain reaps in scope
    // -------------------------------------------------------------------------

    public function testSendPackReapsItsPackChildWithZeroSurvivors(): void
    {
        $repo = $this->createGitRepo('send-pack-reap');
        $head = $repo->refs()['refs/heads/main'] ?? null;
        $this->assertIsString($head);

        $daemon = new GitDaemon($this->config);
        $socket = \fopen('php://memory', 'r+');
        $this->assertIsResource($socket);

        try {
            $method = new \ReflectionMethod(GitDaemon::class, 'sendPack');
            $method->setAccessible(true);
            $method->invoke($daemon, $socket, $repo, [$head]);

            \rewind($socket);
            $written = (string) \stream_get_contents($socket);
        } finally {
            if (\is_resource($socket)) {
                \fclose($socket);
            }
        }

        self::assertStringStartsWith('PACK', $written, 'the real pack-objects child must have streamed its pack');
        $this->assertNoLiveChildren('after sendPack drained its child');
    }

    // -------------------------------------------------------------------------
    // GitDaemon::sendPack — unwinding drain terminates the stalled child
    // -------------------------------------------------------------------------

    public function testSendPackTerminatesTheChildWhenTheDrainUnwinds(): void
    {
        // Fake git: swallow the wants, emit one chunk, then stall forever.
        // The real drain would never end; the TypeError thrown by writeRaw
        // (a stdClass is not a socket) unwinds the finally, which must
        // terminate the stalled child instead of waiting it out.
        $this->withFakeGitOnPath(
            'cat >/dev/null; head -c 4096 /dev/zero; exec sleep ' . self::STALL_SECONDS
        );

        $repo = Repo::new('fake', $this->tmpDir . '/repositories');
        $daemon = new GitDaemon($this->config);

        $start = \hrtime(true);
        $threw = null;
        try {
            $method = new \ReflectionMethod(GitDaemon::class, 'sendPack');
            $method->setAccessible(true);
            // writeRaw() takes a resource|\Socket; an object forces the TypeError
            // that simulates any future throwing statement inside the drain.
            $method->invoke($daemon, new \stdClass(), $repo, [\str_repeat('a', 40)]);
        } catch (\TypeError $e) {
            $threw = $e;
        }
        $elapsedSeconds = (\hrtime(true) - $start) / 1e9;

        self::assertNotNull($threw, 'the drain body must still unwind (test premise)');
        self::assertLessThan(
            self::BOUNDED_TEARDOWN_CEILING_SECONDS,
            $elapsedSeconds,
            'proc_close() waited on the stalled child — the finally did not terminate it (E721)'
        );
        $this->assertNoLiveChildren('after the unwinding sendPack teardown');
    }

    // -------------------------------------------------------------------------
    // GitDaemon::unpackObjects — index-pack child reaps in scope
    // -------------------------------------------------------------------------

    public function testUnpackObjectsReapsItsIndexPackChild(): void
    {
        $push = $this->buildSelfContainedPack();
        $dest = $this->tmpDir . '/repositories/unpack-dest';
        \mkdir($dest, 0755, true);
        \exec('git -c init.defaultBranch=main init --bare ' . \escapeshellarg($dest) . ' 2>/dev/null');

        $daemon = new GitDaemon($this->config);
        $method = new \ReflectionMethod(GitDaemon::class, 'unpackObjects');
        $method->setAccessible(true);
        /** @var array{0: bool, 1: string} $verdict */
        $verdict = $method->invoke($daemon, $dest, $push['pack']);

        self::assertTrue($verdict[0], 'index-pack failed: ' . $verdict[1]);
        $this->assertNoLiveChildren('after unpackObjects reaped index-pack');
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
        \fwrite($client, "git-upload-pack /stop-census\nwant {$head}\ndone\n");

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

        // The request carried a real pack-objects child end to end...
        self::assertStringContainsString('refs/heads/main', $response);
        self::assertStringContainsString('PACK', $response);
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

    public function testHttpReceivePackRequestReapsWithinScope(): void
    {
        // Anonymous canWrite() is fail-closed false, so the route would 403
        // BEFORE spawning; an admin Basic-auth header carries the request to
        // the real child path. Marker proves the spawn; census proves reap.
        $repo = $this->createGitRepo('http-recv');
        $marker = $this->tmpDir . '/recv-spawned';
        $this->withFakeGitOnPath('echo ok > ' . \escapeshellarg($marker) . '; cat >/dev/null');

        $server = new Server($this->config);
        $server->registerRepo(Repo::new('http-recv.git', $repo->path())->withPublic(true));
        $server->registerUser(User::new('pusher')->withAdmin(true)->withPassword('pushpass'));

        $response = $server->handleRequest(
            'POST',
            '/http-recv.git/git-receive-pack',
            '',
            ['Authorization' => 'Basic ' . \base64_encode('pusher:pushpass')],
            "junk-not-a-negotiation\n"
        );

        self::assertSame(200, $response['status']);
        self::assertFileExists($marker, 'receive-pack child never spawned — the census would be vacuous');
        $this->assertNoLiveChildren('after handleReceivePack returned');
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
            "git: { listen_addr: \"127.0.0.1:0\" }\nhttp: { max_pack_bytes: 64 }\n"
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
    // UploadPack CGI path — probe process censuses its own descendants
    // -------------------------------------------------------------------------

    public function testUploadPackSendPackReapsWithinTheSpawningProcess(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('the probe self-census reads /proc (Linux only)');
        }

        $repo = $this->createGitRepo('cgi-census');
        $head = $repo->refs()['refs/heads/main'] ?? null;
        $this->assertIsString($head);

        $probe = $this->tmpDir . '/upload-pack-probe.php';
        \file_put_contents($probe, $this->uploadPackProbeSource());

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmd = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($probe)
            . ' ' . \escapeshellarg($repo->path()) . ' ' . \escapeshellarg($head);
        $proc = \proc_open($cmd, $desc, $pipes);
        $this->assertIsResource($proc);

        // Drain both streams to EOF (the probe writes the pack to stdout and
        // the SURVIVORS marker to stderr, then exits).
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \fclose($pipes[0]);
        $rc = \proc_close($proc);

        self::assertSame(0, $rc, 'probe failed: ' . $stderr);
        self::assertStringStartsWith('PACK', $stdout, 'the CGI sendPack child must have streamed its pack');
        self::assertStringContainsString('SURVIVORS:0', $stderr, 'self-census inside the probe: ' . $stderr);
    }

    private function uploadPackProbeSource(): string
    {
        $autoload = \escapeshellarg(\dirname(__DIR__) . '/vendor/autoload.php');

        return <<<PHP
            <?php
            declare(strict_types=1);
            require {$autoload};

            use SugarCraft\\Serve\\Git\\UploadPack;
            use SugarCraft\\Serve\\Repo;

            \$repo = Repo::new('probe', \$argv[1]);
            \$pack = new UploadPack(\$repo);
            \$send = new ReflectionMethod(UploadPack::class, 'sendPack');
            \$send->setAccessible(true);
            \$send->invoke(\$pack, [\$argv[2]]);

            \$mine = getmypid();
            \$survivors = 0;
            foreach (glob('/proc/[0-9]*') ?: [] as \$dir) {
                \$stat = @file_get_contents(\$dir . '/stat');
                if (\$stat === false) {
                    continue;
                }
                \$close = strrpos(\$stat, ')');
                if (\$close === false) {
                    continue;
                }
                \$fields = preg_split('/\\s+/', trim(substr(\$stat, \$close + 1)));
                if ((int) (\$fields[1] ?? -1) === \$mine) {
                    \$survivors++;
                }
            }
            fwrite(STDERR, 'SURVIVORS:' . \$survivors . "\\n");
            PHP;
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
            $open  = \strpos($stat, '(');
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
                'pid'  => (int) \basename($dir),
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
