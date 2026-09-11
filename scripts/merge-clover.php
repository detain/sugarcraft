#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Merge per-shard PHPUnit clover reports into a single clover file (E691).
 *
 * Usage:
 *   php scripts/merge-clover.php <outDir> <K> <destination.xml>
 *
 * Reads <outDir>/clover-0.xml .. clover-<K-1>.xml as written by
 * `scripts/parallel-tests.sh --clover` and unions them into one report so the
 * sharded sugar-crush coverage job can hand Codecov/Codacy exactly the same
 * artifact shape the serial run produced — the destination path is what the
 * downstream upload steps reference, nothing below this script changes.
 *
 * UNION SEMANTICS — why summed counts, not OR'd covered bits:
 *  * Each shard runs a DISJOINT set of test files, and PHPUnit's <source>
 *    include emits EVERY src file in EVERY shard report, uncovered lines with
 *    count="0" included (measured: 324 files per report at any shard size).
 *    The line keys therefore coincide across shards and the merge is exact.
 *  * Per line, `count` is summed across shards: covered stays derivable
 *    (>0) and the sum is the honest total-executions figure (r62 measured:
 *    line coverage only).
 *  * `crap` on method lines is a function of count, so shard-local crap is
 *    stale after merging; it is recomputed with the line-only-engine formula
 *    (covered -> m, uncovered -> m*(m+1)), verified byte-wise against real
 *    pcov/xdebug output (both report line-only crap).
 *  * File- and project-level <metrics> are recomputed from the merged line
 *    elements. Static attributes (loc/ncloc/classes/complexity) are
 *    engine-invariant and carried across (max).
 *  * <class> metrics: a single-class file has class metrics == file metrics
 *    (PSR-4 norm, 317/324 sugar-crush src files), so its covered* values are
 *    recomputed exactly. Multi-class files fall back to a conservative
 *    max-across-shards floor on covered* — never inflated; no consumer in
 *    this pipeline reads class granularity from clover.
 *
 * FAIL-CLOSED: a missing or unparseable shard clover exits 2 and writes
 * nothing — the same contract the junit conservation guard uses for a missing
 * shard junit. A partial merge must never masquerade as the real report.
 *
 * DETERMINISM: same inputs -> byte-identical output (file order = first-seen
 * across shards in document order, line order = num then type then name,
 * root generated/project timestamp copied from shard 0). scripts/
 * test-merge-clover.php pins this.
 */

$usage = "usage: merge-clover.php <outDir> <K> <destination.xml>\n";

// ---- parse the boundary: three positional args, K a positive integer ----
if ($argc !== 4) {
    fwrite(STDERR, $usage);
    exit(1);
}
$outDir = rtrim($argv[1], '/');
if ($outDir === '') {
    fwrite(STDERR, "merge-clover: empty outDir\n");
    exit(1);
}
if (!preg_match('/^\d+$/', $argv[2]) || (int) $argv[2] < 1) {
    fwrite(STDERR, "merge-clover: K must be an integer >= 1, got: {$argv[2]}\n");
    exit(1);
}
$K = (int) $argv[2];
$dest = $argv[3];

// ---- ingest all shards into a per-file line map (fail closed, no partial output) ----
/** @var array<string, array<string, mixed>> $files keyed by clover file name, insertion order kept */
$files = [];
$generated = null;
$timestamp = null;
$projectName = null;

for ($i = 0; $i < $K; $i++) {
    $path = "{$outDir}/clover-{$i}.xml";
    if (!is_file($path)) {
        fwrite(STDERR, "merge-clover: missing shard clover {$i}: {$path}\n");
        exit(2);
    }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->load($path);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || $errors !== []) {
        $first = $errors[0]->message ?? 'load failed';
        fwrite(STDERR, "merge-clover: unparseable shard clover {$i}: {$path}: " . trim($first) . "\n");
        exit(2);
    }
    $project = $doc->getElementsByTagName('project')->item(0);
    if (!$project instanceof DOMElement) {
        fwrite(STDERR, "merge-clover: no <project> in {$path}\n");
        exit(2);
    }
    if ($i === 0) {
        $coverageRoot = $doc->documentElement;
        $generated = $coverageRoot instanceof DOMElement ? $coverageRoot->getAttribute('generated') : '';
        $timestamp = $project->getAttribute('timestamp');
        $projectName = $project->getAttribute('name');
    }
    foreach ($doc->getElementsByTagName('file') as $fileEl) {
        mergeFileElement($files, $fileEl);
    }
}

// ---- emit the merged document ----
$out = new DOMDocument('1.0', 'UTF-8');
$out->formatOutput = true;
$out->preserveWhiteSpace = false;
$coverage = $out->createElement('coverage');
if ($generated !== null && $generated !== '') {
    $coverage->setAttribute('generated', $generated);
}
$out->appendChild($coverage);

$projectOut = $out->createElement('project');
if ($timestamp !== null && $timestamp !== '') {
    $projectOut->setAttribute('timestamp', $timestamp);
}
if ($projectName !== null && $projectName !== '') {
    $projectOut->setAttribute('name', $projectName);
}
$coverage->appendChild($projectOut);

$totals = emptyMetrics();
$totals['files'] = 0;

foreach ($files as $name => $file) {
    $fileEl = emitFile($out, $name, $file);
    $projectOut->appendChild($fileEl);
    // The file's own <metrics> is its DIRECT child — never the one nested in
    // <class> (getElementsByTagName would surface that first).
    $fileMetrics = null;
    foreach ($fileEl->childNodes as $node) {
        if ($node instanceof DOMElement && $node->tagName === 'metrics') {
            $fileMetrics = $node;
        }
    }
    if ($fileMetrics instanceof DOMElement) {
        accumulate($totals, metricsOf($fileMetrics));
    }
    $totals['files']++;
}

$projectMetrics = $out->createElement('metrics');
foreach (['files', 'loc', 'ncloc', 'classes', 'methods', 'coveredmethods', 'conditionals', 'coveredconditionals', 'statements', 'coveredstatements', 'elements', 'coveredelements'] as $key) {
    $projectMetrics->setAttribute($key, (string) ($totals[$key] ?? 0));
}
$projectOut->appendChild($projectMetrics);

if ($out->save($dest) === false) {
    fwrite(STDERR, "merge-clover: failed to write {$dest}\n");
    exit(2);
}
printf(
    "merge-clover: merged %d shard reports -> %s (files=%d statements=%d coveredstatements=%d)\n",
    $K,
    $dest,
    $totals['files'],
    $totals['statements'],
    $totals['coveredstatements']
);
exit(0);

/**
 * Fold one shard's <file> element into the accumulator map.
 *
 * @param array<string, array<string, mixed>> $files
 */
function mergeFileElement(array &$files, DOMElement $fileEl): void
{
    $name = $fileEl->getAttribute('name');
    if ($name === '') {
        fwrite(STDERR, "merge-clover: <file> without name attribute\n");
        exit(2);
    }
    if (!isset($files[$name])) {
        $files[$name] = [
            'lines' => [],
            'lineOrder' => [],
            'classes' => [],
            'static' => ['loc' => 0, 'ncloc' => 0, 'classes' => 0],
        ];
    }
    $acc = &$files[$name];

    foreach ($fileEl->childNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if ($node->tagName === 'line') {
            mergeLine($acc, $node);
        } elseif ($node->tagName === 'class') {
            mergeClass($acc, $node);
        } elseif ($node->tagName === 'metrics') {
            foreach (['loc', 'ncloc', 'classes'] as $key) {
                $acc['static'][$key] = max($acc['static'][$key], (int) $node->getAttribute($key));
            }
        }
    }
    unset($acc);
}

/**
 * @param array<string, mixed> $acc one file accumulator
 */
function mergeLine(array &$acc, DOMElement $line): void
{
    $num = (int) $line->getAttribute('num');
    $type = $line->getAttribute('type') ?: 'stmt';
    $name = $line->getAttribute('name');
    $key = "{$num}|{$type}|{$name}";
    if (!isset($acc['lines'][$key])) {
        $attrs = [];
        foreach ($line->attributes as $attr) {
            $attrs[$attr->nodeName] = $attr->nodeValue;
        }
        $acc['lines'][$key] = [
            'attrs' => $attrs,
            'count' => 0,
            'truecount' => 0,
            'falsecount' => 0,
        ];
        $acc['lineOrder'][] = $key;
    }
    $entry = &$acc['lines'][$key];
    if ($line->hasAttribute('count')) {
        $entry['count'] += (int) $line->getAttribute('count');
    }
    if ($line->hasAttribute('truecount')) {
        $entry['truecount'] += (int) $line->getAttribute('truecount');
    }
    if ($line->hasAttribute('falsecount')) {
        $entry['falsecount'] += (int) $line->getAttribute('falsecount');
    }
    unset($entry);
}

/**
 * @param array<string, mixed> $acc one file accumulator
 */
function mergeClass(array &$acc, DOMElement $classEl): void
{
    $className = $classEl->getAttribute('name');
    if ($className === '') {
        return;
    }
    if (!isset($acc['classes'][$className])) {
        $acc['classes'][$className] = [
            'namespace' => $classEl->getAttribute('namespace'),
            'static' => ['complexity' => 0, 'methods' => 0, 'conditionals' => 0, 'statements' => 0, 'elements' => 0],
            'coveredMax' => ['coveredmethods' => 0, 'coveredconditionals' => 0, 'coveredstatements' => 0, 'coveredelements' => 0],
        ];
    }
    $metrics = $classEl->getElementsByTagName('metrics')->item(0);
    if (!$metrics instanceof DOMElement) {
        return;
    }
    foreach ($acc['classes'][$className]['static'] as $key => $_) {
        $acc['classes'][$className]['static'][$key] = max($acc['classes'][$className]['static'][$key], (int) $metrics->getAttribute($key));
    }
    foreach ($acc['classes'][$className]['coveredMax'] as $key => $_) {
        $acc['classes'][$className]['coveredMax'][$key] = max($acc['classes'][$className]['coveredMax'][$key], (int) $metrics->getAttribute($key));
    }
}

/**
 * Build the merged <file> element: classes, sorted lines, recomputed metrics.
 *
 * @param array<string, mixed> $file
 */
function emitFile(DOMDocument $out, string $name, array $file): DOMElement
{
    $fileEl = $out->createElement('file');
    $fileEl->setAttribute('name', $name);

    $counts = countLineKinds($file['lines']);

    foreach ($file['classes'] as $className => $class) {
        $classEl = $out->createElement('class');
        $classEl->setAttribute('name', $className);
        if ($class['namespace'] !== '') {
            $classEl->setAttribute('namespace', $class['namespace']);
        }
        $metrics = $out->createElement('metrics');
        $single = count($file['classes']) === 1;
        $setM = static function (string $key, string $value) use ($metrics): void {
            $metrics->setAttribute($key, $value);
        };
        // canonical clover attribute order: static/covered interleaved
        $setM('complexity', (string) $class['static']['complexity']);
        $setM('methods', (string) ($single ? $counts['methods'] : $class['static']['methods']));
        $setM('coveredmethods', (string) ($single ? $counts['coveredMethods'] : $class['coveredMax']['coveredmethods']));
        $setM('conditionals', (string) ($single ? $counts['conditionals'] : $class['static']['conditionals']));
        $setM('coveredconditionals', (string) ($single ? $counts['coveredConditionals'] : $class['coveredMax']['coveredconditionals']));
        $setM('statements', (string) ($single ? $counts['statements'] : $class['static']['statements']));
        $setM('coveredstatements', (string) ($single ? $counts['coveredStatements'] : $class['coveredMax']['coveredstatements']));
        $setM('elements', (string) ($single ? $counts['methods'] + $counts['statements'] + $counts['conditionals'] : $class['static']['elements']));
        $setM('coveredelements', (string) ($single ? $counts['coveredMethods'] + $counts['coveredStatements'] + $counts['coveredConditionals'] : $class['coveredMax']['coveredelements']));
        $classEl->appendChild($metrics);
        $fileEl->appendChild($classEl);
    }

    $keys = $file['lineOrder'];
    usort($keys, static function (string $a, string $b) use ($file): int {
        $ka = explode('|', $a);
        $kb = explode('|', $b);
        return ((int) $ka[0] <=> (int) $kb[0]) ?: (strcmp($ka[1], $kb[1]) ?: strcmp($ka[2], $kb[2]));
    });
    foreach ($keys as $key) {
        $fileEl->appendChild(emitLine($out, $key, $file['lines'][$key]));
    }

    $metrics = $out->createElement('metrics');
    $metrics->setAttribute('loc', (string) $file['static']['loc']);
    $metrics->setAttribute('ncloc', (string) $file['static']['ncloc']);
    $metrics->setAttribute('classes', (string) max($file['static']['classes'], count($file['classes'])));
    $metrics->setAttribute('methods', (string) $counts['methods']);
    $metrics->setAttribute('coveredmethods', (string) $counts['coveredMethods']);
    $metrics->setAttribute('conditionals', (string) $counts['conditionals']);
    $metrics->setAttribute('coveredconditionals', (string) $counts['coveredConditionals']);
    $metrics->setAttribute('statements', (string) $counts['statements']);
    $metrics->setAttribute('coveredstatements', (string) $counts['coveredStatements']);
    $metrics->setAttribute('elements', (string) ($counts['methods'] + $counts['statements'] + $counts['conditionals']));
    $metrics->setAttribute('coveredelements', (string) ($counts['coveredMethods'] + $counts['coveredStatements'] + $counts['coveredConditionals']));
    $fileEl->appendChild($metrics);

    return $fileEl;
}

/**
 * @param array{attrs: array<string, string>, count: int, truecount: int, falsecount: int} $entry
 */
function emitLine(DOMDocument $out, string $key, array $entry): DOMElement
{
    [$num, $type] = explode('|', $key, 3);
    $line = $out->createElement('line');
    $line->setAttribute('num', $num);
    $line->setAttribute('type', $type);

    foreach ($entry['attrs'] as $attr => $value) {
        if (in_array($attr, ['num', 'type', 'count', 'truecount', 'falsecount', 'crap'], true)) {
            continue; // merged or recomputed below
        }
        $line->setAttribute($attr, $value);
    }

    if ($type === 'method') {
        // crap is a function of the merged execution count for line-only engines
        // (pcov in CI, xdebug in sandbox): covered -> m, uncovered -> m*(m+1).
        $complexity = (int) ($entry['attrs']['complexity'] ?? 0);
        $crap = $complexity === 0
            ? (int) ($entry['attrs']['crap'] ?? 0)
            : ($entry['count'] > 0 ? $complexity : $complexity * ($complexity + 1));
        $line->setAttribute('crap', (string) $crap);
    }
    if (array_key_exists('count', $entry['attrs'])) {
        $line->setAttribute('count', (string) $entry['count']);
    }
    if (array_key_exists('truecount', $entry['attrs'])) {
        $line->setAttribute('truecount', (string) $entry['truecount']);
    }
    if (array_key_exists('falsecount', $entry['attrs'])) {
        $line->setAttribute('falsecount', (string) $entry['falsecount']);
    }

    return $line;
}

/**
 * @param array<string, array{attrs: array<string, string>, count: int, truecount: int, falsecount: int}> $lines
 *
 * @return array<string, int>
 */
function countLineKinds(array $lines): array
{
    $counts = [
        'methods' => 0, 'coveredMethods' => 0,
        'statements' => 0, 'coveredStatements' => 0,
        'conditionals' => 0, 'coveredConditionals' => 0,
    ];
    foreach ($lines as $key => $entry) {
        $type = explode('|', $key, 3)[1];
        $covered = match ($type) {
            'cond' => $entry['truecount'] + $entry['falsecount'] > 0,
            // stmt/method (and any future line type) are covered iff count > 0
            default => $entry['count'] > 0,
        };
        if ($type === 'method') {
            $counts['methods']++;
            $counts['coveredMethods'] += $covered ? 1 : 0;
        } elseif ($type === 'cond') {
            $counts['conditionals']++;
            $counts['coveredConditionals'] += $covered ? 1 : 0;
        } else {
            $counts['statements']++;
            $counts['coveredStatements'] += $covered ? 1 : 0;
        }
    }

    return $counts;
}

/**
 * @return array<string, int>
 */
function emptyMetrics(): array
{
    return [
        'loc' => 0, 'ncloc' => 0, 'classes' => 0,
        'methods' => 0, 'coveredmethods' => 0,
        'conditionals' => 0, 'coveredconditionals' => 0,
        'statements' => 0, 'coveredstatements' => 0,
        'elements' => 0, 'coveredelements' => 0,
    ];
}

/**
 * @return array<string, int>
 */
function metricsOf(DOMElement $metrics): array
{
    $values = [];
    foreach (array_keys(emptyMetrics()) as $key) {
        $values[$key] = (int) $metrics->getAttribute($key);
    }

    return $values;
}

/**
 * @param array<string, int> $totals
 * @param array<string, int> $add
 */
function accumulate(array &$totals, array $add): void
{
    foreach ($add as $key => $value) {
        $totals[$key] += $value;
    }
}
