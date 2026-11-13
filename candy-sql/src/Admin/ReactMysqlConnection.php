<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\LoopInterface;
use React\Mysql\MysqlClient;
use React\Mysql\MysqlResult;
use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationToken;

/**
 * Async MySQL connection wrapper using react/mysql.
 *
 * Runs natively on the same ReactPHP event loop that sugar-core's Program
 * already drives — no Revolt loop, no fibers, no loop bridging. (Supersedes an
 * earlier amphp/mysql attempt that needed amphp's Revolt loop bridged onto
 * React's; that bridge class never existed, so the admin pane crashed on open.)
 *
 * The MysqlClient is lazy: it opens the underlying socket on the first query
 * and resolves/rejects the per-query promise on the React loop.
 *
 * Dependency pin (law note, docs-reviews-1104 PB-SQL-13): composer.json pins
 * react/mysql to `0.7.x-dev` deliberately — the `MysqlClient`/`MysqlResult`
 * API used here first ships on the UNRELEASED 0.7.x branch (probe: v0.6.0, the
 * newest tag, contains neither class, and no `^0.7` caret range can resolve
 * until upstream tags a release). Widening to `^0.6` is not an option: the
 * constraint would admit zero versions that compile this file. Revisit at the
 * first friends-of-reactphp/mysql tag that carries MysqlClient.
 *
 * Cancellation semantics (plan 5.1): react/mysql's query promise has no
 * client-side cancellation and the driver does not expose the connection's
 * thread id, so the wrapper fetches `SELECT CONNECTION_ID()` as the very
 * first command on the connection (FIFO ordering guarantees it resolves
 * before any long user query occupies the socket). Cancelling — via a
 * {@see CancellationToken} or the {@see QueryTimeout} deadline — then issues
 * `KILL QUERY <thread_id>` on a SEPARATE short-lived connection, stopping the
 * statement server-side while the caller's promise rejects immediately. If
 * the thread id is not (yet) known, cancellation is client-side only: the
 * promise rejects but the server finishes the statement.
 *
 * @see https://github.com/friends-of-reactphp/mysql
 */
final class ReactMysqlConnection implements AsyncConnection
{
    private MysqlClient $client;
    private LoopInterface $loop;
    private string $uri;

    /** Server thread id of $client's connection, once CONNECTION_ID() resolves. */
    private ?int $threadId = null;
    private bool $threadIdRequested = false;

    /** @var callable(string, LoopInterface): MysqlClient */
    private $killClientFactory;

    /** @var callable(string, LoopInterface): MysqlClient */
    private $clientFactory;

    /**
     * @param float $queryTimeout Per-query deadline in seconds; <= 0 disables
     *                            it (see {@see QueryTimeout} for why a default
     *                            deadline exists at all)
     * @param callable(string, LoopInterface): MysqlClient|null $killClientFactory
     *                            Factory for the short-lived KILL side-channel
     *                            connection; injectable so tests can spy on the
     *                            KILL path without a live server
     * @param float $killTimeout  Deadline for the KILL leg itself: the same
     *                            black-holed route QueryTimeout exists for can
     *                            swallow the KILL too, and an untimed killer
     *                            would leak its fd + watchers per abort
     * @param callable(string, LoopInterface): MysqlClient|null $clientFactory
     *                            Factory for the main client, so the
     *                            post-deadline re-mint is testable without a
     *                            socket (admindata-015)
     */
    public function __construct(
        string $dsn,
        string $username,
        string $password,
        ?LoopInterface $loop = null,
        private readonly float $queryTimeout = QueryTimeout::DEFAULT_SECONDS,
        ?callable $killClientFactory = null,
        private readonly float $killTimeout = self::KILL_TIMEOUT_SECONDS,
        ?callable $clientFactory = null,
    ) {
        $this->loop = $loop ?? ReactLoop::get();
        $this->uri = $this->dsnToUri($dsn, $username, $password);
        $this->clientFactory = $clientFactory
            ?? static fn (string $uri, LoopInterface $loop): MysqlClient => new MysqlClient($uri, null, $loop);
        $this->client = ($this->clientFactory)($this->uri, $this->loop);
        $this->killClientFactory = $killClientFactory
            ?? static fn (string $uri, LoopInterface $loop): MysqlClient => new MysqlClient($uri, null, $loop);
    }

    /**
     * Deadline for the KILL side-channel leg. The user's statement is already
     * being abandoned at this point; the killer only needs enough time for a
     * healthy server to accept `KILL QUERY`, so this stays short.
     */
    private const KILL_TIMEOUT_SECONDS = 5.0;

    /**
     * Execute a query asynchronously.
     *
     * Rejects with a clear timeout error when the query outlives the
     * configured deadline — otherwise a dropped route would leave the
     * promise pending forever (see {@see QueryTimeout}). Both the deadline
     * and an explicit `$cancellation` route through the same server-side
     * `KILL QUERY` path so an abandoned query stops burning the server.
     *
     * @param string $sql SQL query to execute
     * @param CancellationToken|null $cancellation Optional cooperative-cancel handle
     * @return PromiseInterface<list<array<string,mixed>>> Rows as assoc arrays
     */
    public function query(string $sql, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->requestThreadId();

        $promise = $this->client->query($sql)->then(
            static fn(MysqlResult $result): array => $result->resultRows ?? [],
        );

        $timed = QueryTimeout::wrap($promise, $this->queryTimeout, $this->loop, function (): void {
            $this->killQueryOnServer();
            // admindata-015: the deadline fired precisely because the leg is
            // not settling — react/mysql keeps the queued command and its
            // buffered rows pending on this client forever (one wedged leg per
            // timeout across a days-long session). The vendor rejects queued
            // commands on close, so close and re-mint: later queries dial a
            // fresh connection instead of queueing behind the ghost.
            $this->invalidateClient();
        });

        return CancellableQuery::wrap($timed, $cancellation, function (): void {
            $this->killQueryOnServer();
        });
    }

    /**
     * Learn this connection's server thread id, once, ahead of time.
     *
     * Must be dispatched BEFORE user queries: react/mysql runs commands
     * strictly FIFO on the single connection, so a lazy fetch at cancel time
     * would queue behind the very statement being cancelled and never resolve
     * until it finished — defeating the purpose.
     */
    private function requestThreadId(): void
    {
        if ($this->threadIdRequested) {
            return;
        }
        $this->threadIdRequested = true;

        $this->client->query('SELECT CONNECTION_ID() AS id')->then(
            function (MysqlResult $result): void {
                $id = $result->resultRows[0]['id'] ?? null;
                if (is_numeric($id)) {
                    $this->threadId = (int) $id;

                    return;
                }
                // A malformed answer is a miss, not an answer: re-arm so the
                // next probe round can win (admindata-014).
                $this->threadIdRequested = false;
            },
            function (): void {
                // Connect/permission failure — the user's own query will
                // surface the real error; cancellation degrades to client-side
                // for now, but the latch must not freeze: a cold-race failure
                // once must not mean the wrapper's whole life answers
                // client-side-only (admindata-014). Re-arm and let the next
                // query() or cancel re-probe.
                $this->threadIdRequested = false;
            },
        );
    }

    /**
     * Abort the currently executing statement server-side.
     *
     * Uses a separate short-lived connection because the main one is busy
     * executing the very query being killed. `KILL QUERY` cancels the
     * statement but keeps the target connection alive (unlike `KILL
     * CONNECTION`). MySQL's KILL accepts no placeholders; the int-typed
     * thread id makes direct interpolation injection-safe. Fire-and-forget:
     * a failed kill (no privilege, id gone) is deliberately swallowed — the
     * caller's promise has already rejected. The killer leg itself runs under
     * its own short deadline: the black-holed route that made the original
     * query time out can swallow the KILL too, and an UNTIMED killer would
     * leak its fd + loop watchers on every abort (admindata-003) — so the
     * client is force-closed on the never-settling arm as well as the error arm.
     */
    private function killQueryOnServer(): void
    {
        if ($this->threadId === null) {
            // Client-side cancellation only for THIS abort: the KILL would
            // queue on the side-channel without an id to name. Re-arm the
            // probe so a later cancel can go server-side once the id arrives
            // (admindata-014 — the fetch is FIFO on the main connection, so it
            // settles when the busy statement finishes).
            $this->requestThreadId();

            return; // Thread id unknown — client-side cancellation only.
        }

        $killer = ($this->killClientFactory)($this->uri, $this->loop);
        QueryTimeout::wrap(
            $killer->query('KILL QUERY ' . $this->threadId),
            $this->killTimeout,
            $this->loop,
        )->then(
            static function () use ($killer): void {
                // Server answered: graceful QUIT (the socket is provably live).
                $killer->quit();
            },
            static function () use ($killer): void {
                // Rejected (no privilege) OR never settled (deadline) — tear
                // the side-channel down now; nothing is owed to a deaf server.
                $killer->close();
            },
        );
    }

    /**
     * Retire the current main client and dial a fresh one.
     *
     * The react/mysql Client cannot reconnect after close (its stream is
     * gone), so the post-deadline path replaces the instance outright and
     * drops the thread-id state: the new session is a new connection with a
     * new id, re-probed on the next query (admindata-015).
     */
    private function invalidateClient(): void
    {
        try {
            $this->client->close();
        } catch (\Throwable) {
            // A client that cannot even close is already dead; re-minting is
            // the whole point of this path.
        }

        $this->client = ($this->clientFactory)($this->uri, $this->loop);
        $this->threadId = null;
        $this->threadIdRequested = false;
    }

    /**
     * Retire the server session (admindata-004).
     *
     * Force-close is the right arm here, not `quit()`: the drop paths that
     * call this are key swaps and post-reconnect invalidation, where the
     * session is stale or already suspect — nothing is owed to it. react/mysql
     * closes idempotently (its `closed` flag short-circuits repeats) and
     * rejects any outstanding queued commands on the way down.
     */
    public function close(): void
    {
        $this->client->close();
    }

    /**
     * Convert PDO-style DSN to react/mysql URI format.
     *
     * PDO:         mysql:host=db.example.com;port=3306;dbname=test
     * react/mysql: mysql://user:pass@db.example.com:3306/test
     *
     * The `mysql://` scheme is REQUIRED: react/mysql's Factory runs the URI
     * through parse_url() and connects to $parts['host'] (Io/Factory.php). A
     * scheme-less URI like `user:pass@host/db` mis-parses (parse_url reads the
     * username as the scheme and reports no host), so with assertions disabled
     * react/mysql connects to an empty host and every connect is "refused".
     * react/mysql rawurldecode()s the user/pass/db, so they are rawurlencode()d
     * here to survive credentials containing @ : / etc.
     */
    private function dsnToUri(string $dsn, string $username, string $password): string
    {
        $host = $this->extractDsnValue($dsn, 'host') ?? 'localhost';
        // PDO tolerates port 0 (libmysqlclient falls back to 3306) but react/mysql
        // connects to literal :0 and is refused — normalise a missing/zero port.
        $port = $this->extractDsnValue($dsn, 'port');
        if ($port === null || (int) $port <= 0) {
            $port = '3306';
        }
        $dbname = $this->extractDsnValue($dsn, 'dbname') ?? '';

        $user = $username !== '' ? $username : 'root';

        return sprintf(
            'mysql://%s:%s@%s:%s/%s',
            rawurlencode($user),
            rawurlencode($password),
            $host,
            $port,
            rawurlencode($dbname),
        );
    }

    private function extractDsnValue(string $dsn, string $key): ?string
    {
        return DsnParser::extract($dsn, $key);
    }
}
