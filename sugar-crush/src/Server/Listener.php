<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;
use React\Socket\ServerInterface;
use React\Socket\SocketServer;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ProcessContainment;

/**
 * The TCP listener, owned here rather than inside react/socket's
 * `TcpServer` because the server must hold its descriptors (o0-spikes (c)):
 *
 * - the listening socket and every accepted one are REGISTERED with
 *   {@see ForkedChild::registerServerStream()}, so a forked turn child closes
 *   them first thing ({@see ForkedChild::closeInheritedServerFds()});
 * - each is marked FD_CLOEXEC ({@see ProcessContainment::closeOnExec()}), so a
 *   tool process the server itself spawns never inherits them across exec;
 * - a closed connection is FORGOTTEN, because its descriptor number may be
 *   reused by something a turn child legitimately needs.
 *
 * Otherwise this is `TcpServer`'s accept loop: non-blocking accept on the
 * shared loop, one `connection` event per socket, `error` on a failed accept.
 */
final class Listener extends EventEmitter implements ServerInterface
{
    /** @var resource|null */
    private $master;

    private bool $listening = false;

    private function __construct(private readonly LoopInterface $loop)
    {
    }

    /**
     * Bind $uri (`tcp://host:port`; port 0 picks a free one) and start
     * accepting.
     *
     * @throws \RuntimeException naming the address and the OS reason (e.g.
     *         "Address already in use")
     */
    public static function bind(string $uri, LoopInterface $loop): self
    {
        $listener = new self($loop);
        $master = @\stream_socket_server(
            $uri,
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            \stream_context_create(['socket' => ['backlog' => 511]]),
        );
        if ($master === false) {
            throw new \RuntimeException(\sprintf(
                'cannot listen on %s: %s',
                \substr($uri, \strlen('tcp://')),
                $errstr !== '' ? $errstr : 'error ' . $errno,
            ), $errno);
        }

        \stream_set_blocking($master, false);
        ProcessContainment::closeOnExec($master);
        ForkedChild::registerServerStream($master);
        $listener->master = $master;
        $listener->resume();

        return $listener;
    }

    public function getAddress(): ?string
    {
        if (!\is_resource($this->master)) {
            return null;
        }

        $name = (string) \stream_socket_get_name($this->master, false);
        $colon = \strrpos($name, ':');
        if ($colon !== false && \strpos($name, ':') < $colon && !\str_starts_with($name, '[')) {
            $name = '[' . \substr($name, 0, $colon) . ']:' . \substr($name, $colon + 1);
        }

        return 'tcp://' . $name;
    }

    /** The bound port (the real one when 0 was asked for). */
    public function port(): ?int
    {
        $address = $this->getAddress();
        if ($address === null) {
            return null;
        }

        return (int) \substr($address, (int) \strrpos($address, ':') + 1);
    }

    public function pause(): void
    {
        if ($this->listening && \is_resource($this->master)) {
            $this->loop->removeReadStream($this->master);
        }
        $this->listening = false;
    }

    public function resume(): void
    {
        if ($this->listening || !\is_resource($this->master)) {
            return;
        }

        $this->loop->addReadStream($this->master, function ($master): void {
            try {
                $socket = SocketServer::accept($master);
            } catch (\RuntimeException $e) {
                $this->emit('error', [$e]);

                return;
            }

            ProcessContainment::closeOnExec($socket);
            ForkedChild::registerServerStream($socket);
            $connection = new Connection($socket, $this->loop);
            $connection->on('close', static fn () => ForkedChild::forgetServerStream($socket));
            $this->emit('connection', [$connection]);
        });
        $this->listening = true;
    }

    public function close(): void
    {
        if (!\is_resource($this->master)) {
            return;
        }

        $this->pause();
        ForkedChild::forgetServerStream($this->master);
        \fclose($this->master);
        $this->master = null;
        $this->removeAllListeners();
    }
}
