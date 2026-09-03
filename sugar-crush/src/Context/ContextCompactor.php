<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

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
 * a different absolute token count per provider.
 *
 * Token counting estimates 1 token ≈ 4 characters for PHP strings.
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
     * The content key a model-written summary is filed under: the exact
     * user/assistant text of the exchange it summarises, hashed.
     *
     * Hashed rather than stored whole so the map does not carry a second copy of
     * the conversation. Two byte-identical exchanges collide onto one key, which
     * is harmless — they would receive the same summary either way.
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
     * Determine whether compaction should run based on current token usage.
     *
     * Returns true when context usage reaches or exceeds the background
     * compaction threshold (85% by default). Uses token counting with
     * the estimate that 1 token ≈ 4 characters.
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
        $threshold = (int) ($tokenLimit * $this->config->backgroundCompactionThreshold / 100);

        return $tokenCount >= $threshold;
    }

    /**
     * Determine whether foreground blocking compaction is needed.
     *
     * Returns true when context usage reaches or exceeds the foreground
     * blocking threshold (95% by default). At this threshold, new input
     * is blocked until space is freed by compaction.
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
        $threshold = (int) ($tokenLimit * $this->config->foregroundBlockingThreshold / 100);

        return $tokenCount >= $threshold;
    }

    /**
     * Estimated tokens reserved, on top of the blocking tier, so a turn an
     * intra-exchange truncation just enabled still fits once {@see
     * \SugarCraft\Crush\Chat::dispatchTurn()} appends the echoed prompt, the
     * 70% reminder, and this compaction's own notice — none of which are in the
     * $messages handed here.
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
     * Shrink a SINGLE exchange that is larger than the context window, in place,
     * so it stops being un-sendable (prompt_plan.md P4.S4, backlog §12.2 E18).
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

        $threshold = (int) ($tokenLimit * $this->config->foregroundBlockingThreshold / 100);
        $total = $this->countTokens($messages);
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
        $charBudget = max(0, ($share - 10) * 4);

        $truncated = $messages;
        foreach (array_keys($oversized) as $index) {
            $truncated[$index] = $this->truncateMessageHead($messages[$index], $charBudget);
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
    private function truncateMessageHead(array $message, int $charBudget): array
    {
        $content = (string) ($message['content'] ?? '');
        if (mb_strlen($content) <= $charBudget) {
            return $message;
        }

        $length = mb_strlen($content);
        $markerReserve = min($charBudget, self::INTRA_EXCHANGE_MARKER_MAX_CHARS);
        $head = $charBudget - $markerReserve;
        $dropped = $length - $head;
        $truncated = mb_substr($content, 0, $head)
            . "\n\n[... {$dropped} characters truncated to fit the context window ...]";

        if (mb_strlen($truncated) > $charBudget) {
            $truncated = mb_substr($truncated, 0, $charBudget);
        }

        $message['content'] = $truncated;

        return $message;
    }

    /**
     * Determine whether a soft reminder should be sent to the lead agent.
     *
     * Returns true when context usage reaches or exceeds the reminder
     * threshold (70% by default). This is a soft warning surfaced to the
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
     * $tokenCount is an ESTIMATE — {@see countTokens()}'s chars/4 + 10 per
     * message. $tokenLimit is the provider's real window only on the one path
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
        $threshold = (int) ($tokenLimit * $this->config->reminderThreshold / 100);

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
     * Stage 4: Replace file contents with metadata summaries.
     * Stage 5: Remove navigation steps while preserving final destination.
     * Stage 2: Condense older exchanges into single-line summaries capturing
     *          "what happened and any key decisions made."
     * Stage 3: Group consecutive identical exchanges (e.g., repeated grep searches).
     *
     * Stages 4 and 5 run against the RAW pre-summarization content, before
     * stage 2's summarization has a chance to truncate/collapse it away —
     * summarizing first would destroy the very file-content and nav-command
     * patterns stages 4/5 look for, so they must see the originals.
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

        // Stage 0: strip tool-result system messages before pairing
        // (tool results are voluminous intermediate outputs; they are not
        // part of the conversational exchange that needs preserving)
        $messages = $this->removeToolResults($messages);

        // Group messages into user/assistant pairs
        $pairs = $this->groupIntoPairs($messages);

        // Stage 1: if we have <= preserveCount pairs, no compaction needed
        if (count($pairs) <= $preserveCount) {
            return null;
        }

        // Split: last preserveCount pairs are preserved, earlier pairs go to summary
        $preservePairs = array_slice($pairs, -$preserveCount);
        $toSummarizePairs = array_slice($pairs, 0, count($pairs) - $preserveCount);

        // Stages 4 & 5 need the raw (un-summarized) message content to detect
        // file reads and navigation commands, so flatten first and run them
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
     * stage 2 truncates those to 120 characters rather than summarising them,
     * and offering them to a model would produce summaries nothing consumes.
     * Neither are pairs with no assistant reply, which have no exchange to
     * summarise yet.
     *
     * Empty means there is nothing a model could usefully be asked — either
     * nothing to compact at all, or nothing but standalone/unanswered turns.
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
                'assistant' => $assistant,
            ];
        }

        return $out;
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
                $messages[] = ['role' => $pair['role'] ?? 'assistant', 'content' => $pair['assistant'] ?? ''];
                continue;
            }

            $messages[] = ['role' => 'user', 'content' => $pair['user']];
            foreach ($pair['interleaved'] ?? [] as $rider) {
                $messages[] = $rider;
            }
            if ($pair['assistant'] !== null) {
                $messages[] = ['role' => 'assistant', 'content' => $pair['assistant']];
            }
        }

        return $messages;
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

            if ($role === 'user') {
                // Save previous pair if exists
                if ($currentPair !== null) {
                    $pairs[] = $currentPair;
                }
                $currentPair = ['user' => $content, 'assistant' => null];
            } elseif ($role === 'assistant' && $currentPair !== null && $currentPair['assistant'] === null) {
                $currentPair['assistant'] = $content;
            } elseif ($currentPair !== null && $currentPair['assistant'] === null) {
                // Directly after an unanswered user turn: see this method's
                // docblock for why this rides on the pair instead of closing it.
                $currentPair['interleaved'][] = ['role' => $role, 'content' => $content];
            } else {
                // Other roles, or an assistant turn with no user turn to pair
                // with - its own standalone entry.
                if ($currentPair !== null) {
                    $pairs[] = $currentPair;
                    $currentPair = null;
                }
                $pairs[] = ['user' => '', 'assistant' => $content, 'standalone' => true, 'role' => $role];
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
     * Stage 4: Replace file read message content with metadata summary.
     *
     * Detects "file read" type messages by looking for common file extension
     * patterns in message content, then replaces the full content with a
     * metadata summary like "[file: path/to/file.php, N lines]".
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function compactFileReferences(array $messages): array
    {
        return array_map(function (array $msg): array {
            $content = $msg['content'] ?? '';

            if (!$this->isFileReadMessage($content)) {
                return $msg;
            }

            $lines = substr_count($content, "\n") + 1;
            $metadata = $this->extractFileMetadata($content);

            return [
                'role' => $msg['role'] ?? 'assistant',
                'content' => "[file: {$metadata}, {$lines} lines]",
            ];
        }, $messages);
    }

    /**
     * Detect if message content represents a file read operation.
     */
    private function isFileReadMessage(string $content): bool
    {
        // Match common file extension patterns that indicate file content
        // e.g., "<?php\n...class Foo..." or "<?php\ndeclare(strict_types=1);..."
        $phpPattern = '/<\?php\s*\n/s';
        if (preg_match($phpPattern, $content)) {
            return true;
        }

        // Match patterns like "path/to/file.php" or "file.php" appearing as a header
        // followed by substantial content (file content display)
        if (preg_match('/^[\w\-\.\/]+\.(php|ts|js|tsx|jsx|json|html|txt|md|css|yaml|yml)\s*\n/s', $content)) {
            return true;
        }

        // Match content that starts with common file path patterns
        if (preg_match('/^\/[\w\-\.\/]+\.(php|ts|js|tsx|jsx|json|html|txt|md|css|yaml|yml)/m', $content)) {
            return true;
        }

        // Match content with multiple lines containing typical code patterns
        // (indentation, brackets, semicolons)
        if (preg_match('/^\s{2,}[\$\w]\S*\s*[;\{\}]/m', $content) && substr_count($content, "\n") > 3) {
            return true;
        }

        return false;
    }

    /**
     * Extract file path metadata from file read content.
     */
    private function extractFileMetadata(string $content): string
    {
        // Try to extract file path from the first line
        if (preg_match('/^([\w\-\.\/]+\.(php|ts|js|tsx|jsx|json|html|txt|md|css|yaml|yml))/', $content, $matches)) {
            return $matches[1];
        }

        // Try to find a path-like pattern anywhere in content
        if (preg_match('/([\w\-\.\/]+\.(php|ts|js|tsx|jsx|json|html|txt|md|css|yaml|yml))/', $content, $matches)) {
            return $matches[1];
        }

        // Fallback: return a generic indicator based on content characteristics
        $firstLine = explode("\n", $content)[0] ?? 'unknown';
        if (mb_strlen($firstLine) > 50) {
            return 'file';
        }

        return $firstLine;
    }

    /**
     * Stage 0: Remove tool result messages from older exchanges.
     *
     * Removes messages with role=system that carry tool_results, as these are
     * voluminous intermediate outputs that are summarized by stage 2 anyway.
     * Recent tool results (within recentPreserveCount pairs) are kept intact.
     *
     * @param array<array{role:string,content:string,?tool_results?:mixed}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function removeToolResults(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            fn(array $msg): bool => !(
                ($msg['role'] ?? '') === 'system'
                && isset($msg['tool_results'])
            )
        ));
    }

    /**
     * Navigation command patterns matched by removeNavigationSteps().
     *
     * @var array<string>
     */
    private const NAV_PATTERNS = [
        '/^cd\s+/m',
        '/^ls\s*/m',
        '/^pwd$/m',
        '/^mkdir\s+/m',
        '/^rm\s+/m',
        '/^mv\s+/m',
        '/^cp\s+/m',
    ];

    /**
     * Stage 5: Remove navigation steps while preserving final destination or result.
     *
     * Removes messages whose content indicates navigation commands (e.g., "cd /path/to/dir",
     * "ls", "pwd") while preserving the final destination or result that follows.
     *
     * When a nav-pattern message is removed, the immediate following assistant message
     * (the result/destination output) is preserved — unless that result message also
     * represents independent content worth keeping on its own.
     *
     * @param array<array{role:string,content:string}> $messages
     * @return array<array{role:string,content:string}>
     */
    public function removeNavigationSteps(array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $result = [];
        $i = 0;
        $count = count($messages);

        while ($i < $count) {
            $msg = $messages[$i];
            $content = $msg['content'] ?? '';
            $isNavigation = false;

            foreach (self::NAV_PATTERNS as $pattern) {
                if (preg_match($pattern, $content)) {
                    $isNavigation = true;
                    break;
                }
            }

            if (!$isNavigation) {
                $result[] = $msg;
                $i++;
                continue;
            }

            // Navigation message found — skip it, but preserve the following
            // assistant result message if it describes the outcome of this navigation.
            $i++;
            if ($i < $count) {
                $nextMsg = $messages[$i];
                $nextRole = $nextMsg['role'] ?? '';

                // Keep the result message if it's an assistant's output describing
                // the navigation outcome (path, directory listing, etc.).
                if ($nextRole === 'assistant') {
                    $result[] = $nextMsg;
                    $i++;
                }
            }
        }

        return $result;
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
     * Uses the approximation 1 token ≈ 4 characters for PHP strings.
     * Each message also accounts for role overhead (~10 tokens).
     *
     * @param array<array{role:string,content:string}> $messages
     */
    private function countTokens(array $messages): int
    {
        $total = 0;
        foreach ($messages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? '') : (string) $msg;
            $total += (int) ceil(mb_strlen($content) / 4);
            $total += 10; // role overhead
        }
        return $total;
    }

    /**
     * Summarize older exchanges into single-line summaries.
     *
     * Takes an array of pairs (from groupIntoPairs) and produces one
     * summary message per pair, capturing "what happened and any key decisions made."
     * A pair carrying `interleaved` riders produces one extra truncated line per
     * rider, so that nothing the compaction was handed is silently dropped —
     * {@see groupIntoPairs()} for what those riders are and why.
     *
     * @param array<array{user:string,assistant:?string,standalone?:bool,role?:string,interleaved?:list<array{role:string,content:string}>}> $pairs
     * @return array<array{role:string,content:string}>
     */
    private function summarizeExchanges(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        // Generate summaries - one per pair
        $summaries = [];
        foreach ($pairs as $pair) {
            if (isset($pair['standalone']) && $pair['standalone'] === true) {
                // Standalone message (unpaired role like 'system')
                $content = $pair['assistant'] ?? '';
                $role = $pair['role'] ?? 'assistant';
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
                    $riderContent = (string) $rider['content'];
                    $summaries[] = [
                        'role' => (string) $rider['role'],
                        'content' => '[summary] ' . (mb_strlen($riderContent) > 120
                            ? $this->truncateWithEllipsis($riderContent, 120)
                            : $riderContent),
                    ];
                }
            }
        }

        return $summaries;
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
