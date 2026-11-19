<?php

declare(strict_types=1);

/**
 * Path-style matching with the Sahilm scorer. Separators (/, _, -, .) and
 * camelCase boundaries earn bonuses, so a query that lines up with word starts
 * ranks above one whose characters are scattered. Sahilm requires the whole
 * query to land, in order.
 *
 * FuzzyMatcherFactory picks the matcher by name; the scoring weights stay the
 * canonical ones.
 *
 *   php examples/sahilm-paths.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Fuzzy\Matcher\FuzzyMatcherFactory;
use SugarCraft\Fuzzy\SahilmScoring;

$matcher = FuzzyMatcherFactory::named('sahilm', SahilmScoring::canonical());

$files = [
    'src/config/Config.php',
    'src/cache/clear_file.php',
    'docs/cfg-notes.md',
    'tests/ConfigGenerationTest.php',
    'README.md',
];

foreach (['cfg', 'conf'] as $query) {
    echo "query \"{$query}\"\n";
    foreach ($matcher->matchAll($query, $files) as $result) {
        printf("  %4d  %-30s %s\n", $result->score, $result->haystack, json_encode($result->indices()));
    }
    echo "\n";
}

// Sahilm is all-or-nothing on the query: a query whose characters do not all
// appear in order gets no results at all, unlike local alignment.
echo 'zzz matches: ' . count($matcher->matchAll('zzz', $files)) . "\n";
