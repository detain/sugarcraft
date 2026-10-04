<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning\Strategies;

use SugarCraft\Crush\Context\Pruning\CanonicalArguments;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\PruningStrategy;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Newest wins (roadmap 2.3; DCP's deduplication, Cline's duplicate file
 * reads): the output of a call that a NEWER call answered again is pruned.
 *
 * "Answered again" is either an identical call — same tool, same canonical
 * arguments ({@see CanonicalArguments::key()}) — or, for `Read`, a newer read
 * of the WHOLE file ({@see CanonicalArguments::readsWholeFile()}), which holds
 * everything an older read of any range of it held. A newer RANGED read
 * supersedes nothing but its identical twin.
 *
 * Only a successful newer call supersedes: a failed one answered nothing. A
 * protected tool's output is never pruned ({@see PruningPolicy::isProtected()}),
 * and a result under a call id that appears twice is never proposed — one
 * ledger key would prune both. Deterministic, like every strategy.
 */
final class DuplicateCallStrategy implements PruningStrategy
{
    public static function new(): self
    {
        return new self();
    }

    public function propose(array $messages, ContextLedger $ledger, PruningPolicy $policy): LedgerDelta
    {
        $calls = ContextProjector::callsById($messages);
        $results = [];
        $seen = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $results[] = $message;
                $seen[$message->toolCallId()] = ($seen[$message->toolCallId()] ?? 0) + 1;
            }
        }

        $keys = [];
        $wholeReads = [];
        $delta = LedgerDelta::new();
        foreach (array_reverse($results) as $result) {
            $id = $result->toolCallId();
            $call = $calls[$id] ?? null;
            if ($call === null || $id === '' || ($seen[$id] ?? 0) > 1 || $policy->isProtected($call->name())) {
                continue;
            }
            $key = CanonicalArguments::key($call);
            $path = $call->name() === 'Read' ? CanonicalArguments::path($call) : null;
            $superseded = isset($keys[$key]) || ($path !== null && isset($wholeReads[$path]));

            if ($superseded && !$ledger->isPruned($id)) {
                $saves = TokenEstimate::ofText($result->content())
                    - TokenEstimate::ofText(PrunedOutputPlaceholder::for($call->name(), $call->arguments()));
                if ($saves > 0) {
                    $delta = $delta->withPrune(new PruneEntry($id, PruneKind::Output, PruneReason::Duplicate, PruneAuthor::Strategy, $saves));
                }
            }

            if (!$result->isError()) {
                $keys[$key] = true;
                if ($path !== null && CanonicalArguments::readsWholeFile($call)) {
                    $wholeReads[$path] = true;
                }
            }
        }

        return self::oldestFirst($delta);
    }

    /** The delta's entries in conversation order, so two runs agree byte for byte. */
    private static function oldestFirst(LedgerDelta $delta): LedgerDelta
    {
        $ordered = LedgerDelta::new();
        foreach (array_reverse($delta->prunes) as $entry) {
            $ordered = $ordered->withPrune($entry);
        }

        return $ordered;
    }
}
