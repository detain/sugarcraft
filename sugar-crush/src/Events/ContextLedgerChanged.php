<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Context\Pruning\LedgerDelta;

/**
 * "The turn's context ledger just moved" — what a ledger-mutating tool (the
 * model's `Prune`, roadmap 3.B-3, DCP §13.2 G) did to what the model is sent,
 * as the {@see LedgerDelta} it applied.
 *
 * The turn applies the delta to its own ledger at once, so its next request
 * is projected through it; this event is the live copy for the host, which
 * keeps the session's ledger and can dim the pruned rows while the turn is
 * still running. Observation only: the ledger the turn ENDS with still
 * arrives whole on the reply ({@see \SugarCraft\Crush\Message::$contextLedger}),
 * so a consumer that drops this event loses nothing but the early view, and
 * {@see \SugarCraft\Crush\Context\Pruning\ContextLedger::apply()} is
 * idempotent, so one that applies it and then the turn's final ledger
 * applies nothing twice.
 *
 * {@see toArray()} / {@see fromArray()} are its frame form: plain arrays
 * only, because the parent unserializes a child's frames with
 * `allowed_classes => false`.
 */
final readonly class ContextLedgerChanged
{
    /**
     * @param LedgerDelta $delta      what changed
     * @param string      $toolCallId the call that changed it ('' when not a call)
     */
    public function __construct(
        public LedgerDelta $delta,
        public string $toolCallId = '',
    ) {
    }

    /** @return array{delta:array<string,mixed>,toolCallId:string} the frame's fields */
    public function toArray(): array
    {
        return [
            'delta' => $this->delta->toArray(),
            'toolCallId' => $this->toolCallId,
        ];
    }

    /**
     * Rebuild from {@see toArray()}; null for a shape it did not write, or
     * for a delta with nothing left in it once its unreadable entries were
     * skipped — there is nothing to apply.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw) || !is_array($raw['delta'] ?? null)) {
            return null;
        }
        $delta = LedgerDelta::fromArray($raw['delta']);
        if ($delta->isEmpty()) {
            return null;
        }

        return new self($delta, is_string($raw['toolCallId'] ?? null) ? $raw['toolCallId'] : '');
    }
}
