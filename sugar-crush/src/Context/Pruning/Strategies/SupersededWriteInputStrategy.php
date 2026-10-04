<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning\Strategies;

use SugarCraft\Crush\Context\Pruning\CanonicalArguments;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PrunedInputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\PruningStrategy;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * A superseded write's content is elided (roadmap 2.3, DCP's supersede-writes):
 * the `content` argument of a successful `Write` is sent as a placeholder once
 * a LATER successful `Write` of the same file, or a later successful read of
 * the whole file, exists — either one holds what the file says now, so the
 * old content is the largest thing in the request that nobody needs.
 *
 * It prunes the call's INPUT ({@see PruneKind::WriteContent}): the receipt the
 * write answered with is kept, and `Write`'s protection
 * ({@see PruningPolicy::isProtected()}) covers that output, not this.
 */
final class SupersededWriteInputStrategy implements PruningStrategy
{
    public static function new(): self
    {
        return new self();
    }

    public function propose(array $messages, ContextLedger $ledger, PruningPolicy $policy): LedgerDelta
    {
        $failed = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage && $message->isError()) {
                $failed[$message->toolCallId()] = true;
            }
        }

        // Every call in conversation order, newest last.
        $ordered = [];
        $count = [];
        foreach ($messages as $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }
            foreach ($message->toolCalls() ?? [] as $call) {
                if ($call instanceof ToolCall) {
                    $ordered[] = $call;
                    $count[$call->id()] = ($count[$call->id()] ?? 0) + 1;
                }
            }
        }

        $superseded = [];
        $entries = [];
        foreach (array_reverse($ordered) as $call) {
            $id = $call->id();
            $path = CanonicalArguments::path($call);
            if ($path === null || isset($failed[$id]) || $call->argumentsError() !== null) {
                continue;
            }
            if ($call->name() === 'Write') {
                if (isset($superseded[$path]) && $id !== '' && ($count[$id] ?? 0) === 1 && !$ledger->isPruned($id)
                    && is_string($call->arguments()['content'] ?? null)) {
                    $saves = PrunedInputPlaceholder::savedTokens(PruneKind::WriteContent, $call->arguments());
                    if ($saves > 0) {
                        $entries[] = new PruneEntry($id, PruneKind::WriteContent, PruneReason::Superseded, PruneAuthor::Strategy, $saves);
                    }
                }
                $superseded[$path] = true;
            } elseif (CanonicalArguments::readsWholeFile($call)) {
                $superseded[$path] = true;
            }
        }

        $delta = LedgerDelta::new();
        foreach (array_reverse($entries) as $entry) {
            $delta = $delta->withPrune($entry);
        }

        return $delta;
    }
}
