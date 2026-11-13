<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Core\Msg;

/**
 * Async suggestions landed on a field — the forms-side delivery envelope
 * carrying the fetch generation.
 *
 * The payload mirrors the pre-existing `SugarCraft\Core\Msg\SuggestionsReadyMsg`
 * plus one thing: the sequence number the field stamped when it scheduled
 * this exact fetch. The field's ready handler drops any arrival whose seq
 * does not match the newest scheduled flight (sugar-forms-085 — the counter
 * had been incremented and carried through every mutate site since Phase 6
 * without a single reader; this envelope is that reader) and any arrival
 * whose field has lost focus (sugar-forms-081 — a fetch whose cancellation
 * never fired must not pop the overlay on a field the user already left).
 *
 * The core class stays as the documented concept; delivery moved here so
 * the generation can ride without touching sugar-core (pre-1.0 BC is
 * managed through the REMOVED-IN 2.0.0 cadence).
 */
final readonly class FormSuggestionsReadyMsg implements Msg
{
    /**
     * @param string     $fieldKey   which field the suggestions are for
     * @param list<string> $suggestions the fetched suggestion list
     * @param int        $seq        the field's pendingAsyncSeq value at the
     *                               moment this fetch was scheduled
     */
    public function __construct(
        public string $fieldKey,
        public array $suggestions,
        public int $seq = 0,
    ) {
    }
}
