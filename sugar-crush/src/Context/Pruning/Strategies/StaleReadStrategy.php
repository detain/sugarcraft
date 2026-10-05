<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning\Strategies;

use SugarCraft\Crush\Context\Pruning\CanonicalArguments;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
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
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * A read the file no longer matches is pruned (roadmap 2.3, Cline's stale
 * file reads): the output of a `Read` older than a SUCCESSFUL `Edit` or
 * `Write` of the same file ({@see CanonicalArguments::path()}) is sent as a
 * placeholder — the model would be reasoning from text that is not there.
 *
 * The protected window holds here as it does for the age rule: a read from
 * the last {@see PruningPolicy::$protectUserTurns} user turns is kept even
 * when an edit followed it, because a model mid-task edits from the read it
 * just made, and pruning it would only send it back to read again.
 */
final class StaleReadStrategy implements PruningStrategy
{
    /**
     * The tools whose success changes the file they name — every file, for
     * an `ApplyPatch` ({@see writtenPaths()}).
     */
    public const WRITERS = ['Edit', 'Write', 'ApplyPatch'];

    public static function new(): self
    {
        return new self();
    }

    public function propose(array $messages, ContextLedger $ledger, PruningPolicy $policy): LedgerDelta
    {
        $calls = ContextProjector::callsById($messages);
        $seen = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $seen[$message->toolCallId()] = ($seen[$message->toolCallId()] ?? 0) + 1;
            }
        }

        // Walk newest first, so "a later write of this path" is a set lookup.
        $written = [];
        $turns = 0;
        $stale = [];
        foreach (array_reverse($messages) as $message) {
            if (self::isUserTurn($message)) {
                $turns++;

                continue;
            }
            if (!$message instanceof ToolResultMessage) {
                continue;
            }
            $id = $message->toolCallId();
            $call = $calls[$id] ?? null;
            if ($call === null) {
                continue;
            }
            if (in_array($call->name(), self::WRITERS, true)) {
                if (!$message->isError()) {
                    foreach (self::writtenPaths($call) as $writtenPath) {
                        $written[$writtenPath] = true;
                    }
                }

                continue;
            }
            $path = CanonicalArguments::path($call);
            if ($path === null) {
                continue;
            }
            if ($call->name() !== 'Read' || !isset($written[$path]) || $turns < $policy->protectUserTurns
                || $id === '' || ($seen[$id] ?? 0) > 1 || $ledger->isPruned($id) || $policy->isProtected('Read')) {
                continue;
            }
            $saves = TokenEstimate::ofText($message->content())
                - TokenEstimate::ofText(PrunedOutputPlaceholder::for($call->name(), $call->arguments()));
            if ($saves > 0) {
                $stale[] = new PruneEntry($id, PruneKind::Output, PruneReason::Stale, PruneAuthor::Strategy, $saves);
            }
        }

        $delta = LedgerDelta::new();
        foreach (array_reverse($stale) as $entry) {
            $delta = $delta->withPrune($entry);
        }

        return $delta;
    }

    /**
     * The normalised paths a writer's call changed: its one `file_path`, or
     * every path an `ApplyPatch` patch touches (none when it does not parse —
     * the tool refused it, so nothing changed).
     *
     * @return list<string>
     */
    private static function writtenPaths(\SugarCraft\Crush\Tools\ToolCall $call): array
    {
        if ($call->name() !== 'ApplyPatch') {
            $path = CanonicalArguments::path($call);

            return $path === null ? [] : [$path];
        }

        $paths = [];
        foreach (\SugarCraft\Crush\Tools\Edit\PatchParser::paths($call->arguments()['patch'] ?? null) ?? [] as $path) {
            if (trim($path) !== '') {
                $paths[] = CanonicalArguments::normalisedPath(trim($path));
            }
        }

        return $paths;
    }

    /** A prompt that opens a user turn, as the age rule counts them. */
    private static function isUserTurn(TypedMessage $message): bool
    {
        return $message instanceof UserMessage
            && !TurnContextBlock::isTurnContext($message)
            && !CompressionBlock::isSummaryRow($message);
    }
}
