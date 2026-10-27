<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Stream\ReadableStreamInterface;
use Ratchet\RFC6455\Messaging\Frame;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\CookieSessions;
use SugarCraft\Code\Server\Http\ApiController;
use SugarCraft\Code\Server\Http\AuthMiddleware;
use SugarCraft\Code\Server\Http\ClientAddress;
use SugarCraft\Code\Server\Http\ClientAddressGuard;
use SugarCraft\Code\Server\Http\HostAndOriginGuard;
use SugarCraft\Code\Server\Http\HookWebhooks;
use SugarCraft\Code\Server\Http\Responses;
use SugarCraft\Code\Server\Http\Router;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Ws\Connection;
use SugarCraft\Code\Server\Ws\MessageHandler;
use SugarCraft\Code\Server\Ws\ProtocolPendingHandler;
use SugarCraft\Code\Server\Ws\Upgrade;

use function React\Promise\resolve;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The `candy-code serve` gateway: one HTTP + WebSocket listener on the SAME
 * ReactPHP loop the engine's forked turns run on (Appendix O §3.2, §4.1).
 *
 * THE CHAIN, in order, for every request (ISS-055: nothing that can wait on
 * request bytes sits in front of the guards):
 *  1. react/http's streaming parse — headers only reach the chain;
 *  2. the request log (method, path with share tokens redacted, status —
 *     refusals included);
 *  3. {@see ClientAddressGuard} — `--allowed-ips`, ahead of everything that
 *     could tell a refused address more than "refused", and ahead of any
 *     buffering at all;
 *  4. {@see HostAndOriginGuard} — DNS rebinding and cross-site requests;
 *  5. the per-address in-flight cap ({@see MAX_CONCURRENT_PER_ADDRESS}) —
 *     one client cannot park the global pool;
 *  6. the read deadline ({@see REQUEST_READ_DEADLINE_SECONDS}) — headers are
 *     already in (a connection that sends no request at all gets the same
 *     deadline from accept); a request whose BODY stalls, or that waits for a
 *     slot, is answered 408 with `Connection: close`, tearing the socket down
 *     instead of holding its slot forever;
 *  7. react/http's bounds — {@see ServerConfig::MAX_CONCURRENT_REQUESTS} in
 *     flight and a {@see ServerConfig::MAX_API_BODY_BYTES} body, buffered
 *     before anything reads it;
 *  8. {@see AuthMiddleware} — a credential on everything but health, login
 *     and the static UI;
 *  9. {@see Router} — `/ws`, `/api/*`, `/s/{token}` share pages, the UI.
 *
 * WHAT A MESSAGE MEANS is the handler's: `candy-code serve` hands this the
 * `candy-code.v1` {@see \SugarCraft\Code\Protocol\Dispatcher} (O-3b) over the
 * workspace's sessions; a server built without one answers through
 * {@see ProtocolPendingHandler}, which speaks JSON-RPC and offers no methods.
 *
 * Process-wide, the server's sockets are registered with
 * {@see \SugarCraft\Code\Support\ForkedChild} by the {@see Listener}, so every
 * turn child forked while it runs drops them first.
 */
final class Server
{
    /** The WebSocket close code a rotated token ends a connection with. */
    public const CLOSE_CREDENTIALS_ROTATED = 4001;

    /**
     * The WebSocket close code for a connection whose cookie session ended
     * (ISS-009). The same 4001 as {@see CLOSE_CREDENTIALS_ROTATED} on purpose:
     * to the web it is one sign, "signed out, show the login".
     */
    public const CLOSE_SIGNED_OUT = 4001;

    /**
     * How long a request may take to finish arriving — body bytes and slot
     * wait (ISS-055). An HTTP-ingress bound: it never touches a turn, which
     * runs for as long as the model does (the E646/§-law against blanket
     * timeouts stands).
     */
    public const REQUEST_READ_DEADLINE_SECONDS = 10.0;

    /**
     * Requests one client address may have in flight (ISS-055). Every local
     * account shares 127.0.0.1, so this caps them together, not each alone;
     * it sits well under {@see ServerConfig::MAX_CONCURRENT_REQUESTS}, which
     * the global pool keeps.
     */
    public const MAX_CONCURRENT_PER_ADDRESS = 16;

    /** How often the cookie-session expiry sweep runs (ISS-009). */
    private const EXPIRY_SWEEP_SECONDS = 30.0;

    private ?Listener $listener = null;

    /** @var array<string, Connection> */
    private array $connections = [];

    /**
     * Cookie session id => when it last authenticated anything. Written by
     * the auth middleware's observer, read by {@see closeExpiredSessions()}
     * (ISS-009); an entry older than the sliding lifetime describes a session
     * the store itself has already dropped, so it is pruned.
     *
     * @var array<string, float>
     */
    private array $sessionLastUsed = [];

    /**
     * Client address => requests in flight through the cap door (ISS-055).
     *
     * @var array<string, int>
     */
    private array $inFlightByAddress = [];

    private ?TimerInterface $expirySweep = null;

    /**
     * @param (\Closure(string): void)|null $log             one line per event; never a secret
     * @param (\Closure(): float)|null       $clock          the seconds clock the session-expiry sweep reads (defaults to wall time)
     * @param (\Closure(): float)|null       $readDeadline   per-request read-deadline seconds (ISS-055); null for the documented default
     */
    private function __construct(
        private readonly ServerConfig $config,
        private readonly AuthContext $auth,
        private readonly StaticFiles $static,
        private readonly MessageHandler $handler,
        private readonly LoopInterface $loop,
        private readonly \Closure $log,
        private readonly \Closure $clock,
        private readonly float $readDeadlineSeconds,
        private readonly ?\Closure $share = null,
        private readonly ?HookWebhooks $webhooks = null,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $log
     * @param (\Closure(): float)|null      $clock
     * @param float|null                    $requestDeadlineSeconds
     */
    public static function new(
        ServerConfig $config,
        AuthContext $auth,
        ?StaticFiles $static = null,
        ?MessageHandler $handler = null,
        ?LoopInterface $loop = null,
        ?\Closure $log = null,
        ?\Closure $clock = null,
        ?float $requestDeadlineSeconds = null,
        ?\Closure $share = null,
        ?HookWebhooks $webhooks = null,
    ): self {
        return new self(
            $config,
            $auth,
            $static ?? StaticFiles::new(null),
            $handler ?? new ProtocolPendingHandler(),
            $loop ?? Loop::get(),
            $log ?? static function (string $line): void {
            },
            $clock ?? static fn (): float => \microtime(true),
            $requestDeadlineSeconds ?? self::REQUEST_READ_DEADLINE_SECONDS,
            $share,
            $webhooks,
        );
    }

    /**
     * Bind and start answering; returns the bound address.
     *
     * @throws \RuntimeException when the port cannot be bound
     */
    public function start(): string
    {
        if ($this->listener !== null) {
            return (string) $this->listener->getAddress();
        }

        $addressGuard = new ClientAddressGuard($this->config, $this->log);
        $guard = new HostAndOriginGuard($this->config, $this->log);
        // ISS-009: every authenticated use of a cookie session stamps the
        // sweep's clock, so a socket is only closed once its session has been
        // idle for the full sliding lifetime the store itself would expire.
        $credentials = new AuthMiddleware($this->auth, $this->config, $this->log, function (string $session): void {
            $this->sessionLastUsed[$session] = ($this->clock)();
        });
        $api = new ApiController($this->auth, $this->config, $credentials, function (string $session): void {
            unset($this->sessionLastUsed[$session]);
            $this->closeSessionSockets($session, 'you have signed out');
        }, $this->webhooks);
        $upgrade = new Upgrade(
            $this->handler,
            function (Connection $connection): void {
                $this->connections[$connection->id()] = $connection;
                ($this->log)(\sprintf('ws open %s (%s)', $connection->id(), $connection->principal()));
            },
            function (Connection $connection): void {
                unset($this->connections[$connection->id()]);
                ($this->log)(\sprintf('ws closed %s', $connection->id()));
            },
            ServerConfig::MAX_CLIENT_FRAME_BYTES,
            $this->log,
        );
        // SES-3: serve publishes share pages from the session database; a
        // server built without $share answers /s/... through the UI, as before.
        $router = new Router($upgrade(...), $api(...), ($this->static)(...), $this->share === null ? null : ($this->share)(...));

        $http = new HttpServer(
            $this->loop,
            new StreamingRequestMiddleware(),
            // Ahead of the guards, so a refusal is logged like an answer.
            // Method, path and status only: never the query (a ticket rides
            // there), a header or a body (Appendix O §8.6) — and never a
            // whole share token, which lives in the path itself (C-SES-3).
            function (ServerRequestInterface $request, callable $next): PromiseInterface {
                $path = self::redactShareToken($request->getUri()->getPath());

                return resolve($next($request))->then(function (ResponseInterface $response) use ($request, $path): ResponseInterface {
                    ($this->log)(\sprintf('%s %s %d', $request->getMethod(), $path, $response->getStatusCode()));

                    return $response;
                });
            },
            // ISS-055: the guards, the per-address cap and the read deadline
            // all sit AHEAD of the buffering stages, so a refused or stalled
            // client costs no queue time behind someone else's slow upload.
            $addressGuard,
            $guard,
            $this->perAddressCap(...),
            $this->readDeadline(...),
            new LimitConcurrentRequestsMiddleware(ServerConfig::MAX_CONCURRENT_REQUESTS),
            new RequestBodyBufferMiddleware(ServerConfig::MAX_API_BODY_BYTES),
            $credentials,
            $router(...),
        );
        $http->on('error', function (\Throwable $e): void {
            ($this->log)('http error: ' . $e->getMessage());
        });

        $this->listener = Listener::bind($this->config->bindUri(), $this->loop);
        $this->listener->on('error', function (\Throwable $e): void {
            ($this->log)('accept error: ' . $e->getMessage());
        });
        // ISS-055, the header half: a connection that has not sent its first
        // byte by the deadline never gets to hold a slot at all — close it.
        // (Once bytes arrive the parser owns the request and $this->readDeadline
        // bounds its body; react/http gives no handle between the two.)
        $this->listener->on('connection', function (ConnectionInterface $connection): void {
            $timer = $this->loop->addTimer($this->readDeadlineSeconds, function () use ($connection): void {
                ($this->log)('request deadline: a client connected and sent no request');
                $connection->close();
            });
            $connection->once('data', function () use ($timer): void {
                $this->loop->cancelTimer($timer);
            });
        });
        $http->listen($this->listener);

        $this->expirySweep ??= $this->loop->addPeriodicTimer(self::EXPIRY_SWEEP_SECONDS, function (): void {
            $this->closeExpiredSessions();
        });

        return (string) $this->listener->getAddress();
    }

    /** The bound port, once started. */
    public function port(): ?int
    {
        return $this->listener?->port();
    }

    /** `http://host:port` as a browser on this machine reaches it. */
    public function url(): string
    {
        return 'http://' . $this->config->hostForUrl() . ':' . ($this->port() ?? $this->config->port);
    }

    /**
     * A sign-in URL carrying a fresh one-time login code in its FRAGMENT, so
     * the code never reaches a request line, a log or a `Referer`.
     */
    public function loginUrl(): string
    {
        return $this->loginUrls()[0];
    }

    /**
     * The sign-in URL under each host a client may dial
     * ({@see ServerConfig::reachableHosts()}: the bind address, or this
     * machine's own interface addresses on a wildcard `--allow-remote` bind,
     * where `0.0.0.0` names nothing a browser can open). One code serves
     * every entry — it is spent by whichever one is opened first.
     *
     * @return non-empty-list<string>
     */
    public function loginUrls(): array
    {
        $code = $this->auth->loginCodes->mint();
        $port = $this->port() ?? $this->config->port;

        return \array_map(
            static fn (string $host): string => 'http://' . $host . ':' . $port . '/#code=' . $code,
            $this->config->reachableHosts(),
        );
    }

    /**
     * `serve token --rotate` reached this server (roadmap O-3b, carried from
     * W6): re-read the token file and, when the token changed, sign every
     * client out — cookies, tickets and login codes revoked, and every open
     * WebSocket closed with {@see CLOSE_CREDENTIALS_ROTATED}, since each was
     * authenticated with a credential that no longer exists. Answers how many
     * sockets were closed, or null when the token did not change.
     */
    public function reloadToken(): ?int
    {
        if (!$this->auth->reloadToken()) {
            return null;
        }
        $closed = 0;
        foreach ($this->connections as $connection) {
            $connection->close(self::CLOSE_CREDENTIALS_ROTATED, 'the server token was rotated; sign in again');
            $closed++;
        }
        $this->sessionLastUsed = [];
        ($this->log)(\sprintf('token rotated: signed every client out (%d socket%s closed)', $closed, $closed === 1 ? '' : 's'));

        return $closed;
    }

    /**
     * Close every open socket whose cookie session has lapsed out of the
     * sliding sign-in window (ISS-009): the store expires the session itself
     * at the same deadline, but a socket keeps its principal for life unless
     * someone ends it, so a browser that reloaded, died or went silent for
     * {@see CookieSessions::LIFETIME_SECONDS} would otherwise keep reading
     * and writing after its sign-in was gone. Answers how many sockets closed.
     */
    public function closeExpiredSessions(): int
    {
        $now = ($this->clock)();
        $closed = 0;
        foreach ($this->connections as $connection) {
            $session = $connection->cookieSession();
            if ($session === null) {
                continue;
            }
            if ($now - ($this->sessionLastUsed[$session] ?? $now) > CookieSessions::LIFETIME_SECONDS) {
                $connection->close(self::CLOSE_SIGNED_OUT, 'your sign-in expired; sign in again');
                ++$closed;
            }
        }
        // A stamp older than the lifetime describes a session the store has
        // itself dropped (every authenticated use re-stamps both together).
        foreach ($this->sessionLastUsed as $session => $usedAt) {
            if ($now - $usedAt > CookieSessions::LIFETIME_SECONDS) {
                unset($this->sessionLastUsed[$session]);
            }
        }
        if ($closed > 0) {
            ($this->log)(\sprintf('sign-in expired: %d socket%s closed', $closed, $closed === 1 ? '' : 's'));
        }

        return $closed;
    }

    public function connectionCount(): int
    {
        return \count($this->connections);
    }

    /**
     * Stop accepting, then close every WebSocket with 1001. Idempotent; the
     * loop is left running for whatever else it serves.
     */
    public function stop(string $reason = 'server shutting down'): void
    {
        if ($this->listener === null) {
            return;
        }

        if ($this->expirySweep !== null) {
            $this->loop->cancelTimer($this->expirySweep);
            $this->expirySweep = null;
        }
        $this->listener->close();
        $this->listener = null;
        foreach ($this->connections as $connection) {
            $connection->close(Frame::CLOSE_GOING_AWAY, $reason);
        }
        $this->connections = [];
        $this->sessionLastUsed = [];
        $this->inFlightByAddress = [];
    }

    /**
     * The share token IS the credential for a public page, so the request
     * log must never hold a copy an attacker could read back out of the log
     * file (C-SES-3). A `/s/{token}` path is logged as the prefix, the
     * token's first four characters and an ellipsis — enough to correlate
     * repeated hits on one page, not enough to reconstruct the URL. Every
     * other path, and `/s/` itself, is logged verbatim; the keying lives on
     * {@see ShareStore::URL_PREFIX}, the same constant the Router and the
     * controller branch on, so the three can never disagree about what a
     * share path looks like.
     */
    private static function redactShareToken(string $path): string
    {
        $prefix = '/' . ShareStore::URL_PREFIX . '/';
        if (!str_starts_with($path, $prefix)) {
            return $path;
        }
        $token = substr($path, strlen($prefix));
        if ($token === '') {
            return $path;
        }

        return $prefix . substr($token, 0, 4) . '…';
    }

    /**
     * End the sockets a cookie session opened, however it was reached
     * (ISS-009). The session must already be revoked by the caller.
     */
    private function closeSessionSockets(string $session, string $reason): void
    {
        $closed = 0;
        foreach ($this->connections as $connection) {
            if ($connection->cookieSession() === $session) {
                $connection->close(self::CLOSE_SIGNED_OUT, $reason);
                ++$closed;
            }
        }
        if ($closed > 0) {
            // The session id itself is a credential; it stays out of the log.
            ($this->log)(\sprintf('signed out (%d socket%s closed)', $closed, $closed === 1 ? '' : 's'));
        }
    }

    /**
     * The per-address in-flight cap (ISS-055): thirty-two sockets dribbling
     * a body from one address used to occupy the whole global pool and starve
     * `/api/health` and new upgrades behind them. Refused with 429 while the
     * address is at its cap; the count is released the moment the request
     * settles, however it settles.
     */
    private function perAddressCap(ServerRequestInterface $request, callable $next): mixed
    {
        $address = ClientAddress::of($request, $this->config->trustedProxies);
        $running = $this->inFlightByAddress[$address] ?? 0;
        if ($running >= self::MAX_CONCURRENT_PER_ADDRESS) {
            return Responses::error(429, 'rate_limited', 'too many simultaneous requests from this address', ['Retry-After' => '1']);
        }

        $this->inFlightByAddress[$address] = $running + 1;
        $settled = false;
        $release = function () use (&$settled, $address): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if (--$this->inFlightByAddress[$address] <= 0) {
                unset($this->inFlightByAddress[$address]);
            }
        };

        try {
            $result = $next($request);
        } catch (\Throwable $e) {
            $release();

            throw $e;
        }

        if (!$result instanceof PromiseInterface) {
            $release();

            return $result;
        }

        return $result->then(
            function (mixed $response) use ($release): mixed {
                $release();

                return $response;
            },
            function (\Throwable $e) use ($release): never {
                $release();

                throw $e;
            },
        );
    }

    /**
     * The per-request read deadline (ISS-055): from the moment a parsed
     * request enters the chain until its body has fully arrived (or a slot
     * freed up for it to be answered), {@see REQUEST_READ_DEADLINE_SECONDS}.
     * Overdue, the body is abandoned, one log line says so, and the answer is
     * 408 with `Connection: close` — react/http tears the socket down after
     * writing it, which is the "close the socket" the issue asks for and the
     * only handle react/http v1 gives a middleware. Requests whose body is
     * already complete (every GET, every upgrade, every buffered POST that
     * arrives in time) never carry a timer.
     */
    private function readDeadline(ServerRequestInterface $request, callable $next): PromiseInterface
    {
        $body = $request->getBody();
        if (!$body instanceof ReadableStreamInterface || !$body->isReadable()) {
            return resolve($next($request));
        }

        return new Promise(function (callable $resolve, callable $reject) use ($request, $next, $body): void {
            $settled = false;
            $timer = null;
            $stopTimer = function () use (&$timer): void {
                if ($timer !== null) {
                    $this->loop->cancelTimer($timer);
                    $timer = null;
                }
            };
            $body->on('close', $stopTimer);
            $timer = $this->loop->addTimer($this->readDeadlineSeconds, function () use (&$settled, $request, $body, $resolve, $stopTimer): void {
                if ($settled) {
                    return;
                }
                $settled = true;
                $stopTimer();
                ($this->log)(\sprintf(
                    'request deadline: %s %s did not finish reading within %s s',
                    $request->getMethod(),
                    $request->getUri()->getPath(),
                    \rtrim(\rtrim(\sprintf('%.2f', $this->readDeadlineSeconds), '0'), '.'),
                ));
                $body->close();
                $resolve(Responses::error(
                    408,
                    'request_timeout',
                    'the request body did not arrive in time',
                    ['Connection' => 'close'],
                ));
            });

            try {
                resolve($next($request))->then(
                    function (mixed $response) use (&$settled, $resolve, $stopTimer): void {
                        $stopTimer();
                        if ($settled) {
                            return;
                        }
                        $resolve($response);
                    },
                    function (\Throwable $e) use (&$settled, $reject, $stopTimer): void {
                        $stopTimer();
                        if (!$settled) {
                            $reject($e);
                        }
                    },
                );
            } catch (\Throwable $e) {
                $stopTimer();
                if (!$settled) {
                    throw $e;
                }
            }
        });
    }
}
