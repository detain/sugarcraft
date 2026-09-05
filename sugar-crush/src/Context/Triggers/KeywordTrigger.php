<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Triggers;

use InvalidArgumentException;

/**
 * Fires when one of its words appears as a whole word in the user prompt.
 *
 * DESIGN SOURCE: prompt_expand.md §4.20, which records upstream Claude Code's
 * surviving anchored matcher /\bultrathink\b/i and the sentence that the
 * earlier unanchored `includes()` matched "rethinking" for the word "think".
 * This is a SugarCraft architecture type, not a port — charmbracelet/crush
 * has no KeywordTrigger symbol; the repo's "Mirrors charmbracelet/…"
 * convention does not apply. It is written to replace the crude token scan in
 * {@see \SugarCraft\Crush\Skills\Skill::matchesPrompt()} (lowercased
 * description tokens, byte-length filter, unanchored stripos) in a later
 * step; rewiring `Skill` is explicitly NOT this step's business.
 *
 * BOUNDARY SEMANTICS — exactly what the matcher does and does not accept.
 * Each word is compiled to `/\b<preg_quote(word)>\b/iu`. Empirically pinned
 * on this tree's PHP (probe-verified, not assumed):
 * - "think" MATCHES  "re-think", "think!", "Think.", "we should think".
 * - "think" does NOT match "rethinking", "thinking", "bethinks", "re_think"
 *   (underscore is a word constituent, so `re_think` is one whole word).
 * - The `u` modifier matters twice over: it validates the subject as UTF-8
 *   (a malformed byte string simply fails to match instead of erroring), and
 *   on this build it gives `/\b/` Unicode word constituents and full Unicode
 *   case folding — "café" matches "CAFÉ". Consequence documented as designed
 *   behaviour: with Unicode-aware boundaries, letters adjacent to non-ASCII
 *   letters fuse into one word, so "uncafé" does NOT match "café" — the same
 *   whole-word discipline the ASCII cases pin, extended to non-ASCII text.
 *   Without `u`, `é` would be a boundary character and "café" could not match
 *   its own standalone word at all. `u` is therefore required, not optional.
 * - Caveat kept honest: a word whose edge characters are non-word characters
 *   (e.g. "c++") can never fire, because `/\b/` demands a word constituent on
 *   exactly one side of the anchor. Words are expected to be alphanumeric
 *   tokens; this is the same constraint upstream's /\b…\b/i form carries.
 *
 * LIFETIME DEDUP — key, scope, fork semantics (derived here; §7.5 and §9.7
 * name dedup but specify no mechanism, mirroring how
 * {@see \SugarCraft\Crush\Skills\SkillPathNudge} derives its own
 * name-keyed `markAnnounced()` ledger).
 * - KEY: the matched word, `mb_strtolower()`-normalised. Dedup is per word,
 *   not per trigger, so a trigger over ["think","reflect"] fires once for
 *   "think" and still fires later when the user first says "reflect".
 * - SCOPE: the object instance — one ledger per trigger value, living for
 *   that instance's lifetime (in P6.S2 the loader will hold one instance, so
 *   instance scope IS process scope there). The ledger is transient runtime
 *   state, not value state, and is deliberately outside the immutable face:
 *   `matches()`/`matchedWords()` never touch it; only `fires()` reads and
 *   extends it. This follows the `SkillPathNudge` precedent of a mutable
 *   announced-ledger on an otherwise controlled object.
 * - FORK: PHP `clone` copies the array ledger by value, so a forked trigger
 *   starts knowing everything the parent had announced (union-on-fork in
 *   spirit); and `mergeFiredFrom()` unions a sibling's ledger back in for
 *   when parent and child fire independently and results rejoin.
 * - `withWords()` yields a NEW trigger value with a FRESH ledger: changing
 *   the words changes the trigger's identity, so old announcements do not
 *   carry over.
 */
final class KeywordTrigger implements Trigger
{
    /** Matched-word dedup ledger, keyed by lowercased word. Runtime state, not value state. */
    private array $fired = [];

    /** Memoised compiled patterns, keyed by the original word. */
    private array $compiled = [];

    /**
     * @param list<string> $words Whole-word tokens to look for; non-empty, none blank.
     */
    private function __construct(public readonly array $words)
    {
    }

    /**
     * @param list<string> $words Whole-word tokens; the old byte-length filter from the crude matcher is deliberately NOT inherited.
     *
     * @throws InvalidArgumentException if the list is empty or holds a non-string/blank entry.
     */
    public static function new(array $words): self
    {
        if ($words === []) {
            throw new InvalidArgumentException('KeywordTrigger requires at least one word; an empty trigger can never fire.');
        }

        $clean = [];
        foreach (array_values($words) as $i => $word) {
            if (!is_string($word)) {
                throw new InvalidArgumentException(sprintf('KeywordTrigger word %d must be a string, %s given.', $i, get_debug_type($word)));
            }
            if (trim($word) === '') {
                throw new InvalidArgumentException(sprintf('KeywordTrigger word %d must not be blank.', $i));
            }
            $clean[] = $word;
        }

        return new self($clean);
    }

    /**
     * A new trigger value over these words — fresh ledger, see the fork notes in the class docblock.
     *
     * @param list<string> $words
     */
    public function withWords(array $words): self
    {
        return $this->mutate($words);
    }

    /**
     * @param list<string> $words
     */
    private function mutate(array $words): self
    {
        return self::new($words);
    }

    /**
     * Pure whole-word test: does any word appear as a whole word (case- and
     * Unicode-case-insensitive) in the prompt? Never touches the ledger.
     */
    public function matches(string $prompt): bool
    {
        foreach ($this->words as $word) {
            if (preg_match($this->pattern($word), $prompt) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The words that match, in declaration order. Pure; never touches the ledger.
     *
     * @return list<string>
     */
    public function matchedWords(string $prompt): array
    {
        $hits = [];
        foreach ($this->words as $word) {
            if (preg_match($this->pattern($word), $prompt) === 1) {
                $hits[] = $word;
            }
        }

        return $hits;
    }

    /**
     * Whole-word match with lifetime dedup: returns true only when at least
     * one matching word is announced here for the first time, and records
     * every matching word either way. Repeated prompts that only re-hit
     * already-fired words return false.
     */
    public function fires(string $prompt): bool
    {
        $fresh = false;

        foreach ($this->matchedWords($prompt) as $word) {
            $key = mb_strtolower($word);
            if (!isset($this->fired[$key])) {
                $fresh = true;
            }
            $this->fired[$key] = true;
        }

        return $fresh;
    }

    /**
     * Snapshot of the dedup ledger: the lowercased words already announced.
     *
     * @return list<string>
     */
    public function firedWords(): array
    {
        return array_keys($this->fired);
    }

    /**
     * Union-merge another trigger's fired words into this one — the
     * rejoin-half of fork semantics, in the spirit of
     * {@see \SugarCraft\Crush\Skills\SkillPathNudge::markAnnounced()}.
     */
    public function mergeFiredFrom(self $other): void
    {
        foreach ($other->firedWords() as $word) {
            $this->fired[$word] = true;
        }
    }

    /**
     * @throws InvalidArgumentException unreachable via the public face: the constructor path validates every word.
     */
    private function pattern(string $word): string
    {
        if (!isset($this->compiled[$word])) {
            $quoted = preg_quote($word, '/');
            if ($quoted === false) {
                throw new InvalidArgumentException(sprintf('KeywordTrigger word "%s" could not be compiled.', $word));
            }
            $this->compiled[$word] = '/\b' . $quoted . '\b/iu';
        }

        return $this->compiled[$word];
    }
}
