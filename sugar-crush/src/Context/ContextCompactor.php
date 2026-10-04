<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Handles automatic context compaction when conversation history grows large.
 *
 * Stage 1 preserves full messages for the most recent N exchanges
 * (default 10 from CompactorConfig::recentPreserveCount).
 *
 * Stage 2 condenses older exchanges into single-line summaries capturing
 * "what happened and any key decisions made." TWO summarizers can produce those
 * lines: a MODEL-written one, when the caller has supplied summaries through
 * {@see withExchangeSummaries()} (crush_code.md Phase 5 item 6), and the local
 * heuristic otherwise — per exchange, so a partially-answered set degrades
 * rather than failing. The heuristic truncates the user's message and either
 * appends a short assistant reply verbatim or writes
 * `[exchanged information]`; nothing about the caller having no model to ask is
 * an error, which is why it remains.
 *
 * {@see exchangesToSummarize()} is the other half of that seam: it answers
 * "which exchanges would you condense, and with what text?" so a caller can go
 * and get the summaries before calling {@see compact()}. That split is what
 * keeps `compact()` synchronous and side-effect-free — it runs inside
 * {@see \SugarCraft\Crush\Chat}'s TEA `update()`, where a provider call would
 * freeze the render loop.
 *
 * The compaction trigger uses tiered thresholds — percentages of the token
 * limit its caller passes in, configurable on {@see CompactorConfig} and shown
 * here at their defaults:
 * - 70%: reminder sent to lead agent
 * - 85%: background compaction begins
 * - 95%: foreground blocking until space is freed
 *
 * The limit itself is resolved by {@see ContextWindow} — the model's real
 * context window when the backend can report one — so these percentages land on
 * a different absolute token count per provider. Each tier may also carry an
 * absolute token cap (roadmap 2.9, {@see CompactorConfig::withReminderTokens()}
 * and its siblings, per model through {@see CompactorConfig::forModel()}); a
 * capped tier fires at whichever of the two is lower, so on a 1M-token window a
 * cap is what keeps the tiers inside the size a model still works well at.
 *
 * Token counting is {@see TokenEstimate}'s script-weighted estimate plus 10
 * per message - the same figure {@see \SugarCraft\Crush\Chat}'s own estimate
 * uses, so the two agree on CJK and emoji text, where one token per four
 * characters read 3-6x low (audit 15b-13). ASCII still counts four characters
 * per token.
 */
final class ContextCompactor
{
    private int $lastSavingsPercentage = 0;

    public function __construct(
        private readonly CompactorConfig $config,
        /**
         * Model-written one-line summaries for stage 2, keyed by
         * {@see exchangeKey()}. Empty is the ordinary state and means "use the
         * heuristic", which is what every caller without a provider gets — pure
         * unit tests, the echo provider, and either `$SUGARCRUSH_BACKEND_CMD*`
         * shell-out all legitimately have no model to ask
         * (crush_code.md Phase 5 item 6).
         *
         * Supplied rather than fetched, so {@see compact()} stays synchronous
         * and side-effect-free: it runs inside {@see \SugarCraft\Crush\Chat}'s
         * TEA `update()`, where a blocking provider call would freeze every
         * keystroke for the duration of a completion. `Chat` asks the model in a
         * `Cmd`, off the render loop, and hands the answers back through
         * {@see withExchangeSummaries()}.
         *
         * @var array<string, string>
         */
        private readonly array $exchangeSummaries = [],
    ) {}

    /**
     * A copy of this compactor that will use $summaries for the exchanges they
     * cover, and the heuristic for the rest — see the constructor's
     * $exchangeSummaries docblock.
     *
     * Keyed by CONTENT ({@see exchangeKey()}) rather than by position, because
     * the summaries make a round trip to a provider and the history can have
     * moved on by the time they land: a background message appended in between
     * would shift every index, silently attaching each summary to the wrong
     * exchange. A key that no longer occurs simply goes unused, which degrades
     * to the heuristic instead of to a lie.
     *
     * @param array<string, string> $summaries
     */
    public function withExchangeSummaries(array $summaries): self
    {
        return new self($this->config, $summaries);
    }

    /**
     * A copy of this compactor that preserves $fewer fewer of the most recent
     * exchanges in full, never fewer than one (or than the configured count,
     * when that is already below one). $fewer <= 0 answers this same instance.
     *
     * The escape from the 95% blocking tier: when the preserved exchanges alone
     * overflow the window, each further attempt the user makes drops the oldest
     * of them ({@see \SugarCraft\Crush\Chat}'s `blockedAttempts()` counts the
     * attempts). The supplied summaries ride across, so the keys a model was
     * offered still land.
     */
    public function withRecentPreserveReducedBy(int $fewer): self
    {
        if ($fewer <= 0) {
            return $this;
        }

        $current = $this->config->recentPreserveCount;

        return new self(
            $this->config->withRecentPreserveCount(max(min(1, $current), $current - $fewer)),
            $this->exchangeSummaries,
        );
    }

    /**
     * The content key a model-written summary is filed under: the exact
     * user/assistant text of the exchange it summarises, hashed.
     *
     * Hashed rather than stored whole so the map does not carry a second copy of
     * the conversation. Two byte-identical exchanges collide onto one key, and the
     * harmlessness of that collapse was MEASURED (backlog §12.2 E23) rather than
     * assumed: stage 2 emits one summary line per pair, never per key, so both
     * duplicates stay represented; the model is offered nothing of an exchange beyond
     * the two texts the key hashes (its ordinal label is presentational), so
     * whichever paraphrase the positional parse keeps describes either twin
     * truthfully; per-pair riders (`_Request cancelled._`) and `tool_calls`
     * payloads are not keyed and are therefore not carried by the collapse.
     * Adjacent duplicates then fold into stage 3's counted `[2x]` line — with or
     * without a summary map, since that stage keys on identical rendered text.
     * Pinned by the E23 tests in {@see \SugarCraft\Crush\Tests\Context\ExchangeSummaryTest} —
     * the six tests under the "E23 (backlog §12.2)" banner in that file.
     */
    public static function exchangeKey(string $userMsg, string $assistantMsg): string
    {
        return hash('sha256', $userMsg . "\0" . $assistantMsg);
    }

    /**
     * Factory creating a compactor with default config.
     */
    public static function new(): self
    {
        return new self(CompactorConfig::new());
    }

    /**
     * The config this compactor's tiers are measured against — read by a
     * notice that has to name the tier that actually fired (a percentage, or an
     * absolute cap when that is lower, roadmap 2.9) rather than assume 95%.
     */
    public function config(): CompactorConfig
    {
        return $this->config;
    }

    /**
     * Determine whether compaction should run based on current token usage.
     *
     * Returns true when context usage reaches or exceeds the background
     * compaction threshold (85% by default, or its absolute cap when that is
     * lower — {@see CompactorConfig::backgroundCompactionTokenThreshold()}), on
     * {@see countTokens()}'s script-weighted estimate.
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @param int $tokenLimit Maximum tokens allowed in context window.
     */
    public function shouldCompact(array $messages, int $tokenLimit): bool
    {
        if ($tokenLimit <= 0) {
            return false;
        }

        $tokenCount = $this->countTokens($messages);
        $threshold = $this->config->backgroundCompactionTokenThreshold($tokenLimit);

        return $tokenCount >= $threshold;
    }

    /**
     * Determine whether foreground blocking compaction is needed.
     *
     * Returns true when context usage reaches or exceeds the foreground
     * blocking threshold (95% by default, or its absolute cap when that is
     * lower — {@see CompactorConfig::foregroundBlockingTokenThreshold()}). At
     * this threshold, new input is blocked until space is freed by compaction.
     *
     * Mirrors charmbracelet/bubbletea ContextCompactor.shouldCompactForeground.
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @param int $tokenLimit Maximum tokens allowed in context window.
     */
    public function shouldCompactForeground(array $messages, int $tokenLimit): bool
    {
        if ($tokenLimit <= 0) {
            return false;
        }

        $tokenCount = $this->countTokens($messages);
        $threshold = $this->config->foregroundBlockingTokenThreshold($tokenLimit);

        return $tokenCount >= $threshold;
    }

    /**
     * Estimated tokens {@see truncateOversizedExchange()} reserves against the
     * blocking tier when it sizes each oversized message's share — the share is
     * a quotient of (threshold − preserved exchanges − this) — so a rescued
     * wire with any share left to keep lands at least this far UNDER the tier
     * the caller re-checks. That is the whole of this constant's guarantee: it
     * bounds only the $messages array handed to the truncator.
     *
     * It does NOT guarantee the dispatched turn fits. {@see
     * \SugarCraft\Crush\Chat::dispatchTurn()} appends the echoed prompt, the
     * 70% reminder, and this compaction's own notice AFTER that re-check, and
     * none of them is in $messages; 2,000 tokens absorbs a short draft with
     * room to spare, but a draft that is itself enormous rides a sub-tier
     * rescued wire over the window anyway. Measured (review cycle 5, finding
     * 3): an 800,000-char history giant plus a 60,000-char draft dispatched at
     * ~108,113 estimated tokens against a 100,000-token window — and then
     * refused persistently at essentially that flat estimate (attempts 2-6:
     * 108,124→108,252), because the giant is already truncated, nothing is
     * individually oversized any more, and the aggregate is over. Making the
     * bound a function of the draft (mb_strlen($text)), or declining the
     * rescue when the draft alone outgrows the reserve, is recorded as a
     * FOLLOW-UP; changing behaviour here is outside this step's scope.
     */
    private const INTRA_EXCHANGE_HEADROOM_TOKENS = 2000;

    /**
     * Upper bound on the bytes the truncation marker itself can occupy, counted
     * INSIDE each truncated message's budget so the marker never re-inflates a
     * message past its share. Sized for a character count up to ten digits; the
     * message keeps whatever head remains after this reserve.
     */
    private const INTRA_EXCHANGE_MARKER_MAX_CHARS = 160;

    /**
     * Truncate every individual MESSAGE that alone reaches the blocking tier, in
     * place, so a conversation carrying one stops being un-sendable
     * (prompt_plan.md P4.S4, backlog §12.2 E18). The unit is the message, not
     * the exchange: both halves of one oversized exchange are truncated when
     * each alone reaches the tier, and several exchanges can each contribute an
     * oversized message sharing the remaining budget. The METHOD NAME says
     * "exchange" because the overflow this tier exists for — §12.2 E18's single
     * un-sendable exchange — usually contributes exactly one; what it counts
     * and trims is messages, and the notice the caller writes says so.
     *
     * This is the INTRA-exchange case and deliberately nothing else. Every other
     * tier on this class frees space BETWEEN exchanges — stage 2 condenses whole
     * older user/assistant pairs into one-line summaries, and stage 1 preserves
     * the most recent {@see CompactorConfig::$recentPreserveCount} of them in
     * full. That machinery cannot help a conversation whose overflow lives inside
     * ONE exchange: it is recent, so stage 1 preserves it verbatim, and it is a
     * single pair, so there is nothing older to condense. The caller then reaches
     * {@see shouldCompactForeground()}, refuses, and — because a refusal echoes
     * the prompt and appends a notice into history — the very next attempt is
     * refused against a LARGER estimate. Measured on the branch before this
     * method existed: one 800,000-char exchange in history refused five times
     * running with the estimate rising 200,520 -> 201,032, +128 per attempt,
     * because 200,520 is far over the 95,000-token blocking tier of the
     * 100,000-token fallback window and no whole exchange could be dropped.
     *
     * The honest fix is to shorten the oversized exchange itself. Truncation
     * reduces what actually goes on the wire, so {@see countTokens()} of the
     * result is a truthful count of the smaller prompt — the estimate becomes
     * bounded not by lying about its size but by genuinely having less to count.
     * That is the whole distinction §12.2 E18's "must NOT be achieved by silently
     * reporting FEWER input tokens than are there" turns on: the number falls
     * because the bytes fell.
     *
     * Only a message whose OWN estimated count reaches the blocking threshold is
     * touched. A history over the tier purely in aggregate — every exchange
     * individually fits, there are simply too many — is the between-exchanges
     * case: it is returned byte-for-byte unchanged so the caller's refusal
     * stands, exactly as before this method. Passing a message through that is
     * already under the threshold would be a silent rewrite of exchange content
     * the inter-exchange tiers chose to preserve, which is out of this method's
     * scope and would move the goldens.
     *
     * Determinism: the truncation keeps the leading head of each oversized
     * message and drops the tail, then writes a marker naming the exact character
     * count removed. No clock, no randomness, no provider call — the same input
     * yields byte-identical output, so a truncated turn is reproducible and a
     * golden that never crosses the blocking tier is never rewritten by this path.
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @param int $tokenLimit The context window every tier is a percentage of.
     * @return array<array{role:string,content:string}> $messages unchanged when
     *         the limit is non-positive, the history is under the blocking tier,
     *         or nothing individually reaches it; otherwise with every oversized
     *         message truncated to an equal share of the space left under it.
     */
    public function truncateOversizedExchange(array $messages, int $tokenLimit): array
    {
        if ($tokenLimit <= 0) {
            return $messages;
        }

        $threshold = $this->config->foregroundBlockingTokenThreshold($tokenLimit);
        $total = $this->countTokens($messages);

        // HONEST SCOPE, measured at ddd0a5c83 (review cycle 3): this early
        // return is redundant for every input today, not merely under-tested.
        // countTokens() is the sum of the per-message counts, so a history whose
        // TOTAL sits under the blocking threshold cannot contain a single message
        // that reaches it — every $own is at most that total and so below the
        // >= threshold test below, $oversized stays empty, and its `=== []`
        // branch answers the very same unchanged $messages. Deleting this guard
        // left the whole file green (OK (76 tests, 221 assertions)); because no
        // input distinguishes it from that later branch, no honest test can pin
        // it here rather than there. It stays as deliberate defence-in-depth
        // (§1.10): it is the cheapest exit for the overwhelmingly common
        // under-tier wire, and it states the whole-history contract before the
        // per-message split instead of leaving it an emergent property of that
        // split. A future reader should not take it for independently
        // load-bearing logic.
        if ($total < $threshold) {
            return $messages;
        }

        // Split the history into the individual exchanges that alone reach the
        // blocking tier and everything else. The else-part is preserved whole:
        // shrinking a message that already fits is inter-exchange compaction's
        // job, not this method's, and it is exactly what "leave the
        // between-exchanges case untouched" means at the byte level.
        $oversized = [];
        $preservedTokens = 0;
        foreach ($messages as $index => $message) {
            $own = $this->countTokens([$message]);
            if ($own >= $threshold) {
                $oversized[$index] = $own;
            } else {
                $preservedTokens += $own;
            }
        }

        if ($oversized === []) {
            // Over the tier only in aggregate: no single exchange is bigger than
            // the window, so this is the between-exchanges refusal the caller
            // already handles. Nothing here is oversized; nothing here changes.
            return $messages;
        }

        // Every oversized message gets an equal share of the estimate left under
        // the blocking tier after the preserved exchanges and the dispatch
        // headroom. Integer division can only undershoot, so the sum stays under
        // the threshold; the marker is counted inside each message's own budget.
        $share = intdiv(
            max(0, $threshold - $preservedTokens - self::INTRA_EXCHANGE_HEADROOM_TOKENS),
            count($oversized),
        );
        // The share is held in TOKENS - the unit countTokens() checks it in. The
        // character budget is its ASCII reading (four per token) and stays the
        // hard cap; truncateMessageHead() shortens further when the head's script
        // weighs more than a quarter-token per character (audit 15b-13-rem).
        $tokenBudget = max(0, $share - 10);
        $charBudget = $tokenBudget * 4;

        $truncated = $messages;
        foreach (array_keys($oversized) as $index) {
            $truncated[$index] = $this->truncateMessageHead($messages[$index], $charBudget, $tokenBudget);
        }

        return $truncated;
    }

    /**
     * Truncate one wire message's `content` to $charBudget, keeping the head and
     * writing a marker that names the exact number of dropped characters.
     *
     * Non-content keys (`attachments`, `tool_calls`) ride through untouched —
     * {@see \SugarCraft\Crush\Message::toWire()} carries them and dropping one
     * would lose a tool result the exchange still needs. A message already
     * within the budget is returned unchanged, which is what keeps a
     * not-actually-oversized entry from being rewritten.
     *
     * The bound is absolute: whatever the head/marker split, the result is
     * hard-clamped to exactly $charBudget at the end. So at a budget too small to
     * carry the whole marker the marker is itself truncated rather than allowed to
     * re-inflate the message — the count then reads clipped, but the message never
     * exceeds its share and the caller's under-threshold guarantee holds. That
     * regime only arises when the non-oversized exchanges already fill the tier
     * (so this is between-exchanges-dominated), and E18's own case has a budget in
     * the hundreds of thousands of characters, nowhere near it.
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    private function truncateMessageHead(array $message, int $charBudget, int $tokenBudget): array
    {
        $content = (string) ($message['content'] ?? '');

        // HONEST SCOPE, measured at ddd0a5c83 (review cycle 3): like the
        // under-tier short-circuit in truncateOversizedExchange(), this
        // return-unchanged is redundant for every input this method is reached
        // with, not merely untested. Its sole caller is that method, which
        // reaches here only for a message whose own token count already met the
        // blocking threshold — a content string of well over three hundred
        // thousand characters — while $charBudget is a share of the space left
        // UNDER that threshold, so mb_strlen($content) <= $charBudget never
        // holds. Deleting this guard left the whole file green (OK (76 tests,
        // 221 assertions)); no input reaches the branch, so no honest test can
        // pin it. It stays as deliberate defence-in-depth (§1.10): should a
        // future caller pass a not-actually-oversized message, this keeps the
        // method from rewriting content that already fits its budget — the
        // guarantee the doc-block above states. Not independently load-bearing
        // logic today.
        //
        // Since the count became script-weighted (audit 15b-13-rem) the guard
        // reads BOTH budgets: a CJK giant can sit under the character budget
        // (four per token) while weighing four times its token share, and that
        // message must still be cut.
        if (mb_strlen($content) <= $charBudget && TokenEstimate::ofText($content) <= $tokenBudget) {
            return $message;
        }

        $length = mb_strlen($content);
        $markerReserve = min($charBudget, self::INTRA_EXCHANGE_MARKER_MAX_CHARS);
        $head = min($length, $charBudget - $markerReserve);
        $truncated = self::headWithMarker($content, $length, $head, $charBudget);

        // ASCII never enters this loop: its estimate is a quarter-token per
        // character, so the character cap already holds the token share. Any
        // heavier script shrinks the head to the longest one whose estimate
        // fits; the estimate only grows with the head, so a bisection finds it.
        if (TokenEstimate::ofText($truncated) > $tokenBudget) {
            $low = 0;
            $high = $head;
            while ($low < $high) {
                $mid = intdiv($low + $high + 1, 2);
                if (TokenEstimate::ofText(self::headWithMarker($content, $length, $mid, $charBudget)) <= $tokenBudget) {
                    $low = $mid;
                } else {
                    $high = $mid - 1;
                }
            }
            $truncated = self::headWithMarker($content, $length, $low, $charBudget);
        }

        $message['content'] = $truncated;

        return $message;
    }

    /**
     * The first $head characters of $content plus the marker naming how many
     * of its $length were dropped, hard-clamped to $charBudget characters - see
     * {@see truncateMessageHead()} for why the clamp may cut the marker itself.
     */
    private static function headWithMarker(string $content, int $length, int $head, int $charBudget): string
    {
        $dropped = $length - $head;
        $truncated = mb_substr($content, 0, $head)
            . "\n\n[... {$dropped} characters truncated to fit the context window ...]";

        if (mb_strlen($truncated) > $charBudget) {
            $truncated = mb_substr($truncated, 0, $charBudget);
        }

        return $truncated;
    }

    /**
     * Determine whether a soft reminder should be sent to the lead agent.
     *
     * Returns true when context usage reaches or exceeds the reminder
     * threshold (70% by default, or its absolute cap when that is lower —
     * {@see CompactorConfig::reminderTokenThreshold()}). This is a soft warning surfaced to the
     * lead agent before the harder 85%/95% compaction tiers kick in.
     *
     * PURE AND STATELESS — a bare `$tokenCount >= $threshold`, no latch, no
     * timestamp, nothing remembered between calls. So this is NOT "should a
     * reminder be sent for the first time": it answers true on EVERY call once
     * the estimate is over the line, and the caller is what decides how many
     * reminders that turns into. {@see \SugarCraft\Crush\Chat::dispatchTurn()}
     * commits the reminder into `history`, so before it deduplicated
     * ({@see \SugarCraft\Crush\Chat::withoutContextReminders()}) a session
     * twenty turns past the threshold accumulated twenty copies. Any new caller
     * inherits the same obligation.
     *
     * $tokenCount is an ESTIMATE — {@see countTokens()}'s script-weighted
     * {@see TokenEstimate} + 10 per message. $tokenLimit is the provider's real window only on the one path
     * where there is one to have: callers reach it through
     * {@see \SugarCraft\Crush\Context\ContextWindow::ofBackend()}, which
     * returns the hardcoded
     * {@see \SugarCraft\Crush\Context\ContextWindow::FALLBACK_TOKENS}
     * (100,000 ESTIMATED tokens) on the other two — a backend that does not
     * implement {@see \SugarCraft\Crush\Backend\ReportsContextWindow}, and
     * one that reports a non-positive window. So on the reported path the
     * comparison deliberately mixes two units, and on the fallback path both
     * sides are the same estimate; it is a heuristic tier either way, never a
     * measured one.
     *
     * Mirrors charmbracelet/bubbletea ContextCompactor.shouldSendReminder.
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @param int $tokenLimit Maximum tokens allowed in context window.
     */
    public function shouldSendReminder(array $messages, int $tokenLimit): bool
    {
        if ($tokenLimit <= 0) {
            return false;
        }

        $tokenCount = $this->countTokens($messages);
        $threshold = $this->config->reminderTokenThreshold($tokenLimit);

        return $tokenCount >= $threshold;
    }

    /**
     * Apply skill-aware compaction as a separate pass from message-history compaction.
     *
     * Each carried-forward skill is capped at roughly 5,000 tokens of its own content,
     * and the combined budget across every skill still in context is capped at roughly
     * 25,000 tokens. Past that combined cap, the least-recently-invoked skill's
     * content is the first to be dropped.
     *
     * This runs as its own pass, separate from message-history compaction, so a handful
     * of large skills can't eat the entire compaction budget before any conversation
     * history is touched.
     *
     * Mirrors charmbracelet/bubbletea ContextCompactor.compactSkills.
     *
     * @param array<array{role:string,content:string,name?:string,lastInvokedAt?:int}> $messages
     * @return array<array{role:string,content:string,name?:string,lastInvokedAt?:int}> Messages with skills filtered
     */
    public function compactSkills(array $messages): array
    {
        // Extract skill messages (skills have a special role marker)
        $skills = [];
        $nonSkills = [];

        foreach ($messages as $msg) {
            if (isset($msg['role']) && $msg['role'] === 'skill') {
                $skills[] = [
                    'name' => $msg['name'] ?? '',
                    'content' => $msg['content'] ?? '',
                    'lastInvokedAt' => $msg['lastInvokedAt'] ?? 0,
                ];
            } else {
                $nonSkills[] = $msg;
            }
        }

        // Apply skill budget limits via filterSkills
        $filteredSkills = $this->filterSkills($skills);

        // Reconstruct messages with filtered skills
        $result = $nonSkills;
        foreach ($filteredSkills as $skill) {
            $result[] = [
                'role' => 'skill',
                'name' => $skill['name'],
                'content' => $skill['content'],
                'lastInvokedAt' => $skill['lastInvokedAt'],
            ];
        }

        return $result;
    }

    /**
     * Compact a message array through stages 1-5.
     *
     * Stage 1: Preserve the most recent N full user/assistant PAIRS (recentPreserveCount).
     * Stage 4: Replace a successful `Read` tool row with a metadata line.
     * Stage 5: Drop a `Bash` tool row whose whole command is a lone `cd`/`ls`/`pwd`.
     * Stage 2: Condense older exchanges into single-line summaries capturing
     *          "what happened and any key decisions made."
     * Stage 3: Group consecutive identical exchanges (e.g., repeated grep searches).
     *
     * Stages 4 and 5 run against the RAW pre-summarization content, before
     * stage 2's summarization has a chance to truncate/collapse it away —
     * summarizing first would collapse the very tool rows stages 4/5 key on,
     * so they must see the originals. A tool row is recognised by its `tool`
     * key (`{name, arguments, error}`, stamped by
     * {@see \SugarCraft\Crush\Chat::compactionWire()}), never by its text.
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @return array<array{role:string,content:string}> Compacted messages.
     */
    public function compact(array $messages): array
    {
        if ($messages === []) {
            $this->lastSavingsPercentage = 0;
            return [];
        }

        $staged = $this->stagePairs($messages);
        if ($staged === null) {
            $this->lastSavingsPercentage = 0;

            // The stage-0 output, not the caller's array: removeToolResults()
            // has already run inside stagePairs() and its result is what the
            // no-compaction path has always returned.
            return $this->removeToolResults($messages);
        }

        [$messages, $preservePairs, $toSummarizePairs] = $staged;

        // Stage 2: condense older pairs into summaries (one summary per pair)
        $summarized = $this->summarizeExchanges($toSummarizePairs);

        // Stage 3: group similar consecutive exchanges
        $summarized = $this->groupSimilarExchanges($summarized);

        // Flatten preserved pairs back into individual messages
        $preserved = $this->flattenPairs($preservePairs);

        $this->lastSavingsPercentage = $this->calculateSavingsPercentage($messages, [...$summarized, ...$preserved]);

        return [...$summarized, ...$preserved];
    }

    /**
     * Stages 0, 1, 4 and 5 of {@see compact()} — everything that decides WHICH
     * exchanges get condensed and what their content looks like by the time they
     * do, with stage 2's summarization deliberately left out.
     *
     * Extracted so {@see exchangesToSummarize()} can answer "what would you
     * summarise?" through the identical pipeline rather than a re-implementation
     * of it. A second copy of this ordering would drift, and the ordering is not
     * incidental: stages 4/5 must see the RAW content (see {@see compact()}'s
     * docblock), so an exchange handed to a model for summarising is the
     * post-stage-4/5 text and not the original.
     *
     * Null means "nothing to compact" — an empty history, or at most
     * recentPreserveCount pairs however large they are.
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array{0: array<array{role:string,content:string}>, 1: array<mixed>, 2: array<mixed>}|null
     */
    private function stagePairs(array $messages): ?array
    {
        if ($messages === []) {
            return null;
        }

        $preserveCount = $this->config->recentPreserveCount;

        // Stage 0: older tool output becomes its placeholder (roadmap 2.2-1,
        // the engine's age rule). Only rows the stages below are about to
        // condense change; the preserved tail keeps its bytes. What the caller
        // handed in stays $messages: the savings figure is measured against it.
        $staged = $this->removeToolResults($messages);

        // Group messages into user/assistant pairs
        $pairs = $this->groupIntoPairs($staged);

        // Stage 1: if we have <= preserveCount pairs, no compaction needed
        if (count($pairs) <= $preserveCount) {
            return null;
        }

        // Split: last preserveCount pairs are preserved, earlier pairs go to summary
        $preservePairs = array_slice($pairs, -$preserveCount);
        $toSummarizePairs = array_slice($pairs, 0, count($pairs) - $preserveCount);

        // Stages 4 & 5 need the raw (un-summarized) tool rows to detect file
        // reads and navigation commands, so flatten first and run them
        // ahead of stage 2's summarization.
        $rawToSummarize = $this->flattenPairs($toSummarizePairs);

        // Stage 4: compact file references into metadata
        $rawToSummarize = $this->compactFileReferences($rawToSummarize);

        // Stage 5: remove navigation steps
        $rawToSummarize = $this->removeNavigationSteps($rawToSummarize);

        // Re-pair whatever survived stages 4/5 for summarization
        $toSummarizePairs = $this->groupIntoPairs($rawToSummarize);

        return [$messages, $preservePairs, $toSummarizePairs];
    }

    /**
     * The user/assistant exchanges a {@see compact()} of $messages would replace
     * with one-line summaries, in the order it would replace them and with the
     * exact text it would hand stage 2.
     *
     * This is the question a caller has to answer BEFORE it can ask a model for
     * summaries, and it has to be answered by the same pipeline that will
     * consume them — hence {@see stagePairs()}. Each entry carries its
     * {@see exchangeKey()} already computed, so the caller never has to know how
     * the keying works to build the map {@see withExchangeSummaries()} wants.
     *
     * Standalone messages (an unpaired system/assistant turn) are NOT included:
     * stage 2 truncates those to 120 characters rather than summarising them
     * (except the regenerated token-count notice, which stage 2 declines outright —
     * {@see isRegeneratedReminderRow()}), and offering them to a model would
     * produce summaries nothing consumes.
     * Neither are pairs with no assistant reply, which have no exchange to
     * summarise yet.
     *
     * Empty means there is nothing a model could usefully be asked — either
     * nothing to compact at all, or nothing but standalone/unanswered turns.
     *
     * WHAT THE MODEL IS SHOWN IS BOUNDED; WHAT THE KEY IS MADE FROM IS NOT. Each
     * returned `assistant` half passes through {@see boundHeadAssistantForSummary()},
     * because finished tool output enters history as assistant content and a
     * condensed exchange carrying a large tool blob would otherwise reach the
     * summariser whole. The `user` half rides out untouched — §6.6 bounds the tool
     * transcript, not the question — and the `key` is ALWAYS computed from the
     * original unclipped pair, because {@see withExchangeSummaries()} and the
     * apply-on-landing step in Chat recompute that key from the transcript: a key
     * derived from clipped text would summarise the exchange and then fail to find
     * it. So the clip is a copy made on the way out and nothing here rewrites the
     * history it was handed.
     *
     * @param array<array{role:string,content:string}> $messages Wire-format messages.
     * @return list<array{key:string,user:string,assistant:string}>
     */
    public function exchangesToSummarize(array $messages): array
    {
        $staged = $this->stagePairs($messages);
        if ($staged === null) {
            return [];
        }

        $out = [];
        foreach ($staged[2] as $pair) {
            if (isset($pair['standalone']) && $pair['standalone'] === true) {
                continue;
            }
            $user = (string) ($pair['user'] ?? '');
            $assistant = $pair['assistant'] ?? null;
            if (!is_string($assistant) || $assistant === '') {
                continue;
            }
            $out[] = [
                'key' => self::exchangeKey($user, $assistant),
                'user' => $user,
                'assistant' => $this->boundHeadAssistantForSummary($assistant),
            ];
        }

        return $out;
    }

    /**
     * The heading a loaded skill's body arrives under, written by
     * `SkillTool::execute()` onto the tool result that becomes the assistant turn.
     *
     * Spelled here rather than looked for in a role because there is no `skill`
     * role on the wire: the dormant {@see compactSkills()}/{@see filterSkills()}
     * budget machinery has no production caller and never sees the head, so this
     * heading is the only thing that identifies skill output among the condensed
     * exchanges.
     */
    private const SKILL_OUTPUT_MARKER = '## Skill: ';

    /**
     * The head's assistant half as the summariser is shown it: at most
     * {@see CompactorConfig::$toolOutputMaxChars} characters of it, plus a marker
     * naming how many fell off.
     *
     * Pure and deterministic — same text in, same text out, no state read beyond
     * the config — because what it returns is a view handed to one model call and
     * nothing else. The bound is on the RETAINED text alone: the marker is appended
     * after it, so a clipped value runs past the bound by the marker's length, which
     * is the price of a truncation that says how much it removed instead of
     * pretending nothing happened.
     *
     * A skill body is emitted whole at any length (§9.3, "never prune skill
     * outputs"): the heading is instructions the model still has to obey, whereas a
     * tool blob is a record of something already acted on.
     *
     * WHAT THAT EXEMPTION IS AND IS NOT: it is a test of the ten bytes at OFFSET 0 and
     * of nothing else, so ANY assistant text beginning `## Skill: ` rides the summariser
     * request unbounded — including text whose opening bytes a user typed or a tool
     * printed. A forged marker therefore smuggles a body of any length into every later
     * compaction request, and nothing here can tell it from a skill the app loaded.
     *
     * ACCEPTED AS A DOCUMENTED RESIDUAL, not as a new exposure: the exchanges message
     * has handed this same summariser raw, unlabelled user and tool bytes since the
     * request existed, so the forgery buys an author a label on input it already
     * supplied — label-forgery by the SAME untrusted author, not new data exposure —
     * which is the equivalence ruling P8.S3 reached for the prior-summary block in THE
     * FENCE RESIDUAL under {@see \SugarCraft\Crush\Chat::priorSummariesFromHistory()}.
     * Should that balance ever change, the remedy is a stricter shape for the marker
     * than a bare content prefix, not a second bound laid over skill bodies.
     */
    private function boundHeadAssistantForSummary(string $assistant): string
    {
        if (str_starts_with($assistant, self::SKILL_OUTPUT_MARKER)) {
            return $assistant;
        }

        $max = $this->config->toolOutputMaxChars;
        if (mb_strlen($assistant) <= $max) {
            return $assistant;
        }

        $dropped = mb_strlen($assistant) - $max;

        return mb_substr($assistant, 0, $max)
            . "\n\n[... {$dropped} characters truncated ...]";
    }

    /**
     * Flatten pairs produced by groupIntoPairs() back into a flat message array.
     *
     * Round-trips: every message that went into {@see groupIntoPairs()} comes
     * back out, in the order it went in, `interleaved` riders included — see that
     * method's docblock for the three app-authored messages that used to be lost
     * here instead.
     *
     * @param array<array{user:string,assistant:?string,standalone?:bool,role?:string,interleaved?:list<array{role:string,content:string}>}> $pairs
     * @return array<array{role:string,content:string}>
     */
    private function flattenPairs(array $pairs): array
    {
        $messages = [];
        foreach ($pairs as $pair) {
            if (isset($pair['standalone']) && $pair['standalone'] === true) {
                $messages[] = self::withToolMeta(
                    ['role' => $pair['role'] ?? 'assistant', 'content' => $pair['assistant'] ?? ''],
                    $pair['tool'] ?? null,
                );
                continue;
            }

            $messages[] = ['role' => 'user', 'content' => $pair['user']];
            foreach ($pair['interleaved'] ?? [] as $rider) {
                $messages[] = $rider;
            }
            if ($pair['assistant'] !== null) {
                $messages[] = self::withToolMeta(
                    ['role' => 'assistant', 'content' => $pair['assistant']],
                    $pair['assistantTool'] ?? null,
                );
            }
        }

        return $messages;
    }

    /**
     * $row with its `tool` key put back when the row came from a tool result.
     *
     * Pairing keeps only role and content, so without this a tool row would lose
     * the one structural fact stages 4 and 5 key on ({@see isFileReadMessage()},
     * {@see isNavigationRow()}) between {@see groupIntoPairs()} and the stages.
     *
     * @param array{role:string,content:string} $row
     * @param array{name:string,arguments:array<string,mixed>,error?:bool}|null $tool
     * @return array{role:string,content:string,tool?:array{name:string,arguments:array<string,mixed>,error?:bool}}
     */
    private static function withToolMeta(array $row, ?array $tool): array
    {
        if ($tool !== null) {
            $row['tool'] = $tool;
        }

        return $row;
    }

    /**
     * Group a flat message array into user/assistant pairs.
     *
     * A message that is neither `user` nor a reply to an open pair becomes a
     * `standalone` entry, EXCEPT in one position: directly after a user turn
     * that has not been answered yet. There the pair is still open and waiting
     * for its assistant reply, so closing it to make room for a standalone would
     * invent an exchange boundary the conversation does not have. Such a message
     * rides along on the pair in `interleaved` instead, and
     * {@see flattenPairs()} re-emits it between the user turn and the reply.
     *
     * THAT POSITION USED TO DROP THE MESSAGE ENTIRELY — the standalone was
     * pushed only when no pair was open, and a user turn leaves one open with
     * `assistant === null`. Three app-authored messages land exactly there, and
     * the third is the one that made it a correctness bug rather than a cosmetic
     * one:
     *
     *  - the 70% context reminder ({@see \SugarCraft\Crush\Chat}), appended
     *    immediately after the submitted prompt;
     *  - the automatic tier's own report, appended after the prompt on the
     *    parked route;
     *  - **`_Request cancelled._`**, which is the ONLY record that a turn was
     *    aborted. Erasing it left the compacted history carrying a user prompt
     *    with no answer and no explanation, and that history is fed straight
     *    back to the model as an unanswered turn. Reachable with no provider and
     *    no tier involved: cancel a turn, keep working, wait for a compaction.
     *
     * Riding on the pair rather than becoming a standalone of its own is what
     * keeps the PAIR COUNT unchanged, and the pair count is load-bearing twice
     * over: {@see stagePairs()} preserves the last `recentPreserveCount` PAIRS,
     * and {@see exchangesToSummarize()} offers a model only pairs that have both
     * halves. Measured on a 20-turn history with a reminder after every prompt,
     * closing the pair instead took the offered set from 10 exchanges to **0** —
     * i.e. it would have silently disabled model-written summaries on precisely
     * the histories the automatic tier fires on, since a session past the 70%
     * reminder tier is every session that ever reaches 85%.
     *
     * THAT 10-TO-0 FIGURE IS THE WORST CASE, AND IT IS STILL REACHABLE. It is
     * measured on a reminder after EVERY prompt, which is what a history
     * looked like before
     * {@see \SugarCraft\Crush\Chat::withoutContextReminders()} deduplicated
     * them — so it is the shape every pre-dedup session and every checkpoint
     * written by one still holds, and the shape the closing variant would turn
     * back into 0 offered exchanges.
     *
     * ON A DEDUPED HISTORY THE RESIDUAL HARM IS REAL BUT HAS THE OPPOSITE SIGN,
     * which an earlier draft of this paragraph got backwards by calling it
     * "loses one pair". Measured on the same 20-pair fixture, reminders =
     * none / one-after-the-newest-prompt / after-every-prompt:
     *
     *     CURRENT (interleaved rider)      10   10   10 exchanges offered
     *     CLOSING VARIANT (rejected fix)   10   12    0
     *
     * The single surviving reminder does not cost an exchange, it INFLATES the
     * entry count: closing the pair turns one prompt into three entries (an
     * unanswered pair, a standalone reminder, a standalone assistant turn), so
     * the last-`recentPreserveCount`-ENTRIES window slides off two pairs that
     * should have been preserved verbatim and hands them to the summarizer
     * instead. Fewer exchanges offered is the harm in one direction; more
     * offered is the same harm in the other.
     *
     * NONE OF WHICH MAKES THIS FIX LESS NECESSARY, because the reminder is one
     * of the three victims listed above and the only deduplicated one. The
     * automatic tier's own report and `_Request cancelled._` are per-event
     * records — one per event, never collapsed, never bounded at one copy — and
     * `_Request cancelled._` in particular is the ONLY record that a turn was
     * aborted. For those two the drop was total and the fix is the whole of
     * what saves them; nothing about this method changes for them.
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array<array{user:string,assistant:?string,standalone?:bool,role?:string,interleaved?:list<array{role:string,content:string}>}>
     */
    private function groupIntoPairs(array $messages): array
    {
        $pairs = [];
        $currentPair = null;

        foreach ($messages as $msg) {
            $role = is_array($msg) ? ($msg['role'] ?? '') : '';
            $content = is_array($msg) ? ($msg['content'] ?? '') : (string) $msg;
            // Carried, not consumed: a tool row's structural identity has to
            // survive the pairing so stages 4 and 5 can key on it (audit 0.7).
            $tool = is_array($msg) && is_array($msg['tool'] ?? null) ? $msg['tool'] : null;

            if ($role === 'user') {
                // Save previous pair if exists
                if ($currentPair !== null) {
                    $pairs[] = $currentPair;
                }
                $currentPair = ['user' => $content, 'assistant' => null];
            } elseif ($role === 'assistant' && $currentPair !== null && $currentPair['assistant'] === null) {
                $currentPair['assistant'] = $content;
                if ($tool !== null) {
                    $currentPair['assistantTool'] = $tool;
                }
            } elseif ($currentPair !== null && $currentPair['assistant'] === null) {
                // Directly after an unanswered user turn: see this method's
                // docblock for why this rides on the pair instead of closing it.
                $currentPair['interleaved'][] = self::withToolMeta(['role' => $role, 'content' => $content], $tool);
            } else {
                // Other roles, or an assistant turn with no user turn to pair
                // with - its own standalone entry.
                if ($currentPair !== null) {
                    $pairs[] = $currentPair;
                    $currentPair = null;
                }
                $standalone = ['user' => '', 'assistant' => $content, 'standalone' => true, 'role' => $role];
                if ($tool !== null) {
                    $standalone['tool'] = $tool;
                }
                $pairs[] = $standalone;
            }
        }

        // Don't lose the last pair
        if ($currentPair !== null) {
            $pairs[] = $currentPair;
        }

        return $pairs;
    }

    /**
     * Stage 3: Group consecutive identical exchanges into a single entry with count prefix.
     *
     * Groups consecutive messages with identical content (e.g., repeated "file not found"
     * errors, repeated grep searches) into a single entry prefixed with a count like "[3x]".
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function groupSimilarExchanges(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $result = [];
        $currentContent = null;
        $currentRole = null;
        $count = 0;

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';

            if ($content === $currentContent && $role === $currentRole) {
                $count++;
            } else {
                if ($currentContent !== null) {
                    if ($count > 1) {
                        $result[] = [
                            'role' => $currentRole,
                            'content' => "[{$count}x] {$currentContent}",
                        ];
                    } else {
                        $result[] = [
                            'role' => $currentRole,
                            'content' => $currentContent,
                        ];
                    }
                }
                $currentContent = $content;
                $currentRole = $role;
                $count = 1;
            }
        }

        // Don't lose the last group
        if ($currentContent !== null) {
            if ($count > 1) {
                $result[] = [
                    'role' => $currentRole,
                    'content' => "[{$count}x] {$currentContent}",
                ];
            } else {
                $result[] = [
                    'role' => $currentRole,
                    'content' => $currentContent,
                ];
            }
        }

        return $result;
    }

    /**
     * Stage 4: Replace the output of a successful `Read` call with a metadata
     * line like "[file: path/to/file.php, N lines]".
     *
     * KEYED ON THE TOOL ROW, NOT ON THE TEXT (audit 0.7). A row qualifies only when
     * it carries the `tool` key {@see \SugarCraft\Crush\Chat::compactionWire()}
     * stamps from the row's {@see \SugarCraft\Crush\ToolResult}, names `Read`, and
     * did not fail. The content regex this replaces guessed from the bytes — an
     * opening `<?php`, a leading path, two-space-indented lines ending in `;` —
     * so a user prompt quoting a snippet, or an assistant reply showing code, was
     * rewritten into a `[file: …]` line and its text was lost to the summariser,
     * while a Read of a file with none of those shapes slipped through whole.
     * The path comes from the call's own `file_path` argument for the same reason.
     *
     * The rewritten row keeps only role and content: by the time it is a metadata
     * line it is no longer a tool output any later stage needs to recognise.
     *
     * @param array<array{role:string,content:string,tool?:array<string,mixed>}> $messages
     * @return array<array{role:string,content:string,tool?:array<string,mixed>}>
     */
    public function compactFileReferences(array $messages): array
    {
        return array_map(function (array $msg): array {
            if (!$this->isFileReadMessage($msg)) {
                return $msg;
            }

            $content = (string) ($msg['content'] ?? '');
            $lines = substr_count($content, "\n") + 1;
            $metadata = $this->extractFileMetadata($msg);

            return [
                'role' => $msg['role'] ?? 'assistant',
                'content' => "[file: {$metadata}, {$lines} lines]",
            ];
        }, $messages);
    }

    /**
     * Whether $msg is the output of a successful `Read` call — read off the row's
     * `tool` key, never guessed from its content. A user row is never one, whatever
     * it carries.
     *
     * @param array<string,mixed> $msg
     */
    private function isFileReadMessage(array $msg): bool
    {
        $tool = $msg['tool'] ?? null;

        return ($msg['role'] ?? '') === 'assistant'
            && is_array($tool)
            && ($tool['name'] ?? null) === 'Read'
            && ($tool['error'] ?? false) !== true;
    }

    /**
     * The path a `Read` row read, from the call's `file_path` argument. `file`
     * when the call carried none a string could name (a resumed row from before
     * arguments were recorded).
     *
     * @param array<string,mixed> $msg
     */
    private function extractFileMetadata(array $msg): string
    {
        $path = $msg['tool']['arguments']['file_path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            return 'file';
        }

        // One line, bounded: the path is model-supplied and lands in a row the
        // summariser is shown.
        $path = trim(str_replace(["\r", "\n"], ' ', $path));

        return mb_strlen($path) > 200 ? mb_substr($path, 0, 200) . '…' : $path;
    }

    /**
     * Stage 0: older tool output becomes a placeholder naming its call — the
     * same age rule, by the same figures, the engine applies to an over-budget
     * request (roadmap 2.2-1,
     * {@see \SugarCraft\Crush\Context\Pruning\Strategies\ToolOutputAgeStrategy::select()}).
     *
     * A tool row is one carrying the `tool` key
     * ({@see \SugarCraft\Crush\Host\CompactionService::compactionWire()}).
     * Walking back from the newest row, everything from the second-last user
     * prompt on is kept, then the newest 40k estimated tokens of tool output;
     * older output (not `Task` or `Skill`, whose results cannot be had again by
     * re-running a cheap call) becomes
     * {@see \SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder} — and
     * only when that frees at least 20k, so a small history is never touched.
     *
     * THE PRESERVED TAIL IS NEVER CHANGED. The last `recentPreserveCount`
     * exchanges are what {@see compact()} keeps verbatim, and Chat maps a
     * compacted wire back onto its rows by matching that tail byte for byte
     * ({@see \SugarCraft\Crush\Host\CompactionService::messagesFromWire()}):
     * a placeholder there would turn the kept rows into rewritten ones. So the
     * rule only reaches the rows about to be condensed, and a history with
     * nothing to condense comes back as it went in. Row count and roles never
     * change, so the pairing downstream is the pairing of the input.
     *
     * The legacy shape — a `system` row carrying `tool_results`, which no
     * current producer writes — is still dropped.
     *
     * @param array<array{role:string,content:string,tool?:array<string,mixed>,tool_results?:mixed}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function removeToolResults(array $messages): array
    {
        $messages = array_values(array_filter(
            $messages,
            fn(array $msg): bool => !(
                ($msg['role'] ?? '') === 'system'
                && isset($msg['tool_results'])
            )
        ));

        $preserveCount = $this->config->recentPreserveCount;
        $preservedRows = $preserveCount > 0
            ? count($this->flattenPairs(array_slice($this->groupIntoPairs($messages), -$preserveCount)))
            : 0;
        $protectFrom = count($messages) - $preservedRows;

        $rows = [];
        $placeholders = [];
        foreach ($messages as $index => $msg) {
            $content = (string) ($msg['content'] ?? '');
            if (($msg['role'] ?? '') === 'user' && !str_starts_with($content, TurnContextBlock::FENCE . "\n")) {
                $rows[] = ['userTurn' => true];

                continue;
            }
            $tool = $msg['tool'] ?? null;
            if (!is_array($tool)) {
                continue;
            }
            $name = is_string($tool['name'] ?? null) ? $tool['name'] : '';
            $placeholders[$index] = \SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder::for(
                $name,
                is_array($tool['arguments'] ?? null) ? $tool['arguments'] : [],
            );
            $rows[] = [
                'key' => $index,
                'tool' => $name,
                'tokens' => TokenEstimate::ofText($content),
                'placeholderTokens' => TokenEstimate::ofText($placeholders[$index]),
                'keep' => $index >= $protectFrom,
            ];
        }

        $selected = \SugarCraft\Crush\Context\Pruning\Strategies\ToolOutputAgeStrategy::select(
            $rows,
            \SugarCraft\Crush\Context\Pruning\PruningPolicy::new(),
        );
        foreach (array_keys($selected) as $index) {
            $messages[$index]['content'] = $placeholders[$index];
        }

        return $messages;
    }

    /**
     * A shell command that only looks around: the WHOLE command is one `cd`, `ls`
     * or `pwd`, with plain words for arguments and nothing a shell would treat as
     * a second command, a redirect or an expansion. Anchored at both ends, so
     * `cd src && rm -rf build` or `ls; make` is not navigation.
     *
     * `mkdir`, `rm`, `mv` and `cp` are NOT here, though the patterns this replaces
     * listed them: they change the tree, and a record of a change is exactly what a
     * summary has to keep.
     */
    private const NAV_COMMAND_PATTERN = '/\A(?:cd(?:[ \t]+' . self::NAV_WORD . ')?|pwd|ls(?:[ \t]+' . self::NAV_WORD . ')*)\z/';

    /**
     * One plain shell word: no whitespace (so no second line), no quoting or
     * escaping, and no operator, redirect, expansion, glob or grouping character.
     * The separator between words is a space or tab, never a newline.
     */
    private const NAV_WORD = '[^\s;&|<>`$(){}\\\\\'"*?\[\]~!#]+';

    /**
     * Stage 5: Remove navigation steps — the output of a `Bash` call whose whole
     * command was a lone `cd`, `ls` or `pwd` ({@see NAV_COMMAND_PATTERN}).
     *
     * KEYED ON THE TOOL ROW (audit 0.7). The unanchored multi-line patterns this
     * replaces matched any LINE starting `cd `, `ls`, `rm ` and the like in any
     * row's content — so a user prompt such as "ls the files\ncd later" was
     * deleted from the history outright, as was any assistant reply that showed a
     * command on a line of its own; and the row after a "navigation" one was kept
     * on the theory that it was that command's result, which is no longer how a
     * tool call is recorded. A tool row now IS the call and its output together,
     * so a navigation step is one row and dropping it touches nothing around it.
     *
     * A `user` row is never dropped, and neither is a navigation call that failed
     * (`cd` into a directory that does not exist is a fact worth summarising).
     *
     * @param array<array{role:string,content:string,tool?:array<string,mixed>}> $messages
     * @return array<array{role:string,content:string,tool?:array<string,mixed>}>
     */
    public function removeNavigationSteps(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            fn(array $msg): bool => !$this->isNavigationRow($msg),
        ));
    }

    /**
     * @param array<string,mixed> $msg
     */
    private function isNavigationRow(array $msg): bool
    {
        $tool = $msg['tool'] ?? null;
        if (($msg['role'] ?? '') !== 'assistant' || !is_array($tool)) {
            return false;
        }
        if (($tool['name'] ?? null) !== 'Bash' || ($tool['error'] ?? false) === true) {
            return false;
        }

        $command = $tool['arguments']['command'] ?? null;
        if (!is_string($command)) {
            return false;
        }

        return preg_match(self::NAV_COMMAND_PATTERN, trim($command)) === 1;
    }

    /**
     * Apply skill budget constraints to a list of active skills.
     *
     * Skills whose content exceeds the per-skill budget (skillBudgetPerSkill tokens)
     * are truncated. If the combined budget (skillBudgetCombined tokens) is exceeded,
     * the least-recently-invoked skills are dropped first.
     *
     * Note: the while-loop guard `count($skills) > 1` prevents dropping the last
     * remaining skill even if that single skill alone exceeds the combined budget.
     * This is intentional—dropping the only skill would leave nothing to invoke.
     *
     * Mirrors charmbracelet/bubbletea ContextCompactor.filterSkills.
     *
     * @param array<array{name:string,content:string,lastInvokedAt:int}> $skills
     * @return array<array{name:string,content:string,lastInvokedAt:int}> Filtered skills
     */
    public function filterSkills(array $skills): array
    {
        if ($skills === []) {
            return [];
        }

        // Stage A: truncate each skill to per-skill budget
        $budgetPerSkill = $this->config->skillBudgetPerSkill;
        $maxCharsPerSkill = $budgetPerSkill * 4; // 1 token ≈ 4 chars

        $skills = array_map(function (array $skill) use ($maxCharsPerSkill): array {
            $content = $skill['content'] ?? '';
            if (mb_strlen($content) > $maxCharsPerSkill) {
                $skill['content'] = $this->truncateWithEllipsis($content, $maxCharsPerSkill);
            }
            return $skill;
        }, $skills);

        // Stage B: if combined budget exceeded, drop LRU skills until within limit
        $budgetCombined = $this->config->skillBudgetCombined;
        $maxCharsCombined = $budgetCombined * 4;

        $totalChars = array_sum(array_map(
            fn(array $s): int => mb_strlen($s['content'] ?? ''),
            $skills
        ));

        while ($totalChars > $maxCharsCombined && count($skills) > 1) {
            // Find least-recently-invoked (smallest lastInvokedAt)
            $lruIndex = 0;
            $lruTime = PHP_INT_MAX;
            foreach ($skills as $idx => $skill) {
                $invoked = $skill['lastInvokedAt'] ?? PHP_INT_MAX;
                if ($invoked < $lruTime) {
                    $lruTime = $invoked;
                    $lruIndex = $idx;
                }
            }

            // Remove the LRU skill
            $removedLen = mb_strlen($skills[$lruIndex]['content'] ?? '');
            array_splice($skills, $lruIndex, 1);
            $totalChars -= $removedLen;
        }

        return $skills;
    }

    /**
     * Return the percentage of context space saved after the last compaction.
     *
     * @return int 0 if no compaction run, or percentage (0-100) of tokens saved.
     */
    public function savingsPercentage(): int
    {
        return $this->lastSavingsPercentage;
    }

    /**
     * Count estimated tokens in a message array.
     *
     * {@see TokenEstimate::ofText()} per message - a quarter-token per ASCII or
     * Latin character, more for other scripts, CJK and emoji - plus ~10 tokens
     * of role overhead each.
     *
     * @param array<array{role:string,content:string}> $messages
     */
    private function countTokens(array $messages): int
    {
        $total = 0;
        foreach ($messages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? '') : (string) $msg;
            // Script-weighted, the same figure Chat's estimate uses (audit
            // 15b-13-rem): codepoints/4 read CJK and emoji 3-6x low, so every
            // tier here fired late for those sessions.
            $total += TokenEstimate::ofText((string) $content);
            $total += 10; // role overhead
        }
        return $total;
    }

    /**
     * The first bytes of the 70% context-usage notice, spelled to match
     * {@see \SugarCraft\Crush\Chat::CONTEXT_REMINDER_PREFIX}, whose `private`
     * visibility this respects rather than works around: `Chat` constructs THIS
     * class, so a `use` here would be the first code-level edge from `src/Context/`
     * back up to `SugarCraft\Crush\Chat` (every existing `Chat` mention in this
     * directory is a doc-block `{@see}`, never a `use`) — a dependency pointing the
     * wrong way to save restating one string, which is also why
     * {@see self::SKILL_OUTPUT_MARKER} spells `SkillTool::execute()`'s heading
     * literally in this file. The duplication cannot rot unnoticed the way that one
     * can, because `ContextCompactorTest::testTheReminderPrefixSpellingIsPinnedToTheChatConstThatEmitsIt()`
     * reads `Chat`'s private const by reflection (the idiom `BaseSystemPromptTest`
     * uses for `Runtime`'s private prose consts) and compares bytes: rename the
     * notice without renaming this spelling and a test reddens, instead of the guard
     * silently un-declining every reminder row.
     */
    private const CONTEXT_REMINDER_PREFIX = 'Heads up: this conversation has grown to ~';

    /**
     * Whether a row about to be written into the compacted history IS the app's
     * regenerated token-count notice: the same two halves as
     * {@see \SugarCraft\Crush\Chat::isContextReminder()} — role AND prefix, both
     * required — applied to the wire arrays this class works on, and deliberately
     * not a wider net, so a user quoting the notice back stays their own text and
     * rides into a summary like any other row. The decline belongs HERE rather than
     * in the 120-character clip because the notice is appended afresh on every
     * dispatch, so any carry of its bytes is an older figure wearing a `[summary] `
     * label and clipping it harder only shortens the stale number — E38 in one
     * clause. `Chat` declines the same row at carry time out of the transcript
     * ({@see \SugarCraft\Crush\Chat::priorSummariesFromHistory()}, ruling R-D); that
     * decline keeps its job for rows transcripts written before this step still
     * carry, and neither site widens the other's predicate.
     */
    private static function isRegeneratedReminderRow(string $role, string $content): bool
    {
        return $role === 'system'
            && str_starts_with($content, self::CONTEXT_REMINDER_PREFIX);
    }

    /**
     * Summarize older exchanges into single-line summaries.
     *
     * Takes an array of pairs (from groupIntoPairs) and produces one
     * summary message per pair, capturing "what happened and any key decisions made."
      * A pair carrying `interleaved` riders produces one extra truncated line per
      * rider, so that nothing the compaction was handed is silently dropped —
      * {@see groupIntoPairs()} for what those riders are and why. ONE rider is
      * declined rather than carried: the regenerated token-count notice, whose bytes
      * the app writes afresh on every dispatch, so a `[summary] ` line quoting a
      * figure from an older turn is a stale number wearing a summary's label (E38;
      * {@see isRegeneratedReminderRow()} for why the decline sits here and not in the
      * clip, which the other riders still get).
     *
     * THE STATE BLOCK LEADS (roadmap 2.5): the first row is always a
     * {@see Compaction\StateSummaryTemplate} block — the one a caller supplied
     * under {@see Compaction\StateSummaryTemplate::SUMMARY_KEY} (model-written
     * and audited, or built from the full history by
     * {@see \SugarCraft\Crush\Host\CompactionService}), else the heuristic block
     * from these pairs merged with the previous compaction's state row, so the
     * headings the model reads are the same with or without a provider. That
     * previous state row is consumed by the merge rather than carried on as a
     * second, 120-character-clipped copy.
     *
     * @param array<array{user:string,assistant:?string,standalone?:bool,role?:string,interleaved?:list<array{role:string,content:string}>}> $pairs
     * @return array<array{role:string,content:string}>
     */
    private function summarizeExchanges(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $previousState = null;

        // Generate summaries - one per pair
        $summaries = [];
        foreach ($pairs as $pair) {
            if (isset($pair['standalone']) && $pair['standalone'] === true) {
                // Standalone message (unpaired role like 'system')
                $content = $pair['assistant'] ?? '';
                $role = $pair['role'] ?? 'assistant';
                if (self::isRegeneratedReminderRow((string) $role, (string) $content)) {
                    continue; // E38 — see isRegeneratedReminderRow()
                }
                $state = Compaction\StateSummaryTemplate::fromRow((string) $content);
                if ($state !== null) {
                    $previousState = $state; // merged into the new block below
                    continue;
                }
                // Truncate long standalone messages
                $summary = mb_strlen($content) > 120
                    ? $this->truncateWithEllipsis($content, 120)
                    : $content;
                $summaries[] = [
                    'role' => $role,
                    'content' => '[summary] ' . $summary,
                ];
            } else {
                $userContent = $pair['user'] ?? '';
                $assistantContent = $pair['assistant'] ?? '';

                $summary = $this->generateExchangeSummary($userContent, $assistantContent);
                $summaries[] = [
                    'role' => 'assistant',
                    'content' => '[summary] ' . $summary,
                ];

                // A rider is an app-authored message that landed inside this
                // exchange (see groupIntoPairs()). It gets its own truncated line
                // rather than being folded into the summary above, because the
                // summary is keyed to the user/assistant text alone - a
                // model-written one never saw the rider at all - so folding it in
                // would mean either re-keying every summary or dropping the
                // rider, and `_Request cancelled._` is the case where dropping it
                // loses the only record of how the turn ended.
                foreach ($pair['interleaved'] ?? [] as $rider) {
                    $riderRole = (string) $rider['role'];
                    $riderContent = (string) $rider['content'];
                    if (self::isRegeneratedReminderRow($riderRole, $riderContent)) {
                        continue; // E38 — see isRegeneratedReminderRow()
                    }
                    $summaries[] = [
                        'role' => $riderRole,
                        'content' => '[summary] ' . (mb_strlen($riderContent) > 120
                            ? $this->truncateWithEllipsis($riderContent, 120)
                            : $riderContent),
                    ];
                }
            }
        }

        $supplied = $this->exchangeSummaries[Compaction\StateSummaryTemplate::SUMMARY_KEY] ?? null;
        if (!is_string($supplied) || $supplied === '') {
            $heuristic = Compaction\StateSummaryTemplate::fromPairs($pairs);
            $supplied = ($previousState === null ? $heuristic : $heuristic->mergedWith($previousState))->render();
        }

        return [
            ['role' => 'assistant', 'content' => Compaction\StateSummaryTemplate::ROW_PREFIX . $supplied],
            ...$summaries,
        ];
    }

    /**
     * Generate a one-line summary for a user/assistant exchange.
     *
     * A model-written summary wins when one was supplied for this exact
     * exchange (see the constructor's $exchangeSummaries docblock). Otherwise
     * the heuristic below runs, unchanged: it truncates the user's message and
     * appends either the assistant's reply verbatim, when it is short enough to
     * fit, or the placeholder `[exchanged information]` when it is not.
     *
     * That placeholder is what crush_code.md Phase 5 item 6 exists to remove,
     * and it is worth being exact about what "remove" means here: the heuristic
     * is NOT deleted, because it is the only thing available when there is no
     * model to ask, and a compaction that refused to run without a provider
     * would be a worse outcome than a lossy one. What changed is that a session
     * with a provider no longer gets it.
     */
    private function generateExchangeSummary(string $userMsg, string $assistantMsg): string
    {
        $supplied = $this->exchangeSummaries[self::exchangeKey($userMsg, $assistantMsg)] ?? null;
        if (is_string($supplied) && $supplied !== '') {
            return $supplied;
        }

        // Extract the essence: what was asked and what was done
        $userMax = $this->config->summaryUserMaxChars;
        $userTruncated = mb_strlen($userMsg) > $userMax
            ? $this->truncateWithEllipsis($userMsg, $userMax)
            : $userMsg;

        // If assistant is short, include it directly
        if (mb_strlen($assistantMsg) <= $this->config->summaryAssistantMaxChars) {
            return $userTruncated . ' → ' . $assistantMsg;
        }

        // Otherwise just describe what happened
        return $userTruncated . ' → [exchanged information]';
    }

    /**
     * Truncate string to maxChars and append ellipsis if truncated.
     */
    private function truncateWithEllipsis(string $content, int $maxChars): string
    {
        return mb_substr($content, 0, $maxChars - 3) . '...';
    }

    /**
     * Calculate the percentage savings from compaction.
     *
     * @param array<array{role:string,content:string}> $original
     * @param array<array{role:string,content:string}> $compacted
     */
    private function calculateSavingsPercentage(array $original, array $compacted): int
    {
        $originalTokens = $this->countTokens($original);
        $compactedTokens = $this->countTokens($compacted);

        if ($originalTokens === 0) {
            return 0;
        }

        $savings = $originalTokens - $compactedTokens;
        $percentage = (int) (($savings / $originalTokens) * 100);

        return max(0, $percentage);
    }
}
