<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;

/**
 * One end of a connected socket pair carrying length-prefixed frames between a
 * parent process and a forked child.
 *
 * Each frame is a 4-byte big-endian length followed by the payload, so a
 * frame arrives whole regardless of how the kernel splits the reads. {@see send()}
 * blocks until the kernel accepts every byte; {@see onFrame()} reads on the
 * loop and delivers each complete frame. A frame larger than
 * {@see MAX_FRAME_BYTES} is refused on send and treated as a broken peer on
 * receive, so a corrupt length cannot make the reader buffer without bound.
 *
 * Mirrors the socketpair frame channels candy-code's child-process relays and
 * candy-git-server's WorkerFork hand-rolled.
 */
final class ForkChannel
{
    /** Largest payload a single frame may carry, in bytes. */
    public const MAX_FRAME_BYTES = 16 * 1024 * 1024;

    private const CHUNK_BYTES = 65536;

    private string $inbound = '';
    private bool $open = true;
    private ?LoopInterface $loop = null;
    private ?\Closure $onFrame = null;
    private ?\Closure $onClose = null;

    /** @var resource */
    private $stream;

    /** @param resource $stream */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * A connected pair. Windows cannot pair AF_UNIX sockets, so it uses the
     * loopback-TCP emulation PHP provides there instead.
     *
     * @return array{0: self, 1: self}
     */
    public static function pair(): array
    {
        $domain = \PHP_OS_FAMILY === 'Windows' ? \STREAM_PF_INET : \STREAM_PF_UNIX;
        $streams = \stream_socket_pair($domain, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($streams === false) {
            throw new \RuntimeException('ForkChannel: stream_socket_pair() failed');
        }

        return [new self($streams[0]), new self($streams[1])];
    }

    /** True until {@see close()} runs or the peer goes away. */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Write one frame, blocking until the kernel has taken all of it.
     *
     * @throws \InvalidArgumentException  When the payload exceeds MAX_FRAME_BYTES
     * @throws \RuntimeException          When the channel is closed or the peer has gone
     */
    public function send(string $frame): void
    {
        if (!$this->open) {
            throw new \RuntimeException('ForkChannel is closed');
        }
        $size = strlen($frame);
        if ($size > self::MAX_FRAME_BYTES) {
            throw new \InvalidArgumentException('ForkChannel frame of ' . $size . ' bytes exceeds the ' . self::MAX_FRAME_BYTES . ' byte cap');
        }
        $bytes = pack('N', $size) . $frame;
        $total = strlen($bytes);
        $written = 0;
        while ($written < $total) {
            $wrote = @\fwrite($this->stream, substr($bytes, $written, self::CHUNK_BYTES));
            if ($wrote === false || ($wrote === 0 && \feof($this->stream))) {
                throw new \RuntimeException('ForkChannel: peer closed while sending');
            }
            if ($wrote === 0) {
                // Nonblocking backpressure (a full kernel buffer), not a closed
                // peer: wait for the stream to drain, then write the same bytes again.
                $read = null;
                $write = [$this->stream];
                $except = null;
                @\stream_select($read, $write, $except, 1);
                continue;
            }
            $written += $wrote;
        }
    }

    /**
     * Deliver each complete inbound frame to $onFrame, read on $loop.
     *
     * Registering a reader is one-way: a channel has a single frame handler,
     * and replacing it is refused rather than silently rerouting frames.
     *
     * @param callable(string): void $onFrame
     */
    public function onFrame(callable $onFrame, LoopInterface $loop): void
    {
        if (!$this->open) {
            throw new \RuntimeException('ForkChannel is closed');
        }
        if ($this->onFrame !== null) {
            throw new \LogicException('ForkChannel already has a frame handler');
        }
        $this->onFrame = \Closure::fromCallable($onFrame);
        $this->loop = $loop;
        \stream_set_blocking($this->stream, false);
        $loop->addReadStream($this->stream, function (): void {
            $this->readable();
        });
    }

    /**
     * Run $onClose once when the channel stops, whether the peer hung up or
     * {@see close()} ran.
     *
     * @param callable(): void $onClose
     */
    public function onClose(callable $onClose): void
    {
        $this->onClose = \Closure::fromCallable($onClose);
    }

    /** Stop reading and release the stream. Idempotent. */
    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        if ($this->loop !== null) {
            $this->loop->removeReadStream($this->stream);
        }
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
        $this->inbound = '';
        $this->onFrame = null;
        $onClose = $this->onClose;
        $this->onClose = null;
        if ($onClose !== null) {
            $onClose();
        }
    }

    private function readable(): void
    {
        $chunk = @\fread($this->stream, self::CHUNK_BYTES);
        if ($chunk === false || ($chunk === '' && \feof($this->stream))) {
            $this->close();
            return;
        }
        $this->inbound .= $chunk;
        // Walk the buffer with an offset and trim it once after the loop: a read
        // holding thousands of small frames would otherwise re-copy the whole
        // remainder per frame, which is quadratic on the parent's loop.
        $offset = 0;
        $total = strlen($this->inbound);
        while ($total - $offset >= 4) {
            $size = unpack('N', $this->inbound, $offset)[1];
            if ($size > self::MAX_FRAME_BYTES) {
                // A length no honest peer sends: the stream is no longer framed.
                $this->close();
                return;
            }
            if ($total - $offset < 4 + $size) {
                break;
            }
            $frame = substr($this->inbound, $offset + 4, $size);
            $offset += 4 + $size;
            if ($this->onFrame !== null) {
                ($this->onFrame)($frame);
            }
            if (!$this->open) {
                return;
            }
        }
        $this->inbound = (string) substr($this->inbound, $offset);
    }
}
