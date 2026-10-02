<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\LSP\LspExchangeLock;
use SugarCraft\Crush\LSP\LspExchangeState;
use SugarCraft\Crush\LSP\LspResponse;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * Audit B7: an LspConnection is started in one process and used from
 * pcntl_fork()ed turns and sub-agents, all holding copies of one stdio pipe
 * trio. Process-unique ids alone left two failures: concurrent forks raced on
 * stdout and each discarded the other's reply (or read half of it), and a
 * process killed mid-exchange left half a frame on stdin or stdout that
 * desynchronised every later caller. The connection now serialises whole
 * exchanges under a cross-process lock and keeps the stream's state where the
 * next holder can repair it.
 *
 * Children report through temp files and leave through ForkedChild::exitNow()
 * (never a plain exit(), which would run PHPUnit's shutdown in the copy); every
 * wait is bounded, and only this test's own tracked children are signalled.
 */
final class LspConnectionForkSafetyTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private const CHILD_REPORT_DEADLINE = 20.0;

    /** Per-request timeout: what a lost reply costs the pre-fix code. */
    private const REQUEST_TIMEOUT = 5.0;

    /**
     * Single-threaded Content-Length server. Replies are QUEUED with a random
     * delay, so they may leave in a different order than the requests came in.
     *
     *  test/echo {n, note?}  reply {n} after 20-250 ms; with note, first push a
     *                        `test/note` notification
     *  test/slow             reply after 600 ms
     *  test/fast             reply at once
     *  test/split            write the first half of a reply frame now, the
     *                        rest 500 ms later (its second half names a bogus
     *                        Content-Length inside a string)
     *  test/ask              send a server REQUEST reusing the client's id,
     *                        then answer {answered} once the client replies
     *  test/stats            reply {big, bad}: complete test/big notifications
     *                        received, and frames that did not parse
     *  test/stall (notif.)   stop reading stdin for params.seconds
     *  test/big (notif.)     counted
     */
    private const FIXTURE = <<<'PHP'
        <?php
        $born = microtime(true);
        stream_set_blocking(STDIN, false);
        $buf = '';
        $queue = [];
        $stats = ['big' => 0, 'bad' => 0];
        $stallUntil = 0.0;
        $asked = null;
        $frame = static function (array $m): string {
            $j = json_encode($m);
            return 'Content-Length: ' . strlen($j) . "\r\n\r\n" . $j;
        };
        while (microtime(true) - $born < 30.0) {
            $now = microtime(true);
            foreach ($queue as $k => [$due, $raw]) {
                if ($due <= $now) {
                    fwrite(STDOUT, $raw);
                    fflush(STDOUT);
                    unset($queue[$k]);
                }
            }
            if ($now < $stallUntil) {
                usleep(5000);
                continue;
            }
            $r = [STDIN];
            $w = $e = null;
            if (@stream_select($r, $w, $e, 0, 5000) > 0) {
                $chunk = fread(STDIN, 65536);
                if (($chunk === '' || $chunk === false) && feof(STDIN)) {
                    exit(0);
                }
                $buf .= (string) $chunk;
            }
            while (($sep = strpos($buf, "\r\n\r\n")) !== false) {
                if (preg_match('/^Content-Length: (\d+)$/', substr($buf, 0, $sep), $m) !== 1) {
                    $stats['bad']++;
                    $buf = substr($buf, $sep + 4);
                    continue;
                }
                $len = (int) $m[1];
                if (strlen($buf) < $sep + 4 + $len) {
                    break;
                }
                $msg = json_decode(substr($buf, $sep + 4, $len), true);
                $buf = substr($buf, $sep + 4 + $len);
                if (!is_array($msg)) {
                    $stats['bad']++;
                    continue;
                }
                $method = $msg['method'] ?? null;
                $id = $msg['id'] ?? null;
                $params = $msg['params'] ?? [];
                $reply = static fn ($result): string => $frame(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
                if ($method === null) {
                    if ($asked !== null && ($msg['id'] ?? null) === $asked['server']) {
                        $answered = isset($msg['error']) || array_key_exists('result', $msg);
                        $queue[] = [0.0, $frame(['jsonrpc' => '2.0', 'id' => $asked['client'], 'result' => ['answered' => $answered]])];
                        $asked = null;
                    }
                    continue;
                }
                if ($id === null) {
                    if ($method === 'exit') {
                        exit(0);
                    } elseif ($method === 'test/stall') {
                        $stallUntil = microtime(true) + (float) ($params['seconds'] ?? 1.0);
                    } elseif ($method === 'test/big') {
                        $stats['big']++;
                    }
                    continue;
                }
                switch ($method) {
                    case 'test/echo':
                        if (!empty($params['note'])) {
                            $queue[] = [0.0, $frame(['jsonrpc' => '2.0', 'method' => 'test/note', 'params' => ['n' => $params['n']]])];
                        }
                        $queue[] = [$now + random_int(20, 250) / 1000, $reply(['n' => $params['n'] ?? '?'])];
                        break;
                    case 'test/slow':
                        $queue[] = [$now + 0.6, $reply(['n' => 'slow'])];
                        break;
                    case 'test/split':
                        $raw = $reply(['n' => 'split', 'text' => str_repeat('pad ', 128) . 'Content-Length: 7 ' . str_repeat('pad ', 16)]);
                        $half = intdiv(strlen($raw), 2);
                        $queue[] = [0.0, substr($raw, 0, $half)];
                        $queue[] = [$now + 0.5, substr($raw, $half)];
                        break;
                    case 'test/ask':
                        $serverId = is_numeric($id) ? (int) $id : $id;
                        $asked = ['server' => $serverId, 'client' => $id];
                        $queue[] = [0.0, $frame(['jsonrpc' => '2.0', 'id' => $serverId, 'method' => 'workspace/configuration', 'params' => ['items' => []]])];
                        break;
                    case 'test/stats':
                        $queue[] = [0.0, $reply($stats)];
                        break;
                    case 'shutdown':
                        $queue[] = [0.0, $reply(null)];
                        break;
                    default:
                        $queue[] = [0.0, $reply(['n' => $method === 'test/fast' ? 'fast' : $method])];
                }
            }
        }
        PHP;

    private string $script = '';

    private ?LspConnection $connection = null;

    /** @var array<int, string> pid => result file */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('fork safety needs ext-pcntl and ext-posix to fork and kill sharers');
        }

        $this->script = (string) tempnam(sys_get_temp_dir(), 'lsp_fork_safety_');
        file_put_contents($this->script, self::FIXTURE);

        $this->connection = new LspConnection(PHP_BINARY, [$this->script]);
        $this->connection->connect(PHP_BINARY, [], null, self::REQUEST_TIMEOUT);
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ($this->children as $file) {
            @unlink($file);
        }
        $this->children = [];

        $this->connection?->disconnect();
        $this->connection = null;
        if ($this->script !== '' && is_file($this->script)) {
            unlink($this->script);
        }

        parent::tearDown();
    }

    public function testConcurrentForkedCallersEachGetTheirOwnResult(): void
    {
        $connection = $this->connection();

        // Several rounds: one round of a broken transport can pass by luck.
        for ($round = 0; $round < 3; $round++) {
            $pids = [];
            foreach (['a', 'b', 'c', 'd'] as $tag) {
                $n = "r{$round}-{$tag}";
                $pids[$n] = $this->forkReporting(static fn (): string => self::n($connection->sendRequest('test/echo', ['n' => $n])));
            }

            foreach ($pids as $n => $pid) {
                self::assertSame($n, $this->reap($pid), "{$n} must receive its own result");
            }
        }

        self::assertSame('after', self::n($connection->sendRequest('test/echo', ['n' => 'after'])));
    }

    public function testAProcessKilledWhileWaitingDoesNotShiftLaterResults(): void
    {
        $connection = $this->connection();

        $victim = $this->forkReporting(static fn (): string => self::n($connection->sendRequest('test/slow', null)));
        usleep(200_000);
        $this->kill($victim);

        $next = $this->forkReporting(static fn (): string => self::n($connection->sendRequest('test/fast', null)));
        self::assertSame('fast', $this->reap($next), 'the next process must get the call it made');

        foreach (['one', 'two', 'three'] as $n) {
            self::assertSame($n, self::n($connection->sendRequest('test/echo', ['n' => $n])));
        }
    }

    public function testAProcessKilledHalfwayThroughReadingAFrameDoesNotDesyncTheStream(): void
    {
        $connection = $this->connection();

        // The fixture writes the first half of the reply at once and the rest
        // 500 ms later: at 250 ms the victim has consumed half a frame. The
        // orphaned second half is then waiting in the pipe AHEAD of the next
        // caller's reply — the stream starts mid-frame.
        $victim = $this->forkReporting(static fn (): string => self::n($connection->sendRequest('test/split', null)));
        usleep(250_000);
        $this->kill($victim);
        self::assertSame(LspExchangeState::PHASE_READING, $this->sharedState()->phase, 'the victim must die mid-read');
        usleep(400_000);

        self::assertSame('fast', self::n($connection->sendRequest('test/fast', null)));
        self::assertSame('again', self::n($connection->sendRequest('test/echo', ['n' => 'again'])));
        self::assertTrue($connection->isConnected());
    }

    public function testAProcessKilledHalfwayThroughWritingAFrameHasItsFrameFinished(): void
    {
        $connection = $this->connection();

        // The server stops reading, so a 1 MiB notification fills the pipe and
        // its writer is left part-way through the frame when it is killed.
        $connection->sendNotification('test/stall', ['seconds' => 0.8]);
        $victim = $this->forkReporting(static function () use ($connection): string {
            $connection->sendNotification('test/big', ['blob' => str_repeat('x', 1 << 20)]);

            return 'sent';
        });
        usleep(300_000);
        $this->kill($victim);
        $left = $this->sharedState();
        self::assertTrue($left->owesFrame(), 'the victim must die part-way through its frame');
        self::assertGreaterThan(0, $left->frameWritten);
        self::assertFalse($left->frameInflight);

        self::assertSame('after-kill', self::n($connection->sendRequest('test/echo', ['n' => 'after-kill'])));

        $stats = $connection->sendRequest('test/stats', null);
        self::assertFalse($stats->isError, (string) $stats->errorMessage);
        self::assertSame(
            ['big' => 1, 'bad' => 0],
            $stats->result,
            'the dead writer\'s frame must be completed byte-exactly, not overrun by the next request',
        );
        self::assertTrue($connection->isConnected());
    }

    public function testANotificationReadByOneProcessIsReplayedToTheOthersOnce(): void
    {
        $connection = $this->connection();

        /** @var list<string> $seen */
        $seen = [];
        $connection->onNotification(static function (string $method, ?array $params) use (&$seen): void {
            $seen[] = $method . ':' . ($params['n'] ?? '?');
        });

        $reader = $this->forkReporting(static function () use ($connection, &$seen): string {
            self::n($connection->sendRequest('test/echo', ['n' => 'A', 'note' => true]));

            return implode(',', $seen);
        });
        self::assertSame('test/note:A', $this->reap($reader), 'the process that read the notification gets it');

        self::assertSame('P', self::n($connection->sendRequest('test/echo', ['n' => 'P'])));
        self::assertSame(['test/note:A'], $seen, 'the owner must get the notification another process read');

        $later = $this->forkReporting(static function () use ($connection, &$seen): string {
            self::n($connection->sendRequest('test/echo', ['n' => 'B']));

            return implode(',', $seen);
        });
        self::assertSame('test/note:A', $this->reap($later), 'a process forked after the replay must not see it twice');
    }

    public function testAServerRequestReusingOurIdIsAnsweredAndNotTakenForTheResponse(): void
    {
        $connection = $this->connection();

        $response = $connection->sendRequest('test/ask', null);

        self::assertFalse($response->isError, (string) $response->errorMessage);
        self::assertSame(['answered' => true], $response->result);
    }

    public function testANonOwnerDisconnectLeavesTheSharedServerRunning(): void
    {
        $connection = $this->connection();

        $child = $this->forkReporting(static function () use ($connection): string {
            $up = $connection->isConnected() ? 'up' : 'down';
            $connection->disconnect();
            $connection->__destruct();

            return $up;
        });

        self::assertSame('up', $this->reap($child), 'a forked process must see the shared server as up');
        self::assertTrue($connection->isConnected(), 'a non-owner disconnect must not stop the server');
        self::assertSame('still', self::n($connection->sendRequest('test/echo', ['n' => 'still'])));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function connection(): LspConnection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    /** What the last holder left in the connection's shared state file. */
    private function sharedState(): LspExchangeState
    {
        $lock = (new \ReflectionProperty(LspConnection::class, 'lock'))->getValue($this->connection());
        self::assertInstanceOf(LspExchangeLock::class, $lock);

        return $lock->load();
    }

    private static function n(LspResponse $response): string
    {
        if ($response->isError) {
            return 'error:' . ($response->isTimeout() ? 'timeout' : (string) $response->errorMessage);
        }

        return (string) ($response->result['n'] ?? '');
    }

    /** @param \Closure(): string $work */
    private function forkReporting(\Closure $work): int
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'lsp-fork-test-');
        $pid = $this->forkTracked();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $this->runChild($work, $file);
        }

        $this->children[$pid] = $file;

        return $pid;
    }

    /** @param \Closure(): string $work */
    private function runChild(\Closure $work, string $file): never
    {
        try {
            file_put_contents($file, json_encode(['ok' => $work()]));
        } catch (\Throwable $thrown) {
            file_put_contents($file, json_encode(['thrown' => $thrown::class . ': ' . $thrown->getMessage()]));
        }

        ForkedChild::exitNow();
    }

    private function kill(int $pid): void
    {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);
        @unlink($this->children[$pid]);
        unset($this->children[$pid]);
    }

    private function reap(int $pid): string
    {
        $deadline = hrtime(true) + (int) (self::CHILD_REPORT_DEADLINE * 1e9);
        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (hrtime(true) >= $deadline) {
                $this->kill($pid);
                self::fail("child {$pid} did not finish within " . self::CHILD_REPORT_DEADLINE . 's');
            }
            usleep(10_000);
        }

        $this->forgetForkedChild($pid);
        $file = $this->children[$pid];
        unset($this->children[$pid]);
        $raw = (string) @file_get_contents($file);
        @unlink($file);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, "child {$pid} reported nothing");
        self::assertArrayNotHasKey('thrown', $decoded, (string) ($decoded['thrown'] ?? ''));

        return (string) ($decoded['ok'] ?? '');
    }
}
