<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Compactor;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
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

/**
 * Bring back what pruning took out of the model's view (roadmap 3.B-5, DCP
 * §13.2 P2-12 — magic-context's recall, DCP #552): the original output of a
 * tool result that `Prune`, a strategy or `/sweep` replaced with a
 * placeholder, the original input of a call whose arguments were elided, a
 * prompt or result inside a compressed section, or a whole compressed
 * section (`bN`) row by row.
 *
 * NOTHING WAS DELETED. Pruning is a projection: the conversation keeps every
 * row exactly as it happened and the {@see ContextLedger} only says what the
 * next request leaves out, so the raw bytes are still in the turn's rows and
 * this tool reads them from there. It is the cheap alternative to re-running
 * a call whose output may have changed since (a re-`Read` of an edited file
 * is a different answer) or cannot be re-run at all (a `WebFetch` of a page
 * that moved, an expensive `Bash`).
 *
 * IT DOES NOT UNPRUNE. The ledger is left exactly as it was, so the bytes
 * before the pruned row stay what the provider cached; the recalled content
 * arrives once, as this call's own result — which is a tool output like any
 * other, with its own ref, and can be pruned again when the model is done
 * with it.
 *
 * BOUNDED: at most {@see MAX_CALLS_PER_TURN} successful calls per turn (each
 * turn binds a fresh allowance, {@see withLedger()}), and at most
 * {@see MAX_BYTES} of content per call — a section that is larger says where
 * it stopped, and its rows can then be recalled one ref at a time. A ref
 * whose row is already in view in full is refused rather than repeated.
 *
 * PERMISSION CLASS: no-ask. It reads only the session's own conversation — no
 * file, no process, nothing outside the turn — and writes nothing at all.
 * Bound per turn through the same seam as `Prune`
 * ({@see MutatesContextLedger}: it needs the turn's ledger and rows, and
 * never calls `$apply`), so it is offered exactly where `Prune` is — a
 * session or delegated run in the `auto` pruning mode — and never
 * {@see \SugarCraft\Crush\Tools\ParallelSafe}.
 */
#[BuiltInTool(name: 'Recall', permission: ToolPermissionClass::NoAsk, position: 18, gloss: 'bring back, word for word, a tool output it pruned or a section it compressed, by its `rN` / `bN` ref')]
final readonly class Recall implements Tool, BuildsFromCatalog, MutatesContextLedger
{
    public const NAME = 'Recall';

    /** Successful calls a single turn may make. */
    public const MAX_CALLS_PER_TURN = 5;

    /** The most content one call returns, in bytes. */
    public const MAX_BYTES = 40_000;

    /**
     * @param (\Closure(): array{0: ContextLedger, 1: list<TypedMessage>})|null $read
     * @param (\Closure(bool $spend): bool)|null                                 $allowance
     */
    private function __construct(
        private ?\Closure $read = null,
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

    /**
     * Bound to the turn's ledger and rows, with a FRESH allowance: the
     * engine binds its ledger tools once per turn, so the per-turn cap
     * starts over with each turn. `$apply` is never called — recalling
     * changes nothing the model is sent from then on.
     */
    public function withLedger(\Closure $read, \Closure $apply): Tool
    {
        $left = self::MAX_CALLS_PER_TURN;

        return new self($read, static function (bool $spend) use (&$left): bool {
            if ($left <= 0) {
                return false;
            }
            if ($spend) {
                $left--;
            }

            return true;
        });
    }

    /**
     * Unchanged: a recall is not a compaction — it takes nothing out of the
     * model's view — so a hook that refuses compactions has nothing to refuse.
     */
    public function withCompactionGate(\Closure $gate): Tool
    {
        return $this;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Bring back, word for word, content that context pruning took out of your view. Name a '
            . 'tool result by its ref (`r17`, from <ctx-ref r="17"/>) to get its original output when a '
            . 'placeholder now stands in its place, or a compressed section by its block id (`b3`, from '
            . '"[Compressed section b3 …]") to get every row it replaced. Nothing was deleted: the original '
            . 'is returned once, as this call\'s result, and what you are sent afterwards does not change. '
            . 'Prefer this to re-running a call whose answer may have changed since. A ref whose content is '
            . 'already in your context in full is refused. At most ' . self::MAX_CALLS_PER_TURN . ' calls per '
            . 'turn, and at most ' . self::MAX_BYTES . ' bytes per call.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ref' => [
                    'type' => 'string',
                    'description' => 'A ref such as r17 (a pruned tool output, or a row inside a compressed section) or a block id such as b3 (a whole compressed section)',
                ],
            ],
            'required' => ['ref'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        // The runtime pairs the result with its call.
        $callId = '';
        if ($this->read === null || $this->allowance === null) {
            return new ToolResult($callId, 'Error: recall is not available in this run.', true);
        }
        $written = $args['ref'] ?? null;
        if (!\is_string($written) && !\is_int($written)) {
            return new ToolResult($callId, 'Error: ref must be a ref such as r17 or a block id such as b3.', true);
        }
        $written = trim((string) $written);
        if (!($this->allowance)(false)) {
            return new ToolResult($callId, sprintf(
                'Error: Recall may be called at most %d times per turn, and this turn has used them all. Re-run the original call if you still need its output.',
                self::MAX_CALLS_PER_TURN,
            ), true);
        }

        [$ledger, $messages] = ($this->read)();
        $messages = array_values($messages);

        try {
            $content = preg_match('/^[bB](\d+)$/', $written, $m) === 1
                ? self::recallBlock((int) $m[1], $ledger, $messages)
                : self::recallRow($written, $ledger, $messages);
        } catch (\InvalidArgumentException $refused) {
            return new ToolResult($callId, 'Error: ' . $refused->getMessage(), true);
        }
        ($this->allowance)(true);

        return new ToolResult($callId, self::bounded($content));
    }

    /**
     * One row by its ref: a pruned tool output (or the input a prune elided),
     * or a prompt or result a compressed section stands in for.
     *
     * @param list<TypedMessage> $messages
     */
    private static function recallRow(string $written, ContextLedger $ledger, array $messages): string
    {
        $ref = RefTag::parse($written);
        if ($ref === null) {
            throw new \InvalidArgumentException('"' . self::shown($written) . '" is not a ref; write one as r17, or a compressed section as b3.');
        }
        $label = RefTag::label($ref);
        $byRef = array_flip($ledger->refsFor($messages));
        $key = isset($byRef[$ref]) ? (string) $byRef[$ref] : null;
        $keys = ContextLedger::rowKeys($messages, true);
        $index = $key === null ? false : array_search($key, $keys, true);
        if ($key === null || $index === false) {
            throw new \InvalidArgumentException($label . ' names no row of this conversation (a row an earlier compaction summarised away cannot be recalled).');
        }
        $index = (int) $index;
        $row = $messages[$index];
        $hiddenBy = self::hiddenBy($messages, $keys, $ledger)[$index] ?? null;

        if ($row instanceof UserMessage) {
            if ($hiddenBy === null) {
                throw new \InvalidArgumentException($label . ' is a prompt that is already in your context in full; there is nothing to recall.');
            }

            return sprintf('[%s — recalled: a prompt from compressed section %s, word for word]', $label, $hiddenBy) . "\n" . RefTag::stripFrom($row->content());
        }

        $call = ContextProjector::callsById($messages)[$key] ?? null;
        $tool = $call?->name() ?? 'tool';
        $argument = $call === null ? null : PrunedOutputPlaceholder::mainArgument($call->arguments());
        $what = $label . ' ' . $tool . ($argument === null ? '' : ' ' . $argument);
        $entry = $ledger->prune($key);

        if ($entry !== null && $entry->kind->rewritesInput() && $hiddenBy === null) {
            $input = json_encode($call?->arguments() ?? [], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_INVALID_UTF8_SUBSTITUTE);

            return sprintf('[%s — recalled: the call\'s original input, which your context shows elided]', $what) . "\n" . (\is_string($input) ? $input : '{}');
        }
        if ($entry === null && $hiddenBy === null) {
            throw new \InvalidArgumentException($label . ' is not pruned or compressed: its output is already in your context in full.');
        }

        return sprintf(
            '[%s — recalled: the original output, as the tool returned it%s]',
            $what,
            $hiddenBy === null ? '' : ', from compressed section ' . $hiddenBy,
        ) . "\n" . RefTag::stripFrom($row instanceof ToolResultMessage ? $row->content() : '');
    }

    /**
     * Every row a compressed section stands in for, in order — a range
     * block's from its first boundary to its last, a step summary's
     * everything before the step it kept.
     *
     * @param list<TypedMessage> $messages
     */
    private static function recallBlock(int $id, ContextLedger $ledger, array $messages): string
    {
        $block = $ledger->block($id);
        $label = 'b' . $id;
        if ($block === null) {
            throw new \InvalidArgumentException($label . ' is not a compressed section of this conversation.');
        }
        $keys = ContextLedger::rowKeys($messages, true);
        $bounds = self::blockBounds($block, $messages, $keys);
        if ($bounds === null) {
            throw new \InvalidArgumentException($label . '\'s rows are no longer in this conversation (an earlier compaction summarised them away).');
        }
        [$start, $end] = $bounds;
        $hidden = self::hiddenBy($messages, $keys, $ledger);
        $anyHidden = false;
        for ($i = $start; $i <= $end; $i++) {
            $anyHidden = $anyHidden || isset($hidden[$i]);
        }
        if (!$anyHidden) {
            throw new \InvalidArgumentException($label . ' is not compressed now (it was taken back): its rows are already in your context in full.');
        }

        $refs = $ledger->refsFor($messages);
        $calls = ContextProjector::callsById($messages);
        $files = [];
        $lines = [];
        $rows = 0;
        for ($i = $start; $i <= $end; $i++) {
            $row = $messages[$i];
            if (TurnContextBlock::isTurnContext($row)) {
                continue;
            }
            $rows++;
            if ($row instanceof UserMessage) {
                $ref = isset($keys[$i]) ? ($refs[$keys[$i]] ?? null) : null;
                $lines[] = '### ' . ($ref === null ? '' : RefTag::label($ref) . ' · ') . "user\n" . RefTag::stripFrom($row->content());
            } elseif ($row instanceof AssistantMessage) {
                $said = trim($row->content());
                $called = [];
                foreach ($row->toolCalls() ?? [] as $call) {
                    if ($call instanceof ToolCall) {
                        $argument = PrunedOutputPlaceholder::mainArgument($call->arguments());
                        $called[] = '→ ' . $call->name() . ($argument === null ? '' : ' ' . $argument);
                        $path = $call->arguments()['file_path'] ?? $call->arguments()['path'] ?? null;
                        if (\is_string($path) && $path !== '') {
                            $files[$path] = true;
                        }
                    }
                }
                $lines[] = "### assistant\n" . implode("\n", array_filter([$said, ...$called], static fn (string $s): bool => $s !== ''));
            } elseif ($row instanceof ToolResultMessage) {
                $call = $calls[$row->toolCallId()] ?? null;
                $ref = $refs[$row->toolCallId()] ?? null;
                $lines[] = '### ' . ($ref === null ? '' : RefTag::label($ref) . ' · ')
                    . ($call?->name() ?? 'tool') . " result\n" . RefTag::stripFrom($row->content());
            }
        }

        $grouped = (new Compactor())->describe(array_keys($files));

        return sprintf(
            '[%s%s — recalled: the %d row%s it replaced, word for word%s]',
            $label,
            $block->topic === null || $block->topic === '' ? '' : ' "' . $block->topic . '"',
            $rows,
            $rows === 1 ? '' : 's',
            $grouped === '' ? '' : '; files touched: ' . $grouped,
        ) . "\n\n" . implode("\n\n", $lines);
    }

    /**
     * Where $block's rows sit in $messages, or null when they are gone.
     *
     * @param list<TypedMessage>  $messages
     * @param array<int, string>  $keys
     * @return array{0: int, 1: int}|null
     */
    private static function blockBounds(CompressionBlock $block, array $messages, array $keys): ?array
    {
        if ($block->isRange()) {
            return ContextProjector::rangeBounds($messages, $keys, (string) $block->fromKey, (string) $block->toKey);
        }
        $keepFrom = ContextProjector::stepOpening($messages, $block->keepFromToolCallId);
        if ($keepFrom === null) {
            return null;
        }
        // A step summary stands in for every row before the step it kept,
        // past a delegated run's leading system turn.
        $start = 0;
        while ($start < $keepFrom && !$messages[$start] instanceof UserMessage
            && !$messages[$start] instanceof AssistantMessage && !$messages[$start] instanceof ToolResultMessage) {
            $start++;
        }

        return $start < $keepFrom ? [$start, $keepFrom - 1] : null;
    }

    /**
     * The rows an active compressed section stands in for, by index => the
     * label of the OUTERMOST block hiding it (the one the model can see).
     *
     * @param list<TypedMessage> $messages
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    private static function hiddenBy(array $messages, array $keys, ContextLedger $ledger): array
    {
        $hidden = [];
        $blocks = $ledger->activeRangeBlocks();
        $step = $ledger->activeBlock();
        if ($step !== null) {
            $blocks[] = $step;
        }
        foreach ($blocks as $block) {
            $bounds = self::blockBounds($block, $messages, $keys);
            if ($bounds === null) {
                continue;
            }
            for ($i = $bounds[0]; $i <= $bounds[1]; $i++) {
                $hidden[$i] ??= $block->label();
            }
        }

        return $hidden;
    }

    /** $content cut to {@see MAX_BYTES}, on a UTF-8 boundary, saying so. */
    private static function bounded(string $content): string
    {
        if (\strlen($content) <= self::MAX_BYTES) {
            return $content;
        }
        $cut = mb_strcut($content, 0, self::MAX_BYTES, 'UTF-8');

        return $cut . sprintf(
            "\n[… cut at %d bytes of %d: recall the rows inside it one ref at a time for the rest]",
            self::MAX_BYTES,
            \strlen($content),
        );
    }

    /** A written ref that is not one, bounded and on one line. */
    private static function shown(string $written): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $written) ?? '');

        return mb_strlen($text) > 20 ? mb_substr($text, 0, 19) . '…' : $text;
    }
}
