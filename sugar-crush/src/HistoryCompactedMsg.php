<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg dispatched once a background summarization call has produced
 * model-written one-liners for the exchanges a compaction is about to condense
 * (crush_code.md Phase 5 item 6).
 *
 * TWO ROUTES SCHEDULE ONE, and `$parkedSubmission` is what tells them apart:
 * `/compact` typed by hand ({@see Chat::scheduleModelCompaction()}, null) and
 * the automatic 85% tier ({@see Chat::scheduleParkedCompaction()}, the prompt).
 * The fire-and-forget freedoms described below hold for the first and NOT for
 * the second — a parked tier holds `inFlight` true precisely so a second turn
 * cannot be submitted on top of the one it is about to send.
 *
 * Carried by the Cmd either of those returns. The
 * compaction itself happens in {@see Chat::applyModelCompaction()}, when this
 * lands — NOT when `/compact` was typed. That ordering is the whole point: a
 * provider round-trip inside `update()` would freeze every keystroke for the
 * duration of a completion, which on an LLM call is measured in tens of seconds
 * and can legitimately be minutes.
 *
 * Fire-and-forget in the same sense {@see SessionTitledMsg} is: nothing blocks
 * on it, the user can keep typing and can send another turn while it is out, and
 * the compaction applies to whatever history exists when it arrives. The recent
 * exchanges a compaction preserves in full are the newest ones, so a turn sent
 * in the meantime is preserved rather than summarised.
 *
 * Those two freedoms are the reason {@see Chat::applyModelCompaction()} does not
 * go through {@see Chat::compactNow()}: the synchronous `/compact` clears the
 * draft and `inFlight` because a submitted command legitimately does, and this
 * message must touch neither. It once did, and the two costs were exactly the
 * two freedoms above — a half-typed draft wiped, and a running turn's spinner
 * and Enter-swallow lifted so a second concurrent turn was accepted and the
 * first turn's paid-for reply was then dropped as stale.
 *
 * @see $summaries for why a failed call still produces one of these
 */
final class HistoryCompactedMsg implements Msg
{
    /**
     * @param string $compactionId Identifies the `/compact` that scheduled this.
     *                             {@see Chat::update()} drops the message when it
     *                             does not match `$pendingCompactionId`, which is
     *                             how a second `/compact` supersedes the first and
     *                             how `/clear`, `/rewind`, the palette's New
     *                             session action and the double-Escape cancel arm
     *                             each abandon one — see that property's docblock
     *                             for why those four. Deliberately not the generation counter
     *                             — `/compact` starts no turn and bumps no
     *                             generation.
     * @param array<string, string> $summaries Model-written summaries keyed by
     *                             {@see Context\ContextCompactor::exchangeKey()}.
     *                             May be empty, and empty is not a failure: it
     *                             means the compaction goes ahead on the heuristic
     *                             alone, which is exactly what `/compact` did
     *                             before this existed.
     * @param ?string $error       Why the model produced nothing, or null when it
     *                             produced something. Surfaced in the transcript
     *                             rather than swallowed: a compaction is lossy and
     *                             permanent, so "this one was summarised by the
     *                             fallback heuristic and here is why" is
     *                             information the user needs at the moment it
     *                             happens.
     * @param ?Usage $usage        What the summarization call itself cost, or
     *                             null when the provider reported nothing.
     *                             Carried rather than discarded because this is
     *                             a provider call made on the user's key — a
     *                             spend readout that omits it is not a readout —
     *                             and {@see Chat::update()} accounts it BEFORE
     *                             the latch check, so a superseded or abandoned
     *                             summarization is still billed. Null on the
     *                             failure path: there is no Message to read a
     *                             figure off.
     * @param ?string $parkedSubmission The ordinary prompt whose turn is WAITING
     *                             on this summarization, or null when nothing is
     *                             waiting (the `/compact` route, which starts no
     *                             turn). Non-null is the automatic 85% tier
     *                             ({@see Chat::scheduleParkedCompaction()}): the
     *                             user pressed Enter, the tier decided to ask the
     *                             model before sending, and
     *                             {@see Chat::applyModelCompaction()} dispatches
     *                             the turn once the compaction has run.
     *
     *                             CARRIED ON THE MESSAGE RATHER THAN ON `Chat` on
     *                             purpose: the parked prompt belongs to this one
     *                             round-trip, not to `Chat`'s steady state, so any
     *                             route that abandons a summarization by releasing
     *                             `$pendingCompactionId` drops the parked turn
     *                             with it, with no second field to keep in step
     *                             at four sites. Measured, exactly ONE of those
     *                             four is reachable while a submission is parked
     *                             — the double-Escape cancel arm; `/clear`,
     *                             `/rewind` and the palette's New session all
     *                             need a keystroke that never arrives, because
     *                             {@see Chat::update()}'s `inFlight` swallow eats
     *                             it. The swallow is not total, and an earlier
     *                             revision of this said it left "only the cancel
     *                             arm and Ctrl+C" live: PageUp and PageDown sit
     *                             above it and scroll the transcript during the
     *                             parked window. Neither can abandon a parked
     *                             turn, so the count of one stands. The prompt
     *                             is not lost when a cancel drops it: the tier
     *                             echoes it into the transcript before the request
     *                             leaves.
     */
    public function __construct(
        public readonly string $compactionId,
        public readonly array $summaries = [],
        public readonly ?string $error = null,
        public readonly ?Usage $usage = null,
        public readonly ?string $parkedSubmission = null,
    ) {}
}
