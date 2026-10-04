<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Ws;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * One connection's queue of outgoing messages, with byte accounting
 * (roadmap O-3b, Appendix O §6.9).
 *
 * THE TURN NEVER BLOCKS ON A CLIENT. Events go host → log → every outbox, and
 * an outbox hands its connection only what the socket will take: once a write
 * finds the socket's buffer full ({@see Connection::isCongested()}) it holds
 * the rest until the socket drains. A slow browser costs this queue memory and
 * nothing else — never the fork socket a turn reports on.
 *
 * THE WATERMARKS bound that memory, and both are advertised in `server.hello`:
 *
 * - above {@see SOFT_WATERMARK_BYTES} a queued delta (`assistant.delta`,
 *   `reasoning.delta`) is COALESCED with the one queued just before it for the
 *   same part — the text concatenated, the first offset kept — which is
 *   lossless, and the caller is told so it can move the connection's
 *   background subscriptions to narration ({@see isAboveSoft()});
 * - above {@see HARD_WATERMARK_BYTES} every queued EPHEMERAL message is
 *   dropped and replaced by one overflow notice (the caller's
 *   `$overflowNotice`, which tells the client to resubscribe), and if the
 *   queue is still above the mark {@see OVERFLOW_GRACE_SECONDS} later the
 *   connection is closed with 1013 ("try again later"). Durable events are
 *   never dropped silently: they stay queued until that close, and a client
 *   recovers them by replaying from its cursor.
 */
final class Outbox
{
    public const SOFT_WATERMARK_BYTES = 1_048_576;

    public const HARD_WATERMARK_BYTES = 16_777_216;

    public const OVERFLOW_GRACE_SECONDS = 10.0;

    /** RFC 6455 "Try Again Later": the server is shedding this client. */
    public const CLOSE_TRY_AGAIN_LATER = 1013;

    /**
     * @var list<array{bytes: string, ephemeral: bool, coalesce: ?string, message: ?array<string, mixed>}>
     */
    private array $queue = [];

    private int $buffered = 0;

    private bool $overflowing = false;

    private int $dropped = 0;

    private ?TimerInterface $graceTimer = null;

    private bool $flushing = false;

    /**
     * @param (\Closure(int): string)|null $overflowNotice the message that tells
     *        the client how many ephemeral messages were dropped
     */
    private function __construct(
        private readonly Connection $connection,
        private readonly LoopInterface $loop,
        private readonly int $softBytes,
        private readonly int $hardBytes,
        private readonly float $graceSeconds,
        private readonly ?\Closure $overflowNotice,
    ) {
        $connection->onDrain(fn () => $this->flush());
    }

    /**
     * @param (\Closure(int): string)|null $overflowNotice
     */
    public static function for(
        Connection $connection,
        ?LoopInterface $loop = null,
        ?\Closure $overflowNotice = null,
        int $softBytes = self::SOFT_WATERMARK_BYTES,
        int $hardBytes = self::HARD_WATERMARK_BYTES,
        float $graceSeconds = self::OVERFLOW_GRACE_SECONDS,
    ): self {
        if ($softBytes < 1 || $hardBytes < $softBytes) {
            throw new \InvalidArgumentException(\sprintf('Outbox watermarks must satisfy 1 <= soft <= hard; got %d and %d.', $softBytes, $hardBytes));
        }

        return new self($connection, $loop ?? Loop::get(), $softBytes, $hardBytes, $graceSeconds, $overflowNotice);
    }

    /** A message that must arrive: a response, or a durable event. */
    public function push(string $text): void
    {
        $this->enqueue(['bytes' => $text, 'ephemeral' => false, 'coalesce' => null, 'message' => null]);
    }

    /**
     * A live-only message: dropped under overflow. With $coalesceKey and a
     * JSON-RPC notification whose `params.data.text` is a string, it may be
     * merged into the queued message just before it with the same key.
     *
     * @param array<string, mixed> $message the whole JSON-RPC message
     */
    public function pushEphemeral(array $message, ?string $coalesceKey = null): void
    {
        if ($this->overflowing && $this->buffered > $this->hardBytes) {
            $this->dropped++;

            return;
        }

        if ($coalesceKey !== null && $this->buffered > $this->softBytes && $this->queue !== []) {
            $last = \array_key_last($this->queue);
            $tail = $this->queue[$last];
            $merged = $tail['message'] === null || $tail['coalesce'] !== $coalesceKey ? null : self::coalesced($tail['message'], $message);
            if ($merged !== null) {
                $bytes = self::encode($merged);
                $this->buffered += \strlen($bytes) - \strlen($tail['bytes']);
                $this->queue[$last] = ['bytes' => $bytes, 'ephemeral' => true, 'coalesce' => $coalesceKey, 'message' => $merged];
                $this->flush();

                return;
            }
        }

        $this->enqueue(['bytes' => self::encode($message), 'ephemeral' => true, 'coalesce' => $coalesceKey, 'message' => $message]);
    }

    /** Bytes held here, not yet handed to the socket. */
    public function bufferedBytes(): int
    {
        return $this->buffered;
    }

    /** Messages held here, not yet handed to the socket. */
    public function count(): int
    {
        return \count($this->queue);
    }

    /** Whether the queue is past the soft watermark (coalescing, narration). */
    public function isAboveSoft(): bool
    {
        return $this->buffered > $this->softBytes;
    }

    /** Whether ephemeral messages are being shed. */
    public function isOverflowing(): bool
    {
        return $this->overflowing;
    }

    /** Ephemeral messages dropped since the last overflow notice. */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /** Hand the socket everything it will take now. */
    public function flush(): void
    {
        if ($this->flushing) {
            return;
        }
        $this->flushing = true;
        try {
            while ($this->queue !== [] && $this->connection->isOpen() && !$this->connection->isCongested()) {
                $entry = \array_shift($this->queue);
                $this->buffered -= \strlen($entry['bytes']);
                $this->connection->send($entry['bytes']);
            }
        } finally {
            $this->flushing = false;
        }

        if (!$this->connection->isOpen()) {
            $this->discard();

            return;
        }
        if ($this->overflowing && $this->buffered <= $this->softBytes) {
            $this->overflowing = false;
            $this->dropped = 0;
            $this->cancelGrace();
        }
    }

    /** Forget everything queued (the connection is gone). */
    public function discard(): void
    {
        $this->queue = [];
        $this->buffered = 0;
        $this->cancelGrace();
    }

    /**
     * @param array{bytes: string, ephemeral: bool, coalesce: ?string, message: ?array<string, mixed>} $entry
     */
    private function enqueue(array $entry): void
    {
        if (!$this->connection->isOpen()) {
            return;
        }
        $this->queue[] = $entry;
        $this->buffered += \strlen($entry['bytes']);
        $this->flush();

        if ($this->buffered > $this->hardBytes) {
            $this->overflow();
        }
    }

    /**
     * Past the hard mark: shed every queued ephemeral message, tell the client
     * once, and arm the 1013 close for a queue that stays this full.
     */
    private function overflow(): void
    {
        $kept = [];
        foreach ($this->queue as $entry) {
            if ($entry['ephemeral']) {
                $this->dropped++;
                $this->buffered -= \strlen($entry['bytes']);

                continue;
            }
            $kept[] = $entry;
        }
        $this->queue = $kept;

        if (!$this->overflowing) {
            $this->overflowing = true;
            if ($this->overflowNotice !== null) {
                $notice = ($this->overflowNotice)($this->dropped);
                $this->queue[] = ['bytes' => $notice, 'ephemeral' => false, 'coalesce' => null, 'message' => null];
                $this->buffered += \strlen($notice);
            }
            $this->graceTimer = $this->loop->addTimer($this->graceSeconds, function (): void {
                $this->graceTimer = null;
                if ($this->buffered > $this->hardBytes && $this->connection->isOpen()) {
                    $this->discard();
                    $this->connection->close(self::CLOSE_TRY_AGAIN_LATER, 'client too slow; reconnect and resubscribe');
                }
            });
        }
    }

    private function cancelGrace(): void
    {
        if ($this->graceTimer !== null) {
            $this->loop->cancelTimer($this->graceTimer);
            $this->graceTimer = null;
        }
    }

    /**
     * $later appended to $earlier when both are deltas of one part, else null.
     *
     * @param array<string, mixed> $earlier
     * @param array<string, mixed> $later
     * @return array<string, mixed>|null
     */
    private static function coalesced(array $earlier, array $later): ?array
    {
        $a = $earlier['params']['data']['text'] ?? null;
        $b = $later['params']['data']['text'] ?? null;
        if (!\is_string($a) || !\is_string($b)) {
            return null;
        }
        $earlier['params']['data']['text'] = $a . $b;

        return $earlier;
    }

    /** @param array<string, mixed> $message */
    private static function encode(array $message): string
    {
        return (string) \json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
