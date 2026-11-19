<?php

declare(strict_types=1);

/**
 * A type-to-filter picker: every character typed must land, in order. Local
 * alignment alone would accept "b" inside "abcdef" for the query "bxz"; full-query
 * mode rejects the candidate unless the whole query is a subsequence of it.
 *
 *   php examples/full-query-picker.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

$loose = new SmithWatermanMatcher();
$picker = SmithWatermanMatcher::forFiltering();

$commands = ['git status', 'git stash', 'go test ./...', 'make build', 'gst-helper'];

foreach (['bxz', 'gst'] as $query) {
    echo "query \"{$query}\"\n";
    printf("  local alignment:  %s\n", describe($loose->match($query, 'abcdef')));
    foreach ($commands as $command) {
        $result = $picker->match($query, $command);
        if ($result === null) {
            continue;
        }
        printf("  %-14s score %2d, indices %s\n", $command, $result->score, json_encode($result->indices()));
    }
    echo "\n";
}

function describe(?\SugarCraft\Fuzzy\MatchResult $result): string
{
    return $result === null ? 'no match' : "score {$result->score}";
}
