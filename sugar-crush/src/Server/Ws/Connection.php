<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Ws;

use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * One upgraded WebSocket: RFC 6455 framing over the duplex stream react/http
 * hands a 101 response (o0-spikes "upgrade pattern").
 *
 * The codec is `ratchet/rfc6455`'s {@see MessageBuffer}, so masking,
 * fragmentation, UTF-8 validation and close-code checks are not reimplemented
 * here. What this class adds is POLICY:
 * - the {@see \SugarCraft\Crush\Server\ServerConfig::MAX_CLIENT_MESSAGE_BYTES}
 *   ceiling, closing 1009 past it;
 * - text only — a binary message closes 1003 (`sugarcrush.v1` is JSON);
 * - ping answered with pong, a client close echoed and the stream ended;
 * - a throwing handler closes 1011 rather than tearing down the server;
 * - exactly one {@see MessageHandler::onClose()} however the socket ends;
 * - BACKPRESSURE made visible (roadmap O-3b, Appendix O §6.9): when the
 *   socket's write buffer is full the stream react/http pipes into it is
 *   paused, a write reports it, and {@see isCongested()} stays true until
 *   the socket drains and {@see onDrain()}'s listeners run. The bytes already
 *   handed over are never lost; the per-connection {@see Outbox} uses this to
 *   stop handing over more.
 *
 * `permessage-deflate` stays off (Appendix O §6.1): latency, CPU, and the
 * compression-oracle risk of secrets beside attacker-influenced text.
 */
final class Connection
{
    private readonly MessageBuffer $buffer;

    private bool $closed = false;

    /** The close code sent or received; 1006 until there is one. */
    private int $closeCode = Frame::CLOSE_ABNORMAL;

    /** Whether the last write found the socket's buffer full. */
    private bool $congested = false;

    /** @var list<\Closure(): void> */
    private array $drainListeners = [];

    /**
     * @param \Closure(self): void|null $onGone the server's bookkeeping, after the handler's onClose
     */
    public function __construct(
        private readonly string $id,
        private readonly string $principal,
        private readonly WritableStreamInterface $toClient,
        ReadableStreamInterface $fromClient,
        private readonly MessageHandler $handler,
        int $maxMessageBytes,
        private readonly ?\Closure $onGone = null,
    ) {
        $this->buffer = new MessageBuffer(
            new CloseFrameChecker(),
            fn (MessageInterface $message) => $this->onMessage($message),
            fn (FrameInterface $frame) => $this->onControl($frame),
            true,
            null,
            $maxMessageBytes,
            $maxMessageBytes,
            function (string $bytes): void {
                if (!$this->toClient->write($bytes)) {
                    $this->congested = true;
                }
            },
        );

        $fromClient->on('data', function (string $data): void {
            if (!$this->closed) {
                $this->buffer->onData($data);
            }
        });
        $fromClient->on('close', fn () => $this->gone());
        $toClient->on('close', fn () => $this->gone());
        $toClient->on('drain', function (): void {
            $this->congested = false;
            foreach ($this->drainListeners as $listener) {
                $listener();
            }
        });
    }

    /** Whether the socket's write buffer was full at the last write and has not drained since. */
    public function isCongested(): bool
    {
        return $this->congested;
    }

    /**
     * Run $listener each time a congested socket drains.
     *
     * @param \Closure(): void $listener
     */
    public function onDrain(\Closure $listener): void
    {
        $this->drainListeners[] = $listener;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** How the upgrade authenticated: `cookie`, `ticket`, `bearer` or `subprotocol`. */
    public function principal(): string
    {
        return $this->principal;
    }

    public function isOpen(): bool
    {
        return !$this->closed;
    }

    /** Send one text message; false once the connection is closing. */
    public function send(string $text): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->buffer->sendMessage($text);

        return true;
    }

    /** Close with $code (RFC 6455 §7.4) and end the stream. Idempotent. */
    public function close(int $code = Frame::CLOSE_NORMAL, string $reason = ''): void
    {
        if ($this->closed) {
            return;
        }

        $this->closeCode = $code;
        $this->buffer->sendFrame($this->buffer->newCloseFrame($code, \substr($reason, 0, 123)));
        $this->toClient->end();
        $this->gone();
    }

    private function onMessage(MessageInterface $message): void
    {
        if ($message->isBinary()) {
            $this->close(Frame::CLOSE_BAD_DATA, 'text frames only');

            return;
        }

        try {
            $this->handler->onMessage($this, $message->getPayload());
        } catch (\Throwable) {
            $this->close(Frame::CLOSE_SRV_ERR, 'internal error');
        }
    }

    /**
     * A control frame from the client, OR a close frame the codec built
     * itself for a protocol violation (oversize, bad mask, bad UTF-8) — both
     * arrive here, and both mean "echo the code and end".
     */
    private function onControl(FrameInterface $frame): void
    {
        switch ($frame->getOpcode()) {
            case Frame::OP_PING:
                if (!$this->closed) {
                    $this->buffer->sendFrame($this->buffer->newFrame($frame->getPayload(), true, Frame::OP_PONG));
                }
                break;
            case Frame::OP_CLOSE:
                $payload = $frame->getPayload();
                $code = \strlen($payload) >= 2 ? (int) \unpack('n', \substr($payload, 0, 2))[1] : Frame::CLOSE_NORMAL;
                $this->close($code === Frame::CLOSE_NO_STATUS ? Frame::CLOSE_NORMAL : $code);
                break;
        }
    }

    private function gone(): void
    {
        $first = !$this->closed;
        $this->closed = true;
        if (!$first) {
            return;
        }
        $this->drainListeners = [];

        try {
            $this->handler->onClose($this, $this->closeCode);
        } finally {
            if ($this->onGone !== null) {
                ($this->onGone)($this);
            }
        }
    }
}
