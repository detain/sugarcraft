<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Compactor;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\MutatesContextLedger;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Util\TokenCount;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * The model's own context pruning (roadmap 3.B-3, DCP §13.2 F — opencode-dcp
 * 2.x's `prune` and `distill`, merged): drop, or replace with a distillation,
 * tool outputs the model is finished with, by the ref each carries
 * (`<ctx-ref r="17"/>`, {@see RefTag}).
 *
 * Nothing in the conversation is rewritten. Each target becomes a
 * {@see PruneEntry} by {@see PruneAuthor::Model} in the turn's
 * {@see ContextLedger}, and the {@see ContextProjector} shows it on every
 * later request — the NEXT step of this very turn included — as the one-line
 * {@see PrunedOutputPlaceholder} (the tool and its main argument, so the call
 * can be re-run), or, with a distillation, as the model's own text under that
 * line ({@see PruneKind::Distilled}). The transcript the person reads keeps
 * every output.
 *
 * VALIDATION is per target and soft (DCP's message-mode style): a ref that is
 * not one, names no tool result the model has read, names an output of a
 * protected tool ({@see PruningPolicy::PROTECTED_TOOLS} — a `Task` report or a
 * `Skill` body cannot be fetched again by re-running a cheap call — and
 * `Prune`'s own receipts), names one already pruned, or carries a
 * distillation no shorter than the output, is skipped and named in the
 * receipt. The call fails only when NOTHING applied, so a batch with one bad
 * ref still prunes the rest.
 *
 * PERMISSION CLASS: no-ask. It writes only the harness's own record of what
 * the model is sent — no file, no process, nothing outside the session — and
 * everything it does is undone by re-running the pruned call. Offered only
 * where a host keeps the session's ledger and its pruning mode is `auto`
 * (`turnTools()` drops it otherwise: a sub-agent's conversation, `-p`, a
 * session set to `manual` or `off`). Never {@see \SugarCraft\Crush\Tools\ParallelSafe}:
 * the ledger lives in the turn's own process ({@see MutatesContextLedger}).
 */
#[BuiltInTool(name: 'Prune', permission: ToolPermissionClass::NoAsk, position: 14, gloss: 'drop or distill its own finished tool outputs from what it is sent, by their `<ctx-ref r="N"/>` refs')]
final readonly class Prune implements Tool, BuildsFromCatalog, MutatesContextLedger
{
    public const NAME = 'Prune';

    /**
     * @param (\Closure(): array{0: ContextLedger, 1: list<\SugarCraft\Crush\Messages\Message>})|null $read
     * @param (\Closure(LedgerDelta): ?ContextLedger)|null                                         $apply
     */
    private function __construct(
        private ?\Closure $read = null,
        private ?\Closure $apply = null,
        private ?\Closure $gate = null,
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
        return new self($read, $apply, $this->gate);
    }

    public function withCompactionGate(\Closure $gate): Tool
    {
        return new self($this->read, $this->apply, $gate);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Remove or distill tool outputs you are finished with, to keep your context small. Every tool '
            . 'result ends with a ref tag such as <ctx-ref r="17"/>; name it as `r17`. Without a `distillation` '
            . 'the output is replaced by a one-line placeholder naming the tool and its main argument (re-run '
            . 'the tool if you need it again). With a `distillation`, your text replaces the output — make it '
            . 'complete: signatures, values, paths, exact error strings. Do NOT prune output you will edit '
            . 'against or quote exactly in the next steps. Batch several targets in one call; a single tiny '
            . 'output is not worth a call. Make this call alongside your next real tool calls rather than as '
            . 'your only action. Outputs of Task, Skill, Edit and Write are never pruned.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'targets' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'The tool outputs to prune',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ref' => ['type' => 'string', 'description' => 'A tool-result ref such as r17, copied from <ctx-ref r="17"/>'],
                            'distillation' => ['type' => 'string', 'description' => 'Optional. A complete technical substitute for the output. Omit to drop the output entirely.'],
                        ],
                        'required' => ['ref'],
                    ],
                ],
                'reason' => [
                    'type' => 'string',
                    'enum' => PruneReason::modelReasons(),
                    'description' => 'noise: nothing in it bears on the task; superseded: a later output replaced it; done: it served its purpose',
                ],
            ],
            'required' => ['targets', 'reason'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        // The runtime pairs the result with its call.
        $callId = '';
        if ($this->read === null || $this->apply === null) {
            return new ToolResult($callId, 'Error: context pruning is not available in this run.', true);
        }

        $reason = \is_string($args['reason'] ?? null) ? PruneReason::tryFrom($args['reason']) : null;
        if ($reason === null || !\in_array($reason->value, PruneReason::modelReasons(), true)) {
            return new ToolResult($callId, 'Error: reason must be one of ' . implode(', ', PruneReason::modelReasons()) . '.', true);
        }
        $targets = $args['targets'] ?? null;
        if (!\is_array($targets) || $targets === [] || !array_is_list($targets)) {
            return new ToolResult($callId, 'Error: targets must be a non-empty list of {"ref": "rN"} objects.', true);
        }

        [$ledger, $messages] = ($this->read)();
        if (!$ledger->effectiveMode()->allowsModelPruning()) {
            return new ToolResult($callId, sprintf(
                'Error: context pruning is `%s` for this session, so only the person prunes (/sweep).',
                $ledger->effectiveMode()->value,
            ), true);
        }

        [$delta, $pruned, $distilled, $skipped, $files] = self::plan($targets, $reason, $ledger, $messages);
        if ($delta->isEmpty()) {
            return new ToolResult($callId, 'Error: nothing was pruned.' . self::skippedText($skipped), true);
        }
        // DCP §13.2 F: a prune is a compaction, and a PreCompact hook that
        // refuses compactions refuses it — checked once there is something to
        // prune, so a call that would change nothing never runs the chain.
        $refusal = $this->gate === null ? null : ($this->gate)('auto', '');
        if ($refusal !== null) {
            return new ToolResult($callId, 'Error: a PreCompact hook refused this prune (' . $refusal . '); nothing was pruned.', true);
        }
        if (($this->apply)($delta) === null) {
            return new ToolResult($callId, 'Error: the turn\'s context ledger could not be reached from where this call ran; nothing was pruned.', true);
        }

        return new ToolResult($callId, self::receipt($delta, $pruned, $distilled, $skipped, (new Compactor())->describeTargets($files)));
    }

    /**
     * Resolve every target against the rows the model read and the ledger as
     * it stands: the delta to apply, the tools pruned (name => count), the
     * refs distilled, each skipped target with why, and the arguments of
     * every pruned call (for the receipt's files, {@see Compactor::describeTargets()}).
     *
     * @param list<mixed>                                $targets
     * @param list<\SugarCraft\Crush\Messages\Message>   $messages
     *
     * @return array{0: LedgerDelta, 1: array<string, int>, 2: list<string>, 3: list<string>, 4: list<array<array-key, mixed>>}
     */
    private static function plan(array $targets, PruneReason $reason, ContextLedger $ledger, array $messages): array
    {
        $policy = PruningPolicy::new();
        $idsByRef = array_flip($ledger->refsFor($messages));
        $calls = ContextProjector::callsById($messages);
        $results = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage && !isset($results[$message->toolCallId()])) {
                $results[$message->toolCallId()] = $message;
            }
        }

        $delta = LedgerDelta::new();
        $pruned = [];
        $distilled = [];
        $skipped = [];
        $files = [];
        $named = [];
        foreach ($targets as $target) {
            $written = \is_array($target) ? ($target['ref'] ?? null) : null;
            $ref = \is_string($written) || \is_int($written) ? RefTag::parse((string) $written) : null;
            if ($ref === null) {
                $skipped[] = self::shown($written) . ' (not a ref)';

                continue;
            }
            $label = RefTag::label($ref);
            if (isset($named[$ref])) {
                $skipped[] = $label . ' (named twice)';

                continue;
            }
            $named[$ref] = true;

            $id = isset($idsByRef[$ref]) ? (string) $idsByRef[$ref] : null;
            if ($id !== null && ContextLedger::isUserRowKey($id)) {
                // Roadmap 3.B-4: prompts carry refs too, for Compress ranges.
                $skipped[] = $label . ' (a prompt, not a tool result)';

                continue;
            }
            $result = $id === null ? null : ($results[$id] ?? null);
            if ($id === null || $result === null) {
                $skipped[] = $label . ' (no tool result you have read has this ref)';

                continue;
            }
            $call = $calls[$id] ?? null;
            $tool = $call?->name() ?? 'tool';
            if ($policy->isProtected($tool) || $tool === self::NAME) {
                $skipped[] = $label . ' (protected: ' . $tool . ')';

                continue;
            }
            if ($ledger->isPruned($id)) {
                $skipped[] = $label . ' (already pruned)';

                continue;
            }

            $arguments = $call?->arguments() ?? [];
            $output = $result->content();
            $distillation = \is_string($target['distillation'] ?? null) ? trim($target['distillation']) : '';
            if ($distillation !== '') {
                if (\strlen($distillation) >= \strlen($output)) {
                    $skipped[] = $label . ' (the distillation is no shorter than the output)';

                    continue;
                }
                $kind = PruneKind::Distilled;
                $standIn = PrunedOutputPlaceholder::distilled($tool, $arguments, $distillation);
            } else {
                $distillation = null;
                $kind = PruneKind::Output;
                $standIn = PrunedOutputPlaceholder::for($tool, $arguments);
            }
            $saves = TokenEstimate::ofText($output) - TokenEstimate::ofText($standIn);
            if ($saves <= 0) {
                $skipped[] = $label . ' (too small to be worth a placeholder)';

                continue;
            }

            $delta = $delta->withPrune(new PruneEntry($id, $kind, $reason, PruneAuthor::Model, $saves, $distillation));
            $pruned[$tool] = ($pruned[$tool] ?? 0) + 1;
            $files[] = $arguments;
            if ($kind === PruneKind::Distilled) {
                $distilled[] = $label;
            }
        }

        return [$delta, $pruned, $distilled, $skipped, $files];
    }

    /**
     * `Pruned 4 outputs (~18.2K tokens): Read ×3, Grep ×1; files: code ×2,
     * config ×1. Distilled: r12. Skipped: r9 (protected: Task).` — the files
     * the pruned calls named, grouped by the dormant {@see Compactor}
     * (roadmap 3.B-5, DCP §13.2 P2-11), and left out when none named one.
     *
     * @param array<string, int> $pruned
     * @param list<string>       $distilled
     * @param list<string>       $skipped
     */
    private static function receipt(LedgerDelta $delta, array $pruned, array $distilled, array $skipped, string $files = ''): string
    {
        $total = array_sum($pruned);
        $byTool = [];
        foreach ($pruned as $tool => $n) {
            $byTool[] = $tool . ' ×' . $n;
        }

        return sprintf(
            'Pruned %d output%s (~%s tokens): %s%s.%s%s',
            $total,
            $total === 1 ? '' : 's',
            TokenCount::compact($delta->freedTokens()),
            implode(', ', $byTool),
            $files === '' ? '' : '; files: ' . $files,
            $distilled === [] ? '' : ' Distilled: ' . implode(', ', $distilled) . '.',
            self::skippedText($skipped),
        );
    }

    /** @param list<string> $skipped */
    private static function skippedText(array $skipped): string
    {
        return $skipped === [] ? '' : ' Skipped: ' . implode('; ', $skipped) . '.';
    }

    /** A target that is not a ref, shown bounded and on one line. */
    private static function shown(mixed $written): string
    {
        if (!\is_string($written) && !\is_int($written)) {
            return 'a target without a ref';
        }
        $text = trim(preg_replace('/\s+/', ' ', (string) $written) ?? '');

        return '"' . (mb_strlen($text) > 20 ? mb_substr($text, 0, 19) . '…' : $text) . '"';
    }
}
