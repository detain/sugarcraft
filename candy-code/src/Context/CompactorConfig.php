<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use SugarCraft\Code\Context\Pruning\NudgePolicy;
use SugarCraft\Code\Tools\BuiltIn\Compress;

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
 * DEFAULTS (roadmap N-P4b, a user decision): the reminder tier is capped at
 * {@see DEFAULT_REMINDER_TOKENS} (100k) and the automatic-compaction tier at
 * {@see DEFAULT_COMPACTION_TOKENS} (150k); the blocking tier keeps NO cap, so
 * the only tier that refuses a prompt is still a percentage of the window.
 * Quality degrades well before 70% of a 1M window, which is the case the caps
 * exist for. A window up to ~142k tokens is untouched — its percentage tiers
 * (70% of 142,857 = 100k, 85% of 176,470 = 150k) come first.
 *
 * AN ABSOLUTE TIER NEVER REFUSES. The thrash breaker counts only compactions
 * that left the turn UNSENT, which only the blocking tier (or the spend cap)
 * does; and {@see ContextCompactor} skips an absolute tier outright when a
 * compaction could not bring the history under it (a preserved tail heavier
 * than the cap), so a session over the cap for good is neither re-compacted
 * on every prompt nor counted as thrashing. {@see smartZone()} keeps DCP's
 * lower figures (50k/100k) as an opt-in. `0` in the settings turns a cap off.
 *
 * The settings keys (`compaction.*`, Appendix N §2.2) are read by
 * {@see fromSettings()}, which `Bootstrap::chat()` hands the session,
 * `Chat::applySettings()` rebuilds it from on a save (so they apply live), and
 * `EngineBackend::compactorConfig()` falls back to.
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

    /** The reminder tier's default absolute cap (N-P4b): min(70%, 100k). */
    public const DEFAULT_REMINDER_TOKENS = 100_000;

    /** The automatic-compaction tier's default absolute cap (N-P4b): min(85%, 150k). */
    public const DEFAULT_COMPACTION_TOKENS = 150_000;

    /**
     * The base trio of absolute caps, keyed by the {@see ABSOLUTE_FIELDS}
     * property names in tier order — the ladder the tiers were designed on.
     * A configured (or model-overridden) cap trio that breaks ascending
     * order falls back to this whole trio, mirroring the percentage fallback
     * and the nudge min/max door in {@see fromSettings()} (ISS-085).
     *
     * @var array<string, ?int>
     */
    private const DEFAULT_TOKEN_CAPS = [
        'reminderTokens' => self::DEFAULT_REMINDER_TOKENS,
        'backgroundCompactionTokens' => self::DEFAULT_COMPACTION_TOKENS,
        'foregroundBlockingTokens' => null,
    ];

    /** `compaction.*` settings keys read by {@see fromSettings()} (Appendix N §2.2). */
    public const SETTING_REMINDER_PERCENT = 'compaction.reminderPercent';
    public const SETTING_AUTO_PERCENT = 'compaction.autoPercent';
    public const SETTING_BLOCK_PERCENT = 'compaction.blockPercent';
    public const SETTING_KEEP_RECENT = 'compaction.keepRecent';
    public const SETTING_SUMMARY_USER_CHARS = 'compaction.summaryUserChars';
    public const SETTING_SUMMARY_ASSISTANT_CHARS = 'compaction.summaryAssistantChars';
    public const SETTING_TOOL_OUTPUT_CHARS = 'compaction.toolOutputChars';
    public const SETTING_REMINDER_TOKENS = 'compaction.reminderTokens';
    public const SETTING_AUTO_TOKENS = 'compaction.autoTokens';
    public const SETTING_BLOCK_TOKENS = 'compaction.blockTokens';
    public const SETTING_MODEL_TOKEN_CAPS = 'compaction.modelTokenCaps';

    /**
     * The model-context-management keys (DCP `contextPruning.*`), carried here
     * so one launch-built config answers every compaction question.
     */
    public const SETTING_NUDGE_MIN_TOKENS = 'contextPruning.minContextTokens';
    public const SETTING_NUDGE_MAX_TOKENS = 'contextPruning.maxContextTokens';
    public const SETTING_NUDGE_FREQUENCY = 'contextPruning.nudgeFrequency';
    public const SETTING_NUDGE_ITERATIONS = 'contextPruning.iterationNudgeThreshold';
    public const SETTING_COMPRESS = 'contextPruning.compress';

    /** `contextPruning.compress` values: `manual` = only on a `/compress` turn; `auto` = every `auto`-mode turn. */
    public const COMPRESS_MODES = ['manual', 'auto'];

    /**
     * The idle offer, the summary mode and the thrash breaker (roadmap N-P4b
     * remainder) — the three compaction numbers that lived as constants on
     * {@see IdleCompactionPolicy} and as the implicit "is there a summary
     * backend" test.
     */
    public const SETTING_IDLE_OFFER_SECONDS = 'compaction.idleOfferSeconds';
    public const SETTING_MODE = 'compaction.mode';
    public const SETTING_REFILL_LIMIT = 'compaction.refillLimit';

    /**
     * `compaction.idleAuto` (CTX-5): whether the idle window, rather than only
     * producing the human offer, also triggers the automatic fold on its own.
     * Default false — it spends a provider call on a session nobody is watching.
     */
    public const SETTING_IDLE_AUTO = 'compaction.idleAuto';

    /** `compaction.idleAuto`: the idle window only ever offers; nothing folds unattended. */
    public const IDLE_AUTO_OFF = 'off';

    /** `compaction.idleAuto`: fold once the full {@see IdleCompactionPolicy::IDLE_SECONDS}-shaped offer window has passed. */
    public const IDLE_AUTO_HOUR = 'hour';

    /**
     * `compaction.idleAuto` (C-CTX-5.c): fold a session idle just short of its
     * provider's prompt-cache lifetime, so the next prompt does not prefill the
     * whole conversation uncached — {@see IdleCompactionPolicy::shouldAutoCompactAtCacheTtl()}.
     */
    public const IDLE_AUTO_CACHE_TTL = 'cacheTtl';

    /** Every `compaction.idleAuto` mode, the default first. */
    public const IDLE_AUTO_MODES = [self::IDLE_AUTO_OFF, self::IDLE_AUTO_HOUR, self::IDLE_AUTO_CACHE_TTL];

    /** `compaction.mode`: the summary model writes the summaries (the heuristic is its fallback). */
    public const MODE_LLM = 'llm';

    /** `compaction.mode`: the local one-line heuristic only — no summarisation call is ever made. */
    public const MODE_HEURISTIC = 'heuristic';

    /**
     * `compaction.mode`: nothing compacts on its own — no automatic tier and
     * no ahead-of-need summary. `/compact` still works, on the summary model,
     * and the blocking tier still refuses a prompt the window cannot take.
     */
    public const MODE_OFF = 'off';

    /** Every `compaction.mode` value, the default first. */
    public const MODES = [self::MODE_LLM, self::MODE_HEURISTIC, self::MODE_OFF];

    /**
     * Every settings key {@see fromSettings()} reads — the keys a save must
     * rebuild a session's config for (`Chat::applySettings()`).
     */
    public const SETTINGS = [
        self::SETTING_REMINDER_PERCENT,
        self::SETTING_AUTO_PERCENT,
        self::SETTING_BLOCK_PERCENT,
        self::SETTING_KEEP_RECENT,
        self::SETTING_SUMMARY_USER_CHARS,
        self::SETTING_SUMMARY_ASSISTANT_CHARS,
        self::SETTING_TOOL_OUTPUT_CHARS,
        self::SETTING_REMINDER_TOKENS,
        self::SETTING_AUTO_TOKENS,
        self::SETTING_BLOCK_TOKENS,
        self::SETTING_MODEL_TOKEN_CAPS,
        self::SETTING_IDLE_OFFER_SECONDS,
        self::SETTING_IDLE_AUTO,
        self::SETTING_MODE,
        self::SETTING_REFILL_LIMIT,
        self::SETTING_NUDGE_MIN_TOKENS,
        self::SETTING_NUDGE_MAX_TOKENS,
        self::SETTING_NUDGE_FREQUENCY,
        self::SETTING_NUDGE_ITERATIONS,
        self::SETTING_COMPRESS,
    ];

    /**
     * The short names a `compaction.modelTokenCaps` entry uses, mapped to the
     * {@see ABSOLUTE_FIELDS} they set — the same suffixes as the three
     * top-level cap keys.
     */
    public const MODEL_CAP_KEYS = [
        'reminderTokens' => 'reminderTokens',
        'autoTokens' => 'backgroundCompactionTokens',
        'blockTokens' => 'foregroundBlockingTokens',
    ];

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
     *                                      this)`. Default {@see DEFAULT_REMINDER_TOKENS}
     *                                      (N-P4b); null is no cap — the percentage
     *                                      alone decides, as before 2.9.
     * @param bool $reminderTokensSet      Whether $reminderTokens was configured,
     *                                      null included. The sentinel is what lets a
     *                                      per-model override CLEAR a base cap: an
     *                                      override naming the key with null sets it,
     *                                      one that leaves the key out inherits.
     * @param ?int $backgroundCompactionTokens Absolute cap on the automatic
     *                                      compaction tier, same rule. Default
     *                                      {@see DEFAULT_COMPACTION_TOKENS}; null: no cap.
     * @param bool $backgroundCompactionTokensSet Sentinel for the above.
     * @param ?int $foregroundBlockingTokens Absolute cap on the blocking tier,
     *                                      same rule. Null (the default): no cap —
     *                                      the one tier that refuses stays a percentage.
     * @param bool $foregroundBlockingTokensSet Sentinel for the above.
     * @param array<string, array<string, ?int>> $modelTokenOverrides Per-model
     *                                      absolute caps, keyed by model id or by
     *                                      `provider/model` (DCP's `modelMinLimits` /
     *                                      `modelMaxLimits`), each a partial map over
     *                                      {@see ABSOLUTE_FIELDS}. Applied by
     *                                      {@see forModel()}; inert until then.
     * @param int $nudgeMinContextTokens   Below this the model is not reminded to
     *                                      manage its context (DCP `minContextTokens`);
     *                                      {@see nudgePolicy()}.
     * @param int $nudgeMaxContextTokens   Above this every reminder is the strong one.
     * @param int $nudgeFrequency          Rows between two reminders.
     * @param int $nudgeIterationThreshold Tool results since the last prompt that make
     *                                      an iteration reminder due.
     * @param string $compressMode         `manual` (the default,
     *                                      {@see \SugarCraft\Code\Tools\BuiltIn\Compress::MODE_DEFAULT}):
     *                                      the model's `Compress` only on a `/compress`
     *                                      turn; `auto`: on every turn `Prune` is offered.
     * @param int $idleOfferSeconds        How long a session past its whole window must
     *                                      sit untouched before `/compact` is offered
     *                                      instead of the prompt
     *                                      ({@see IdleCompactionPolicy::shouldPrompt()});
     *                                      `0` never offers. Default
     *                                      {@see IdleCompactionPolicy::IDLE_SECONDS}.
     * @param string $mode                 Who writes compaction summaries, and whether
     *                                      anything compacts on its own: one of
     *                                      {@see MODES} ({@see autoCompacts()},
     *                                      {@see summarisesWithModel()}).
     * @param int $refillLimit             Automatic compactions in a row that may come
     *                                      straight back over their tier, turn unsent,
     *                                      before the thrash breaker refuses
     *                                      ({@see IdleCompactionPolicy::thrashTripped()}).
     *                                      Default {@see IdleCompactionPolicy::REFILL_LIMIT}.
     * @param bool $idleAuto               Whether the idle window also triggers the
     *                                      automatic fold on its own (CTX-5,
     *                                      {@see IdleCompactionPolicy::shouldAutoCompact()})
     *                                      rather than only offering `/compact` to a
     *                                      human. Default false: the fold is a billed
     *                                      provider call on a session nobody is watching.
     * @param string $idleAutoMode          which clock the idle fold reads — one of
     *                                      {@see IDLE_AUTO_MODES}; the two faces of
     *                                      `$idleAuto` are settled into a consistent
     *                                      pair by the constructor body below.
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
        public ?int $reminderTokens = self::DEFAULT_REMINDER_TOKENS,
        public bool $reminderTokensSet = false,
        public ?int $backgroundCompactionTokens = self::DEFAULT_COMPACTION_TOKENS,
        public bool $backgroundCompactionTokensSet = false,
        public ?int $foregroundBlockingTokens = null,
        public bool $foregroundBlockingTokensSet = false,
        public array $modelTokenOverrides = [],
        public int $nudgeMinContextTokens = NudgePolicy::MIN_CONTEXT_TOKENS,
        public int $nudgeMaxContextTokens = NudgePolicy::MAX_CONTEXT_TOKENS,
        public int $nudgeFrequency = NudgePolicy::NUDGE_FREQUENCY,
        public int $nudgeIterationThreshold = NudgePolicy::ITERATION_THRESHOLD,
        public string $compressMode = Compress::MODE_DEFAULT,
        public int $idleOfferSeconds = IdleCompactionPolicy::IDLE_SECONDS,
        public string $mode = self::MODE_LLM,
        public int $refillLimit = IdleCompactionPolicy::REFILL_LIMIT,
        public bool $idleAuto = false,
        public string $idleAutoMode = self::IDLE_AUTO_OFF,
    ) {
        foreach (self::ABSOLUTE_FIELDS as $field) {
            self::assertAbsolute($field, $this->{$field});
        }
        foreach ($modelTokenOverrides as $model => $caps) {
            self::assertOverride((string) $model, $caps);
        }
        if (!\in_array($compressMode, self::COMPRESS_MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'compressMode must be one of %s; got "%s".',
                implode(', ', self::COMPRESS_MODES),
                $compressMode,
            ));
        }
        if (!\in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'mode must be one of %s; got "%s".',
                implode(', ', self::MODES),
                $mode,
            ));
        }
        if ($idleOfferSeconds < 0) {
            throw new \InvalidArgumentException(sprintf('idleOfferSeconds must be 0 (never) or more; got %d.', $idleOfferSeconds));
        }
        if ($refillLimit < 1) {
            throw new \InvalidArgumentException(sprintf('refillLimit must be at least 1; got %d.', $refillLimit));
        }
        if (!\in_array($idleAutoMode, self::IDLE_AUTO_MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'idleAutoMode must be one of %s; got "%s".',
                implode(', ', self::IDLE_AUTO_MODES),
                $idleAutoMode,
            ));
        }
        // The bool and the mode are one setting seen from two sides, and the
        // pair may never disagree: `$idleAuto` is what the watch gates already
        // read (Chat's own `shouldWatchIdleCompaction()`, unchanged by
        // C-CTX-5.c); the mode is what decides WHICH clock folds. Every
        // writer sets both faces, so what this guard catches is a hand-built
        // half-pair — and the readonly properties make the constructor the
        // last place a rewrite could have happened, so a loud throw is the
        // honest answer, mirroring how an invalid mode itself is met.
        if ($this->idleAuto !== ($this->idleAutoMode !== self::IDLE_AUTO_OFF)) {
            throw new \InvalidArgumentException(sprintf(
                'idleAuto and idleAutoMode must agree: mode "%s" is %s; got idleAuto %s.',
                $this->idleAutoMode,
                $this->idleAutoMode === self::IDLE_AUTO_OFF ? 'off' : 'on',
                $this->idleAuto ? 'true' : 'false',
            ));
        }
    }

    /**
     * The config the `compaction.*` and `contextPruning.*` settings describe,
     * over {@see new()}'s defaults (roadmap N-P4b, Appendix N §2.2).
     *
     * NEVER THROWS, like every per-turn settings reader: a value of the wrong
     * shape or out of range is ignored and that field keeps its default. The
     * three percentages are taken TOGETHER — if what they would resolve to is
     * not strictly `reminder < auto < block`, all three keep their defaults
     * (the schema's `ThresholdOrderValidator` refuses such a save; this guards
     * a hand edit). A cap of `0` turns that cap OFF; an absent key keeps the
     * default. `compaction.modelTokenCaps` is `{model: {reminderTokens,
     * autoTokens, blockTokens}}` (a model id or `provider/model`; `0` or null
     * clears that cap for the model); a malformed entry is skipped.
     * `compaction.idleOfferSeconds` (`0` never offers), `compaction.mode` (one
     * of {@see MODES}, case-insensitive) and `compaction.refillLimit` (at
     * least 1) follow the same rule. `compaction.idleAuto` (CTX-5) takes a real
     * boolean or one of {@see IDLE_AUTO_MODES}, case-insensitive: the bool keeps
     * reading exactly as it did (C-CTX-5.c) — `true` means `hour`, `false`
     * means `off` — while `"cacheTtl"` folds to the provider's prompt-cache
     * lifetime instead. Anything else is ignored, leaving the off default,
     * because this key buys a background provider call and an ambiguous edit
     * must not buy it by accident.
     *
     * `fromSettings([])` equals {@see new()}.
     *
     * @param array<string, mixed> $config the merged settings (`Bootstrap::readUserConfig()`)
     */
    public static function fromSettings(array $config): self
    {
        $base = self::new();
        $changes = [];

        $percents = [
            'reminderThreshold' => self::intSetting($config, self::SETTING_REMINDER_PERCENT, 1, 99) ?? $base->reminderThreshold,
            'backgroundCompactionThreshold' => self::intSetting($config, self::SETTING_AUTO_PERCENT, 1, 99) ?? $base->backgroundCompactionThreshold,
            'foregroundBlockingThreshold' => self::intSetting($config, self::SETTING_BLOCK_PERCENT, 1, 99) ?? $base->foregroundBlockingThreshold,
        ];
        if ($percents['reminderThreshold'] < $percents['backgroundCompactionThreshold']
            && $percents['backgroundCompactionThreshold'] < $percents['foregroundBlockingThreshold']
        ) {
            $changes = $percents;
        }

        foreach ([
            'recentPreserveCount' => self::SETTING_KEEP_RECENT,
            'summaryUserMaxChars' => self::SETTING_SUMMARY_USER_CHARS,
            'summaryAssistantMaxChars' => self::SETTING_SUMMARY_ASSISTANT_CHARS,
            'toolOutputMaxChars' => self::SETTING_TOOL_OUTPUT_CHARS,
            'nudgeFrequency' => self::SETTING_NUDGE_FREQUENCY,
            'nudgeIterationThreshold' => self::SETTING_NUDGE_ITERATIONS,
            'refillLimit' => self::SETTING_REFILL_LIMIT,
        ] as $field => $key) {
            $value = self::intSetting($config, $key, 1);
            if ($value !== null) {
                $changes[$field] = $value;
            }
        }

        // The three absolute caps form ONE ladder, not three independent figures
        // (ISS-085): every tier fires at min(percent, cap), so as long as the
        // caps ascend — with "no cap" (null) confined to the far end — the
        // effective thresholds stay ordered. The early-null leg closed with
        // the follow-up: a null at an EARLY leg used to skip its pairwise
        // check, so reminder=0 + auto=50000 validated and the uncapped
        // reminder percentage could exceed the capped auto threshold on a
        // large-window model; that ladder now falls back whole like any
        // descending trio. The remaining disclosed follow-up is save-time:
        // extending ThresholdOrderValidator with the percentage-pair leg (it
        // checks the percent keys only against each other, never against the
        // caps). A descending trio (a blockTokens under the default
        // autoTokens refuses every prompt auto-compaction would have kept; an
        // autoTokens under the reminderTokens kills summary prefetch) falls
        // back whole, exactly as the percentage trio does. A field left out or
        // malformed keeps its current figure and its not-configured sentinel.
        $caps = [];
        foreach ([
            'reminderTokens' => self::SETTING_REMINDER_TOKENS,
            'backgroundCompactionTokens' => self::SETTING_AUTO_TOKENS,
            'foregroundBlockingTokens' => self::SETTING_BLOCK_TOKENS,
        ] as $field => $key) {
            $value = self::intSetting($config, $key, 0);
            if ($value !== null) {
                $caps[$field] = $value === 0 ? null : $value;
            }
        }
        if ($caps !== []) {
            $ladder = [];
            foreach (self::ABSOLUTE_FIELDS as $field) {
                // array_key_exists, not ?? — an explicit null means "cap off",
                // which must not be read as "field absent" and defaulted back.
                $ladder[$field] = \array_key_exists($field, $caps) ? $caps[$field] : $base->{$field};
            }
            if (!self::orderedCaps($ladder)) {
                $caps = self::DEFAULT_TOKEN_CAPS;
            }
            foreach ($caps as $field => $tokens) {
                $changes[$field] = $tokens;
                $changes[$field . 'Set'] = true;
            }
        }

        $min = self::intSetting($config, self::SETTING_NUDGE_MIN_TOKENS, 0) ?? $base->nudgeMinContextTokens;
        $max = self::intSetting($config, self::SETTING_NUDGE_MAX_TOKENS, 0) ?? $base->nudgeMaxContextTokens;
        if ($min <= $max) {
            $changes['nudgeMinContextTokens'] = $min;
            $changes['nudgeMaxContextTokens'] = $max;
        }

        $idle = self::intSetting($config, self::SETTING_IDLE_OFFER_SECONDS, 0);
        if ($idle !== null) {
            $changes['idleOfferSeconds'] = $idle;
        }

        $mode = $config[self::SETTING_MODE] ?? null;
        if (\is_string($mode) && \in_array(strtolower(trim($mode)), self::MODES, true)) {
            $changes['mode'] = strtolower(trim($mode));
        }

        // `compaction.idleAuto` (CTX-5, modes C-CTX-5.c): a mode word wins,
        // case-insensitively like `compaction.mode`; a real bool keeps reading
        // as it always did (`true` = hour, `false` = off) so settings saved
        // before this step behave unchanged; anything else is ignored — this
        // key buys a background provider call, and an ambiguous edit must not
        // buy it by accident.
        $idleAuto = $config[self::SETTING_IDLE_AUTO] ?? null;
        $idleAutoMode = self::idleAutoModeSetting($idleAuto);
        if ($idleAutoMode !== null) {
            $changes['idleAutoMode'] = $idleAutoMode;
            $changes['idleAuto'] = $idleAutoMode !== self::IDLE_AUTO_OFF;
        }

        $compress = $config[self::SETTING_COMPRESS] ?? null;
        if (\is_string($compress) && \in_array(strtolower(trim($compress)), self::COMPRESS_MODES, true)) {
            $changes['compressMode'] = strtolower(trim($compress));
        }

        $overrides = self::modelCapsSetting($config[self::SETTING_MODEL_TOKEN_CAPS] ?? null);
        if ($overrides !== []) {
            $changes['modelTokenOverrides'] = $overrides;
        }

        return $changes === [] ? $base : $base->mutate($changes);
    }

    /**
     * The model's context reminders (roadmap 3.B-4) with this config's
     * thresholds — {@see NudgePolicy::new()} when none was configured.
     */
    public function nudgePolicy(): NudgePolicy
    {
        return NudgePolicy::new()
            ->withContextTokens($this->nudgeMinContextTokens, $this->nudgeMaxContextTokens)
            ->withNudgeFrequency($this->nudgeFrequency)
            ->withIterationThreshold($this->nudgeIterationThreshold);
    }

    /**
     * Whether the model's `Compress` is offered on a turn the person did NOT
     * start with `/compress` (`contextPruning.compress: auto`). Only ever
     * where `Prune` is offered too — the pruning mode's `auto`.
     */
    public function offersCompressUnprompted(): bool
    {
        return $this->compressMode === 'auto';
    }

    /**
     * Whether anything compacts without being asked: the automatic tier and
     * the ahead-of-need summary. False only for `compaction.mode: off`; the
     * blocking tier and `/compact` do not depend on it.
     */
    public function autoCompacts(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }

    /**
     * Whether a compaction asks the summary model for its summaries rather
     * than writing the heuristic lines. `heuristic` never asks; `off` asks
     * only for a compaction the person started (`/compact`), since nothing
     * else compacts; `llm` always asks.
     *
     * @param bool $automatic whether the compaction is the session's own (a
     *        tier or the ahead-of-need summary) rather than `/compact`
     */
    public function summarisesWithModel(bool $automatic): bool
    {
        return match ($this->mode) {
            self::MODE_HEURISTIC => false,
            self::MODE_OFF => !$automatic,
            default => true,
        };
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

    /** Offer `/compact` after $seconds idle past the window; `0` never offers. */
    public function withIdleOfferSeconds(int $seconds): self
    {
        return $this->mutate(['idleOfferSeconds' => $seconds]);
    }

    /**
     * One of {@see MODES}.
     *
     * @throws \InvalidArgumentException on any other value
     */
    public function withMode(string $mode): self
    {
        return $this->mutate(['mode' => $mode]);
    }

    /** Let the thrash breaker allow $limit refilling compactions in a row. */
    public function withRefillLimit(int $limit): self
    {
        return $this->mutate(['refillLimit' => $limit]);
    }

    /**
     * Let the idle window fold the automatic tier on its own (CTX-5) rather
     * than only offer. The mode is the same setting seen from this side:
     * `false` turns it fully off, and `true` keeps a mode already chosen (or
     * means the hour one).
     */
    public function withIdleAuto(bool $idleAuto = true): self
    {
        if (!$idleAuto) {
            return $this->mutate(['idleAuto' => false, 'idleAutoMode' => self::IDLE_AUTO_OFF]);
        }

        return $this->mutate([
            'idleAuto' => true,
            'idleAutoMode' => $this->idleAutoMode === self::IDLE_AUTO_OFF ? self::IDLE_AUTO_HOUR : $this->idleAutoMode,
        ]);
    }

    /**
     * Choose which clock the idle fold reads — one of {@see IDLE_AUTO_MODES}.
     *
     * @throws \InvalidArgumentException on any other value
     */
    public function withIdleAutoMode(string $mode): self
    {
        return $this->mutate(['idleAutoMode' => $mode, 'idleAuto' => $mode !== self::IDLE_AUTO_OFF]);
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
     * model — as the far end only: an entry that clears an early cap while a
     * later one stays finite breaks the ladder and {@see forModel()} refuses
     * it whole (ISS-085 early-null leg) — and a key left out inherits the
     * base config's.
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
     * instance when no override names it — or when the override would take
     * the cap ladder out of ascending order or sit a "no cap" before a
     * finite one (ISS-085), which reads exactly like an entry that names no
     * caps at all.
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

        // Validate against the figures this override would produce, naming only
        // the fields it touched: the entry rides on top of the base (or an
        // already-resolved) ladder, and an override that would push one step
        // below its neighbour — or open an early "no cap" in front of a
        // finite one, ISS-085's early-null leg — is dropped whole rather
        // than half-applied.
        $ladder = [];
        foreach (self::ABSOLUTE_FIELDS as $field) {
            $ladder[$field] = \array_key_exists($field, $caps) ? $caps[$field] : $this->{$field};
        }
        if (!self::orderedCaps($ladder)) {
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
     * Whether an absolute-cap ladder ascends. null means "no cap" — an ∞ that
     * may only sit at the FAR end of the ladder, never before a finite figure;
     * the trio must arrive in {@see ABSOLUTE_FIELDS} order (tier order).
     *
     * Each tier fires at min(percent, cap). With the percentages ordered (the
     * fallback above), the finite caps ascending, and nulls confined to a
     * suffix, every effective threshold is ordered on every path: a capped
     * tier fires no later than its finite cap, the next tier no earlier than
     * the percentage that ordered them. The suffix rule is ISS-085's
     * early-null leg, closed by the follow-up: the pairwise form this door
     * first shipped let an early null skip its pair, so the measured
     * reminder=0 + auto=50000 ladder validated and the uncapped reminder
     * percentage could exceed the capped auto threshold on a large-window
     * model. What remains disclosed is the SAVE-time leg — extending
     * ThresholdOrderValidator to check the percent keys against the caps,
     * not only against each other.
     *
     * @param array<string, ?int> $ladder
     */
    private static function orderedCaps(array $ladder): bool
    {
        [$reminder, $auto, $block] = \array_values($ladder);

        // An ∞ may not precede a finite figure: it would ride its percentage
        // while a later tier rides a smaller cap, and the ladders cross.
        if ($reminder === null) {
            return $auto === null && $block === null;
        }

        if ($auto === null) {
            return $block === null;
        }

        return $reminder <= $auto && ($block === null || $auto <= $block);
    }

    /**
     * @param array<string, mixed> $changes promoted-property name => new value
     */
    private function mutate(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * $config[$key] as an int in [$min, $max], or null when absent or not one
     * (an integral float such as `85.0` counts; a string does not).
     *
     * @param array<string, mixed> $config
     */
    private static function intSetting(array $config, string $key, int $min, ?int $max = null): ?int
    {
        $value = $config[$key] ?? null;
        if (\is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < \PHP_INT_MAX) {
            $value = (int) $value;
        }
        if (!\is_int($value) || $value < $min || ($max !== null && $value > $max)) {
            return null;
        }

        return $value;
    }

    /**
     * A `compaction.idleAuto` value as one of {@see IDLE_AUTO_MODES}, or null
     * to keep the default. Strings match the mode words case-insensitively and
     * keep their canonical spelling (`cacheTtl` is what goes on the wire, and
     * `'CacheTTL'` round-trips to it); the legacy real bool maps to hour/off
     * exactly as it did before C-CTX-5.c. `"true"`-strings and ints are still
     * not coerced — see {@see fromSettings()} for why this key in particular
     * refuses to guess.
     */
    private static function idleAutoModeSetting(mixed $value): ?string
    {
        if (\is_bool($value)) {
            return $value ? self::IDLE_AUTO_HOUR : self::IDLE_AUTO_OFF;
        }

        if (!\is_string($value)) {
            return null;
        }

        $index = array_search(
            strtolower(trim($value)),
            array_map('strtolower', self::IDLE_AUTO_MODES),
            true,
        );

        return $index === false ? null : self::IDLE_AUTO_MODES[$index];
    }

    /**
     * `compaction.modelTokenCaps` as {@see $modelTokenOverrides}: entries that
     * are not a map of known short names to `int >= 0 | null` are dropped
     * whole, so one typo cannot half-apply.
     *
     * @return array<string, array<string, ?int>>
     */
    private static function modelCapsSetting(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $overrides = [];
        foreach ($value as $model => $caps) {
            if (!\is_string($model) || trim($model) === '' || !\is_array($caps) || $caps === []) {
                continue;
            }
            $entry = [];
            foreach ($caps as $name => $tokens) {
                $field = \is_string($name) ? (self::MODEL_CAP_KEYS[$name] ?? null) : null;
                if (\is_float($tokens) && is_finite($tokens) && floor($tokens) === $tokens) {
                    $tokens = (int) $tokens;
                }
                if ($field === null || ($tokens !== null && (!\is_int($tokens) || $tokens < 0))) {
                    continue 2;
                }
                $entry[$field] = $tokens === 0 ? null : $tokens;
            }
            $overrides[$model] = $entry;
        }

        return $overrides;
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
