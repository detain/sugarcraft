<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Context\ContextPressure;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\MutatesContextLedger;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Util\TokenCount;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * The model's own range compression (roadmap 3.B-4, DCP §13.2 F — opencode-dcp
 * v3's `compress` in range mode): replace a CLOSED range of the conversation
 * — from one ref to another, prompts, steps and tool outputs alike — with an
 * exhaustive summary the model writes, as a {@see CompressionBlock} range in
 * the turn's {@see ContextLedger}. Nothing in the conversation is rewritten:
 * the {@see ContextProjector} shows the summary in the range's place on every
 * later request, this turn's next step included, and the transcript the
 * person reads keeps every row. `/decompress bN` takes a block back.
 *
 * NESTED BLOCKS. A range may cover earlier blocks (it may start or end AT one,
 * `bN`); each is then CONSUMED, and the new summary names it once as a `(bN)`
 * placeholder the projection expands to that block's own summary — so a
 * re-compression never restates what is already summarised. A missing
 * placeholder is appended; an unknown or repeated one is refused.
 *
 * GUARDS, against DCP #573's "compression snowball" (each block re-absorbing
 * the last plus a little; 71 blocks, 738K tokens burnt):
 * - SIZE: a summary is refused when its own text is more than half the tokens
 *   it newly replaces plus 2000 ({@see SUMMARY_RATIO}, {@see SUMMARY_SLACK}) —
 *   a summary that saves nothing is not a summary;
 * - NESTING: a block whose placeholders would expand past
 *   {@see MAX_BLOCK_TOKENS} is refused; the model is told to leave the earlier
 *   block standalone and start after it.
 *
 * PROTECTED CONTENT. The outputs of `Task` and `Skill` inside the range
 * ({@see \SugarCraft\Crush\Context\Pruning\PruningPolicy::COMPRESS_PROTECTED_TOOLS})
 * are re-attached to the summary verbatim by the projection; the model is told
 * not to restate them.
 *
 * MANUAL BY DEFAULT ({@see MODE_DEFAULT}, the roadmap's "Prune auto, Compress
 * manual" — DCP #611: under pressure a model compresses content still
 * needed). The tool is offered only on a turn the person started with
 * `/compress [focus]` ({@see triggerPrompt()}, {@see isTriggered()}), and that
 * turn may make exactly ONE successful call ({@see withAllowance()}). The
 * person can opt out of that in `config.json` with `contextPruning.compress:
 * auto` ({@see \SugarCraft\Crush\Context\CompactorConfig::offersCompressUnprompted()}):
 * then it is offered on every turn where the model may prune — the pruning
 * mode's `auto`, beside `Prune` — with no per-turn allowance. Bound by
 * {@see \SugarCraft\Crush\Backend\EngineBackend}'s turn like `Prune`
 * ({@see MutatesContextLedger}), PreCompact-gated, and never
 * {@see \SugarCraft\Crush\Tools\ParallelSafe}.
 *
 * PERMISSION CLASS: no-ask — like `Prune`, it writes only the harness's own
 * record of what the model is sent, and `/decompress` undoes it.
 */
#[BuiltInTool(name: 'Compress', permission: ToolPermissionClass::NoAsk, position: 16, gloss: 'replace a closed range of the conversation with its own summary — only on a turn you start with `/compress`')]
final readonly class Compress implements Tool, BuildsFromCatalog, MutatesContextLedger
{
    public const NAME = 'Compress';

    /** `manual`: offered only on a `/compress` turn, one call per trigger. */
    public const MODE_DEFAULT = 'manual';

    /** The first line of a `/compress` prompt — the manual trigger. */
    public const TRIGGER = '<compress triggered manually>';

    /** A summary's own text may be at most this share of what it newly replaces… */
    public const SUMMARY_RATIO = 0.5;

    /** …plus this many tokens. */
    public const SUMMARY_SLACK = 2000;

    /** A block with its placeholders expanded may be at most this many tokens. */
    public const MAX_BLOCK_TOKENS = 16_000;

    private const TRIGGER_TEXT = "Manual trigger received: use the Compress tool now, exactly once.\n"
        . 'Find the most significant CLOSED part of the conversation — finished research, a resolved '
        . 'detour, work already verified — and compress it into a high-fidelity technical summary. '
        . 'Preserve every detail later work depends on, and never include this message or the current step. '
        . 'After the call, reply briefly with what you compressed, then stop.';

    /**
     * @param (\Closure(): array{0: ContextLedger, 1: list<TypedMessage>})|null $read
     * @param (\Closure(LedgerDelta): ?ContextLedger)|null                       $apply
     * @param (\Closure(string, string): ?string)|null                          $gate
     * @param (\Closure(bool): bool)|null                                       $allowance
     */
    private function __construct(
        private ?\Closure $read = null,
        private ?\Closure $apply = null,
        private ?\Closure $gate = null,
        private ?\Closure $allowance = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return self::new();
    }

    public function withLedger(\Closure $read, \Closure $apply): Tool
    {
        return new self($read, $apply, $this->gate, $this->allowance);
    }

    public function withCompactionGate(\Closure $gate): Tool
    {
        return new self($this->read, $this->apply, $gate, $this->allowance);
    }

    /**
     * This tool with the turn's allowance: `$allowance(false)` answers whether
     * a call may run, `$allowance(true)` spends it once a call succeeded — so
     * a call the tool refused leaves the model free to retry. Unset, every
     * call is refused (the manual default with no `/compress`).
     *
     * @param \Closure(bool $spend): bool $allowance
     */
    public function withAllowance(\Closure $allowance): self
    {
        return new self($this->read, $this->apply, $this->gate, $allowance);
    }

    /**
     * The second line of a `/compact --self` prompt (roadmap 3.B-4, Kilo's
     * legacy self-compaction): the trigger asks for ONE range over the whole
     * closed conversation, and the person previews the summary before it
     * applies ({@see \SugarCraft\Crush\Context\Pruning\CompressPreviewHook}).
     */
    public const SELF_MARKER = '[compact --self]';

    private const SELF_TEXT = "Manual trigger received: use the Compress tool now, exactly once, with ONE range from the "
        . 'oldest ref in your context to the last row before this message, so your summary stands in for the whole '
        . 'conversation so far. Write it as the hand-over a fresh session would need: the user\'s requests (quote the '
        . 'latest verbatim), what was done and decided, the files and functions involved, errors and how they were '
        . 'fixed, and what is still pending. The person reviews your summary before it applies. After the call, '
        . 'reply briefly with what you compressed, then stop.';

    /** The user turn `/compact --self [focus]` sends. */
    public static function selfCompactionPrompt(string $focus = ''): string
    {
        $focus = trim($focus);

        return self::TRIGGER . "\n" . self::SELF_MARKER . "\n" . self::SELF_TEXT
            . ($focus === '' ? '' : "\n\nFocus from the user:\n" . $focus);
    }

    /**
     * Whether the newest prompt in $messages is a `/compact --self` trigger —
     * the turn whose Compress call the person previews.
     *
     * @param list<mixed> $messages typed messages
     */
    public static function isSelfCompaction(array $messages): bool
    {
        for ($i = \count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? null;
            if ($message instanceof UserMessage && !TurnContextBlock::isTurnContext($message) && !CompressionBlock::isSummaryRow($message)) {
                return str_starts_with(RefTag::stripFrom($message->content()), self::TRIGGER . "\n" . self::SELF_MARKER);
            }
        }

        return false;
    }

    /** The user turn `/compress [focus]` sends. */
    public static function triggerPrompt(string $focus = ''): string
    {
        $focus = trim($focus);

        return self::TRIGGER . "\n" . self::TRIGGER_TEXT . ($focus === '' ? '' : "\n\nFocus from the user:\n" . $focus);
    }

    /**
     * Why `/compress` cannot run in a session in $mode, or null when it can:
     * `off` tags no row with a ref to compress by. `manual` runs it — the
     * model gets no context tool on its own turns there, but a `/compress`
     * turn is the person asking for one
     * ({@see \SugarCraft\Crush\Backend\EngineBackend::turnTools()}).
     */
    public static function refusalFor(\SugarCraft\Crush\Context\Pruning\PruningMode $mode): ?string
    {
        return match ($mode) {
            \SugarCraft\Crush\Context\Pruning\PruningMode::Auto, \SugarCraft\Crush\Context\Pruning\PruningMode::Manual => null,
            \SugarCraft\Crush\Context\Pruning\PruningMode::Off => 'Context pruning is `off` for this session, so no row carries a ref to compress by. /pruning auto turns it back on.',
        };
    }

    /**
     * Whether the newest prompt in $messages is a `/compress` trigger — the
     * turn the manual default offers the tool on.
     *
     * @param list<mixed> $messages typed messages
     */
    public static function isTriggered(array $messages): bool
    {
        for ($i = \count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? null;
            if ($message instanceof UserMessage && !TurnContextBlock::isTurnContext($message) && !CompressionBlock::isSummaryRow($message)) {
                return str_starts_with(RefTag::stripFrom($message->content()), self::TRIGGER);
            }
        }

        return false;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return "Collapse a CLOSED range of the conversation into a detailed summary that replaces it in your context.\n\n"
            . "THE SUMMARY\nMake it EXHAUSTIVE: file paths, function signatures, decisions made, constraints discovered, "
            . 'key findings, exact error strings — everything later work depends on. It is the authoritative record, so '
            . 'faithful that the original rows add nothing. When the range includes the user\'s messages, preserve their '
            . "intent exactly (scope, constraints, acceptance criteria); quote short ones directly. Yet be lean: drop failed "
            . "attempts, verbose output and back-and-forth exploration.\n\n"
            . "BOUNDARIES\nEvery prompt and tool result ends with a ref tag such as <ctx-ref r=\"17\"/>: name it `r17`. "
            . "An earlier compressed section is named by its block id `bN` (its summary row says `Compressed section bN`). "
            . "`from` must come before `to`; both must be visible in your context. A range ending at a tool result "
            . "extends to the end of that step, so no call is separated from its result. Never include the newest user "
            . "message or your current step.\n\n"
            . "COMPRESSED BLOCK PLACEHOLDERS\nWhen your range covers earlier blocks, write each as the placeholder `(bN)` "
            . "exactly once where its content belongs; it is replaced by that block's full summary, so write the "
            . "surrounding text to read correctly after expansion. Do not invent placeholders for blocks outside the "
            . "range, and do not write `(bN)` anywhere else (say `compressed bN` in prose).\n\n"
            . "PROTECTED CONTENT\nOutputs of Task and Skill inside the range are re-attached verbatim automatically — "
            . "do not restate them.\n\n"
            . "LIMITS\nA summary must be clearly smaller than what it replaces (at most half its tokens plus 2000), and "
            . 'a block with its placeholders expanded may not pass ' . TokenCount::compact(self::MAX_BLOCK_TOKENS) . ' tokens — '
            . "leave a large earlier block standalone and start after it. Several independent, non-overlapping ranges "
            . 'go in one call as separate `ranges` entries.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => ['type' => 'string', 'description' => "3-5 word label shown in the transcript, e.g. 'Auth system exploration'"],
                'ranges' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'One or more non-overlapping ranges, each with its boundaries and summary',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'string', 'description' => 'First row of the range: a ref rN or a block bN'],
                            'to' => ['type' => 'string', 'description' => 'Last row of the range: a ref rN or a block bN'],
                            'summary' => ['type' => 'string', 'description' => 'Exhaustive technical summary replacing everything in the range; include each covered (bN) exactly once'],
                        ],
                        'required' => ['from', 'to', 'summary'],
                    ],
                ],
            ],
            'required' => ['topic', 'ranges'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $callId = '';
        if ($this->read === null || $this->apply === null) {
            return self::error('context compression is not available in this run.');
        }
        if ($this->allowance === null || !($this->allowance)(false)) {
            return self::error('Compress runs only when the person asks for it with /compress, once per request. '
                . 'Do not retry until a message starting ' . self::TRIGGER . ' appears; use Prune for finished tool outputs.');
        }

        $topic = \is_string($args['topic'] ?? null) ? trim(preg_replace('/\s+/', ' ', $args['topic']) ?? '') : '';
        if ($topic === '') {
            return self::error('topic must be a short non-empty label.');
        }
        $ranges = $args['ranges'] ?? null;
        if (!\is_array($ranges) || $ranges === [] || !array_is_list($ranges)) {
            return self::error('ranges must be a non-empty list of {"from": "rN", "to": "rN", "summary": "…"} objects.');
        }

        [$ledger, $messages] = ($this->read)();
        $messages = array_values($messages);
        if (!$ledger->effectiveMode()->showsRefs()) {
            return self::error('context pruning is `off` for this session, so nothing carries a ref to compress by.');
        }

        try {
            [$delta, $receipts] = self::plan($topic, $ranges, $ledger, $messages);
        } catch (\InvalidArgumentException $e) {
            return self::error($e->getMessage());
        }

        $refusal = $this->gate === null ? null : ($this->gate)(self::isTriggered($messages) ? 'manual' : 'auto', $topic);
        if ($refusal !== null) {
            return self::error('a PreCompact hook refused this compression (' . $refusal . '); nothing was compressed.');
        }
        if (($this->apply)($delta) === null) {
            return self::error('the turn\'s context ledger could not be reached from where this call ran; nothing was compressed.');
        }
        ($this->allowance)(true);

        return new ToolResult($callId, implode(' ', $receipts));
    }

    /**
     * Resolve every range against the rows the model read and the ledger as it
     * stands: the delta to apply and one receipt per block. Throws
     * {@see \InvalidArgumentException} with the model-facing reason when any
     * range cannot be compressed — the whole call is refused, so the model
     * fixes the batch rather than half of it landing.
     *
     * @param list<mixed>        $ranges
     * @param list<TypedMessage> $messages
     *
     * @return array{0: LedgerDelta, 1: list<string>}
     */
    private static function plan(string $topic, array $ranges, ContextLedger $ledger, array $messages): array
    {
        $keys = ContextLedger::rowKeys($messages);
        $allKeys = ContextLedger::rowKeys($messages, true);
        $idsByRef = array_flip($ledger->refsFor($messages));
        $indexByKey = array_flip($keys);

        // The newest prompt: no range may reach it.
        $limit = \count($messages);
        foreach ($allKeys as $index => $key) {
            if ($messages[$index] instanceof UserMessage) {
                $limit = $index;
            }
        }
        // The step summary's kept part starts here; earlier rows are not in view.
        $harness = $ledger->activeBlock();
        $visibleFrom = $harness === null ? 0 : (ContextProjector::stepOpening($messages, $harness->keepFromToolCallId) ?? 0);

        $blockBounds = [];
        foreach ($ledger->activeRangeBlocks() as $block) {
            $bounds = ContextProjector::rangeBounds($messages, $allKeys, (string) $block->fromKey, (string) $block->toKey);
            if ($bounds !== null) {
                $blockBounds[$block->id] = $bounds;
            }
        }

        $delta = LedgerDelta::new();
        $receipts = [];
        $taken = [];
        $nextId = $ledger->nextBlockId;
        foreach ($ranges as $n => $range) {
            $which = \count($ranges) > 1 ? 'range ' . ($n + 1) . ': ' : '';
            if (!\is_array($range) || !\is_string($range['from'] ?? null) || !\is_string($range['to'] ?? null) || !\is_string($range['summary'] ?? null) || trim($range['summary']) === '') {
                throw new \InvalidArgumentException($which . 'each range needs `from`, `to` and a non-empty `summary`.');
            }
            [$start, $fromRef, $fromKey] = self::boundary($range['from'], false, $ledger, $messages, $idsByRef, $indexByKey, $blockBounds, $which);
            [$end, $toRef, $toKey] = self::boundary($range['to'], true, $ledger, $messages, $idsByRef, $indexByKey, $blockBounds, $which);
            if ($start > $end) {
                throw new \InvalidArgumentException($which . "{$range['from']} comes after {$range['to']}; `from` must come before `to`.");
            }
            if ($end >= $limit) {
                throw new \InvalidArgumentException($which . 'the range reaches the newest user message or your current step; end it before them.');
            }
            if ($start < $visibleFrom) {
                throw new \InvalidArgumentException($which . "{$range['from']} is inside the conversation summary b{$harness?->id}; start the range after it.");
            }
            foreach ($taken as [$s, $e]) {
                if ($start <= $e && $s <= $end) {
                    throw new \InvalidArgumentException($which . 'it overlaps another range of this call; ranges in one call must not overlap.');
                }
            }
            $taken[] = [$start, $end];

            $consumed = [];
            foreach ($blockBounds as $id => [$bs, $be]) {
                if ($bs >= $start && $be <= $end) {
                    $consumed[] = $id;
                } elseif ($bs <= $end && $start <= $be) {
                    throw new \InvalidArgumentException($which . "the range cuts block b{$id}; extend it to cover the whole block, or start after it.");
                }
            }

            $summary = self::withPlaceholders(trim($range['summary']), $consumed, $which);
            $inner = [];
            foreach ($consumed as $id) {
                [$bs, $be] = $blockBounds[$id];
                for ($i = $bs; $i <= $be; $i++) {
                    $inner[$i] = true;
                }
            }
            $newly = [];
            $pruned = 0;
            for ($i = $start; $i <= $end; $i++) {
                if (isset($inner[$i])) {
                    continue;
                }
                $newly[] = $messages[$i];
                if ($messages[$i] instanceof ToolResultMessage) {
                    $pruned += $ledger->prune($messages[$i]->toolCallId())?->tokens ?? 0;
                }
            }
            $newlyTokens = max(0, ContextPressure::ofMessages($newly) - $pruned);
            $own = TokenEstimate::ofText(trim((string) preg_replace('/\(b\d+\)/', '', $summary)));
            if ($own > self::SUMMARY_RATIO * $newlyTokens + self::SUMMARY_SLACK) {
                throw new \InvalidArgumentException(sprintf(
                    '%sSummary (~%s tokens) is not much smaller than the content it replaces (~%s tokens); compress a larger closed range or write a tighter summary.',
                    $which,
                    TokenCount::compact($own),
                    TokenCount::compact($newlyTokens),
                ));
            }
            $block = CompressionBlock::range($nextId, $topic, $fromKey, $toKey, $fromRef, $toRef, $summary, $newlyTokens, $own, $consumed);
            $expanded = TokenEstimate::ofText(ContextProjector::expandedSummary($block, $ledger));
            if ($expanded > self::MAX_BLOCK_TOKENS) {
                throw new \InvalidArgumentException(sprintf(
                    '%sexpanding the placeholders would make b%d ~%s tokens, past the %s nesting cap; leave the earlier block%s standalone and start this range after it.',
                    $which,
                    $nextId,
                    TokenCount::compact($expanded),
                    TokenCount::compact(self::MAX_BLOCK_TOKENS),
                    \count($consumed) === 1 ? ' b' . $consumed[0] : 's',
                ));
            }

            $delta = $delta->withBlock($block);
            $receipts[] = sprintf(
                'Compressed %d row%s (~%s tokens) into block b%d (~%s tokens)%s.',
                $end - $start + 1,
                $end === $start ? '' : 's',
                TokenCount::compact($newlyTokens),
                $nextId,
                TokenCount::compact($own),
                $consumed === [] ? '' : ', covering ' . implode(', ', array_map(static fn (int $id): string => 'b' . $id, $consumed)),
            );
            $nextId++;
        }

        // The cooldown (NudgePolicy): the model just managed its context.
        return [$ledger->nudges === [] ? $delta : $delta->withNudgesCleared(), $receipts];
    }

    /**
     * Where one boundary sits, the ref it is shown as, and the key the block
     * keeps it by.
     *
     * @param list<TypedMessage>                $messages
     * @param array<int, string>                $idsByRef    ref => row key
     * @param array<string, int>                $indexByKey  row key => index
     * @param array<int, array{0: int, 1: int}> $blockBounds active range block => bounds
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private static function boundary(string $written, bool $end, ContextLedger $ledger, array $messages, array $idsByRef, array $indexByKey, array $blockBounds, string $which): array
    {
        $written = trim($written);
        if (preg_match('/^[bB](\d+)$/', $written, $m) === 1) {
            $block = $ledger->block((int) $m[1]);
            if ($block === null || !isset($blockBounds[$block->id])) {
                throw new \InvalidArgumentException($which . "{$written} is not a compressed section in your context.");
            }
            [$bs, $be] = $blockBounds[$block->id];

            return $end
                ? [$be, (int) $block->toRef, (string) $block->toKey]
                : [$bs, (int) $block->fromRef, (string) $block->fromKey];
        }

        $ref = RefTag::parse($written);
        $key = $ref === null ? null : ($idsByRef[$ref] ?? null);
        $index = $key === null ? null : ($indexByKey[(string) $key] ?? null);
        if ($ref === null || $key === null || $index === null) {
            throw new \InvalidArgumentException($which . "{$written} is not a ref in your context; use a <ctx-ref r=\"N\"/> you can see, as rN.");
        }
        $key = (string) $key;
        if (ContextLedger::isUserRowKey($key)) {
            return [$index, $ref, $key];
        }

        // A tool result: its step, opener through its last result.
        $opener = ContextProjector::stepOpening($messages, $key);
        if ($opener === null) {
            throw new \InvalidArgumentException($which . "{$written} names a result whose call is not in your context.");
        }
        $calls = $messages[$opener] instanceof AssistantMessage ? ($messages[$opener]->toolCalls() ?? []) : [];
        $first = $calls[0] ?? null;
        $stepKey = ContextLedger::stepKey($first instanceof ToolCall ? $first->id() : $key);
        if (!$end) {
            return [$opener, $ref, $stepKey];
        }
        $last = $opener;
        while (($messages[$last + 1] ?? null) instanceof ToolResultMessage) {
            $last++;
        }

        return [$last, $ref, $stepKey];
    }

    /**
     * $summary with every consumed block's placeholder present exactly once:
     * a missing one appended, an unknown or repeated one refused.
     *
     * @param list<int> $consumed
     */
    private static function withPlaceholders(string $summary, array $consumed, string $which): string
    {
        $named = CompressionBlock::placeholdersIn($summary);
        foreach (array_count_values($named) as $id => $times) {
            if (!\in_array($id, $consumed, true)) {
                throw new \InvalidArgumentException($which . "(b{$id}) is not a block inside this range; write `compressed b{$id}` in prose, or cover it.");
            }
            if ($times > 1) {
                throw new \InvalidArgumentException($which . "(b{$id}) appears {$times} times; include each placeholder exactly once.");
            }
        }
        foreach ($consumed as $id) {
            if (!\in_array($id, $named, true)) {
                $summary .= "\n\n" . sprintf(CompressionBlock::PLACEHOLDER, $id);
            }
        }

        return $summary;
    }

    private static function error(string $reason): ToolResult
    {
        return new ToolResult('', 'Error: ' . $reason, true);
    }
}
