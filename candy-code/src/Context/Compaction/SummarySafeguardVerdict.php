<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context\Compaction;

/**
 * What the safeguard audit (plan_comparison.md CTX-4) made of a model-written
 * compaction summary. Carried on {@see \SugarCraft\Code\App\HistoryCompactedMsg::$safeguardHold}
 * as a detail string when — and only when — it is HOLD, and read at the three
 * landing sites that consume a model summary: {@see \SugarCraft\Code\Chat::landModelCompaction()}
 * refuses the typed route and degrades the parked one to the heuristic fold, the
 * two background-summary slots (Chat's ahead-of-need arm and the served host's)
 * discard the summaries rather than offer them for a later splice. Every other
 * route, and every PASS and FILL, behaves exactly as it did before this existed.
 *
 * @see SummarySafeguard for what each verdict is decided on
 */
enum SummarySafeguardVerdict
{
    /** Every required section was written and every identifier survived; use the model's block whole. */
    case PASS;

    /** Usable, but partial — missing sections or lost detail. Filled headings and the heuristic cover the gaps; this is today's ordinary path. */
    case FILL;

    /**
     * Structural collapse: the state block the model wrote is missing more
     * than half its model sections AND carries under half the file
     * references. The model text is discarded and the heuristic lands instead
     * — differently by route: `/compact` refuses and preserves the full
     * history, the automatic tier degrades to the heuristic fold, because
     * holding its parked request would 400 on a prompt the user already
     * typed. Partial records are NOT collapse — they are what the heuristic
     * re-fills for — see {@see SummarySafeguard} for the calibration.
     */
    case HOLD;
}
