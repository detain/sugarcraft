<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol;

use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Ws\Outbox;
use SugarCraft\Code\Server\Ws\Peer;

/**
 * One connected client as the protocol sees it: its connection and
 * {@see Outbox}, whether it has said `server.hello`, what it said there, what
 * it may do, what it is looking at, and its request budget.
 *
 * MUTABLE ON PURPOSE: it is the live state of one socket, owned by the
 * {@see Dispatcher} from open to close.
 */
final class Client
{
    /** Appendix O §6.9: requests per second, sustained. */
    public const RATE_PER_SECOND = 50.0;

    /** Appendix O §6.9: the burst a client may spend at once. */
    public const RATE_BURST = 200.0;

    /**
     * Bytes the byte meter (CWPD-1) refills per second, sustained. The
     * request bucket counts ROUNTRIPS; this one counts WIRE — a client that
     * floods 16 MiB frames spends the meter at ~8 MiB/s however cheap each
     * frame is to answer, so a hostile socket cannot keep the server
     * decoding at full rate forever. Half the frame ceiling per second: one
     * legal maximum frame per two seconds of steady flood.
     */
    public const BYTE_RATE_PER_SECOND = 8_388_608.0;

    /**
     * Bytes the meter holds at rest — exactly the transport's own client
     * frame ceiling ({@see ServerConfig::MAX_CLIENT_FRAME_BYTES}, pinned by
     * that equality), so ONE legal maximum frame always fits from an idle
     * bucket: the meter refuses floods, never a legitimate single
     * `session.import`. The pre-existing `json_decode` ceiling only refused
     * AFTER the parse, so up to 16 MiB of hostile text reached the parser
     * per frame; the meter refuses before it.
     */
    public const BYTE_BURST = 16_777_216.0;

    private bool $initialized = false;

    /** @var array<string, mixed> */
    private array $info = [];

    /** @var list<string> */
    private array $caps = [];

    /** @var list<string> */
    private array $viewing = [];

    private ?string $foreground = null;

    private bool $narrateBackground = false;

    private float $budget = self::RATE_BURST;

    private float $refilledAt;

    private float $byteBudget = self::BYTE_BURST;

    private float $bytesRefilledAt;

    /**
     * A socket client passes its {@see Outbox}. An in-process client passes
     * $deliver instead, and every event reaches it as the {@see EventEnvelope}
     * itself, with nothing encoded (PL-5c).
     *
     * @param list<string> $scopes
     * @param \Closure(): float $clock
     * @param (\Closure(EventEnvelope): void)|null $deliver
     */
    public function __construct(
        private readonly Peer $peer,
        private readonly ?Outbox $outbox,
        private readonly array $scopes,
        private readonly \Closure $clock,
        private readonly ?\Closure $deliver = null,
    ) {
        if (($outbox === null) === ($deliver === null)) {
            throw new \InvalidArgumentException('A client is either a socket (an outbox) or in-process (a deliver closure), never both or neither.');
        }
        $this->refilledAt = ($this->clock)();
        $this->bytesRefilledAt = $this->refilledAt;
    }

    public function id(): string
    {
        return $this->peer->id();
    }

    /** Who is on the other end: a socket {@see \SugarCraft\Code\Server\Ws\Connection}, or the in-process peer. */
    public function connection(): Peer
    {
        return $this->peer;
    }

    /** Whether this client is the in-process host: no socket and no outbox. */
    public function isInProcess(): bool
    {
        return $this->outbox === null;
    }

    /** The socket's outbox. An in-process client has none; its events go through {@see emit()}. */
    public function outbox(): Outbox
    {
        return $this->outbox ?? throw new \LogicException('An in-process client has no outbox: its events reach it through emit().');
    }

    /** Whether a socket's queue is past the soft watermark; never true for an in-process client. */
    public function isBackedUp(): bool
    {
        return $this->outbox !== null && $this->outbox->isAboveSoft();
    }

    /** How the socket authenticated (`cookie`, `ticket`, `bearer`, `subprotocol`, `viewer`). */
    public function principal(): string
    {
        return $this->peer->principal();
    }

    /** The viewer grant's public id on the socket's credential; null for every other door. */
    public function tokenId(): ?string
    {
        return $this->peer->tokenId();
    }

    /** The viewer grant's session pin list riding the socket; null when unpinned. */
    public function viewerSessions(): ?array
    {
        return $this->peer->viewerSessions();
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function hasScope(Scope $scope): bool
    {
        return \in_array($scope->value, $this->scopes, true);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * @param array<string, mixed> $info the client's `server.hello` `client` object
     * @param list<string> $caps
     */
    public function initialize(array $info, array $caps): void
    {
        $this->initialized = true;
        $this->info = $info;
        $this->caps = $caps;
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        return $this->info;
    }

    /** @return list<string> */
    public function caps(): array
    {
        return $this->caps;
    }

    /**
     * What the client says it is showing (`client.viewing`), and whether the
     * sessions it follows but does not have in front should be narrated
     * rather than streamed (a multi-pane grid: one tile in focus, the rest
     * glanced at — Appendix O §7.4).
     *
     * @param list<string> $sessionIds
     */
    public function view(array $sessionIds, ?string $foreground, bool $narrateBackground = false): void
    {
        $this->viewing = $sessionIds;
        $this->foreground = $foreground;
        $this->narrateBackground = $narrateBackground;
    }

    /** @return list<string> */
    public function viewing(): array
    {
        return $this->viewing;
    }

    /**
     * Whether $sessionId is in front of the user — the one subscription the
     * soft watermark never downgrades to narration. A client that never said
     * counts every session as in front.
     */
    public function isForeground(string $sessionId): bool
    {
        if ($this->foreground !== null) {
            return $this->foreground === $sessionId;
        }

        return $this->viewing === [] || \in_array($sessionId, $this->viewing, true);
    }

    /** Whether this client asked for its background subscriptions to be narrated. */
    public function narratesBackground(): bool
    {
        return $this->narrateBackground;
    }

    /**
     * Whether $sessionId should reach this client as narration because the
     * client asked so (`client.viewing` with `narrate`) and it is not in
     * front. The soft watermark's own downgrade is the feed's to add.
     */
    public function wantsNarration(string $sessionId): bool
    {
        return $this->narrateBackground && !$this->isForeground($sessionId);
    }

    /**
     * Spend one request from the budget (a token bucket: {@see RATE_BURST}
     * at once, refilled at {@see RATE_PER_SECOND}); false when it is empty.
     */
    public function admit(): bool
    {
        $now = ($this->clock)();
        // CWPD-11: elapsed time is never negative. A clock stepping backwards
        // (NTP correction, a rebased container clock, a scripted harness) used
        // to be credited as drain — 10 seconds of retreat emptied a 200-request
        // burst to -300 and the client was refused everything until the clock
        // caught up. Refill on forward time only; a backward step is simply
        // "no time has passed for me".
        $this->budget = \min(self::RATE_BURST, $this->budget + \max(0.0, $now - $this->refilledAt) * self::RATE_PER_SECOND);
        $this->refilledAt = $now;
        if ($this->budget < 1.0) {
            return false;
        }
        $this->budget -= 1.0;

        return true;
    }

    /**
     * Spend $bytes from the byte meter (a token bucket: {@see BYTE_BURST}
     * at rest, refilled at {@see BYTE_RATE_PER_SECOND}); false when the
     * meter cannot cover the whole frame. The dispatcher consults it on
     * every message BEFORE decoding (CWPD-1): an oversized flood is refused
     * as wire, never parsed.
     */
    public function admitBytes(int $bytes): bool
    {
        $now = ($this->clock)();
        $this->byteBudget = \min(self::BYTE_BURST, $this->byteBudget + \max(0.0, $now - $this->bytesRefilledAt) * self::BYTE_RATE_PER_SECOND);
        $this->bytesRefilledAt = $now;
        if ($this->byteBudget < $bytes) {
            return false;
        }
        $this->byteBudget -= $bytes;

        return true;
    }

    /** Queue a message that must arrive on a socket. An in-process client has no wire: use {@see emit()}. */
    public function send(string $text): void
    {
        $this->outbox()->push($text);
    }

    /**
     * Hand one session or server event to this client, in the form its transport takes.
     *
     * A socket client gets the JSON-RPC notification: a $mustArrive event goes out whole
     * ($frame, when given, is its wire text, encoded once for every subscriber), and any
     * other event is queued ephemerally under $coalesceKey, where the outbox may shed or
     * merge it. An in-process client gets the envelope itself and nothing is encoded, so
     * nothing is shed either: every event is delivered, in the order it was emitted.
     *
     * @param (\Closure(): string)|null $frame the wire text of $envelope, computed on demand
     */
    public function emit(EventEnvelope $envelope, bool $mustArrive = false, ?string $coalesceKey = null, ?\Closure $frame = null): void
    {
        if ($this->deliver !== null) {
            ($this->deliver)($envelope);

            return;
        }
        if ($mustArrive) {
            $this->send($frame !== null ? $frame() : JsonRpc::encode($envelope->notification()));

            return;
        }
        $this->outbox()->pushEphemeral($envelope->notification(), $coalesceKey);
    }
}
