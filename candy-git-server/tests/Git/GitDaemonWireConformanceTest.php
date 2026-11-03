<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Git\GitDaemon;

/**
 * End-to-end pins for the BLOCKING daemon transport — the code path the
 * review found dead-on-arrival (C-01: select watched the listener only, so
 * client bytes were never read; M-08: the request-line regex rejected the
 * real pkt-framed wire; M-09: single-shot writes truncated; C-08: floods
 * and newcomers evicted established clients).
 *
 * The daemon runs the real serve() loop in a proc_open child (KILL-leashed,
 * reaped in tearDown) bound to an ephemeral port; the child announces
 * "PORT <n>" on stdout after binding. Clients are raw sockets speaking the
 * pkt-framed git-daemon wire, and the advertisement is pinned against the
 * real `git upload-pack --advertise-refs` binary as a byte oracle.
 *
 * @covers \SugarCraft\GitServer\Git\GitDaemon
 */
final class GitDaemonWireConformanceTest extends TestCase
{
    private string $tmpDir;
    /** @var resource|null */
    private $proc = null;
    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-daemon-e2e-' . \uniqid('', true);
        \mkdir($this->tmpDir . '/repositories', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->stopChild();
        $this->removeDirectory($this->tmpDir);
    }

    public function testBlockingTransportAnswersAPktFramedUploadPackAdvertisement(): void
    {
        $port = $this->startDaemon(maxConnections: 4);

        $client = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 3.0);
        self::assertIsResource($client, "connect failed: {$errstr}");
        $response = $this->requestAndRead($client, 'repo.git');
        \fclose($client);

        // C-01 + M-08: bytes are READ off the client socket and the
        // pkt-framed (NUL-argumented) request line PARSES. The old loop
        // never selected on clients and the old regex never matched this wire.
        self::assertStringContainsString(' refs/heads/main', $response, 'advertisement must carry the ref line');
        // Grammar discriminator: real upload-pack frames the FIRST ref
        // ('<sha> SP HEAD') with a NUL before its capability list — no NUL
        // means caps glued into the refname, the pipe-delimited defect
        // class the handrolled advertisement suffered from.
        self::assertStringContainsString("HEAD\x00", $response, 'first ref line must NUL-separate its capabilities');
        self::assertStringContainsString('side-band-64k', $response);
        self::assertStringNotContainsString('report-status|', $response, 'caps must never be pipe-delimited (upload-pack splits on whitespace)');
        self::assertSame('0000', \substr($response, -4), 'advertisement ends with the ASCII-hex flush packet');
    }

    /**
     * The daemon's advertisement must equal REAL `git upload-pack
     * --advertise-refs` byte for byte — the delegation claim pinned as
     * an oracle, not paraphrased. (Completing a fetch past the
     * advertisement is the disclosed stateless-rpc follow-up; a full
     * `git clone` therefore cannot succeed here and is NOT claimed.)
     */
    public function testAdvertisementMatchesTheRealBinaryByteForByte(): void
    {
        if (!\is_executable('/usr/bin/git')) {
            self::markTestSkipped('e2e pins require the system git binary (host requirement)');
        }
        $port = $this->startDaemon(maxConnections: 4);

        $client = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 3.0);
        self::assertIsResource($client, "connect failed: {$errstr}");
        $response = $this->requestAndRead($client, 'repo.git');
        \fclose($client);

        [$rc, $expected] = $this->runLeashed([
            'git', '-C', $this->tmpDir . '/repo.git', 'upload-pack', '--advertise-refs', '.',
        ]);
        self::assertSame(0, $rc, 'the oracle upload-pack run itself must succeed');
        self::assertNotSame('', $expected, 'the oracle produced no advertisement (test premise)');
        self::assertSame($expected, $response, 'the daemon must relay the real advertisement verbatim');
    }

    public function testPreAuthFloodIsClosedSilentlyWithoutPoisoningLaterClients(): void
    {
        $port = $this->startDaemon(maxConnections: 4);

        $flood = @\stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $s1, 3.0);
        self::assertIsResource($flood);
        \stream_set_blocking($flood, false);
        @\fwrite($flood, \str_repeat('A', GitDaemon::MAX_REQUEST_BYTES + 8192));
        // The over-cap client must be CLOSED by the server (cap armed),
        // not merely unanswered: a neutered cap keeps the socket open
        // waiting for a newline that never comes, so an absence-of-bytes
        // assert alone would stay green under the mutation.
        $closed = false;
        $deadline = \microtime(true) + 3.0;
        while (\microtime(true) < $deadline) {
            $chunk = @\fread($flood, 1024);
            if ($chunk === false || \feof($flood)) {
                $closed = true;
                break;
            }
            self::assertSame('', $chunk, 'an over-cap pre-auth flood gets no service');
            \usleep(2000);
        }
        \fclose($flood);
        self::assertTrue($closed, 'the pre-auth byte cap must close the flooding client (C-08)');

        $response = $this->request('repo.git', port: $port);
        self::assertStringContainsString('refs/heads/main', $response, 'the daemon survives pre-auth floods');
    }

    public function testCapacityRefusesTheNewcomerAndKeepsEstablishedTransfers(): void
    {
        // Two seats. Client A holds one and stays silent (no request line
        // yet); client B is the third newcomer: the OLD policy killed A
        // mid-existence, the NEW policy drops only B without a byte.
        $port = $this->startDaemon(maxConnections: 2);

        $a = @\stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $s1, 3.0);
        $b = @\stream_socket_client("tcp://127.0.0.1:{$port}", $e2, $s2, 3.0);
        self::assertIsResource($a);
        self::assertIsResource($b);

        // Seat A with a served request, but KEEP the socket object — A is
        // established; a newcomer must never displace it.
        $response = $this->requestAndRead($a, 'repo.git');
        self::assertStringContainsString('refs/heads/main', $response, 'established clients survive a full house');
        \fclose($a);

        // Fill both seats with idle newcomers, then probe a third: it gets
        // refused (closed without service) while the two ahead of it live.
        $c = @\stream_socket_client("tcp://127.0.0.1:{$port}", $e3, $s3, 3.0);
        $d = @\stream_socket_client("tcp://127.0.0.1:{$port}", $e4, $s4, 3.0);
        self::assertIsResource($c);
        self::assertIsResource($d);

        $refused = @\stream_socket_client("tcp://127.0.0.1:{$port}", $e5, $s5, 3.0);
        self::assertIsResource($refused);
        $seen = '';
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline) {
            $chunk = @\fread($refused, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $seen .= $chunk;
        }
        self::assertSame('', $seen, 'the over-capacity newcomer is refused without a byte of service');
        \fclose($refused);
        \fclose($c);
        \fclose($d);
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    private function startDaemon(int $maxConnections, bool $seed = false): int
    {
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: \"E2E\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 8, max_connections: {$maxConnections}, allow_anonymous_read: true }\n"
        );

        // Spawn php DIRECTLY (no timeout wrapper): a wrapper would make
        // stopChild's proc_terminate kill only `timeout`, orphaning the
        // daemon grandchild inside serve(). The harness self-leashes with
        // its own SIGALRM instead.
        $cmd = [
            \PHP_BINARY,
            __DIR__ . '/daemon-e2e-child.php',
            $this->tmpDir,
            $seed ? '1' : '0',
            (string) $maxConnections,
        ];
        $pipes = [];
        $proc = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertNotFalse($proc, 'failed to spawn daemon child');
        $this->proc = $proc;
        $this->pipes = $pipes;

        \stream_set_timeout($pipes[1], 8);
        $line = \fgets($pipes[1]);
        self::assertIsString($line, 'daemon child never announced its port');
        self::assertMatchesRegularExpression('/^PORT \d+$/', \trim($line), 'unexpected child handshake: ' . \trim($line));

        return (int) \explode(' ', \trim($line))[1];
    }

    /** Open a fresh client, send a pkt-framed upload-pack request, read the reply. */
    private function request(string $repoName, int $port): string
    {
        $client = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 3.0);
        self::assertIsResource($client, "connect failed: {$errstr}");
        $response = $this->requestAndRead($client, $repoName);
        \fclose($client);

        return $response;
    }

    /**
     * @param resource $client
     */
    private function requestAndRead($client, string $repoName): string
    {
        $payload = "git-upload-pack /{$repoName}\0host=localhost\0";
        @\fwrite($client, \sprintf('%04x', \strlen($payload) + 4) . $payload);

        $response = '';
        $deadline = \microtime(true) + 8.0;
        while (\microtime(true) < $deadline) {
            $chunk = @\fread($client, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $response .= $chunk;
                if (\str_ends_with($response, '0000')) {
                    break; // complete advertisement (ends with flush pkt)
                }
                continue;
            }
            if (\feof($client)) {
                break;
            }
            \usleep(2000);
        }

        return $response;
    }

    /**
     * @param list<string> $argv
     * @return array{0:int,1:string,2:string}
     */
    private function runLeashed(array $argv): array
    {
        $cmd = ['timeout', '-s', 'KILL', '25', ...$argv];
        $proc = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertNotFalse($proc);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $out, $err];
    }

    private function stopChild(): void
    {
        if ($this->proc === null) {
            return;
        }
        @\proc_terminate($this->proc);            // TERM
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline) {
            $status = \proc_get_status($this->proc);
            if (!$status['running']) {
                break;
            }
            \usleep(20000);
        }
        $status = \proc_get_status($this->proc);
        if ($status['running']) {
            @\proc_terminate($this->proc, 9);     // 9 = SIGKILL, no pcntl constant needed
        }
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
        \proc_close($this->proc);
        $this->proc = null;
        $this->pipes = [];
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
}
