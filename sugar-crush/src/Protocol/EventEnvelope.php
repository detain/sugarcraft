<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

use SugarCraft\Crush\Host\SessionEvent;

/**
 * One event as the wire carries it (Appendix O §6.4): the `params` of an
 * `event` notification.
 *
 *     {sessionId, seq?, type, ts, turnId?, durable, data}
 *
 * `seq` is present only on a durable session event the log numbered, and a
 * client never advances its cursor on an event without one. The same shape
 * comes from three places — an event heard live, a row replayed from
 * `session_events`, and a server-scope event — so a client cannot tell a
 * replayed event from a live one, which is the point.
 */
final class EventEnvelope
{
    /** The JSON-RPC notification method every event rides in. */
    public const METHOD = 'event';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public readonly ?string $sessionId,
        public readonly ?int $seq,
        public readonly string $type,
        public readonly int $ts,
        public readonly ?string $turnId,
        public readonly bool $durable,
        public readonly array $data,
    ) {
    }

    public static function fromSessionEvent(SessionEvent $event): self
    {
        return new self($event->sessionId, $event->seq, $event->type, $event->ts, $event->turnId, $event->isDurable(), $event->data);
    }

    /**
     * A durable event read back from the log ({@see \SugarCraft\Crush\Session\EnhancedSessionStore::sessionEvents()}).
     * The log keeps the envelope's `turnId` inside its payload; it is lifted
     * back out here.
     *
     * @param array{seq: int, ts: int, type: string, payload: array<string, mixed>} $row
     */
    public static function fromLogRow(string $sessionId, array $row): self
    {
        $data = $row['payload'];
        $turnId = isset($data['turnId']) && \is_string($data['turnId']) ? $data['turnId'] : null;
        unset($data['turnId']);

        return new self($sessionId, $row['seq'], $row['type'], $row['ts'], $turnId, true, $data);
    }

    /**
     * A server-scope event: no session, no seq.
     *
     * @param array<string, mixed> $data
     */
    public static function server(string $type, array $data = [], ?int $tsMs = null): self
    {
        return new self(null, null, $type, $tsMs ?? (int) \floor(\microtime(true) * 1000), null, false, $data);
    }

    /**
     * A live-only event of a session (narration).
     *
     * @param array<string, mixed> $data
     */
    public static function ephemeral(string $sessionId, string $type, array $data, ?string $turnId = null): self
    {
        return new self($sessionId, null, $type, (int) \floor(\microtime(true) * 1000), $turnId, false, $data);
    }

    /**
     * A copy with $data merged over this event's data (the feed adds a
     * delta's `partId` and `offset`, which only the live stream can know).
     *
     * @param array<string, mixed> $data
     */
    public function withData(array $data): self
    {
        return new self($this->sessionId, $this->seq, $this->type, $this->ts, $this->turnId, $this->durable, [...$this->data, ...$data]);
    }

    /**
     * Whether a client's cursor moves on this event.
     */
    public function isNumbered(): bool
    {
        return $this->durable && $this->seq !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['sessionId' => $this->sessionId];
        if ($this->seq !== null) {
            $out['seq'] = $this->seq;
        }
        $out['type'] = $this->type;
        $out['ts'] = $this->ts;
        if ($this->turnId !== null) {
            $out['turnId'] = $this->turnId;
        }
        $out['durable'] = $this->durable;
        $out['data'] = $this->data === [] ? new \stdClass() : $this->data;

        return $out;
    }

    /**
     * The `event` notification carrying this envelope.
     *
     * @return array{jsonrpc: string, method: string, params: array<string, mixed>}
     */
    public function notification(): array
    {
        return JsonRpc::notification(self::METHOD, $this->toArray());
    }
}
