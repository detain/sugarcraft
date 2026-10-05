<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Compaction;

use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\BuiltIn\MemoryTool;

/**
 * The memory flush before a compaction (roadmap 2.11, OpenClaw's pre-compaction
 * flush): one silent, tool-enabled step in which the model may save to memory
 * whatever is worth keeping beyond the session, before the summary replaces
 * the rows it would have read it from.
 *
 * WHY. A compaction summary is written for the conversation's next step: it
 * keeps what the task needs and drops the rest. A fact that matters to the
 * NEXT session — a user preference, a correction, a decision and why it was
 * taken, how the project builds — is exactly what it drops, and the memory
 * store ({@see MemoryTool}, roadmap 5.1) is where such a fact belongs. This is
 * the last moment the model can still read it.
 *
 * SILENT. The flush's request and reply never join the conversation: they are
 * not in the transcript, not shown, and not sent again. What persists is what
 * the Memory tool wrote. Its usage is billed like any step.
 *
 * CACHED. The request is the request the model was last sent — same system
 * prompt, same tool schemas, same history through the same ledger — plus one
 * user row ({@see INSTRUCTION}), the shape the step summary uses
 * ({@see StepSummarizer}), so its prefix is one the provider already holds.
 * Every tool stays advertised; this hook, registered on the flush's own copy
 * of the PreToolUse chain, refuses every call but the Memory tool's, and the
 * flush answers every permission question with no — nobody is asked anything
 * during a step nobody sees.
 *
 * ONCE PER COMPACTION CYCLE. The engine runs it right before a step summary it
 * is about to write, and not again until that summary has landed: a summary
 * that fails and is retried on a later step does not flush twice.
 */
final readonly class MemoryFlush implements HookInterface
{
    public const NAME = 'memory-flush';

    /** The flush's one user row. */
    public const INSTRUCTION = 'The conversation above is about to be compacted: the harness will replace its older part '
        . 'with a summary, and anything the summary does not carry will be lost. Before that, save what is worth '
        . 'keeping beyond this session with the Memory tool — durable facts about the project, the user\'s '
        . 'preferences and corrections, decisions and why they were taken — using "save", or "str_replace" to '
        . 'update a note that already exists. Do not save task progress or anything the summary will carry, and '
        . 'prefer saving nothing to saving noise. Only the Memory tool runs here. If there is nothing to save, '
        . 'reply NONE and call no tool.';

    /** What a call to any other tool is told during the flush. */
    public const REFUSAL = 'Only the Memory tool runs during the pre-compaction memory flush.';

    public static function new(): self
    {
        return new self();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '.*';
    }

    public function execute(HookContext $context): HookResult
    {
        return $context->toolName === MemoryTool::NAME
            ? HookResult::allow()
            : HookResult::deny(self::REFUSAL);
    }

    /**
     * Whether $tools can flush at all: no Memory tool, nowhere to write.
     *
     * @param iterable<mixed> $tools
     */
    public static function available(iterable $tools): bool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof MemoryTool) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the flush step on $runtime — whose hook chain carries this hook —
     * over $app's conversation. Returns how many Memory calls succeeded. A
     * flush that fails costs the compaction nothing: it throws only what the
     * caller chooses to let through, and the engine swallows it.
     *
     * @param \Closure(AssistantMessage): void $onAssistant bills the step's response
     */
    public static function run(
        Runtime $runtime,
        App $app,
        \Closure $onAssistant,
        ?callable $onProgress = null,
        ?callable $onHeartbeat = null,
    ): int {
        $request = $app->withMessages([...$app->messages, new UserMessage(self::INSTRUCTION)]);
        $refuseAsks = static fn (): bool => false;

        $saved = 0;
        foreach ($runtime->run($request, null, $refuseAsks, null, $onProgress, $onHeartbeat) as $message) {
            if ($message instanceof AssistantMessage) {
                $onAssistant($message);
            } elseif ($message instanceof ToolResultMessage && !$message->isError()) {
                $saved++;
            }
        }

        return $saved;
    }
}
