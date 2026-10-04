<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Backend\QueueMode;

/**
 * How one submission asks to be admitted (roadmap O-2g, Appendix O §4.3
 * `SessionHost::submit(string $text, SubmitOptions $o)` and §6.6).
 *
 * WHY A VALUE AND NOT FLAGS. The same submission arrives from a keyboard
 * (Enter, Tab), from a wire client (`turn.submit {delivery, idempotencyKey}`)
 * and from a queue release, and {@see TurnController} answers all three with
 * one admission rule. What differs between them is only what the sender asked
 * for, so that is what this carries:
 *
 * - **delivery** — what a submission that arrives while a turn is running
 *   should do: {@see QueueMode::Steer} (read at the running turn's next step
 *   boundary, roadmap 1.C-3), {@see QueueMode::Followup} (queued for after it)
 *   or {@see QueueMode::Interrupt} (cancel the running turn, then send). Null
 *   is the host's default, {@see QueueMode::onEnter()} — what Enter does in the
 *   TUI, so a client that names no delivery behaves like the keyboard.
 * - **idempotencyKey** — echoed on the {@see TurnTicket}, so a client that
 *   retries a send can tell its admission from a second one (§6.6).
 * - **resolveMentions** — whether `@file` / `@diff` / `@session:` / `@url`
 *   mentions in the text are attached. False for text the user did not type
 *   (a command file's expansion, a background session's announcement), the
 *   rule {@see TurnController::userTurnMessage()} documents.
 *
 * Immutable and fluent like every value in this package.
 */
final class SubmitOptions
{
    private function __construct(
        public readonly ?QueueMode $delivery,
        public readonly ?string $idempotencyKey,
        public readonly bool $resolveMentions,
    ) {
    }

    /** The keyboard's options: default delivery, mentions resolved, no key. */
    public static function new(): self
    {
        return new self(null, null, true);
    }

    /** A copy asking for $delivery while a turn runs; null is the host default. */
    public function withDelivery(?QueueMode $delivery): self
    {
        return $this->mutate(['delivery' => $delivery]);
    }

    /**
     * A copy carrying $key back on its ticket.
     *
     * @throws \InvalidArgumentException for an empty key; pass null for none
     */
    public function withIdempotencyKey(?string $key): self
    {
        if ($key !== null && trim($key) === '') {
            throw new \InvalidArgumentException('An idempotency key must be non-empty; pass null for none.');
        }

        return $this->mutate(['idempotencyKey' => $key]);
    }

    /** A copy that does (true) or does not (false) attach the text's mentions. */
    public function withResolveMentions(bool $resolve): self
    {
        return $this->mutate(['resolveMentions' => $resolve]);
    }

    /**
     * The delivery this submission gets while a turn is running: the one it
     * asked for, else {@see QueueMode::onEnter()}.
     */
    public function effectiveDelivery(): QueueMode
    {
        return $this->delivery ?? QueueMode::onEnter();
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge([
            'delivery' => $this->delivery,
            'idempotencyKey' => $this->idempotencyKey,
            'resolveMentions' => $this->resolveMentions,
        ], $changes));
    }
}
