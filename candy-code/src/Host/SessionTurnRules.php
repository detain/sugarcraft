<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Message;

/**
 * The turn judgements the two drivers of one session used to keep as twins.
 *
 * `Chat` (the TUI) and `SessionHost` (the served host) drive one session from
 * two event loops, and for years each carried its own copy of the small,
 * pure decisions listed below — kept honest only by paired `{@see Chat::...}`
 * prose that {@see \SugarCraft\Code\Tests\Host\ChatParityCitationTest()}
 * enforces the existence of. Prose pins that a citation exists; it cannot pin
 * that two bodies agree. These can: each method is THE implementation, called
 * from both drivers, and the deleted twin is one more line of prose the next
 * reader no longer has to trust.
 *
 * The class is all-static on purpose. The host driver's shape is pinned
 * byte-exact by SessionHostSurfaceCensusTest (a golden listing of every
 * constant and method, trait-composed ones included), so handing either
 * driver an instance to hold would drift a structural golden for a value
 * object that carries no state. "Constructed once, held by both" is honoured
 * as "defined once, consulted by both": there is no construction, so there
 * is nothing to hold or to diverge.
 *
 * Nothing here reaches a clock, a promise or the event loop (E646): every
 * judgement is arithmetic over numbers and message shapes the callers
 * already computed.
 */
final class SessionTurnRules
{
    /** The dispatch proceeds as written: no tier fired. */
    public const ROUTE_SEND = 'send';

    /** Nothing compacts on its own and the window cannot take the prompt. */
    public const ROUTE_FOREGROUND_BLOCKED = 'foreground-blocked';

    /** The automatic tier fired: compact before the prompt goes out. */
    public const ROUTE_COMPACT = 'compact';

    /**
     * Land one runtime notice as its transcript row (C-CTX-6.e).
     *
     * A structured frame becomes its TYPED recall row — the count sentence
     * for content (identical bytes to what the plain notice carried before,
     * so web and legacy readers are unchanged) plus the titles that make the
     * row expandable. A frame that fails to decode is DROPPED, never rendered
     * as raw JSON on the user's transcript. An unstructured notice rides as
     * the plain row it always was. Null means "drop this one"; every other
     * return value is the row to append.
     *
     * The notices themselves carry git stderr and model-authored tool names
     * from parser warnings (audit 15b-03) — they are addressed to the user,
     * which is why the malformed shape is silenced rather than shown.
     */
    public static function noticeSlotFor(string $notice): ?Message
    {
        if (!RuntimeNoticeSink::isStructuredFrame($notice)) {
            return Message::notice($notice);
        }

        $frame = RuntimeNoticeSink::decodeStructured($notice);
        if ($frame === null) {
            return null;
        }

        return Message::recallNotice(
            $frame['count'],
            RuntimeNoticeSink::recallRowText($frame['count']),
            $frame['titles'],
        );
    }

    /**
     * Which compaction route this dispatch takes (roadmap N-P4b).
     *
     * `compaction.mode: off`: nothing compacts on its own, so the automatic
     * tier is skipped outright — but a prompt the window cannot take is still
     * refused, by the same blocking test and with the same refusal the tier
     * gives once compacting has come up short, which points at `/compact`
     * and `/clear`. Without that arm, `off` would send a request the provider
     * rejects. `heuristic` and `llm` need no arm here — the tier below always
     * runs; a landed ahead-of-need summary (ISS-064) only replaces the
     * heuristic lines it would write, never the arm that fires them.
     *
     * The two tier tests arrive as callables because the pair must keep its
     * short-circuit shape exactly: the blocking predicate runs only on the
     * non-auto route and the tier predicate only on the auto route, and each
     * driver reads "due" from state the other does not have. The arithmetic
     * that decides WHICH route is the shared part; what each route then
     * spends stays the driver's own.
     */
    public static function compactionRoute(bool $autoCompacts, callable $blockingTierDue, callable $autoTierDue): string
    {
        if (!$autoCompacts && $blockingTierDue()) {
            return self::ROUTE_FOREGROUND_BLOCKED;
        }

        if ($autoCompacts && $autoTierDue()) {
            return self::ROUTE_COMPACT;
        }

        return self::ROUTE_SEND;
    }

    /**
     * The 70% context-reminder row: appended only when due, and always
     * counted against the history BEFORE the strip.
     *
     * Both drivers strip every stale reminder from the base UNCONDITIONALLY
     * and append the current copy only when the tier fires, so the history
     * holds at most one, current, copy. Order is load-bearing twice over:
     *
     * (1) Scoping the strip inside the due-test — its original shape — leaves
     * the last copy a session was ever sent in history forever once the
     * estimate falls back UNDER the tier, which is precisely what /compact is
     * for: it stays on the provider wire every turn thereafter, quoting a
     * figure from before the compaction. Pinned by
     * ContextReminderDedupTest::testABelowThresholdDispatchRemovesAStalePreExistingReminder().
     *
     * (2) THE FIGURE IS COUNTED BEFORE THE STRIP — the caller passes the
     * pre-strip estimate, not a post-strip one — so the number the message
     * quotes is the same number the due-predicate just compared against the
     * threshold, and "grown to ~N estimated tokens, past the ... threshold"
     * cannot contradict itself. Counting after the strip would quote a figure
     * 53 estimated tokens per dropped copy BELOW the threshold it claims to
     * be past. Pinned by
     * ContextReminderDedupTest::testTheQuotedFigureIsNeverBelowTheThresholdItSaysItIsPast().
     *
     * What N does NOT do is overstate the history committed by the caller: it
     * overstates the post-strip history IN ISOLATION, by 53 estimated tokens
     * per dropped copy (estimateTokenCount's own chars/4 + 10 over this
     * message's length), but that array never rides alone and the copy
     * appended here weighs the same 53 as each one just dropped, so the two
     * cancel. Both units are the estimate, never a provider count.
     */
    public static function contextReminderFor(bool $dueForReminder, int $preStripTokens): ?Message
    {
        return $dueForReminder ? CompactionService::contextReminderMessage($preStripTokens) : null;
    }

    /**
     * May this dispatch MINT an ahead-of-need summarisation request
     * (roadmap 2.10, request arm of C-CTX-5.b)?
     *
     * The request is decided by OpenCode's skip rules, owned by
     * {@see CompactionService::aheadOfNeedAllowed()} — the 64,000-token floor
     * plus the model — folded beside the three slot facts: the tier must be
     * firing (below it nothing will need the summaries), the slot must be
     * free (one request per compaction cycle), and the spend cap must not be
     * reached. Each driver ANDs in whatever else it owns — the TUI also waits
     * out a manual `/compact` or a parked fold, the host also requires a
     * backend to exist — those terms are not shared and stay outside.
     *
     * The 64,000-token floor is a REQUEST rule: the drop arm never consults
     * it (MINOR-2, round-1 review) — re-applying it on the way out would
     * discard an idle-minted small-window slot that was never expected to
     * clear it — which is why dropping is a separate judgement
     * ({@see self::summariesOutOfDate()} and each driver's own model test),
     * not this one inverted.
     */
    public static function aheadOfNeedRequestAllowed(
        bool $dueForReminder,
        bool $slotHeld,
        bool $capReached,
        int $aheadTokens,
        string $sessionModel,
    ): bool {
        return $dueForReminder
            && !$slotHeld
            && !$capReached
            && CompactionService::aheadOfNeedAllowed($aheadTokens, null, $sessionModel);
    }

    /**
     * Is the summaries a landed slot holds now stale (drop arm, second leg)?
     *
     * A landed set is kept while its fingerprint still matches the history
     * being dispatched — a failed request included, so a failing summariser
     * is not re-asked every turn — and is dropped when either the tier stops
     * firing (nothing will need them) or the fingerprint says a compaction,
     * /rewind, /clear or session switch has moved the history under it. The
     * slot-freeing identity legs (session id, model) differ per driver on
     * purpose and stay with each driver; this is only the "the records no
     * longer describe this transcript" judgement over the three booleans both
     * of them compute the same way.
     */
    public static function summariesOutOfDate(bool $dueForReminder, bool $summariesHeld, bool $fingerprintMatches): bool
    {
        return $summariesHeld && (!$dueForReminder || !$fingerprintMatches);
    }
}
