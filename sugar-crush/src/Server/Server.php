<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\PromiseInterface;
use Ratchet\RFC6455\Messaging\Frame;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Http\ApiController;
use SugarCraft\Crush\Server\Http\AuthMiddleware;
use SugarCraft\Crush\Server\Http\ClientAddressGuard;
use SugarCraft\Crush\Server\Http\HostAndOriginGuard;
use SugarCraft\Crush\Server\Http\Router;
use SugarCraft\Crush\Server\Http\StaticFiles;
use SugarCraft\Crush\Server\Ws\Connection;
use SugarCraft\Crush\Server\Ws\MessageHandler;
use SugarCraft\Crush\Server\Ws\ProtocolPendingHandler;
use SugarCraft\Crush\Server\Ws\Upgrade;

use function React\Promise\resolve;

/**
 * The `sugarcrush serve` gateway: one HTTP + WebSocket listener on the SAME
 * ReactPHP loop the engine's forked turns run on (Appendix O §3.2, §4.1).
 *
 * THE CHAIN, in order, for every request:
 *  1. react/http's bounds — {@see ServerConfig::MAX_CONCURRENT_REQUESTS} in
 *     flight and a {@see ServerConfig::MAX_API_BODY_BYTES} body, buffered
 *     before anything reads it;
 *  2. the request log (method, path, status — refusals included);
 *  3. {@see ClientAddressGuard} — `--allowed-ips`, ahead of everything that
 *     could tell a refused address more than "refused";
 *  4. {@see HostAndOriginGuard} — DNS rebinding and cross-site requests;
 *  5. {@see AuthMiddleware} — a credential on everything but health, login
 *     and the static UI;
 *  6. {@see Router} — `/ws`, `/api/*`, the UI.
 *
 * WHAT A MESSAGE MEANS is the handler's: `sugarcrush serve` hands this the
 * `sugarcrush.v1` {@see \SugarCraft\Crush\Protocol\Dispatcher} (O-3b) over the
 * workspace's sessions; a server built without one answers through
 * {@see ProtocolPendingHandler}, which speaks JSON-RPC and offers no methods.
 *
 * Process-wide, the server's sockets are registered with
 * {@see \SugarCraft\Crush\Support\ForkedChild} by the {@see Listener}, so every
 * turn child forked while it runs drops them first.
 */
final class Server
{
    /** The WebSocket close code a rotated token ends a connection with. */
    public const CLOSE_CREDENTIALS_ROTATED = 4001;

    private ?Listener $listener = null;

    /** @var array<string, Connection> */
    private array $connections = [];

    /**
     * @param \Closure(string): void $log one line per event; never a secret
     */
    private function __construct(
        private readonly ServerConfig $config,
        private readonly AuthContext $auth,
        private readonly StaticFiles $static,
        private readonly MessageHandler $handler,
        private readonly LoopInterface $loop,
        private readonly \Closure $log,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $log
     */
    public static function new(
        ServerConfig $config,
        AuthContext $auth,
        ?StaticFiles $static = null,
        ?MessageHandler $handler = null,
        ?LoopInterface $loop = null,
        ?\Closure $log = null,
    ): self {
        return new self(
            $config,
            $auth,
            $static ?? StaticFiles::new(null),
            $handler ?? new ProtocolPendingHandler(),
            $loop ?? Loop::get(),
            $log ?? static function (string $line): void {
            },
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
        $authMiddleware = new AuthMiddleware($this->auth, $this->config);
        $api = new ApiController($this->auth, $this->config);
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
        );
        $router = new Router($upgrade(...), $api(...), ($this->static)(...));

        $http = new HttpServer(
            $this->loop,
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware(ServerConfig::MAX_CONCURRENT_REQUESTS),
            new RequestBodyBufferMiddleware(ServerConfig::MAX_API_BODY_BYTES),
            // Ahead of the guards, so a refusal is logged like an answer.
            // Method, path and status only: never the query (a ticket rides
            // there), a header or a body (Appendix O §8.6).
            function (ServerRequestInterface $request, callable $next): PromiseInterface {
                return resolve($next($request))->then(function (ResponseInterface $response) use ($request): ResponseInterface {
                    ($this->log)(\sprintf('%s %s %d', $request->getMethod(), $request->getUri()->getPath(), $response->getStatusCode()));

                    return $response;
                });
            },
            $addressGuard,
            $guard,
            $authMiddleware,
            $router(...),
        );
        $http->on('error', function (\Throwable $e): void {
            ($this->log)('http error: ' . $e->getMessage());
        });

        $this->listener = Listener::bind($this->config->bindUri(), $this->loop);
        $this->listener->on('error', function (\Throwable $e): void {
            ($this->log)('accept error: ' . $e->getMessage());
        });
        $http->listen($this->listener);

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
        ($this->log)(\sprintf('token rotated: signed every client out (%d socket%s closed)', $closed, $closed === 1 ? '' : 's'));

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

        $this->listener->close();
        $this->listener = null;
        foreach ($this->connections as $connection) {
            $connection->close(Frame::CLOSE_GOING_AWAY, $reason);
        }
        $this->connections = [];
    }
}
