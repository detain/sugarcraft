<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Width;

/**
 * Disambiguates concurrent agent labels that share a long prompt prefix.
 *
 * Task-tool prompts routinely open with the same instruction ("You are
 * auditing one library in ..."), so a batch of runs shows rows whose
 * distinguishable tail is clipped away by the 30-cell budget. This folder
 * rewrites each label of a colliding group to
 * `"<head words> … <first differing word (+1)>"`, which puts the difference
 * inside the visible window.
 *
 * The rule set is deliberately conservative:
 *
 * - only groups of >= 2 labels whose FIRST TWO WORDS are identical fold
 *   (a one-word prefix like "Audit candy-core" stays byte-identical);
 * - every member of a group must have words beyond the shared prefix, so
 *   identical labels — and truncated 2-word labels — never fold;
 * - a member keeps its plain label when folding would not shorten it.
 *
 * Pure and positional: same set in, same per-label results out regardless
 * of order; nothing is reordered and no row identity is touched, so click
 * zones and Alt-slot chords are unaffected. Applied AFTER
 * {@see \SugarCraft\Crush\Tui\Components\PaneLabel::safe()} escaping and
 * BEFORE the cell clips, at the two whole-set seams that build every
 * agent listing (dashboard entries, split-column runs).
 */
final class AgentLabelFolding
{
    /** Words two labels must share before folding is worth their row. */
    public const MIN_COMMON_WORDS = 2;

    /** Leading shared words kept verbatim in a folded label. */
    public const HEAD_WORDS = 2;

    /** Words taken from the first difference onward ("+ up to ~1 more"). */
    public const TAIL_WORDS = 2;

    private const ELLIPSIS = '…';

    private function __construct()
    {
    }

    /**
     * Fold a positional list of display labels. Labels outside any colliding
     * group — and every label when fewer than two exist — return unchanged.
     *
     * @param list<string> $labels
     *
     * @return list<string>
     */
    public static function fold(array $labels): array
    {
        if (count($labels) < 2) {
            return $labels;
        }

        $words = array_map(self::words(...), $labels);
        $groups = [];
        foreach ($words as $i => $member) {
            if (count($member) < self::MIN_COMMON_WORDS) {
                continue;
            }
            $groups[implode("\x00", \array_slice($member, 0, self::MIN_COMMON_WORDS))][] = $i;
        }

        $folded = $labels;
        foreach ($groups as $members) {
            self::foldGroup($members, $words, $folded);
        }

        return $folded;
    }

    /**
     * Rewrite the operation of each freshly-built display state to its
     * folded label, positionally. Only ever called on states constructed by
     * the same seam (dashboard entries, split-column runs) — the fold must
     * never reach an object the rest of the model reads back.
     *
     * @param array<array-key, AgentDisplayState> $states
     *
     * @return array<array-key, AgentDisplayState>
     */
    public static function apply(array $states): array
    {
        $labels = [];
        foreach ($states as $state) {
            $labels[] = $state->operation;
        }

        $folded = self::fold($labels);
        foreach (array_keys($states) as $i => $key) {
            $states[$key]->operation = $folded[$i];
        }

        return $states;
    }

    /**
     * @param list<int>    $members indices into $words
     * @param list<list<string>> $words
     * @param list<string> $folded  in-out label list
     */
    private static function foldGroup(array $members, array $words, array &$folded): void
    {
        if (count($members) < 2) {
            return;
        }

        $prefix = self::sharedPrefix(array_map(static fn (int $i): array => $words[$i], $members));
        if (count($prefix) < self::MIN_COMMON_WORDS) {
            return;
        }

        // Every member needs words beyond the prefix: an exact duplicate,
        // or a label that is only the prefix, has nothing to disambiguate
        // with — the whole group then stays as the screen already shows it.
        foreach ($members as $i) {
            if (count($words[$i]) <= count($prefix)) {
                return;
            }
        }

        foreach ($members as $i) {
            $candidate = implode(' ', \array_slice($words[$i], 0, self::HEAD_WORDS))
                . ' ' . self::ELLIPSIS . ' '
                . implode(' ', \array_slice($words[$i], count($prefix), self::TAIL_WORDS));
            if (Width::string($candidate) < Width::string($folded[$i])) {
                $folded[$i] = $candidate;
            }
        }
    }

    /**
     * Longest word prefix shared by every member list.
     *
     * @param list<list<string>> $members
     *
     * @return list<string>
     */
    private static function sharedPrefix(array $members): array
    {
        $shortest = min(array_map(\count(...), $members));
        $prefix = [];
        for ($w = 0; $w < $shortest; $w++) {
            $word = $members[0][$w];
            foreach ($members as $member) {
                if ($member[$w] !== $word) {
                    return $prefix;
                }
            }
            $prefix[] = $word;
        }

        return $prefix;
    }

    /**
     * @return list<string>
     */
    private static function words(string $label): array
    {
        $parts = preg_split('/\s+/u', trim($label), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }
}
