<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use PgAsync\Client;
use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationToken;

/**
 * Async PostgreSQL connection wrapper using voryx/pgasync.
 *
 * PgAsync is the only pure-PHP async Postgres client that runs on the ReactPHP
 * event loop (the one sugar-core's Program drives) — no Revolt, no fibers, no
 * loop bridging. (Supersedes an earlier amphp/postgres attempt whose Revolt
 * loop could not be bridged onto React's, crashing the admin pane on open.)
 *
 * PgAsync's query API streams rows as an RxPHP Observable; we buffer the whole
 * result set with toArray() and adapt it to a react/promise via toPromise(),
 * so the admin-fetch path sees the same PromiseInterface<list<rows>> contract
 * as the MySQL wrapper.
 *
 * Cancellation semantics (plan 5.1): genuinely server-side, courtesy of the
 * driver. Cancelling the query promise disposes the RxPHP subscription
 * (RxPHP's toPromise() wires promise-cancel → dispose), and PgAsync's query
 * disposable sends a wire-protocol CancelRequest — the pg_cancel_backend()
 * equivalent, using the connection's BackendKeyData — on a separate socket.
 * Both an explicit {@see CancellationToken} and the {@see QueryTimeout}
 * deadline take this path (promise-timer cancels the underlying promise when
 * the timer fires), so a cancelled or timed-out statement actually stops
 * executing on the server. No extra side-channel is needed here.
 *
 * TLS: unlike the react/mysql connector (which takes only a plaintext
 * mysql:// URI), PgAsync carries a real TLS story — the libpq `sslmode`
 * that {@see \SugarCraft\Sql\Db\ConnectionConfig} bakes into every pgsql
 * DSN is threaded through to PgAsync's own `tls` parameter here. In the
 * required modes (require/verify-ca/verify-full) PgAsync refuses the
 * connection outright when the server declines SSLRequest rather than
 * silently downgrading to plaintext. One upstream parity caveat:
 * pgasync's verify-ca sets verify_peer=false/allow_self_signed=true, so
 * it means "encrypted, CA-chain NOT verified" — weaker than libpq's
 * verify-ca; only verify-full gets certificate verification. Note also
 * that pgsql `require` matches libpq semantics (encrypt, no cert check)
 * — unlike the MySQL table in {@see \SugarCraft\Sql\Db\MysqlDatabase},
 * where require escalates to system-store verification.
 *
 * @see https://github.com/voryx/PgAsync
 */
final class ReactPostgresConnection implements AsyncConnection
{
    /**
     * The libpq sslmode spellings PgAsync accepts as its `tls` parameter.
     * Local (not vendor-coupled) list: an unrecognised value would throw
     * inside the vendor constructor, so unknown modes are defensively
     * omitted and inherit PgAsync's 'prefer' default.
     */
    private const PGSQL_TLS_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    private Client $client;
    private LoopInterface $loop;

    /**
     * @param float $queryTimeout Per-query deadline in seconds; <= 0 disables
     *                            it (see {@see QueryTimeout} for why a default
     *                            deadline exists at all)
     */
    public function __construct(
        string $dsn,
        string $username = '',
        string $password = '',
        ?LoopInterface $loop = null,
        private readonly float $queryTimeout = QueryTimeout::DEFAULT_SECONDS,
    ) {
        $this->loop = $loop ?? ReactLoop::get();
        $this->client = new Client($this->dsnToParameters($dsn, $username, $password), $this->loop);
    }

    /**
     * Execute a query asynchronously.
     *
     * Rejects with a clear timeout error when the query outlives the
     * configured deadline — otherwise a dropped route would leave the
     * promise pending forever (see {@see QueryTimeout}).
     *
     * @param string $sql SQL query to execute
     * @param CancellationToken|null $cancellation Optional cooperative-cancel handle;
     *        cancelling stops the statement server-side via PgAsync's CancelRequest
     * @return PromiseInterface<list<array<string,mixed>>> Rows as assoc arrays
     */
    public function query(string $sql, ?CancellationToken $cancellation = null): PromiseInterface
    {
        // PgAsync emits each row as a separate Observable item; toArray() buffers
        // the full result set into one emission, toPromise() bridges to react/promise.
        $promise = $this->client->query($sql)->toArray()->toPromise();

        // No onTimeout hook needed: promise-timer cancels $promise on deadline,
        // which disposes the Rx subscription → PgAsync sends CancelRequest.
        $timed = QueryTimeout::wrap($promise, $this->queryTimeout, $this->loop);

        // No onServerCancel hook either — CancellableQuery::wrap() cancels the
        // wrapped promise, and that same dispose chain aborts the statement
        // server-side.
        return CancellableQuery::wrap($timed, $cancellation);
    }

    /**
     * Convert PDO-style DSN to a PgAsync connection-parameter array.
     *
     * PDO:     pgsql:host=localhost;port=5432;dbname=test
     * PgAsync: ['host'=>.., 'port'=>.., 'database'=>.., 'user'=>.., 'password'=>..]
     *
     * PgAsync requires both 'user' and 'database'; host/port have built-in
     * defaults but we pass them explicitly from the DSN when present.
     * The DSN's libpq `sslmode` (added by ConnectionConfig::buildDsn for
     * pgsql, so its spelling is already normalised) rides as the `tls`
     * parameter — see the class docblock for the required-mode and
     * verify-ca parity semantics.
     *
     * @return array<string,mixed>
     */
    private function dsnToParameters(string $dsn, string $username, string $password): array
    {
        $params = [
            'host'            => $this->extractDsnValue($dsn, 'host') ?? 'localhost',
            'port'            => (int) ($this->extractDsnValue($dsn, 'port') ?? '5432'),
            'user'            => $username !== '' ? $username : 'postgres',
            'database'        => $this->extractDsnValue($dsn, 'dbname') ?? 'postgres',
            'auto_disconnect' => true,
        ];

        if ($password !== '') {
            $params['password'] = $password;
        }

        $sslMode = $this->extractDsnValue($dsn, 'sslmode');
        if ($sslMode !== null && in_array($sslMode, self::PGSQL_TLS_MODES, true)) {
            $params['tls'] = $sslMode;
        }

        return $params;
    }

    private function extractDsnValue(string $dsn, string $key): ?string
    {
        return DsnParser::extract($dsn, $key);
    }
}
