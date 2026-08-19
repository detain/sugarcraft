<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg dispatched once the background summarization call `/compact`
 * scheduled has produced model-written one-liners for the exchanges it is about
 * to condense (crush_code.md Phase 5 item 6).
 *
 * Carried by the Cmd {@see Chat::scheduleModelCompaction()} returns. The
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
     *                             how `/clear`, `/rewind` and the palette's New
     *                             session action each abandon one — see that
     *                             property's docblock for why those three and no
     *                             others. Deliberately not the generation counter
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
     */
    public function __construct(
        public readonly string $compactionId,
        public readonly array $summaries = [],
        public readonly ?string $error = null,
        public readonly ?Usage $usage = null,
    ) {}
}
