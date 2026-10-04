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
 *  3. {@see HostAndOriginGuard} — DNS rebinding and cross-site requests;
 *  4. {@see AuthMiddleware} — a credential on everything but health, login
 *     and the static UI;
 *  5. {@see Router} — `/ws`, `/api/*`, the UI.
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

        $guard = new HostAndOriginGuard($this->config);
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
        return $this->url() . '/#code=' . $this->auth->loginCodes->mint();
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
