<?php

declare(strict_types=1);

/**
 * One-shot: which SugarCraft libs does sugar-crush actually touch, and through
 * which classes. Emits a per-lib surface table for the audit agents.
 *
 * Deliberately regex-free: the patterns are all backslash-heavy and building
 * them by string concatenation is how the first version of this script died.
 * Scanning on literal prefixes instead.
 *
 * Usage: php prompt_kit/tools/crush-dep-surface.php
 */

$libs = [
    'candy-core' => 'Core',
    'candy-forms' => 'Forms',
    'candy-sprinkles' => 'Sprinkles',
    'candy-shine' => 'Shine',
    'candy-fuzzy' => 'Fuzzy',
    'sugar-veil' => 'Veil',
    'sugar-mcp' => 'Mcp',
    'sugar-diff' => 'Diff',
    'sugar-toast' => 'Toast',
    'candy-mosaic' => 'Mosaic',
    'candy-mouse' => 'Mouse',
    'candy-layout' => 'Layout',
    'candy-focus' => 'Focus',
    'candy-kit' => 'Kit',
    'candy-pty' => 'Pty',
];

$BS = chr(92);
$repoRoot = dirname(__DIR__, 2);
$crushDir = $repoRoot . '/sugar-crush';
$skip = ['/vendor/', '/.phpunit.cache/', '/node_modules/'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($crushDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace($BS, '/', $f->getPathname());
    foreach ($skip as $s) {
        if (str_contains($path, $s)) {
            continue 2;
        }
    }
    $files[$path] = (string) file_get_contents($path);
}

echo 'scanned ' . count($files) . " sugar-crush php files (vendor + caches excluded)\n\n";

foreach ($libs as $slug => $ns) {
    $prefix = 'SugarCraft' . $BS . $ns . $BS;
    $usePrefix = 'use ' . $prefix;

    $classes = [];
    $useFiles = [];
    $inlineFiles = [];

    foreach ($files as $path => $body) {
        $hit = false;

        // `use SugarCraft\Ns\A\B;` — take the FQCN up to the semicolon.
        $off = 0;
        while (($pos = strpos($body, $usePrefix, $off)) !== false) {
            $start = $pos + strlen($usePrefix);
            $end = strpos($body, ';', $start);
            if ($end !== false) {
                $sym = rtrim(substr($body, $start, $end - $start));
                if ($sym !== '' && !str_contains($sym, ' ') && !str_contains($sym, "\n")) {
                    $classes[$sym] = ($classes[$sym] ?? 0) + 1;
                    $hit = true;
                }
            }
            $off = $start;
        }

        // Any other textual mention of the namespace (static calls, docblocks, strings).
        $seen = false;
        $off = 0;
        while (($pos = strpos($body, $prefix, $off)) !== false) {
            $seen = true;
            $off = $pos + strlen($prefix);
        }
        if ($seen) {
            $inlineFiles[] = $path;
        }
        if ($hit) {
            $useFiles[] = $path;
        }
    }

    arsort($classes);

    $short = static fn (string $f): string => substr($f, strlen($crushDir) + 1);
    $uniq = static fn (array $a): array => array_values(array_unique(array_map($short, $a)));

    $allHits = $uniq(array_merge($useFiles, $inlineFiles));
    $src = array_values(array_filter($allHits, static fn (string $f): bool => str_starts_with($f, 'src/')));
    $tests = array_values(array_filter($allHits, static fn (string $f): bool => str_starts_with($f, 'tests/')));
    $other = array_values(array_filter($allHits, static fn (string $f): bool => !str_starts_with($f, 'src/') && !str_starts_with($f, 'tests/')));

    $names = array_keys($classes);

    echo "=== {$slug} (SugarCraft\\{$ns}) ===\n";
    echo '  referencing files: ' . count($allHits)
        . '  (src=' . count($src) . ', tests=' . count($tests) . ', other=' . count($other) . ")\n";
    echo '  distinct symbols imported: ' . count($names) . "\n";
    if ($names === []) {
        echo "  NONE - namespace appears only as an inline FQN or not at all\n";
    }
    foreach (array_slice($names, 0, 30) as $n) {
        echo "    - {$n}  x{$classes[$n]}\n";
    }
    if (count($names) > 30) {
        echo '    ... +' . (count($names) - 30) . " more\n";
    }
    if ($src !== []) {
        echo '  src entry points: ' . implode(', ', array_slice($src, 0, 8))
            . (count($src) > 8 ? ' +' . (count($src) - 8) . ' more' : '') . "\n";
    }
    if ($other !== []) {
        echo '  other: ' . implode(', ', array_slice($other, 0, 8))
            . (count($other) > 8 ? ' +' . (count($other) - 8) . ' more' : '') . "\n";
    }
    echo "\n";
}
