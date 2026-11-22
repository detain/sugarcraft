<?php

declare(strict_types=1);

/*
 * Write a phpunit config whose single <testsuite> lists every test file of a
 * lib longest-first, so ParaTest's one-file-at-a-time queue becomes
 * longest-processing-time (LPT) scheduling.
 *
 * usage: php scripts/paratest-lpt-config.php <lib-dir> <durations|none> <out.xml> [--bootstrap=<abs path>]
 *
 *   durations  a junit XML from any phpunit or paratest run of this lib (a
 *              file's time is the sum of its testcases), or a TSV of
 *              "path<TAB>seconds[<TAB>...]" rows (the
 *              scripts/parallel-tests-durations.tsv shape), or `none`.
 *              Paths are matched lib-relative, so a junit recorded in another
 *              checkout of the repo still applies.
 *
 * WHY: ParaTest 7.4.x hands a free worker the next file in suite order, and
 * suite order is alphabetical by path (php-file-iterator sorts each
 * <directory>), so a slow file late in the alphabet starts late and sets the
 * wall. <file> entries are dispatched in the order listed, which turns this
 * list into the schedule. `--order-by=duration` does not help: the parent
 * sorts with a NullResultCache, so it is a no-op.
 *
 * Every file the lib's own phpunit.xml selects is emitted. Files with no
 * recorded duration go FIRST (unknown = assume slow), so a stale durations
 * file can only cost balance, never drop a test.
 *
 * The last stderr line is machine-readable for scripts/paratest.sh:
 *   lpt: files=N unknown=U total=S longest=L suggested-k=K
 * where K = ceil(1.25 * total / longest): above total / longest the longest
 * file sets the wall and extra workers only add contention, and the 1.25 is the
 * "little more" that absorbs contention inflating every file's time.
 */

[$self, $libArg, $durations, $out] = \array_pad($argv, 4, null);
if ($out === null) {
    \fwrite(\STDERR, "usage: php {$self} <lib-dir> <durations.(xml|tsv)|none> <out.xml> [--bootstrap=<abs path>]\n");
    exit(2);
}

$lib = \rtrim((string) \realpath((string) $libArg), '/');
$base = "{$lib}/phpunit.xml";
if ($lib === '' || !\is_file($base)) {
    \fwrite(\STDERR, "paratest-lpt-config: no phpunit.xml in {$libArg}\n");
    exit(2);
}
if (!\is_file("{$lib}/vendor/autoload.php")) {
    \fwrite(\STDERR, "paratest-lpt-config: {$lib}/vendor is missing; run composer install there first\n");
    exit(2);
}
$slug = \basename($lib);

$bootstrapOverride = null;
foreach (\array_slice($argv, 4) as $arg) {
    if (\str_starts_with($arg, '--bootstrap=')) {
        $bootstrapOverride = \substr($arg, \strlen('--bootstrap='));
    }
}

require "{$lib}/vendor/autoload.php";

$abs = static fn (string $p): string => \str_starts_with($p, '/') ? $p : "{$lib}/{$p}";

/*
 * A recorded path to lib-relative form: absolute paths from any checkout
 * ("/x/y/<slug>/tests/A.php") and repo-root-relative TSV rows
 * ("<slug>/tests/A.php") both become "tests/A.php".
 */
$relative = static function (string $path) use ($slug): string {
    if (\str_starts_with($path, "{$slug}/")) {
        return \substr($path, \strlen($slug) + 1);
    }
    $at = \strpos($path, "/{$slug}/");

    return $at === false ? $path : \substr($path, $at + \strlen($slug) + 2);
};

// 1. Durations, keyed lib-relative.
$dur = [];
if ($durations !== 'none') {
    if (!\is_file((string) $durations)) {
        \fwrite(\STDERR, "paratest-lpt-config: durations file {$durations} not found\n");
        exit(2);
    }
    if (\str_ends_with((string) $durations, '.xml')) {
        $x = \simplexml_load_file((string) $durations);
        if ($x === false) {
            \fwrite(\STDERR, "paratest-lpt-config: cannot parse {$durations}\n");
            exit(2);
        }
        foreach ($x->xpath('//testcase[@file]') as $tc) {
            $key = $relative((string) $tc['file']);
            $dur[$key] = ($dur[$key] ?? 0.0) + (float) $tc['time'];
        }
    } else {
        foreach (\file((string) $durations, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $line) {
            $cols = \explode("\t", $line);
            if (\count($cols) < 2 || !\is_numeric($cols[1])) {
                continue;
            }
            $dur[$relative($cols[0])] = (float) $cols[1];
        }
    }
}

// 2. The file set the lib's config selects, through the iterator PHPUnit uses.
$dom = new \DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->load($base);
$xp = new \DOMXPath($dom);
$files = [];
foreach ($xp->query('/phpunit/testsuites/testsuite') as $suite) {
    $exclude = [];
    foreach ($xp->query('exclude', $suite) as $e) {
        $exclude[] = $abs(\trim($e->textContent));
    }
    foreach ($xp->query('directory', $suite) as $d) {
        $suffix = $d->getAttribute('suffix') ?: 'Test.php';
        $prefix = $d->getAttribute('prefix');
        \array_push($files, ...(new \SebastianBergmann\FileIterator\Facade())->getFilesAsArray($abs(\trim($d->textContent)), $suffix, $prefix, $exclude));
    }
    foreach ($xp->query('file', $suite) as $f) {
        $files[] = (string) \realpath($abs(\trim($f->textContent)));
    }
}
$files = \array_values(\array_unique($files));
if ($files === []) {
    \fwrite(\STDERR, "paratest-lpt-config: {$base} selects no test files\n");
    exit(1);
}

$timeOf = static fn (string $file): ?float => $dur[\substr($file, \strlen($lib) + 1)] ?? null;
$unknown = \array_values(\array_filter($files, static fn (string $f): bool => $timeOf($f) === null));
$known = \array_values(\array_filter($files, static fn (string $f): bool => $timeOf($f) !== null));
\usort($known, static fn (string $a, string $b): int => ($timeOf($b) <=> $timeOf($a)) ?: \strcmp($a, $b));
$ordered = \array_merge($unknown, $known);

// 3. Rewrite the lib's config: absolute paths, one LPT-ordered testsuite.
$root = $dom->documentElement;
foreach (['bootstrap', 'cacheDirectory', 'cacheResultFile'] as $attr) {
    if ($root->hasAttribute($attr)) {
        $root->setAttribute($attr, $abs($root->getAttribute($attr)));
    }
}
if ($bootstrapOverride !== null) {
    $root->setAttribute('bootstrap', $bootstrapOverride);
}
// The generated file lives outside the lib, where the relative schema path dangles.
$root->removeAttribute('xsi:noNamespaceSchemaLocation');
foreach ($xp->query('/phpunit/source//directory | /phpunit/source//file | /phpunit/coverage//directory | /phpunit/coverage//file') as $n) {
    $n->textContent = $abs(\trim($n->textContent));
}
$old = $xp->query('/phpunit/testsuites')->item(0);
$new = $dom->createElement('testsuites');
$suite = $dom->createElement('testsuite');
$suite->setAttribute('name', 'lpt');
foreach ($ordered as $f) {
    $suite->appendChild($dom->createElement('file', \htmlspecialchars($f, \ENT_XML1)));
}
$new->appendChild($suite);
$root->replaceChild($new, $old);
$root->insertBefore($dom->createComment(\sprintf(
    ' GENERATED by scripts/paratest-lpt-config.php from %s + %s: %d files, %d without a recorded duration (listed first) ',
    $base,
    $durations,
    \count($ordered),
    \count($unknown),
)), $new);
$dom->formatOutput = true;
if ($dom->save((string) $out) === false) {
    \fwrite(\STDERR, "paratest-lpt-config: cannot write {$out}\n");
    exit(1);
}

$total = 0.0;
foreach ($known as $f) {
    $total += (float) $timeOf($f);
}
$longest = $known === [] ? 0.0 : (float) $timeOf($known[0]);
$suggested = $longest > 0.0 ? (int) \ceil(1.25 * $total / $longest) : 0;

if ($durations !== 'none' && \count($unknown) * 2 > \count($files)) {
    \fwrite(\STDERR, \sprintf(
        "paratest-lpt-config: WARNING %d of %d files have no duration in %s; is it for this lib?\n",
        \count($unknown),
        \count($files),
        $durations,
    ));
}
if ($known !== []) {
    \fwrite(\STDERR, \sprintf("lpt: longest file %s (%.1fs)\n", \substr($known[0], \strlen($lib) + 1), $longest));
}
\fwrite(\STDERR, \sprintf(
    "lpt: files=%d unknown=%d total=%.1f longest=%.1f suggested-k=%d\n",
    \count($ordered),
    \count($unknown),
    $total,
    $longest,
    $suggested,
));
