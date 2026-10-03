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
        self::$failStore = null;
        if ($this->faultyDir !== null) {
            foreach (glob($this->faultyDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->faultyDir);
            $this->faultyDir = null;
        }
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

    /**
     * LspExchangeLock::store()/storeFrame() report a write that did not land
     * (a full or read-only temp filesystem). An exchange whose phase or frame
     * record never landed runs unprotected — killed part-way, it would leave
     * the next holder trusting a stale state — so it is refused before a byte
     * goes out, the rule sugar-mcp StdioMcpServer::exchange() applies to its
     * phase marker.
     */
    public function testAnExchangeWhoseStateCannotBeRecordedIsRefused(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes through a read-only directory');
        }

        $connection = $this->connection();
        $property = new \ReflectionProperty(LspConnection::class, 'lock');
        $original = $property->getValue($connection);
        self::assertInstanceOf(LspExchangeLock::class, $original);

        // The same connection, its lock set moved into a directory this test
        // can make read-only: the temp-then-rename every store needs fails.
        $dir = sys_get_temp_dir() . '/lsp-unrecordable-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $property->setValue($connection, LspExchangeLock::new('unrecordable', $dir));
        $original->destroy();

        try {
            chmod($dir, 0500);
            try {
                $connection->sendNotification('test/big', ['blob' => 'unrecorded']);
                $refused = $connection->sendRequest('test/fast', null);
            } finally {
                chmod($dir, 0700);
            }

            self::assertTrue($refused->isError, 'an exchange ran with a state it could not record: ' . json_encode($refused->result));
            self::assertFalse($refused->isTimeout(), 'the refusal must be immediate, not a wait');

            $stats = $connection->sendRequest('test/stats', null);
            self::assertFalse($stats->isError, (string) $stats->errorMessage);
            self::assertSame(['big' => 0, 'bad' => 0], $stats->result, 'the notification went out unprotected');
            self::assertTrue($connection->isConnected(), 'a refused exchange must not cost the session');
        } finally {
            $connection->disconnect();
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * A dead holder's untouched frame is owed, and the next holder's in-flight
     * mark for it does not land (a transient ENOSPC/EDQUOT). Nothing of it is
     * written, so it must stay owed with NO write in flight — the record the
     * exchange's closing store puts on disk. Leaving the unrecorded in-flight
     * mark in memory let that closing store record a write that never
     * happened, and the next holder latched the session broken for good.
     */
    public function testAnOwedFrameWhoseInflightMarkFailsStaysOwedWithNoWriteInFlight(): void
    {
        $connection = $this->connection();
        $lock = $this->faultyLock($connection);

        $owed = self::framed(['jsonrpc' => '2.0', 'method' => 'test/big', 'params' => ['blob' => 'owed']]);
        self::assertTrue($lock->storeFrame($owed));
        self::assertTrue($lock->store($lock->load()->withFrame(strlen($owed), 0, false)));

        self::$failStore = self::failOnce(static fn (LspExchangeState $s): bool => $s->frameInflight);
        $refused = $connection->sendRequest('test/fast', null);
        self::$failStore = null;

        self::assertTrue($refused->isError, 'the owed frame went out without its in-flight mark');
        $left = $this->sharedState();
        self::assertFalse($left->frameInflight, 'a write that never happened is recorded as in flight');
        self::assertFalse($left->broken);
        self::assertTrue($left->owesFrame(), 'the untouched frame must stay owed');

        self::assertSame('fast', self::n($connection->sendRequest('test/fast', null)));
        self::assertTrue($connection->isConnected(), 'the next holder latched the session broken');
        $stats = $connection->sendRequest('test/stats', null);
        self::assertSame(['big' => 1, 'bad' => 0], $stats->result, 'the owed frame was not finished exactly once');
    }

    /** Our own frame whose in-flight mark does not land is not sent, and nothing stays owed. */
    public function testAnOwnFrameWhoseInflightMarkFailsIsNotSent(): void
    {
        $connection = $this->connection();
        $this->faultyLock($connection);

        self::$failStore = self::failOnce(static fn (LspExchangeState $s): bool => $s->frameInflight);
        $connection->sendNotification('test/big', ['blob' => 'unmarked']);
        self::$failStore = null;

        $left = $this->sharedState();
        self::assertSame(0, $left->frameLength, 'an abandoned message of our own stays owed');
        self::assertFalse($left->broken);

        self::assertSame('fast', self::n($connection->sendRequest('test/fast', null)));
        $stats = $connection->sendRequest('test/stats', null);
        self::assertSame(['big' => 0, 'bad' => 0], $stats->result, 'the frame went out without its in-flight mark');
        self::assertTrue($connection->isConnected());
    }

    /** Our own frame whose start record does not land is not sent, and nothing stays owed. */
    public function testAnOwnFrameWhoseStartRecordFailsIsNotSent(): void
    {
        $connection = $this->connection();
        $this->faultyLock($connection);

        self::$failStore = self::failOnce(static fn (LspExchangeState $s): bool => $s->frameLength > 0);
        $connection->sendNotification('test/big', ['blob' => 'unrecorded']);
        self::$failStore = null;

        $left = $this->sharedState();
        self::assertSame(0, $left->frameLength);
        self::assertFalse($left->broken);

        self::assertSame('fast', self::n($connection->sendRequest('test/fast', null)));
        $stats = $connection->sendRequest('test/stats', null);
        self::assertSame(['big' => 0, 'bad' => 0], $stats->result, 'the frame went out without its start record');
        self::assertTrue($connection->isConnected());
    }

    /**
     * The READING mark does not land: the reply is not read (a holder killed
     * mid-frame would otherwise leave a CLEAN record over a stdout that no
     * longer starts at a boundary), and the session survives — the unread
     * reply carries an id nobody waits for and the next reader drops it.
     */
    public function testARequestWhoseReadingMarkFailsIsAnErrorAndTheSessionSurvives(): void
    {
        $connection = $this->connection();
        $this->faultyLock($connection);

        self::$failStore = self::failOnce(
            static fn (LspExchangeState $s): bool => $s->phase === LspExchangeState::PHASE_READING
        );
        $unread = $connection->sendRequest('test/echo', ['n' => 'unread']);
        self::$failStore = null;

        self::assertTrue($unread->isError, 'the reply was read without the READING mark: ' . json_encode($unread->result));
        self::assertFalse($unread->isTimeout(), 'the refusal must be immediate, not a wait');
        self::assertFalse($this->sharedState()->broken);

        self::assertSame('after', self::n($connection->sendRequest('test/echo', ['n' => 'after'])));
        self::assertTrue($connection->isConnected());
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private const FAULTY_SCHEME = 'lspfaultystore';

    /** What the wrapper strips to reach the real path. */
    public const FAULTY_URL_PREFIX = self::FAULTY_SCHEME . '://';

    /**
     * Decides, per state store the connection makes, whether it fails. Read
     * by the stream wrapper {@see faultyLock()} installs.
     *
     * @var (\Closure(LspExchangeState): bool)|null
     */
    public static ?\Closure $failStore = null;

    private ?string $faultyDir = null;

    /**
     * Move the connection's lock set behind a stream wrapper that passes every
     * operation through to a private directory, except a state store
     * {@see $failStore} rejects: its rename fails, as one on a full or
     * read-only temp filesystem would. A read-only directory cannot do this —
     * it fails EVERY store, so the branches after the first never run.
     */
    private function faultyLock(LspConnection $connection): LspExchangeLock
    {
        if (!in_array(self::FAULTY_SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::FAULTY_SCHEME, self::faultyWrapperClass());
        }

        $this->faultyDir = sys_get_temp_dir() . '/lsp-faulty-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->faultyDir, 0700);

        $property = new \ReflectionProperty(LspConnection::class, 'lock');
        $original = $property->getValue($connection);
        self::assertInstanceOf(LspExchangeLock::class, $original);
        $lock = LspExchangeLock::new('faulty', self::FAULTY_SCHEME . '://' . $this->faultyDir);
        $property->setValue($connection, $lock);
        $original->destroy();

        return $lock;
    }

    /**
     * @param \Closure(LspExchangeState): bool $matches
     *
     * @return \Closure(LspExchangeState): bool true for the first state that matches, never after
     */
    private static function failOnce(\Closure $matches): \Closure
    {
        $spent = false;

        return static function (LspExchangeState $state) use ($matches, &$spent): bool {
            if ($spent || !$matches($state)) {
                return false;
            }

            return $spent = true;
        };
    }

    /** @param array<string, mixed> $message */
    private static function framed(array $message): string
    {
        $json = (string) json_encode($message);

        return 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json;
    }

    /** @return class-string */
    private static function faultyWrapperClass(): string
    {
        $wrapper = new class () {
            /** @var resource|null */
            public $context;

            /** @var resource|null */
            private $handle;

            private static function real(string $url): string
            {
                return substr($url, strlen(LspConnectionForkSafetyTest::FAULTY_URL_PREFIX));
            }

            public function stream_open(string $url, string $mode, int $options, ?string &$opened): bool
            {
                $handle = @fopen(self::real($url), $mode);
                $this->handle = $handle === false ? null : $handle;

                return $this->handle !== null;
            }

            public function stream_read(int $count): string|false
            {
                return fread($this->handle, $count);
            }

            public function stream_write(string $data): int
            {
                return (int) fwrite($this->handle, $data);
            }

            public function stream_eof(): bool
            {
                return feof($this->handle);
            }

            public function stream_flush(): bool
            {
                return fflush($this->handle);
            }

            public function stream_close(): void
            {
                fclose($this->handle);
            }

            public function stream_seek(int $offset, int $whence): bool
            {
                return fseek($this->handle, $offset, $whence) === 0;
            }

            public function stream_tell(): int
            {
                return (int) ftell($this->handle);
            }

            public function stream_truncate(int $size): bool
            {
                return ftruncate($this->handle, $size);
            }

            public function stream_lock(int $operation): bool
            {
                return flock($this->handle, $operation);
            }

            /** @return array<int|string, int>|false */
            public function stream_stat(): array|false
            {
                return fstat($this->handle);
            }

            public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
            {
                return false;
            }

            public function stream_metadata(string $url, int $option, mixed $value): bool
            {
                return $option === STREAM_META_ACCESS ? chmod(self::real($url), (int) $value) : false;
            }

            /** @return array<int|string, int>|false */
            public function url_stat(string $url, int $flags): array|false
            {
                return @stat(self::real($url));
            }

            public function unlink(string $url): bool
            {
                return @unlink(self::real($url));
            }

            public function rename(string $from, string $to): bool
            {
                $source = self::real($from);
                $rule = LspConnectionForkSafetyTest::$failStore;
                if ($rule !== null && str_ends_with($to, '.state')
                    && $rule(LspExchangeState::decode((string) file_get_contents($source)))) {
                    return false;
                }

                return rename($source, self::real($to));
            }
        };

        return $wrapper::class;
    }

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
