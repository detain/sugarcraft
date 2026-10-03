<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * Configuration for automatic context compaction.
 *
 * Controls the tiered threshold system that triggers compaction at
 * different levels of context usage, and budgets for skill content
 * during compaction passes.
 *
 * Every tier is a percentage of the window and, optionally, an ABSOLUTE token
 * cap beside it (roadmap 2.9): the tier fires at whichever is lower. A
 * percentage alone scales the wrong way on a large window — on a 1M-token model
 * the 70% reminder lands at ~700k estimated tokens, far past the size at which
 * a model keeps its quality (DCP's "smart zone", 50k/100k by default). Each
 * cap can be overridden per model ({@see forModel()}).
 *
 * The caps are UNSET by default, so {@see new()} behaves exactly as the
 * percentages always did. {@see smartZone()} is DCP's figures. Not the default,
 * because the automatic tier's thrash breaker refuses the prompt once three
 * compactions in a row leave the context over the tier: with a 100k cap on a 1M
 * window, ten preserved exchanges heavier than 100k would turn every prompt
 * into a refusal whose advice ("/model with a larger context window") cannot
 * help. Choosing that default is a settings decision (N-P4b).
 *
 * All values are immutable after construction — use with*() methods
 * to produce derived instances.
 */
final readonly class CompactorConfig
{
    /**
     * DCP's `compress.minContextLimit` default (03-opencode-dcp.md §4.7): the
     * size past which DCP starts nudging the model about its context, whatever
     * the window. The figure a caller hands {@see withReminderTokens()} to put
     * the reminder tier in the "smart zone" — see {@see smartZone()}.
     */
    public const SMART_ZONE_REMINDER_TOKENS = 50_000;

    /**
     * DCP's `compress.maxContextLimit` default: the size DCP treats as the
     * emergency line. {@see smartZone()} caps the automatic-compaction tier here.
     */
    public const SMART_ZONE_COMPACTION_TOKENS = 100_000;

    /**
     * The three absolute caps, by property name — the keys a
     * {@see $modelTokenOverrides} entry may carry.
     */
    public const ABSOLUTE_FIELDS = ['reminderTokens', 'backgroundCompactionTokens', 'foregroundBlockingTokens'];

    /**
     * @param int $reminderThreshold        Context usage percentage (0-100) at which
     *                                      a reminder is sent to the lead agent.
     *                                      Default: 70.
     * @param int $backgroundCompactionThreshold Context usage percentage at which
     *                                      automatic compaction begins in the background.
     *                                      Default: 85.
     * @param int $foregroundBlockingThreshold Context usage percentage at which
     *                                      foreground compaction blocks new input.
     *                                      Default: 95.
     * @param int $recentPreserveCount     Number of most-recent exchanges to keep
     *                                      full (uncompacted) during stage one.
     *                                      Default: 10.
     * @param int $skillBudgetPerSkill      Max tokens per skill during compaction.
     *                                      Default: 5000.
     * @param int $skillBudgetCombined     Combined budget cap across all skills
     *                                      still in context after compaction.
     *                                      Default: 25000.
     * @param int $summaryUserMaxChars     Max characters to retain from user messages
     *                                      when generating exchange summaries (stage 2).
     *                                      Default: 80.
     * @param int $summaryAssistantMaxChars Assistant content longer than this is
     *                                      replaced with "[exchanged information]"
     *                                      in exchange summaries (stage 2) — by the
     *                                      HEURISTIC summarizer. An exchange the
     *                                      caller supplied a model-written summary
     *                                      for never reaches either bound; see
     *                                      {@see ContextCompactor::withExchangeSummaries()}.
     *                                      Default: 100.
     * @param int $toolOutputMaxChars      Max characters of a condensed exchange's ASSISTANT
     *                                      half that {@see ContextCompactor::exchangesToSummarize()}
     *                                      shows the summariser. Finished tool output enters
     *                                      history as assistant content, so this is the bound on
     *                                      what one large tool blob costs the model that is being
     *                                      asked for summaries. The `user` half of a condensed
     *                                      exchange is not bounded here, and neither is the
     *                                      preserved recent window — this speaks to the head a
     *                                      model reads, never to the transcript. An assistant turn
     *                                      opening with the skill marker is exempt: skill output
     *                                      is never pruned. Numerically equal to
     *                                      Chat::SUMMARY_LINE_MAX_CHARS and to
     *                                      ContextCompactor::INTRA_EXCHANGE_HEADROOM_TOKENS and
     *                                      unrelated to both — those are an output-side record
     *                                      clip and a token reserve on the blocking-tier rescue,
     *                                      this is an input-side head bound. Three 2,000s that
     *                                      mean three things.
     *                                      Default: 2000.
     * @param ?int $reminderTokens         Absolute cap, in estimated tokens, on the
     *                                      reminder tier (roadmap 2.9): the tier fires
     *                                      at `min(reminderThreshold% of the window,
     *                                      this)`. Null (the default) is no cap — the
     *                                      percentage alone decides, as before 2.9.
     * @param bool $reminderTokensSet      Whether $reminderTokens was configured,
     *                                      null included. The sentinel is what lets a
     *                                      per-model override CLEAR a base cap: an
     *                                      override naming the key with null sets it,
     *                                      one that leaves the key out inherits.
     * @param ?int $backgroundCompactionTokens Absolute cap on the automatic
     *                                      compaction tier, same rule. Null: no cap.
     * @param bool $backgroundCompactionTokensSet Sentinel for the above.
     * @param ?int $foregroundBlockingTokens Absolute cap on the blocking tier,
     *                                      same rule. Null: no cap.
     * @param bool $foregroundBlockingTokensSet Sentinel for the above.
     * @param array<string, array<string, ?int>> $modelTokenOverrides Per-model
     *                                      absolute caps, keyed by model id or by
     *                                      `provider/model` (DCP's `modelMinLimits` /
     *                                      `modelMaxLimits`), each a partial map over
     *                                      {@see ABSOLUTE_FIELDS}. Applied by
     *                                      {@see forModel()}; inert until then.
     */
    public function __construct(
        public int $reminderThreshold = 70,
        public int $backgroundCompactionThreshold = 85,
        public int $foregroundBlockingThreshold = 95,
        public int $recentPreserveCount = 10,
        public int $skillBudgetPerSkill = 5000,
        public int $skillBudgetCombined = 25000,
        public int $summaryUserMaxChars = 80,
        public int $summaryAssistantMaxChars = 100,
        public int $toolOutputMaxChars = 2000,
        public ?int $reminderTokens = null,
        public bool $reminderTokensSet = false,
        public ?int $backgroundCompactionTokens = null,
        public bool $backgroundCompactionTokensSet = false,
        public ?int $foregroundBlockingTokens = null,
        public bool $foregroundBlockingTokensSet = false,
        public array $modelTokenOverrides = [],
    ) {
        foreach (self::ABSOLUTE_FIELDS as $field) {
            self::assertAbsolute($field, $this->{$field});
        }
        foreach ($modelTokenOverrides as $model => $caps) {
            self::assertOverride((string) $model, $caps);
        }
    }

    /**
     * Factory creating a config with default values.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * The defaults plus DCP's absolute "smart zone" caps: the reminder at
     * {@see SMART_ZONE_REMINDER_TOKENS}, automatic compaction at
     * {@see SMART_ZONE_COMPACTION_TOKENS}, each only where it is below the
     * percentage. The blocking tier keeps no cap: it guards the WINDOW, not
     * quality, and refusing input below the window would refuse prompts the
     * model can still take.
     */
    public static function smartZone(): self
    {
        return self::new()
            ->withReminderTokens(self::SMART_ZONE_REMINDER_TOKENS)
            ->withBackgroundCompactionTokens(self::SMART_ZONE_COMPACTION_TOKENS);
    }

    /**
     * Create a new config with a different reminderThreshold value.
     */
    public function withReminderThreshold(int $reminderThreshold): self
    {
        return $this->mutate(['reminderThreshold' => $reminderThreshold]);
    }

    /**
     * Create a new config with a different backgroundCompactionThreshold value.
     */
    public function withBackgroundCompactionThreshold(int $backgroundCompactionThreshold): self
    {
        return $this->mutate(['backgroundCompactionThreshold' => $backgroundCompactionThreshold]);
    }

    /**
     * Create a new config with a different foregroundBlockingThreshold value.
     */
    public function withForegroundBlockingThreshold(int $foregroundBlockingThreshold): self
    {
        return $this->mutate(['foregroundBlockingThreshold' => $foregroundBlockingThreshold]);
    }

    /**
     * Create a new config with a different recentPreserveCount value.
     */
    public function withRecentPreserveCount(int $recentPreserveCount): self
    {
        return $this->mutate(['recentPreserveCount' => $recentPreserveCount]);
    }

    /**
     * Create a new config with a different skillBudgetPerSkill value.
     */
    public function withSkillBudgetPerSkill(int $skillBudgetPerSkill): self
    {
        return $this->mutate(['skillBudgetPerSkill' => $skillBudgetPerSkill]);
    }

    /**
     * Create a new config with a different skillBudgetCombined value.
     */
    public function withSkillBudgetCombined(int $skillBudgetCombined): self
    {
        return $this->mutate(['skillBudgetCombined' => $skillBudgetCombined]);
    }

    public function withSummaryUserMaxChars(int $summaryUserMaxChars): self
    {
        return $this->mutate(['summaryUserMaxChars' => $summaryUserMaxChars]);
    }

    public function withSummaryAssistantMaxChars(int $summaryAssistantMaxChars): self
    {
        return $this->mutate(['summaryAssistantMaxChars' => $summaryAssistantMaxChars]);
    }

    /**
     * Create a new config with a different toolOutputMaxChars value.
     */
    public function withToolOutputMaxChars(int $toolOutputMaxChars): self
    {
        return $this->mutate(['toolOutputMaxChars' => $toolOutputMaxChars]);
    }

    /**
     * Cap the reminder tier at $tokens estimated tokens; null removes the cap.
     * Either way the cap counts as configured ({@see $reminderTokensSet}).
     *
     * @throws \InvalidArgumentException when $tokens is below 1
     */
    public function withReminderTokens(?int $tokens): self
    {
        return $this->mutate(['reminderTokens' => $tokens, 'reminderTokensSet' => true]);
    }

    /**
     * Cap the automatic-compaction tier; null removes the cap.
     *
     * @throws \InvalidArgumentException when $tokens is below 1
     */
    public function withBackgroundCompactionTokens(?int $tokens): self
    {
        return $this->mutate(['backgroundCompactionTokens' => $tokens, 'backgroundCompactionTokensSet' => true]);
    }

    /**
     * Cap the blocking tier; null removes the cap.
     *
     * @throws \InvalidArgumentException when $tokens is below 1
     */
    public function withForegroundBlockingTokens(?int $tokens): self
    {
        return $this->mutate(['foregroundBlockingTokens' => $tokens, 'foregroundBlockingTokensSet' => true]);
    }

    /**
     * Add (or replace) the absolute caps for one model.
     *
     * $model is matched by {@see forModel()} against `provider/model` first and
     * the bare model id second, so `"sglang/qwen3"` pins one deployment and
     * `"qwen3"` every provider serving it. $caps is a partial map over
     * {@see ABSOLUTE_FIELDS}; a key present with null clears that cap for the
     * model, a key left out inherits the base config's.
     *
     * @param array<string, ?int> $caps
     * @throws \InvalidArgumentException on an empty model, an unknown key, or a cap below 1
     */
    public function withModelTokenOverride(string $model, array $caps): self
    {
        return $this->mutate(['modelTokenOverrides' => [...$this->modelTokenOverrides, $model => $caps]]);
    }

    /**
     * This config with the per-model caps for $model applied, or this same
     * instance when no override names it.
     *
     * The lookup is `"$provider/$model"` then `$model` — the more specific key
     * wins, and only one entry applies (they are not layered). Resolve from the
     * base config each time: the result carries the applied caps as its own.
     */
    public function forModel(?string $model, ?string $provider = null): self
    {
        if ($model === null || $model === '') {
            return $this;
        }

        $caps = null;
        if ($provider !== null && $provider !== '') {
            $caps = $this->modelTokenOverrides[$provider . '/' . $model] ?? null;
        }
        $caps ??= $this->modelTokenOverrides[$model] ?? null;
        if ($caps === null) {
            return $this;
        }

        $changes = [];
        foreach ($caps as $field => $tokens) {
            $changes[$field] = $tokens;
            $changes[$field . 'Set'] = true;
        }

        return $this->mutate($changes);
    }

    /**
     * The token count at which the reminder tier fires on a $tokenLimit-token
     * window: the percentage, or the absolute cap when that is lower.
     */
    public function reminderTokenThreshold(int $tokenLimit): int
    {
        return self::tierThreshold($tokenLimit, $this->reminderThreshold, $this->reminderTokens);
    }

    /** The automatic-compaction tier's token count; see {@see reminderTokenThreshold()}. */
    public function backgroundCompactionTokenThreshold(int $tokenLimit): int
    {
        return self::tierThreshold($tokenLimit, $this->backgroundCompactionThreshold, $this->backgroundCompactionTokens);
    }

    /** The blocking tier's token count; see {@see reminderTokenThreshold()}. */
    public function foregroundBlockingTokenThreshold(int $tokenLimit): int
    {
        return self::tierThreshold($tokenLimit, $this->foregroundBlockingThreshold, $this->foregroundBlockingTokens);
    }

    /**
     * `min(percent% of the window, cap)`. The percentage half is the exact
     * expression every tier used before 2.9 — float multiply, then truncate —
     * so an uncapped config lands on the same token as it always did.
     */
    private static function tierThreshold(int $tokenLimit, int $percent, ?int $cap): int
    {
        $byPercent = (int) ($tokenLimit * $percent / 100);

        return $cap === null ? $byPercent : min($byPercent, $cap);
    }

    /**
     * @param array<string, mixed> $changes promoted-property name => new value
     */
    private function mutate(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    private static function assertAbsolute(string $field, ?int $tokens): void
    {
        if ($tokens !== null && $tokens < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be at least 1 token, or null for no cap; got %d.', $field, $tokens));
        }
    }

    private static function assertOverride(string $model, mixed $caps): void
    {
        if ($model === '') {
            throw new \InvalidArgumentException('A per-model token override needs a model id.');
        }
        if (!is_array($caps)) {
            throw new \InvalidArgumentException(sprintf('The token override for "%s" must be a map of caps.', $model));
        }
        foreach ($caps as $field => $tokens) {
            if (!in_array($field, self::ABSOLUTE_FIELDS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown token override "%s" for "%s"; expected one of %s.',
                    $field,
                    $model,
                    implode(', ', self::ABSOLUTE_FIELDS),
                ));
            }
            if ($tokens !== null && !is_int($tokens)) {
                throw new \InvalidArgumentException(sprintf('%s for "%s" must be an integer or null.', $field, $model));
            }
            self::assertAbsolute($field, $tokens);
        }
    }
}
