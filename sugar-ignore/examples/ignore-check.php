<?php

declare(strict_types=1);

/**
 * ignore-check — ask the ignore engine about a handful of paths.
 *
 *   php examples/ignore-check.php
 *
 * The ignore files are injected as path => contents, so this example never
 * touches the filesystem: the three files below are literals. Each probe prints
 * the deciding `<file>: <pattern>` rule, or "not ignored". The rules follow git:
 * a deeper ignore file overrides a shallower one, `.git/info/exclude` ranks
 * below every `.gitignore`, and a directory-only rule needs $isDir = true.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Ignore\IgnoreRules;

$rules = IgnoreRules::fromPaths([
    '.gitignore'        => "*.log\nbuild/\n/secret.env\n",
    'pkg/.gitignore'    => "!keep.log\n",
    '.git/info/exclude' => "scratch.txt\n",
]);

$probes = [
    ['app/debug.log', false],   // *.log in the root .gitignore, at any depth
    ['pkg/keep.log', false],    // pkg/.gitignore re-includes it
    ['build', true],            // the directory itself
    ['build/out.o', false],     // nothing under an ignored directory
    ['secret.env', false],      // anchored: only at the root
    ['pkg/secret.env', false],  // not ignored: the leading slash anchors the rule
    ['scratch.txt', false],     // from .git/info/exclude
    ['src/main.php', false],
];

foreach ($probes as [$path, $isDir]) {
    $deciding = $rules->ignores($path, $isDir);
    printf("%-18s %s\n", $path, $deciding ?? 'not ignored');
}

printf("undecidable rules: %d\n", count($rules->undecidableRules()));
