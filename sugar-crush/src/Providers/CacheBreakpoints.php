<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Usage;

/**
 * Anthropic prompt-cache breakpoints, applied wipe-then-reapply on every step.
 *
 * DESIGN SOURCE: prompt_plan.md P10.S2, executed from prompt_expand.md §4.15
 * (Anthropic's bundled caching spec), §5.8 (charmbracelet/crush's
 * `getCacheControlOptions` / `PrepareStep` discipline), §6.5 (sst/opencode's
 * `applyCaching` refinements) and §9.5 (the SugarCraft sketch). This is a
 * SugarCraft architecture type, not a port — there is no single upstream PHP
 * symbol to mirror, so the repo's "Mirrors charmbracelet/<repo>.<Method>"
 * convention does not apply; the honest lineage is crush's `PrepareStep`
 * closure in Go (§5.8) restated against the Anthropic block shapes this tree
 * already builds in {@see VertexProvider::anthropicBlocks()} and
 * {@see BedrockProvider::systemBlocks()}.
 *
 * WHY WIPE-THEN-REAPPLY. Anthropic accepts at most self::MAX_BREAKPOINTS
 * `cache_control` breakpoints per request. Upstream measured (crush §5.8: "the
 * wipe is the load-bearing part") that without clearing first, each step of a
 * multi-step agentic turn ADDS its marks on top of the previous step's, the
 * request crosses the cap, and the API 400s. So `apply()` is not "add
 * breakpoints"; it is "re-derive the whole breakpoint set from scratch every
 * step", and it is called on every step.
 *
 * THE DEFAULT MARK PLAN (crush §5.8): last tool + last system + last two
 * messages — exactly four. Render order is `tools` → `system` → `messages`
 * (§4.15), so each mark rides the deepest block of its region:
 *   - the last block of the last `role: system` turn — §4.15: "a breakpoint on
 *     the last system block caches both tools and system together";
 *   - the last blocks of the final two messages, with the lookback repair below
 *     substituting intermediate positions when a message boundary would sit too
 *     far back;
 *   - the last tool definition, kept only while it does not crowd out a
 *     lookback-critical message mark (see REPAIR PRIORITY).
 *
 * 20-BLOCK LOOKBACK REPAIR. Each breakpoint walks backward at most
 * self::LOOKBACK_LIMIT content blocks for a prior cache entry (§4.15
 * "20-block lookback window"). A single turn that adds more than that —
 * routine in agentic loops with many tool_use/tool_result blocks — silently
 * misses. The fix §4.15 prescribes is "place an intermediate breakpoint every
 * ~15 blocks in long turns", so the tail marks are derived as a backward
 * chain: anchor on the final content block, then step to the previous
 * message's last block whenever it lies within self::INTERMEDIATE_SPACING
 * blocks, else drop an intermediate mark exactly that far back (mid-message).
 * The chain's head (the final block) always outranks deeper entries.
 *
 * REPAIR PRIORITY. When the chain needs more slots than the budget leaves
 * after the system mark, the LAST-TOOL mark gives way, not a message mark:
 * §4.15 makes the last-system breakpoint cache tools+system together, so
 * losing the tool mark costs a redundant pin, while losing a tail mark costs a
 * silent miss inside the conversation prefix. A tool keeps its own mark
 * whenever the tail chain fits inside the slots left over.
 *
 * AUTOMATIC CACHING BUDGET. Providers/gateways may inject their own cache
 * breakpoint alongside ours (opencode §6.5 skips marking exactly for this
 * case). Such a mark is any `cache_control` whose `type` is NOT 'ephemeral' —
 * e.g. the Bedrock `{"type": "default"}` cachePoint dialect. Those marks are
 * never wiped (they are not this class's to name; silently deleting a
 * mechanism it does not own is §16.4's swallowed-error shape) and never
 * overwritten — a block already carrying one stays unmarked while its region's
 * mark slides to the nearest preceding free block. They DO consume slots from
 * the same per-request cap, so the explicit budget is self::MAX_BREAKPOINTS
 * minus the number observed: self::BUDGET_WITH_AUTOMATIC with exactly one.
 * When the preserved foreign marks ALONE exceed self::MAX_BREAKPOINTS the
 * producer has handed us a request that 400s no matter what we add — a
 * contract violation at the seam, so `apply()` throws naming the count instead
 * of shipping the breach to the wire (§1.10 fail-fast, the same posture as the
 * shape throws). This makes "total breakpoints <= self::MAX_BREAKPOINTS" an
 * invariant of every successful return. One asymmetry ships knowingly
 * (LOW-38, deferred by adjudication): a non-array `cache_control` value rides
 * uncounted at block and tool level but is dropped at message level — the
 * wiring step inherits this knowingly.
 *
 * DETERMINISM. No clock, no randomness, no filesystem, no environment: same
 * inputs produce byte-identical output, which is what makes the prefix
 * cacheable at all (§4.15's invariant — any change anywhere in the prefix
 * invalidates everything after it). Input arrays are never mutated; fresh
 * copies are returned. apply(apply(x)) === apply(x) holds because the wipe
 * re-derives from scratch — pinned by test, not merely relied on. The scope
 * of "no environment" is apply() itself: this class's only `getenv` is the
 * static {@see CacheBreakpoints::disabledFromEnvironment()}, which consumers
 * call before constructing — never from inside apply().
 *
 * SHAPES AND THE SKELETON DEVIATION. The plan's skeleton declares
 * `apply(array $messages, array $tools): array` without naming the array
 * shapes or the return shape. This implementation parses Anthropic-wire turns
 * — `['role' => 'system'|'user'|'assistant', 'content' => string|block[]]`,
 * blocks being the `['type' => ..., ...]` arrays {@see VertexProvider} builds
 * and tools the flat `['name' => ..., 'description' => ..., 'input_schema' =>
 * ...]` shape {@see VertexProvider::formatAnthropicTools()} emits. The return
 * is `['tools' => ..., 'messages' => ...]`: both regions receive marks and the
 * signature returns one value, so the two travel keyed together. System rides
 * INSIDE `$messages` as a `role: system` turn (crush's §5.8 model, and §4.15's
 * mid-conversation-system escape hatch) rather than as a third parameter — the
 * skeleton fixed the parameter list, and hoisting to the top-level `system`
 * field stays the provider's own job, exactly as it is today. String content
 * normalises to a single text block (a bare string cannot carry a marker);
 * every other shape fails loudly at the boundary. That hoisting carries a
 * wiring-time hazard, recorded in close-review follow-ups (39)/(41): once this
 * class is wired to the Anthropic arm, the last-system mark must travel through
 * the P10.S1 `systemBlocks` block form, because
 * {@see VertexProvider::systemInstruction()} hoists the system to a top-level
 * string and {@see VertexProvider::formatAnthropicMessages()} drops
 * SystemMessage rows from `messages` — a mark attached to an in-messages system
 * row is silently discarded on the string arm.
 *
 * WHERE IT IS WIRED (audit A15). Its one production caller is
 * {@see VertexProvider}'s Anthropic arm, which calls `apply()` on every
 * request body it builds - every step of an agentic turn - when the
 * `promptCache` setting is on and the model offers caching. That caller
 * closes the system-prompt hazard recorded above by always handing the system
 * in as a leading block-form `role: system` turn and lifting it back into the
 * top-level `system` field afterwards. {@see BedrockProvider} does not call
 * this class: Converse spells a breakpoint as a separate `cachePoint` block,
 * not a `cache_control` field, and places its own. A successful return
 * guarantees total marks (`tools` + `messages`, ephemeral and
 * preserved-automatic alike) at or below self::MAX_BREAKPOINTS; an input
 * already breaching the cap on foreign marks throws rather than returning a
 * doomed request (the caller turns that into an error response, never a
 * request).
 *
 * THE KILL SWITCH (P10.S3). self::DISABLE_ENV names the presence flag and
 * {@see CacheBreakpoints::disabledFromEnvironment()} is its only reader,
 * static by design: consumers consult it when constructing, and apply() itself
 * stays environment-free (see DETERMINISM above). Its consumer is
 * `ProviderFactory::promptCacheEnabled()`, which builds the vertex and bedrock
 * providers with breakpoints off when it is set; a provider built that way
 * never calls apply() at all. `new CacheBreakpoints(false)`
 * — what the switch reduces to — still runs every input-derived duty in
 * apply(): shape validation throws, the ephemeral wipe lands, and the F-1
 * over-cap breach on preserved foreign marks still fires, because that story
 * is input-derived. What a disabled instance refuses to do is ADD marks: it
 * returns the wiped, audited input with zero ephemeral breakpoints. Preserved
 * foreign marks are never removed (§1.10 — we do not delete mechanisms we do
 * not own), so "disabled" honestly means "no NEW breakpoints", not "caching
 * off for every mechanism in the request".
 *
 * LIVE CHANNEL, NOT YET LIVE (P10.S3). {@see CacheBreakpoints::observeCacheHealth()}
 * has no production feeder at this tree. The cache buckets it reads do reach
 * {@see Usage} on every provider response now (`CompleteResponse::$usage`,
 * E17), but the consumer that would hand each one in belongs in the turn
 * loop, which the A15 wiring did not touch. Until then the method's contract
 * is exercised by tests and by any consumer that already holds a Usage
 * carrying buckets of its own. No channel is invented here.
 */
final class CacheBreakpoints
{
    /**
     * Hard API limit: Anthropic rejects a request carrying more than this many
     * `cache_control` breakpoints anywhere in tools+system+messages.
     */
    public const MAX_BREAKPOINTS = 4;

    /**
     * Explicit marks this class may add when exactly one automatic-cache
     * breakpoint already consumes a slot (MAX_BREAKPOINTS minus that one).
     */
    public const BUDGET_WITH_AUTOMATIC = self::MAX_BREAKPOINTS - 1;

    /**
     * How far back, in content blocks, a breakpoint looks for a prior cache
     * entry (§4.15 "20-block lookback window"). A gap wider than this between
     * consecutive breakpoints silently misses.
     */
    public const LOOKBACK_LIMIT = 20;

    /**
     * Spacing the tail chain keeps between consecutive marks (§4.15: "place an
     * intermediate breakpoint every ~15 blocks in long turns") — comfortably
     * inside LOOKBACK_LIMIT so a step that appends a handful of blocks cannot
     * push the deepest mark out of reach before the next reapply.
     */
    public const INTERMEDIATE_SPACING = 15;

    /**
     * Presence flag that turns this class's breakpoints off (P10.S3). Read only
     * via {@see CacheBreakpoints::disabledFromEnvironment()} — the repo's
     * flag semantics: set to any text other than the empty string or the
     * literal `0` disables; unset, empty and `0` all mean enabled.
     */
    public const DISABLE_ENV = 'SUGARCRUSH_DISABLE_PROMPT_CACHE';

    /**
     * How many consecutive both-buckets-zero Usage reports must pile up before
     * {@see CacheBreakpoints::observeCacheHealth()} names the silence. Three —
     * the PermissionGate.php `STRIKE_THRESHOLD = 3` idiom: a single zero report
     * can be an un-cached first turn of a fresh conversation, two can be a
     * short preamble, and it is the third in a row that says the prefix never
     * crosses the model's minimum cacheable length (512-4096 tokens, §4.15) or
     * the breakpoints are not reaching the wire at all.
     */
    public const CONSECUTIVE_ZERO_REPORTS_THRESHOLD = 3;

    /**
     * The breakpoint marker this class writes, in Anthropic's wire spelling.
     */
    private const EPHEMERAL_MARK = ['type' => 'ephemeral'];

    /**
     * Consecutive responses seen by observeCacheHealth() reporting both cache
     * buckets at zero; any other observation resets it. State, not a clock —
     * the same instance fed a scripted sequence sees the same counts.
     */
    private int $consecutiveZeroReports = 0;

    /**
     * @param bool $marksEnabled false — what the DISABLE_ENV kill switch
     *                           reduces to — keeps every input-derived duty of
     *                           apply() (shape throws, the ephemeral wipe, the
     *                           F-1 over-cap breach) and adds zero ephemeral
     *                           breakpoints. Default true keeps every existing
     *                           `new CacheBreakpoints()` call site byte-identical
     *                           to the pre-switch construction.
     */
    public function __construct(private readonly bool $marksEnabled = true)
    {
    }

    /**
     * Is the P10.S3 kill switch set? Presence-flag semantics, verbatim from the
     * repo's other flags ({@see \SugarCraft\Crush\Chat::envFlag()},
     * {@see \SugarCraft\Crush\Backend\EngineBackend::parallelToolCallsEnabled()}):
     * unset, empty and the literal `0` read as false; any other text reads as
     * true. Static and side-effect-free so a consumer can consult it once at
     * construction — apply() never calls it, keeping the mark plan
     * environment-independent (see DETERMINISM in the class docblock).
     */
    public static function disabledFromEnvironment(): bool
    {
        $value = getenv(self::DISABLE_ENV);

        return $value !== false && $value !== '' && $value !== '0';
    }

    /**
     * Wipe every breakpoint this class owns, then re-derive the mark set.
     *
     * @param array<array-key, mixed> $messages Anthropic-wire turns; a
     *                                          `role: system` turn rides here
     *                                          (see class docblock).
     * @param array<array-key, mixed> $tools    Flat Anthropic tool definitions.
     * @return array{tools: array<array-key, array<string, mixed>>, messages: array<array-key, array<string, mixed>>}
     *
     * @throws \InvalidArgumentException on a turn that is not an array with a
     *                                   non-empty string role and string or
     *                                   array content, on a content block that
     *                                   is not an array, on a tool that is not
     *                                   an array, or when the preserved
     *                                   automatic (non-ephemeral) marks alone
     *                                   exceed self::MAX_BREAKPOINTS — a
     *                                   producer contract violation.
     */
    public function apply(array $messages, array $tools): array
    {
        [$messages, $automatic] = $this->wipeMessages($messages);
        [$tools, $automaticTools] = $this->wipeTools($tools);
        $automatic += $automaticTools;

        if ($automatic > self::MAX_BREAKPOINTS) {
            // Fail-fast at the seam: marks this class must preserve already
            // breach the cap, so every shape of successful return here is a
            // guaranteed API 400. The producer that handed them over is the
            // broken party — surface it now, naming the count, instead of
            // forwarding the breach to the wire.
            throw new \InvalidArgumentException(
                'CacheBreakpoints::apply(): the incoming request already carries ' . $automatic
                . ' automatic (non-ephemeral) cache marks, over the ' . self::MAX_BREAKPOINTS
                . '-breakpoint cap that a successful return must respect; the producer of those marks broke the contract.'
            );
        }

        if (!$this->marksEnabled) {
            // The kill switch, placed after the audit on purpose: a disabled
            // instance owes the caller the SAME throws on the SAME bad input,
            // then adds nothing. Not one ephemeral mark goes in; preserved
            // foreign marks ride on untouched.
            return ['tools' => $tools, 'messages' => $messages];
        }

        // Defense-in-depth clamp at the automatic == MAX_BREAKPOINTS boundary:
        // the guard above rejects a breach, and keeping max() here means the
        // mark loops below can never be handed a negative budget even if the
        // two lines are ever reordered (§1.10).
        $budget = max(0, self::MAX_BREAKPOINTS - $automatic);
        $bounds = $this->messageBlockBounds($messages);
        $systemIndex = $this->lastSystemIndex($messages);

        if ($systemIndex !== null && $budget > 0) {
            [$messages, $systemMarked] = $this->markRegionTail($messages, $systemIndex);
            if ($systemMarked) {
                $budget--;
            }
        }

        // The chain wants one slot per entry; the tool keeps its own mark only
        // when the chain fits with a slot to spare (REPAIR PRIORITY above).
        $tail = $this->tailChain($bounds, $systemIndex);

        if ($tools !== [] && count($tail) < $budget) {
            $lastToolKey = array_key_last($tools);
            $tools[$lastToolKey]['cache_control'] = self::EPHEMERAL_MARK;
            $budget--;
        }

        foreach ($tail as $position) {
            if ($budget <= 0) {
                break;
            }

            [$key, $offset] = $this->resolveBlock($bounds, $position);
            if (isset($messages[$key]['content'][$offset]['cache_control'])) {
                continue; // an automatic mark already owns this block
            }

            $messages[$key]['content'][$offset]['cache_control'] = self::EPHEMERAL_MARK;
            $budget--;
        }

        return ['tools' => $tools, 'messages' => $messages];
    }

    /**
     * Consecutive-zero-reports diagnostic (P10.S3): hand me each response's
     * Usage as it arrives; on the third consecutive report where BOTH cache
     * buckets came back measured at zero — and on every one after — I return
     * the sentence something should eventually print. Null otherwise.
     *
     * The fire condition is exactly §4.15's: "both cache_creation_input_tokens
     * and cache_read_input_tokens = 0 means no cache". Zero means REPORTED zero
     * (Usage.php null-vs-0 doctrine: null is unreported, not a measurement),
     * so a null Usage or any null bucket resets the count without firing, and
     * a reported non-zero in either bucket resets it too. Pure state: no I/O,
     * no clock, no suppression beyond the counter itself — the consumer decides
     * whether to surface what it gets. There is no production feeder at this
     * tree yet; see LIVE CHANNEL, NOT YET LIVE in the class docblock.
     */
    public function observeCacheHealth(?Usage $usage): ?string
    {
        if ($usage === null || $usage->cacheReadTokens === null || $usage->cacheCreationTokens === null) {
            $this->consecutiveZeroReports = 0;

            return null;
        }

        if ($usage->cacheReadTokens !== 0 || $usage->cacheCreationTokens !== 0) {
            $this->consecutiveZeroReports = 0;

            return null;
        }

        $this->consecutiveZeroReports++;

        if ($this->consecutiveZeroReports < self::CONSECUTIVE_ZERO_REPORTS_THRESHOLD) {
            return null;
        }

        return 'CacheBreakpoints: ' . self::CONSECUTIVE_ZERO_REPORTS_THRESHOLD
            . " consecutive responses reported cache_read_input_tokens = 0 and cache_creation_input_tokens = 0;"
            . ' no prompt cache entry is being read or written. The minimum cacheable prefix is model-dependent'
            . ' (512 to 4096 tokens, §4.15) — below it the breakpoints are silently ignored upstream.';
    }

    // -------------------------------------------------------------------------
    // Wipe
    // -------------------------------------------------------------------------

    /**
     * Copies every turn, strips every ephemeral-dialect `cache_control` it can
     * reach (message level and block level), normalises string content to a
     * text block, and counts the automatic marks left standing.
     *
     * @param array<array-key, mixed> $messages
     * @return array{0: array<array-key, array<string, mixed>>, 1: int}
     */
    private function wipeMessages(array $messages): array
    {
        $wiped = [];
        $automatic = 0;

        foreach ($messages as $key => $turn) {
            if (!is_array($turn)) {
                throw new \InvalidArgumentException(
                    'CacheBreakpoints::apply(): every message must be an array turn, '
                    . gettype($turn) . ' given at key ' . var_export($key, true) . '.'
                );
            }

            $role = $turn['role'] ?? null;
            if (!is_string($role) || $role === '') {
                throw new \InvalidArgumentException(
                    'CacheBreakpoints::apply(): every message must carry a non-empty string '
                    . '"role"; the turn at key ' . var_export($key, true) . ' does not.'
                );
            }

            $clean = ['role' => $role, 'content' => $this->parseContent($turn['content'] ?? null, $key)];

            $messageMark = $turn['cache_control'] ?? null;
            if (is_array($messageMark)) {
                if ($this->isAutomaticMark($messageMark)) {
                    // Not this class's to name — keep it, charged to the budget.
                    $clean['cache_control'] = $messageMark;
                    $automatic++;
                }
                // An ephemeral spelling at message level is a stale mark from a
                // crush-shaped caller or an older step: dropped. This class
                // always marks at block level, where the wire wants it.
            }

            foreach ($clean['content'] as $i => $block) {
                $mark = $block['cache_control'] ?? null;
                if (!is_array($mark)) {
                    continue;
                }

                if ($this->isAutomaticMark($mark)) {
                    $automatic++;
                    continue;
                }

                unset($clean['content'][$i]['cache_control']);
            }

            $wiped[$key] = $clean;
        }

        return [$wiped, $automatic];
    }

    /**
     * @param array<array-key, mixed> $tools
     * @return array{0: array<array-key, array<string, mixed>>, 1: int}
     */
    private function wipeTools(array $tools): array
    {
        $wiped = [];
        $automatic = 0;

        foreach ($tools as $key => $tool) {
            if (!is_array($tool)) {
                throw new \InvalidArgumentException(
                    'CacheBreakpoints::apply(): every tool must be an array definition, '
                    . gettype($tool) . ' given at key ' . var_export($key, true) . '.'
                );
            }

            $mark = $tool['cache_control'] ?? null;
            if (is_array($mark)) {
                if ($this->isAutomaticMark($mark)) {
                    $automatic++;
                } else {
                    unset($tool['cache_control']);
                }
            }

            $wiped[$key] = $tool;
        }

        return [$wiped, $automatic];
    }

    // -------------------------------------------------------------------------
    // Mark placement
    // -------------------------------------------------------------------------

    /**
     * The backward chain of content-block positions (indexes into the
     * flattened message-block list, tail territory only). chain[0] is the
     * final block; later entries strictly decrease.
     *
     * For an ordinary transcript this is exactly crush's "last 2 messages":
     * the last block of the last message, then the last block of the one
     * before — and no deeper, which is what leaves a slot for the tool mark.
     * When that boundary sits further back than INTERMEDIATE_SPACING (a long
     * turn — the §4.15 ">20-block" case), the chain drops intermediate marks
     * at that spacing until it reaches the boundary, so every ~15-block window
     * of the long turn carries a breakpoint. Deeper than the second-to-last
     * message the chain deliberately stops: those regions already carry the
     * cache entries the PREVIOUS steps' breakpoints wrote, and this step's
     * marks find one inside the lookback because the chain head starts where
     * the last step's chain head sat.
     *
     * @param array<array-key, array{0: int, 1: int}> $bounds
     * @return list<int>
     */
    private function tailChain(array $bounds, ?int $systemIndex): array
    {
        $floor = $systemIndex === null ? 0 : $bounds[$systemIndex][1];

        $head = -1;
        foreach ($bounds as [, $pastLast]) {
            if ($pastLast - 1 > $head) {
                $head = $pastLast - 1;
            }
        }

        if ($head < $floor) {
            return [];
        }

        $chain = [$head];
        $boundary = $this->previousBoundary($bounds, $head, $floor);
        $stop = $boundary ?? $floor;

        if ($boundary === null && $head - $floor <= self::INTERMEDIATE_SPACING) {
            return $chain; // a short single-message tail: one anchor suffices
        }

        $position = $head;
        while (true) {
            $next = $this->previousBoundary($bounds, $position, $floor);
            $jump = $position - self::INTERMEDIATE_SPACING;

            if ($next === null || $next < $jump) {
                $next = $jump;
            }

            if ($next <= $stop) {
                $chain[] = $stop;
                break;
            }

            $chain[] = $next;
            $position = $next;
        }

        return $chain;
    }

    /**
     * The last block of the message ending strictly before $position,
     * restricted to tail territory (never inside the system region). Null when
     * the chain has reached the first tail message.
     *
     * @param array<array-key, array{0: int, 1: int}> $bounds
     */
    private function previousBoundary(array $bounds, int $position, int $floor): ?int
    {
        $best = null;

        foreach ($bounds as [, $pastLast]) {
            $lastBlock = $pastLast - 1;
            if ($lastBlock < $floor || $lastBlock >= $position) {
                continue;
            }

            if ($best === null || $lastBlock > $best) {
                $best = $lastBlock;
            }
        }

        return $best;
    }

    /**
     * Marks the deepest still-free block of the given message and reports
     * whether a mark was placed. Freezing rather than overwriting: a block
     * already carrying an automatic mark stays that mechanism's, and the
     * region's breakpoint lands one block earlier — byte-stable for the same
     * input, so determinism survives.
     *
     * @param array<array-key, array<string, mixed>> $messages
     * @return array{0: array<array-key, array<string, mixed>>, 1: bool}
     */
    private function markRegionTail(array $messages, int|string $regionKey): array
    {
        for ($offset = count($messages[$regionKey]['content']) - 1; $offset >= 0; $offset--) {
            if (!isset($messages[$regionKey]['content'][$offset]['cache_control'])) {
                $messages[$regionKey]['content'][$offset]['cache_control'] = self::EPHEMERAL_MARK;

                return [$messages, true];
            }
        }

        return [$messages, false];
    }

    /**
     * @param array<array-key, array{0: int, 1: int}> $bounds
     * @return array{0: array-key, 1: int}
     */
    private function resolveBlock(array $bounds, int $position): array
    {
        foreach ($bounds as $key => [$first, $pastLast]) {
            if ($position >= $first && $position < $pastLast) {
                return [$key, $position - $first];
            }
        }

        throw new \InvalidArgumentException(
            'CacheBreakpoints: tail chain position ' . $position . ' lies outside every message region.'
        );
    }

    /**
     * Flattened [firstBlockIndex, pastLastBlockIndex) per message key, in walk
     * order — the coordinate system the chain and the resolver share.
     *
     * @param array<array-key, array<string, mixed>> $messages
     * @return array<array-key, array{0: int, 1: int}>
     */
    private function messageBlockBounds(array $messages): array
    {
        $bounds = [];
        $cursor = 0;

        foreach ($messages as $key => $turn) {
            $count = count($turn['content']);
            $bounds[$key] = [$cursor, $cursor + $count];
            $cursor += $count;
        }

        return $bounds;
    }

    /**
     * @param array<array-key, array<string, mixed>> $messages
     */
    private function lastSystemIndex(array $messages): ?int
    {
        $found = null;

        foreach ($messages as $key => $turn) {
            if ($turn['role'] === 'system') {
                $found = $key;
            }
        }

        return $found;
    }

    // -------------------------------------------------------------------------
    // Shape parsing
    // -------------------------------------------------------------------------

    /**
     * Parses a turn's content into trusted block arrays at the boundary: a
     * non-empty string becomes a single text block, an array of block arrays
     * is copied through, anything else fails loudly — a half-parsed turn must
     * not reach the wire builder.
     *
     * @return list<array<string, mixed>>
     */
    private function parseContent(mixed $content, int|string $turnKey): array
    {
        if (is_string($content)) {
            return $content === '' ? [] : [['type' => 'text', 'text' => $content]];
        }

        if (!is_array($content)) {
            throw new \InvalidArgumentException(
                'CacheBreakpoints::apply(): message content must be a string or an array of '
                . 'blocks; the turn at key ' . var_export($turnKey, true) . ' carries '
                . gettype($content) . '.'
            );
        }

        $blocks = [];

        foreach ($content as $block) {
            if (!is_array($block)) {
                throw new \InvalidArgumentException(
                    'CacheBreakpoints::apply(): every content block must be an array; the turn '
                    . 'at key ' . var_export($turnKey, true) . ' carries ' . gettype($block) . '.'
                );
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * A breakpoint whose `type` is not this class's ephemeral dialect belongs
     * to an automatic-caching mechanism (e.g. Bedrock's `{"type": "default"}`
     * cachePoint) — preserved, and charged to the shared slot budget.
     *
     * @param array<array-key, mixed> $cacheControl
     */
    private function isAutomaticMark(array $cacheControl): bool
    {
        return ($cacheControl['type'] ?? null) !== 'ephemeral';
    }
}
