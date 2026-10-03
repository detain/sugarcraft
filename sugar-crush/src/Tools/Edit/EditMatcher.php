<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Finds where an Edit's `old_string` lands in a file, one stage at a time,
 * stopping at the first stage that finds it in exactly one place (audit 0.11,
 * roadmap 3.I-1). The chain, strictest first:
 *
 *  1. `exact` — the bytes as given. Any number of occurrences is returned;
 *     uniqueness is the caller's rule ({@see \SugarCraft\Crush\Tools\BuiltIn\Edit}
 *     honours `replace_all`).
 *  2. `uniform-indent` — the roadmap's "indentation-flexible" stage: the same
 *     lines shifted by ONE indentation delta, `new_string` shifted alike
 *     ({@see IndentFlexibleMatcher}).
 *  3. `line-trimmed` — every line equal once leading and trailing whitespace
 *     is ignored ({@see LineTrimmedMatcher}).
 *  4. `whitespace-normalised` — runs of spaces and tabs inside a line treated
 *     as one space ({@see WhitespaceNormalisedMatcher}).
 *  5. `block-anchor` — first and last lines exact, the lines between at least
 *     {@see BlockAnchorMatcher::THRESHOLD} alike ({@see BlockAnchorMatcher}).
 *  6. `unicode-normalised` — curly quotes, dashes, odd spaces and NFKC
 *     compatibility forms folded ({@see UnicodeNormalisedMatcher}).
 *
 * The roadmap lists line-trimmed BEFORE indentation-flexible; it runs after it
 * here because every uniform shift is also a line-trimmed match, and the
 * stricter stage must get first refusal or its stronger promise — relative
 * indentation intact, `new_string` moved by the proven delta — is never the
 * one reported.
 *
 * EVERY STAGE AFTER `exact` IS UNIQUE-ONLY, even under `replace_all`: a
 * forgiving stage that picked one of several candidates would edit a place the
 * model never named. A stage with several candidates does not end the chain —
 * a later stage can be stricter along a different axis — but if nothing after
 * it is unique, the FIRST ambiguity is what the model is told, with the line of
 * every candidate. A unique fuzzy match that is out of all proportion to
 * `old_string` is refused ({@see isDisproportionateMatch()}).
 *
 * Null means no stage matched; {@see EditFailureHints::notFound()} explains why.
 */
final class EditMatcher
{
    public const STAGE_EXACT = 'exact';
    public const STAGE_UNIFORM_INDENT = 'uniform-indent';
    public const STAGE_LINE_TRIMMED = 'line-trimmed';
    public const STAGE_WHITESPACE = 'whitespace-normalised';
    public const STAGE_BLOCK_ANCHOR = 'block-anchor';
    public const STAGE_UNICODE = 'unicode-normalised';

    /** How each fuzzy stage's leniency reads inside a refusal sentence. */
    private const LENIENCY = [
        self::STAGE_UNIFORM_INDENT => 'with its indentation shifted',
        self::STAGE_LINE_TRIMMED => 'with each line\'s leading and trailing whitespace ignored',
        self::STAGE_WHITESPACE => 'with runs of spaces and tabs treated as one',
        self::STAGE_BLOCK_ANCHOR => 'by its first and last lines',
        self::STAGE_UNICODE => 'with quotes, dashes and Unicode forms normalised',
    ];

    /** Candidate lines a refusal lists before it says "and N more". */
    private const MAX_LISTED = 8;

    /**
     * @param list<MatchStage> $stages
     */
    private function __construct(private array $stages)
    {
    }

    public static function new(): self
    {
        return new self([
            new ExactMatcher(),
            new IndentFlexibleMatcher(),
            new LineTrimmedMatcher(),
            new WhitespaceNormalisedMatcher(),
            new BlockAnchorMatcher(),
            new UnicodeNormalisedMatcher(),
        ]);
    }

    /**
     * The stage names in the order they run.
     *
     * @return list<string>
     */
    public function stages(): array
    {
        return array_map(static fn (MatchStage $stage): string => $stage->name(), $this->stages);
    }

    public function match(string $content, string $old, string $new): ?EditMatch
    {
        if ($old === '') {
            return null;
        }

        $ambiguous = null;
        foreach ($this->stages as $stage) {
            $found = self::distinct($stage->find($content, $old, $new));
            if ($found === []) {
                continue;
            }

            if ($stage->name() === self::STAGE_EXACT) {
                return new EditMatch(self::STAGE_EXACT, $old, $new, \count($found));
            }

            if (\count($found) > 1) {
                $ambiguous ??= [$stage->name(), $found];
                continue;
            }

            $candidate = $found[0];
            $matched = $candidate->matchedIn($content);
            if ($stage->name() !== self::STAGE_UNIFORM_INDENT && self::isDisproportionateMatch($old, $matched)) {
                return self::disproportionate($stage->name(), $content, $old, $matched, $candidate, $new);
            }

            return new EditMatch(
                $stage->name(),
                $matched,
                $candidate->newString,
                1,
                $stage->name() . ' match: ' . ($candidate->detail ?? 'old_string matched only after normalisation'),
                $candidate->offset,
            );
        }

        if ($ambiguous !== null) {
            [$name, $found] = $ambiguous;
            $lines = array_map(static fn (MatchCandidate $c): int => EditFailureHints::lineAt($content, $c->offset), $found);

            return new EditMatch(
                $name,
                $old,
                $new,
                \count($found),
                null,
                null,
                sprintf(
                    '%s, it matches %d places (starting at lines %s). Include more of the surrounding lines so it '
                    . 'matches exactly one; a forgiving match is never applied to more than one place, replace_all '
                    . 'included',
                    self::LENIENCY[$name] ?? 'loosely',
                    \count($found),
                    self::listLines($lines),
                ),
                $lines,
            );
        }

        return null;
    }

    /**
     * Whether a fuzzy match is too far from `old_string` to apply.
     *
     * A forgiving stage proves that the ENDS or the SHAPE of a block agree, not
     * that the block is the one the model meant. When the matched text differs
     * from `old_string` by more than a quarter of its lines (at least two), or
     * carries more than twice — or less than half — of its non-whitespace bytes
     * (with 32 bytes of slack for short strings), the edit would replace a block
     * the model has evidently not read, so it is refused instead.
     */
    public static function isDisproportionateMatch(string $old, string $matched): bool
    {
        $oldLines = substr_count(rtrim($old, "\n"), "\n") + 1;
        $matchedLines = substr_count(rtrim($matched, "\n"), "\n") + 1;
        if (abs($matchedLines - $oldLines) > max(2, intdiv($oldLines, 4))) {
            return true;
        }

        $oldBytes = strlen((string) preg_replace('/\s+/', '', $old));
        $matchedBytes = strlen((string) preg_replace('/\s+/', '', $matched));

        return $matchedBytes > 2 * $oldBytes + 32 || $oldBytes > 2 * $matchedBytes + 32;
    }

    private static function disproportionate(
        string $stage,
        string $content,
        string $old,
        string $matched,
        MatchCandidate $candidate,
        string $new,
    ): EditMatch {
        $first = EditFailureHints::lineAt($content, $candidate->offset);
        $last = $first + substr_count(rtrim($matched, "\n"), "\n");

        return new EditMatch(
            $stage,
            $old,
            $new,
            1,
            null,
            null,
            sprintf(
                '%s, the only near match (lines %d-%d) is %d line(s) and %d non-whitespace bytes against '
                . 'old_string\'s %d line(s) and %d — too different to apply without guessing. Read those lines and '
                . 'copy the block exactly',
                self::LENIENCY[$stage] ?? 'loosely',
                $first,
                $last,
                $last - $first + 1,
                strlen((string) preg_replace('/\s+/', '', $matched)),
                substr_count(rtrim($old, "\n"), "\n") + 1,
                strlen((string) preg_replace('/\s+/', '', $old)),
            ),
            [$first],
        );
    }

    /**
     * Candidates with duplicate spans removed, in file order.
     *
     * @param list<MatchCandidate> $found
     * @return list<MatchCandidate>
     */
    private static function distinct(array $found): array
    {
        $seen = [];
        $out = [];
        foreach ($found as $candidate) {
            $key = $candidate->offset . ':' . $candidate->length;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $candidate;
            }
        }
        usort($out, static fn (MatchCandidate $a, MatchCandidate $b): int => $a->offset <=> $b->offset);

        return $out;
    }

    /**
     * @param list<int> $lines
     */
    private static function listLines(array $lines): string
    {
        $shown = \array_slice($lines, 0, self::MAX_LISTED);
        $text = implode(', ', $shown);

        return \count($lines) > \count($shown) ? $text . ' and ' . (\count($lines) - \count($shown)) . ' more' : $text;
    }
}
