<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Ws;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use SugarCraft\Crush\Server\Http\AuthMiddleware;
use SugarCraft\Crush\Server\Http\Responses;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * `GET /ws`: the RFC 6455 handshake, answered with a 101 whose body is a
 * duplex stream react/http pipes the socket through (o0-spikes "upgrade
 * pattern"; Appendix O §3.2, §6.1).
 *
 * By the time a request reaches here it has passed {@see
 * \SugarCraft\Crush\Server\Http\HostAndOriginGuard} and {@see AuthMiddleware},
 * so this class decides only the WebSocket question. The subprotocol is
 * STRICT: a client that does not offer `sugarcrush.v1` is refused before the
 * upgrade (426), and the 101 echoes `sugarcrush.v1` alone — never the
 * `sugarcrush.auth.<token>` entry a token client may also have offered, which
 * would put the token in a response header.
 *
 * The handshake response is ratchet's (a Guzzle PSR-7 response); its status
 * and headers are copied onto react/http's own {@see Response}, because only
 * that class carries the duplex body react/http forwards the socket into.
 */
final class Upgrade
{
    private int $sequence = 0;

    /**
     * @param \Closure(Connection): void $onOpen the server's bookkeeping, before the handler sees it
     * @param \Closure(Connection): void $onGone the server's bookkeeping, after the handler's onClose
     */
    public function __construct(
        private readonly MessageHandler $handler,
        private readonly \Closure $onOpen,
        private readonly \Closure $onGone,
        private readonly int $maxMessageBytes = ServerConfig::MAX_CLIENT_MESSAGE_BYTES,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $offered = \array_map('trim', \explode(',', $request->getHeaderLine('Sec-WebSocket-Protocol')));
        if (!\in_array(ServerConfig::SUBPROTOCOL, $offered, true)) {
            return Responses::error(426, 'subprotocol_required', 'offer the WebSocket subprotocol ' . ServerConfig::SUBPROTOCOL, [
                'Sec-WebSocket-Version' => (string) RequestVerifier::VERSION,
            ]);
        }

        $factory = new HttpFactory();
        $negotiator = new ServerNegotiator(new RequestVerifier(), $factory, false);
        $negotiator->setSupportedSubProtocols([ServerConfig::SUBPROTOCOL]);
        $negotiator->setStrictSubProtocolCheck(true);
        $handshake = $negotiator->handshake($request);

        if ($handshake->getStatusCode() !== 101) {
            return Responses::error($handshake->getStatusCode(), 'bad_upgrade', 'not a valid WebSocket upgrade');
        }

        $toClient = new ThroughStream();
        $fromClient = new ThroughStream();

        $headers = [];
        foreach ($handshake->getHeaders() as $name => $values) {
            if (\strtolower($name) !== 'x-powered-by') {
                $headers[$name] = $values;
            }
        }
        $headers['Sec-WebSocket-Protocol'] = ServerConfig::SUBPROTOCOL;

        $connection = new Connection(
            \sprintf('c%d-%s', ++$this->sequence, \bin2hex(\random_bytes(4))),
            (string) $request->getAttribute(AuthMiddleware::ATTR_PRINCIPAL, ''),
            $toClient,
            $fromClient,
            $this->handler,
            $this->maxMessageBytes,
            $this->onGone,
        );
        ($this->onOpen)($connection);
        // Next tick, not now: the 101 has not been written yet, so anything
        // the handler sent from onOpen would be emitted before react/http
        // pipes this body onto the socket — and lost.
        Loop::futureTick(function () use ($connection): void {
            if ($connection->isOpen()) {
                $this->handler->onOpen($connection);
            }
        });

        return new Response(101, $headers, new CompositeStream($toClient, $fromClient));
    }
}
