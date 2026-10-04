<?php

declare(strict_types=1);

namespace SugarCraft\Crush\RepoMap;

use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Renders ranked repo-map entries ({@see SymbolGraph::rankedEntries()}) as the
 * compact outline a model reads, and sizes it to a token budget by binary
 * search (roadmap 5.5-3).
 *
 * It mirrors Aider's `RepoMap.to_tree()` / `render_tree()` and
 * `get_ranked_tags_map_uncached()`:
 *
 *     src/Chat.php:
 *     ⋮
 *     │final class Chat implements Model
 *     ⋮
 *     │    public function update(Msg $msg): array
 *     ⋮
 *
 * Files appear in path order. Each shows its selected definition lines plus
 * the header line of the class-like that encloses each one (from the tag's
 * `scope`), every shown line prefixed `│`, every elided run collapsed to one
 * `⋮`, and every output line clipped to {@see MAX_LINE_CHARS} characters. A
 * bare entry (a file with no selected definitions) is its path alone.
 *
 * THE BUDGET SEARCH. {@see fit()} binary-searches HOW MANY of the ranked
 * entries to render, starting at `budget / 25` entries, and keeps the largest
 * rendering whose {@see TokenEstimate::ofText()} estimate fits; it stops early
 * once a fitting rendering is within {@see ACCEPTABLE_SHORTFALL} of the
 * budget. WHERE THIS DIFFERS: Aider also accepts a tree up to 15% OVER the
 * budget. Here the budget is a ceiling — a map the caller sized for a prompt
 * slot or a tool result never overruns it — so the 15% tolerance applies on
 * the under side only.
 *
 * NO FILE I/O HERE. Source lines come from the `$lines` closure the caller
 * supplies, so the read (and the containment decision for it) stays with the
 * caller that knows the checkout; a closure that cannot produce a line falls
 * back to the tag's own `type name`.
 */
final class RepoMapRenderer
{
    public const MAX_LINE_CHARS = 100;

    /** Stop the search once a fitting map is at least 85% of the budget. */
    public const ACCEPTABLE_SHORTFALL = 0.15;

    /** Aider's first guess: one entry per 25 tokens of budget. */
    public const TOKENS_PER_ENTRY_GUESS = 25;

    private const SCOPE_TYPES = [Tag::TYPE_CLASS, Tag::TYPE_INTERFACE, Tag::TYPE_TRAIT, Tag::TYPE_ENUM];

    /** @var array<string, list<string>> memoized source lines per file */
    private array $lineCache = [];

    /**
     * @param \Closure(string): list<string> $lines        root-relative path => its source lines, 0-based
     * @param array<string, list<Tag>>       $tagsByFile   every tag the map was built from, for scope headers
     */
    private function __construct(
        private readonly \Closure $lines,
        private readonly array $tagsByFile,
    ) {
    }

    /**
     * @param \Closure(string): list<string> $lines
     * @param array<string, list<Tag>>       $tagsByFile
     */
    public static function new(\Closure $lines, array $tagsByFile = []): self
    {
        return new self($lines, $tagsByFile);
    }

    /**
     * The largest rendering of a prefix of $entries whose token estimate is at
     * most $maxTokens, or '' when not even one entry fits.
     *
     * @param list<array{path: string, tag: ?Tag}> $entries best first
     */
    public function fit(array $entries, int $maxTokens): string
    {
        $count = \count($entries);
        if ($count === 0 || $maxTokens <= 0) {
            return '';
        }

        // Every rendered entry costs at least one token (two definitions on
        // one source line, which share it, are the rare exception), so no
        // prefix much longer than the budget can fit: searching past it only rendered (and read
        // the source of) thousands of entries that could never be kept — on
        // a 1,700-file tree, every file's lines held in memory at once.
        $lower = 0;
        $upper = \min($count, $maxTokens);
        $middle = \min(\intdiv($maxTokens, self::TOKENS_PER_ENTRY_GUESS), $count);
        $best = '';
        $bestTokens = 0;

        while ($lower <= $upper) {
            $tree = $this->render(\array_slice($entries, 0, $middle));
            $tokens = TokenEstimate::ofText($tree);

            if ($tokens <= $maxTokens && $tokens > $bestTokens) {
                $best = $tree;
                $bestTokens = $tokens;
                if (($maxTokens - $tokens) / $maxTokens < self::ACCEPTABLE_SHORTFALL) {
                    break;
                }
            }

            if ($tokens < $maxTokens) {
                $lower = $middle + 1;
            } else {
                $upper = $middle - 1;
            }
            $middle = \intdiv($lower + $upper, 2);
        }

        return $best;
    }

    /**
     * Every entry rendered, grouped by file in path order.
     *
     * @param list<array{path: string, tag: ?Tag}> $entries
     */
    public function render(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        // path => [line => true] for tagged files; bare files map to null.
        $files = [];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            $tag = $entry['tag'];
            if ($tag === null) {
                $files[$path] ??= null;

                continue;
            }
            $files[$path] ??= [];
            $files[$path][$tag->line] = $tag;
        }
        \ksort($files, \SORT_STRING);

        $out = [];
        foreach ($files as $path => $tags) {
            $path = (string) $path;
            $out[] = '';
            if ($tags === null) {
                $out[] = $path;

                continue;
            }
            $out[] = $path . ':';
            foreach ($this->outline($path, $tags) as $line) {
                $out[] = $line;
            }
        }

        $clipped = \array_map(
            static fn (string $line): string => \mb_substr($line, 0, self::MAX_LINE_CHARS, 'UTF-8'),
            $out,
        );

        return \implode("\n", $clipped) . "\n";
    }

    /**
     * One file's definition lines and their enclosing headers, with elided
     * runs collapsed to `⋮`.
     *
     * @param array<int, Tag> $tags line => tag
     * @return list<string>
     */
    private function outline(string $path, array $tags): array
    {
        $source = $this->sourceLines($path);

        // line => fallback text when the source cannot supply that line.
        $shown = [];
        foreach ($tags as $line => $tag) {
            $shown[$line] = $tag->type . ' ' . $tag->name;
            $header = $this->scopeHeader($path, $tag);
            if ($header !== null) {
                $shown[$header->line] ??= $header->type . ' ' . $header->name;
            }
        }
        \ksort($shown);

        $out = [];
        $previous = 0;
        foreach ($shown as $line => $fallback) {
            if ($line !== $previous + 1) {
                $out[] = '⋮';
            }
            $text = $source[$line - 1] ?? null;
            $out[] = '│' . \rtrim(\is_string($text) && \trim($text) !== '' ? $text : $fallback);
            $previous = $line;
        }
        if ($source === [] || $previous < \count($source)) {
            $out[] = '⋮';
        }

        return $out;
    }

    /** The class-like definition enclosing $tag in its file, nearest above it. */
    private function scopeHeader(string $path, Tag $tag): ?Tag
    {
        if ($tag->scope === '') {
            return null;
        }

        $best = null;
        foreach ($this->tagsByFile[$path] ?? [] as $candidate) {
            if (
                $candidate->isDefinition()
                && $candidate->name === $tag->scope
                && \in_array($candidate->type, self::SCOPE_TYPES, true)
                && $candidate->line <= $tag->line
                && ($best === null || $candidate->line > $best->line)
            ) {
                $best = $candidate;
            }
        }

        return $best === null || $best->line === $tag->line ? null : $best;
    }

    /** @return list<string> */
    private function sourceLines(string $path): array
    {
        if (!isset($this->lineCache[$path])) {
            $this->lineCache[$path] = \array_values(($this->lines)($path));
        }

        return $this->lineCache[$path];
    }
}
