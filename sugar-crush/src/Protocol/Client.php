<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

use SugarCraft\Crush\Server\Ws\Connection;
use SugarCraft\Crush\Server\Ws\Outbox;

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

    /**
     * @param list<string> $scopes
     * @param \Closure(): float $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Outbox $outbox,
        private readonly array $scopes,
        private readonly \Closure $clock,
    ) {
        $this->refilledAt = ($this->clock)();
    }

    public function id(): string
    {
        return $this->connection->id();
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function outbox(): Outbox
    {
        return $this->outbox;
    }

    /** How the socket authenticated (`cookie`, `ticket`, `bearer`, `subprotocol`). */
    public function principal(): string
    {
        return $this->connection->principal();
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
        $this->budget = \min(self::RATE_BURST, $this->budget + ($now - $this->refilledAt) * self::RATE_PER_SECOND);
        $this->refilledAt = $now;
        if ($this->budget < 1.0) {
            return false;
        }
        $this->budget -= 1.0;

        return true;
    }

    /** Queue a message that must arrive. */
    public function send(string $text): void
    {
        $this->outbox->push($text);
    }
}
