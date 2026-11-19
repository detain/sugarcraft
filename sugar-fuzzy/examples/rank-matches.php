<?php

declare(strict_types=1);

/**
 * Rank candidates against a query and show which characters matched. This is
 * the core of a filter-as-you-type list: matchAll() orders the survivors by
 * score, and each MatchResult carries the code-point indices a highlighter
 * needs to paint the matched characters.
 *
 *   php examples/rank-matches.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Fuzzy\Highlighter;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

$candidates = ['apple', 'banana', 'cabbage', 'apricot', 'grape'];
$matcher = new SmithWatermanMatcher();
$highlighter = new Highlighter();

foreach (['ab', 'ap'] as $query) {
    echo "query \"{$query}\"\n";
    foreach ($matcher->matchAll($query, $candidates) as $result) {
        // Brackets stand in for a bold or colored style; a TUI passes an ANSI styler.
        printf(
            "  %3d  %s\n",
            $result->score,
            $highlighter->highlight($result, static fn (string $run): string => "[{$run}]"),
        );
    }
    echo "\n";
}

// A limit keeps only the best N; a negative limit means unlimited.
$top = $matcher->matchAll('a', $candidates, limit: 2);
echo 'top two for "a": ' . implode(', ', array_map(static fn ($r) => $r->haystack, $top)) . "\n";
