<?php

declare(strict_types=1);

/**
 * LPT (longest-processing-time) shard-manifest generator for scripts/parallel-tests.sh.
 *
 * Usage:
 *   php scripts/parallel-tests-make-shards.php <junit.xml|durations.tsv> <K> <outDir> [<lib>]
 *
 * <lib> (default candy-code, back-compat) is the lib directory slug; the
 * class->file mapping below is derived from <lib>/composer.json so any lib's
 * junit can be sharded.
 *
 * Input is either a PHPUnit 10 JUnit XML (durations measured from a real run;
 * per-file durations are aggregated and written to <outDir>/durations.tsv) or a
 * pre-aggregated TSV `file<TAB>seconds<TAB>testCount`.
 *
 * Outputs:
 *   <outDir>/durations.tsv   per-file seconds (only when parsing XML)
 *   <outDir>/shard-<i>.list  K files, one repo-relative test path per line,
 *                            order = descending duration so shards stay readable
 *   <outDir>/plan-k<K>.tsv   bucket <TAB> seconds <TAB> file count
 *
 * Determinism: input sorted duration desc / path asc (guaranteed here); ties in
 * bucket load broken by lowest index. Same input => byte-identical manifests,
 * so a resumed run rebuilds the same plan.
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: parallel-tests-make-shards.php <junit.xml|durations.tsv> <K>=1.. <outDir> [<lib>]\n");
    exit(1);
}
$in = (string) $argv[1];
$K = (int) $argv[2];
$outDir = (string) $argv[3];
$lib = (string) ($argv[4] ?? 'candy-code');
if ($K < 1 || !is_file($in)) {
    fwrite(STDERR, "usage: parallel-tests-make-shards.php <junit.xml|durations.tsv> <K>=1.. <outDir> [<lib>]\n");
    exit(1);
}
if (preg_match('{^[A-Za-z0-9][A-Za-z0-9._-]*$}', $lib) !== 1) {
    fwrite(STDERR, "parallel-tests-make-shards: <lib> must be a plain lib directory slug: {$lib}\n");
    exit(1);
}
$repoRoot = dirname(__DIR__);
$psr4 = testNamespaceMap($lib, $repoRoot);
@mkdir($outDir, 0o755, true);

$durationsTsv = str_ends_with($outDir, '/') ? "{$outDir}durations.tsv" : "{$outDir}/durations.tsv";

if (str_ends_with($in, '.xml')) {
    [$in, ] = parseJunit($in, $durationsTsv, $psr4);
}

$loads = array_fill(0, $K, 0.0);
$buckets = array_fill(0, $K, []);

$fh = fopen($in, 'r');
while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\n");
    if ($line === '') {
        continue;
    }
    [$file, $secs] = array_pad(explode("\t", $line), 2, '0');
    $t = (float) $secs;
    $min = 0;
    for ($i = 1; $i < $K; $i++) {
        if ($loads[$i] < $loads[$min] - 1e-9) {
            $min = $i;
        }
    }
    $buckets[$min][] = $file;
    $loads[$min] += $t;
}
fclose($fh);

foreach ($buckets as $i => $bucket) {
    file_put_contents("{$outDir}/shard-{$i}.list", $bucket === [] ? '' : implode("\n", $bucket) . "\n");
}
$plan = '';
foreach ($loads as $i => $l) {
    $plan .= sprintf("%d\t%.3f\t%d\n", $i, $l, count($buckets[$i]));
}
file_put_contents("{$outDir}/plan-k{$K}.tsv", $plan);
fwrite(STDERR, sprintf("K=%d buckets=%s\n", $K, implode(' ', array_map(static fn($l) => sprintf('%.1fs', $l), $loads))));

/**
 * Aggregate a PHPUnit 10 JUnit XML into per-test-FILE durations and totals.
 *
 * Grouping key: the `<testcase class=...>` attribute mapped through the lib's
 * composer.json PSR-4 autoload rules (autoload-dev first, e.g.
 * SugarCraft\Code\Tests\<Path> -> candy-code/tests/<Path>). Class-first is NOT
 * cosmetic: a test method inherited from a trait carries a `file=` pointing at
 * the TRAIT file, which phpunit cannot execute as a suite ("Class ... cannot be
 * found") — measured 2026-09-10 with candy-code tests/Support/
 * RefusesAnUnreadableSourceTrait.php absorbing one census fixture from 8
 * classes. The `file=` attribute is only a fallback for classes outside the
 * mapped namespaces.
 *
 * @param array<string,string> $psr4 namespace-prefix => repo-relative dir
 * @return array{0:string,1:array{tests:int,assertions:int,errors:int,failures:int,skipped:int}} [tsvPath, totals]
 */
function parseJunit(string $xmlPath, string $tsvPath, array $psr4): array
{
    $dom = new DOMDocument();
    $dom->load($xmlPath, LIBXML_NOCDATA);
    $xp = new DOMXPath($dom);
    $rootDir = dirname(__DIR__) . '/';

    $perFile = [];
    foreach ($xp->query('//testcase') as $tc) {
        /** @var DOMElement $tc */
        $time = (float) ($tc->getAttribute('time') ?: '0');
        $class = $tc->getAttribute('class');
        $mapped = classToFile($class, $psr4);
        if ($mapped !== null) {
            $file = $mapped;
        } else {
            $file = $tc->getAttribute('file');
            if (str_starts_with($file, $rootDir)) {
                $file = substr($file, strlen($rootDir));
            }
            if ($file === '') {
                fwrite(STDERR, "unmappable testcase class: {$class}\n");
                exit(3);
            }
        }
        $perFile[$file] ??= ['t' => 0.0, 'n' => 0];
        $perFile[$file]['t'] += $time;
        $perFile[$file]['n']++;
    }

    // Determinism: duration desc, path asc on ties (full precision, no bucketing).
    $entries = [];
    foreach ($perFile as $file => $row) {
        $entries[] = [$file, $row['t'], $row['n']];
    }
    usort($entries, static fn($a, $b) => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));

    $fh = fopen($tsvPath, 'w');
    foreach ($entries as [$file, $t, $n]) {
        fwrite($fh, sprintf("%s\t%.3f\t%d\n", $file, $t, $n));
    }
    fclose($fh);

    $root = $dom->getElementsByTagName('testsuite')->item(0);
    $totals = [
        'tests' => (int) ($root?->getAttribute('tests') ?: 0),
        'assertions' => (int) ($root?->getAttribute('assertions') ?: 0),
        'errors' => (int) ($root?->getAttribute('errors') ?: 0),
        'failures' => (int) ($root?->getAttribute('failures') ?: 0),
        'skipped' => iterator_count($xp->query('//testcase/skipped')),
    ];
    fwrite(STDERR, sprintf(
        "source junit: tests=%d assertions=%d errors=%d failures=%d skipped=%d\n",
        $totals['tests'],
        $totals['assertions'],
        $totals['errors'],
        $totals['failures'],
        $totals['skipped'],
    ));
    fwrite(STDERR, sprintf("files=%d durations=%s\n", count($entries), $tsvPath));

    return [$tsvPath, $totals];
}

function classToFile(string $class, array $psr4): ?string
{
    // Longest prefix first so a nested rule wins over its parent (e.g. a
    // `...\Tests\Fixtures\` sub-root beats the plain `...\Tests\` entry).
    $prefixes = array_keys($psr4);
    usort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($prefixes as $prefix) {
        if (str_starts_with($class, $prefix)) {
            return $psr4[$prefix] . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    }

    return null;
}

/**
 * The lib's namespace-prefix => repo-relative-directory map, read from its
 * composer.json PSR-4 rules — autoload-dev first (that is where test suites
 * live), then the main autoload as a second source; dev entries win because
 * they are only inserted when the prefix is new. Dir values may be a string or
 * an array (composer allows several roots per prefix); the first is used, a
 * documented simplification — a moved file falls back to the `file=` attribute.
 *
 * @return array<string,string>
 */
function testNamespaceMap(string $lib, string $repoRoot): array
{
    $manifestPath = "{$repoRoot}/{$lib}/composer.json";
    if (!is_file($manifestPath)) {
        fwrite(STDERR, "parallel-tests-make-shards: missing {$lib}/composer.json — cannot derive the class->file map\n");
        exit(1);
    }
    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "parallel-tests-make-shards: unreadable {$lib}/composer.json: {$e->getMessage()}\n");
        exit(1);
    }
    $map = [];
    foreach (['autoload-dev', 'autoload'] as $section) {
        foreach ($manifest[$section]['psr-4'] ?? [] as $prefix => $dirs) {
            $dir = is_array($dirs) ? ($dirs[0] ?? null) : $dirs;
            if (!is_string($prefix) || !is_string($dir) || $dir === '') {
                continue;
            }
            $prefix = rtrim(str_replace('\\\\', '\\', $prefix), '\\') . '\\';
            $map[$prefix] ??= "{$lib}/" . rtrim($dir, '/') . '/';
        }
    }
    if ($map === []) {
        fwrite(STDERR, "parallel-tests-make-shards: {$lib}/composer.json declares no PSR-4 roots\n");
        exit(1);
    }

    return $map;
}
