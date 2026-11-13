<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Mysql\MysqlClient;
use React\Mysql\MysqlResult;
use React\Promise\Deferred;
use SugarCraft\Sql\Admin\QueryTimeout;
use SugarCraft\Sql\Admin\ReactMysqlConnection;
use SugarCraft\Sql\Admin\ReactPostgresConnection;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Guards the PDO-DSN → react/mysql-URI translation plus the cancel/timeout
 * lifecycle (admindata-003/-014/-015).
 *
 * react/mysql's Factory runs the URI through parse_url() and connects to
 * $parts['host'], requiring a `mysql://` scheme. A scheme-less URI mis-parses
 * (the username becomes the "scheme", host is empty) and every connect is
 * "refused" — so this test pins the scheme and the host/port/credential
 * round-trip. dsnToUri() is exercised in isolation (no socket/loop).
 *
 * The lifecycle legs drive a socket-less MysqlClient subclass on a local
 * StreamSelectLoop: nothing ever opens, the deadline legs are short, and the
 * loop exits when its timers clear — proving fd/watcher behaviour without a
 * server.
 */
final class ReactMysqlConnectionTest extends TestCase
{
    /** Build the react/mysql URI dsnToUri() would hand the driver. */
    private function uriFor(string $dsn, string $user, string $pass): string
    {
        $ref = new \ReflectionClass(ReactMysqlConnection::class);
        $conn = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('dsnToUri');
        $method->setAccessible(true);

        return $method->invoke($conn, $dsn, $user, $pass);
    }

    public function testUriCarriesTheRequiredMysqlScheme(): void
    {
        $uri = $this->uriFor('mysql:host=db.example.com;port=3306;dbname=shop', 'appuser', 'secret');

        $this->assertStringStartsWith('mysql://', $uri);
    }

    public function testUriParsesToTheRemoteHostNotLocalhost(): void
    {
        $uri = $this->uriFor('mysql:host=db.example.com;port=3307;dbname=shop', 'appuser', 'secret');
        $parts = parse_url($uri);

        $this->assertSame('mysql', $parts['scheme'] ?? null);
        $this->assertSame('db.example.com', $parts['host'] ?? null, 'host must survive — not default to localhost');
        $this->assertSame(3307, $parts['port'] ?? null);
    }

    public function testZeroPortIsNormalisedToTheDefault(): void
    {
        // PDO tolerates port 0 (→3306); react/mysql connects to literal :0 and
        // is refused, so dsnToUri must substitute the default.
        $uri = $this->uriFor('mysql:host=mysqlcluster1;port=0;dbname=my', 'replication_user', 'secret');
        $parts = parse_url($uri);

        $this->assertSame('mysqlcluster1', $parts['host'] ?? null);
        $this->assertSame(3306, $parts['port'] ?? null, 'port 0 must not reach the driver');
    }

    public function testCredentialsAndDbnameRoundTripThroughUrlEncoding(): void
    {
        // react/mysql rawurldecode()s user/pass/path, so special chars must be encoded.
        $uri = $this->uriFor('mysql:host=h;port=3306;dbname=my db', 'user@corp', 'p@ss:w/rd');
        $parts = parse_url($uri);

        $this->assertSame('user@corp', rawurldecode($parts['user'] ?? ''));
        $this->assertSame('p@ss:w/rd', rawurldecode($parts['pass'] ?? ''));
        $this->assertSame('my db', rawurldecode(ltrim($parts['path'] ?? '', '/')));
    }

    /**
     * admindata-008 (SQL-A07): an empty username used to substitute 'root',
     * silently dialing the admin path as the server's most powerful local
     * identity. The real constructor (not the reflection bypass) must refuse.
     */
    public function testEmptyUsernameRefusesTheGuessedRootDial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty username');

        new ReactMysqlConnection('mysql:host=h;port=3306;dbname=d', '', 'p');
    }

    /** The refusal is only for the empty sentinel — a real user still dials. */
    public function testExplicitUsernameDialsUnchanged(): void
    {
        $connection = new ReactMysqlConnection('mysql:host=h;port=3306;dbname=d', 'appuser', 'p');

        $this->assertInstanceOf(ReactMysqlConnection::class, $connection);
    }

    /**
     * Pins the query-deadline API on both async wrappers: an optional float
     * defaulting to QueryTimeout::DEFAULT_SECONDS, so existing
     * `new ReactMysqlConnection($dsn,$u,$p)` call sites get the protective
     * deadline without changes. Timeout semantics themselves are covered by
     * QueryTimeoutTest.
     */
    public function testQueryTimeoutParameterDefaultsToTheSharedDeadline(): void
    {
        foreach ([ReactMysqlConnection::class, ReactPostgresConnection::class] as $class) {
            $timeout = null;
            foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $param) {
                if ($param->getName() === 'queryTimeout') {
                    $timeout = $param;
                }
            }

            $this->assertNotNull($timeout, $class);
            $this->assertSame(QueryTimeout::DEFAULT_SECONDS, $timeout->getDefaultValue(), $class);
        }
    }

    /**
     * Pins the cancellation API (plan 5.1) on the AsyncConnection contract and
     * both implementations: query() accepts an optional sugar-async
     * CancellationToken so callers can abort in-flight queries cooperatively.
     */
    public function testQueryAcceptsAnOptionalCancellationToken(): void
    {
        foreach ([
            \SugarCraft\Sql\Admin\AsyncConnection::class,
            ReactMysqlConnection::class,
            ReactPostgresConnection::class,
        ] as $class) {
            $params = (new \ReflectionMethod($class, 'query'))->getParameters();

            $this->assertSame('cancellation', $params[1]->getName(), $class);
            $this->assertSame(\SugarCraft\Async\CancellationToken::class, (string) $params[1]->getType()->getName(), $class);
            $this->assertTrue($params[1]->allowsNull(), $class);
            $this->assertNull($params[1]->getDefaultValue(), $class);
        }
    }

    /**
     * Server-side cancellation (plan 5.1): with the thread id known, the KILL
     * path opens a SEPARATE short-lived connection (the main one is busy
     * executing the very query being killed) and issues `KILL QUERY <id>`
     * with the int-typed id interpolated (MySQL's KILL takes no placeholders).
     * A settled KILL means the server is alive, so the killer quits gracefully.
     */
    public function testKillPathIssuesKillQueryOnASeparateConnection(): void
    {
        $spy = $this->recordingClient();

        $factoryCalls = 0;
        $conn = $this->connectionWith(
            threadId: 42,
            killClientFactory: function (string $uri, $loop) use ($spy, &$factoryCalls) {
                $factoryCalls++;
                $this->assertStringStartsWith('mysql://', $uri, 'killer reuses the same connection URI');
                return $spy;
            },
        );

        $kill = new \ReflectionMethod($conn, 'killQueryOnServer');
        $kill->invoke($conn);

        $this->assertSame(1, $factoryCalls, 'exactly one side-channel connection');
        $this->assertSame(['KILL QUERY 42', '(quit)'], $spy->issued);
    }

    /**
     * admindata-003: the same black-holed route the query deadline exists for
     * can swallow the KILL itself. The killer leg therefore runs under its own
     * short deadline and the side-channel is force-CLOSED on the never-
     * settling arm — without that wrap the killer client, its socket and its
     * loop watchers leak on every timed-out cancel.
     */
    public function testKillPathClosesTheKillerWhenTheKillBlackHoles(): void
    {
        $spy = $this->recordingClient();
        $spy->hold = new Deferred(); // the KILL answer never arrives

        $conn = $this->connectionWith(
            threadId: 42,
            killClientFactory: static fn (): MysqlClient => $spy,
            killTimeout: 0.02,
        );

        $loop = $this->loopOf($conn);
        $kill = new \ReflectionMethod($conn, 'killQueryOnServer');
        $kill->invoke($conn);
        $loop->run(); // 0.02 s kill-deadline fires, closes the killer, loop empties

        $this->assertSame(['KILL QUERY 42', '(close)'], $spy->issued, 'never-settled KILL must force-close the side-channel');
    }

    /**
     * A KILL that comes back rejected (no privilege, id gone) still tears the
     * side-channel down — the error arm closes, it does not "quit" a session
     * it no longer trusts.
     */
    public function testKillPathClosesTheKillerWhenTheKillIsRejected(): void
    {
        $spy = $this->recordingClient();
        $spy->rejectNext = true;

        $conn = $this->connectionWith(
            threadId: 42,
            killClientFactory: static fn (): MysqlClient => $spy,
        );

        $kill = new \ReflectionMethod($conn, 'killQueryOnServer');
        $kill->invoke($conn);

        $this->assertSame(['KILL QUERY 42', '(close)'], $spy->issued);
    }

    /**
     * Without a known thread id (CONNECTION_ID() not yet resolved), no
     * side-channel connection is opened at all — cancellation degrades to
     * client-side for THIS abort.
     */
    public function testKillPathIsANoOpWhenThreadIdIsUnknown(): void
    {
        $factoryCalls = 0;
        $main = $this->recordingClient();
        $conn = $this->connectionWith(
            threadId: null,
            killClientFactory: function () use (&$factoryCalls) {
                $factoryCalls++;
                return null;
            },
            client: $main,
        );

        $kill = new \ReflectionMethod($conn, 'killQueryOnServer');
        $kill->invoke($conn);

        $this->assertSame(0, $factoryCalls, 'no KILL side-channel without a thread id');
    }

    /**
     * admindata-014: a failed CONNECTION_ID() probe must not freeze the
     * wrapper into client-side-only cancellation for its whole life. The
     * rejection arm re-arms the latch, so every later cancel (or query)
     * re-requests the id and the NEXT abort can go server-side.
     */
    public function testCancelWithUnknownIdReProbesAndReArmsAfterFailure(): void
    {
        $main = $this->recordingClient();
        $main->rejectProbe = true;

        $conn = $this->connectionWith(threadId: null, killClientFactory: static fn () => null, client: $main);

        // First request fails to answer (cold race/privilege). In the old
        // shape the latch stayed up forever and this was the last probe the
        // wrapper ever sent.
        $request = new \ReflectionMethod($conn, 'requestThreadId');
        $request->invoke($conn);
        $latch = new \ReflectionProperty($conn, 'threadIdRequested');
        $this->assertFalse($latch->getValue($conn), 'a failed probe re-arms the latch (no permanent one-shot)');

        $kill = new \ReflectionMethod($conn, 'killQueryOnServer');
        $kill->invoke($conn);
        $kill->invoke($conn);

        $this->assertSame(
            ['SELECT CONNECTION_ID() AS id', 'SELECT CONNECTION_ID() AS id', 'SELECT CONNECTION_ID() AS id'],
            $main->issued,
            'probe is retried on the first request and on every cancel with an unknown id',
        );
    }

    /**
     * admindata-015: when the deadline fires the driver leg is provably not
     * settling — react/mysql keeps the queued command and its buffered rows
     * pending on the SAME client forever (one wedged leg per timeout). The
     * timeout arm must close the client and re-mint a fresh one for later
     * queries, and drop the stale thread-id state with it.
     */
    public function testDeadlineTimeoutClosesAndRemintsTheMainClient(): void
    {
        $loop = new StreamSelectLoop();
        $clients = [];
        $clientFactory = static function (string $uri, LoopInterface $l) use (&$clients): MysqlClient {
            $spy = new class extends MysqlClient {
                public bool $closed = false;
                public function __construct()
                {
                    // no socket: this spy never dials
                }

                public function query($sql, array $params = [])
                {
                    $d = new Deferred();
                    return $d->promise(); // nothing ever settles — the deadline must fire
                }

                public function close()
                {
                    $this->closed = true;
                }
            };
            $clients[] = $spy;
            return $spy;
        };

        $conn = new ReactMysqlConnection(
            'mysql:host=h;dbname=d',
            'u',
            'p',
            $loop,
            0.02,
            killClientFactory: static fn () => $this->fail('no KILL without a thread id'),
            clientFactory: $clientFactory,
        );

        $error = null;
        $conn->query('SELECT SLEEP(60)')->then(static fn () => null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $loop->run();

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('timed out', $error->getMessage());
        $this->assertCount(2, $clients, 'the wedged client is replaced by a fresh dial');
        $this->assertTrue($clients[0]->closed, 'the never-settling client is closed, not abandoned');

        $threadId = new \ReflectionProperty($conn, 'threadId');
        $latch = new \ReflectionProperty($conn, 'threadIdRequested');
        $this->assertNull($threadId->getValue($conn), 'the fresh session gets a fresh probe (old id is gone)');
        $this->assertFalse($latch->getValue($conn), 'probe latch released for the new client');
    }

    /**
     * The token-cancel route must NOT invalidate the client: `KILL QUERY`
     * answers 1317 on the target connection and the statement ends — the
     * socket itself is fine, and killing the client here would punish the
     * session for a cooperative cancel.
     */
    public function testTokenCancelLeavesTheMainClientAlive(): void
    {
        $loop = new StreamSelectLoop();
        $clients = [];
        $clientFactory = static function (string $uri, LoopInterface $l) use (&$clients): MysqlClient {
            $spy = new class extends MysqlClient {
                public bool $closed = false;
                public function __construct()
                {
                }

                public function query($sql, array $params = [])
                {
                    if (str_contains((string) $sql, 'CONNECTION_ID')) {
                        $r = new MysqlResult();
                        $r->resultRows = [['id' => 77]];
                        return resolve($r);
                    }

                    return (new Deferred())->promise();
                }

                public function close()
                {
                    $this->closed = true;
                }
            };
            $clients[] = $spy;
            return $spy;
        };

        $killer = $this->recordingClient();
        $conn = new ReactMysqlConnection(
            'mysql:host=h;dbname=d',
            'u',
            'p',
            $loop,
            30.0, // generous: only the token fires here
            killClientFactory: static fn () => $killer,
            clientFactory: $clientFactory,
        );

        $source = \SugarCraft\Async\CancellationSource::new();
        $settled = null;
        $conn->query('SELECT SLEEP(60)', $source->token())->then(
            static function () use (&$settled): void {
                $settled = 'resolve';
            },
            static function (\Throwable $e) use (&$settled): void {
                $settled = $e::class;
            },
        );
        $source->cancel();

        $this->assertSame(\SugarCraft\Sql\Admin\QueryCancelledException::class, $settled);
        $this->assertSame(['KILL QUERY 77', '(quit)'], $killer->issued, 'server-side abort still goes out');
        $this->assertCount(1, $clients, 'no re-mint: the cooperative cancel keeps the session');
        $this->assertFalse($clients[0]->closed, 'main client untouched by a token cancel');
    }

    /**
     * A MysqlClient whose socket layer is replaced by call recording. The
     * probe arm answers id 42 (or rejects when told), user queries settle
     * with a null result (or never, when $hold is set).
     */
    private function recordingClient(): MysqlClient
    {
        return new class extends MysqlClient {
            /** @var list<string> */
            public array $issued = [];
            public bool $closed = false;
            public ?Deferred $hold = null;
            public bool $rejectNext = false;
            public bool $rejectProbe = false;

            public function __construct()
            {
                // deliberately skips parent: no URI validation, no socket
            }

            public function query($sql, array $params = [])
            {
                $this->issued[] = (string) $sql;
                if (str_contains((string) $sql, 'CONNECTION_ID')) {
                    if ($this->rejectProbe) {
                        return reject(new \RuntimeException('probe refused'));
                    }
                    $r = new MysqlResult();
                    $r->resultRows = [['id' => 42]];
                    return resolve($r);
                }
                if ($this->hold !== null) {
                    return $this->hold->promise();
                }
                if ($this->rejectNext) {
                    return reject(new \RuntimeException('no privilege'));
                }

                return resolve(null);
            }

            public function quit()
            {
                $this->issued[] = '(quit)';
                return resolve(null);
            }

            public function close()
            {
                $this->issued[] = '(close)';
                $this->closed = true;
            }
        };
    }

    /** Build a connection with spy internals, no socket/loop activity. */
    private function connectionWith(
        ?int $threadId,
        ?callable $killClientFactory,
        ?MysqlClient $client = null,
        float $killTimeout = 5.0,
    ): ReactMysqlConnection {
        $ref = new \ReflectionClass(ReactMysqlConnection::class);
        $conn = $ref->newInstanceWithoutConstructor();

        $set = function (string $prop, mixed $value) use ($ref, $conn): void {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($conn, $value);
        };
        $set('uri', 'mysql://root:secret@db.example.com:3306/shop');
        $set('loop', new StreamSelectLoop());
        $set('threadId', $threadId);
        $set('killClientFactory', $killClientFactory ?? static fn () => null);
        $set('client', $client ?? $this->recordingClient());
        $set('queryTimeout', 30.0);
        $set('killTimeout', $killTimeout);
        $set('clientFactory', static fn () => new MysqlClient('mysql://root@127.0.0.1:1/none'));

        return $conn;
    }

    private function loopOf(ReactMysqlConnection $conn): StreamSelectLoop
    {
        $p = new \ReflectionProperty($conn, 'loop');
        $p->setAccessible(true);
        $loop = $p->getValue($conn);
        $this->assertInstanceOf(StreamSelectLoop::class, $loop);

        return $loop;
    }
}
