<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents\Live;

/**
 * The lineage fold over {@see AgentRunCards}: the same cards on disk, read as
 * a parent → children tree instead of a flat list (roadmap A3).
 *
 * TaskTool stamps every card it writes with the launcher's identity
 * (`'parent' => $this->parentAgentId ?? AgentRunCards::MAIN`), so the
 * hierarchy is already on disk from the moment a run starts — this class only
 * folds it, it never writes or edits a card. A nested delegation's launcher is
 * the delegating run itself ({@see \SugarCraft\Code\Tools\BuiltIn\TaskTool::nestedFor()}),
 * which is what makes the tree deeper than one level.
 *
 * Nothing is dropped and nothing prints twice:
 *
 *  - a card whose parent has a card here rides under it;
 *  - a card whose parent names no card here is an ORPHAN — it rides at the top
 *    level flagged loudly, because a vanished launcher (a swept background
 *    daemon, a deleted mailbox tree) must never take its still-listed
 *    delegations out of sight;
 *  - cards only reachable through a parent cycle — forged or corrupt lineage —
 *    can hang a naive walk, so the fold marks them at the top level and moves
 *    on instead.
 *
 * Order is stable: {@see AgentRunCards::runs()} sorts by (startedAt, runId)
 * and the fold preserves it among siblings and among top-level rows, so the
 * same cards render the same bytes every time.
 *
 * UNTRUSTED ON READ, like the cards: the fold's callers print these rows, so
 * every field crosses the same shape-check {@see AgentRunCards::runs()}
 * applies, and rendering goes through the transcript-safe one-line filter.
 */
final class AgentRunStatus
{
    /** The fold is a namespace of statics; there is nothing to hold. */
    private function __construct()
    {
    }

    /**
     * Every card in the session's tree, pre-order flattened.
     *
     * Each row is `['card' => array, 'depth' => int, 'orphan' => bool,
     * 'cycle' => bool]`: depth counts indents (0 = top level), orphan marks a
     * row whose parent names no card here, cycle marks a row reachable only
     * through a parent cycle. Rows come out in render order, so a caller
     * printing them verbatim gets the whole tree with no sorting of its own.
     *
     * @param  string             $scope  the session whose cards to read
     * @param  string|null        $parent the launcher treated as the root, defaults to the session's own agent
     * @param  string|null        $root   the cards tree, defaults to {@see AgentInbox::defaultRoot()}
     * @return list<array{card: array<string, mixed>, depth: int, orphan: bool, cycle: bool}>
     */
    public static function tree(string $scope, ?string $parent = null, ?string $root = null): array
    {
        $parent ??= AgentRunCards::MAIN;
        $cards = AgentRunCards::runs($scope, $root);

        $known = [];
        $children = [];
        foreach ($cards as $card) {
            $known[$card['runId']] = true;
            $children[$card['parent']][] = $card;
        }

        $rows = [];
        $seen = [];

        // Top level in card order: the requested launcher's own runs, and
        // every card whose parent has no card here (orphan, loudly, never
        // silently dropped).
        foreach ($cards as $card) {
            $direct = $card['parent'] === $parent;
            if (!$direct && isset($known[$card['parent']])) {
                continue;
            }

            self::emit($card, 0, !$direct, $children, $seen, $rows);
        }

        // Whatever is left is reachable only through a cycle. The walk marks
        // each such island's entry at the top level and rides its reachable
        // branches once, so forged lineage renders instead of hanging.
        foreach ($cards as $card) {
            if (isset($seen[$card['runId']])) {
                continue;
            }

            self::emit($card, 0, false, $children, $seen, $rows, cycle: true);
        }

        return $rows;
    }

    /**
     * Push one card, then each of its children one level deeper, never twice.
     *
     * @param  array<string, mixed>                                                    $card
     * @param  array<string, list<array<string, mixed>>>                               $children
     * @param  array<string, true>                                                     $seen
     * @param  list<array{card: array<string, mixed>, depth: int, orphan: bool, cycle: bool}> $rows
     */
    private static function emit(
        array $card,
        int $depth,
        bool $orphan,
        array $children,
        array &$seen,
        array &$rows,
        bool $cycle = false,
    ): void {
        $seen[$card['runId']] = true;
        $rows[] = ['card' => $card, 'depth' => $depth, 'orphan' => $orphan, 'cycle' => $cycle];

        foreach ($children[$card['runId']] ?? [] as $child) {
            if (isset($seen[$child['runId']])) {
                continue;
            }

            self::emit($child, $depth + 1, false, $children, $seen, $rows);
        }
    }
}
