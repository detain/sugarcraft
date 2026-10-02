<?php

declare(strict_types=1);

/**
 * tools/prune-low-rated-ansi.php — retire ANSI art that human review scored 0 or 1,
 * across all three places a piece lives: the ansi/ansi.json entry, the .ansi file
 * itself, and the ansi/README.md row.
 *
 * WHY THE DEFAULT IS A DRY RUN. The committed corpus carries hundreds of entries
 * rated 0 or 1, and --apply unlinks files. A tool whose no-flag invocation is
 * destructive turns one forgotten flag into a corpus loss; printing the match set
 * first means `php tools/prune-low-rated-ansi.php` is always safe to type, and
 * what it prints is exactly what a human vets before opting into the deletion.
 *
 * WHY NULL RATINGS ARE NEVER PRUNED — THE EXPLICIT CARVE-OUT. On the 0-9 scale
 * ansi-view offers, 0 is a verdict: a reviewer looked and scored the piece lowest.
 * A null rating means nobody has looked at all (that is precisely why the
 * generator's --remeasure adopts new art with a null rather than a 0). Pruning
 * nulls would delete unjudged art on the strength of an absence of evidence,
 * which inverts what a rating threshold is for.
 *
 * WHY A PRESENT-BUT-NON-STANDARD RATING IS KEPT AND REPORTED. A string "3" or a
 * float where an int belongs is corrupted data, not a low score; the tool cannot
 * know what the reviewer meant, so it fails closed toward keeping the art and
 * prints the anomaly for a human to judge rather than guessing at a prune.
 *
 * WHY THIS TOOL DOES NOT EDIT README.md. ansi/README.md is a generated projection
 * of ansi/ansi.json — tools/gen-ansi-readme.php owns every table row and re-derives
 * the "**N pieces** ..." header counts on each run. Deleting rows by hand here
 * would fight the `--check` CI guard for exactly the reason that guard exists
 * (see tools/tests/GenAnsiReadmeTest.php), and would leave the header counts
 * stating a total the tables no longer carry. So --apply removes the JSON entries
 * and the art, then re-projects the README by running the generator, and runs the
 * generator's --check so the tool verifies its own three artifacts agree.
 *
 * Set SUGARCRAFT_ANSI_ROOT to point at a fixture tree instead of the real ansi/
 * directory — that is how tools/tests/PruneLowRatedAnsiTest.php drives --apply
 * without touching the committed corpus. The `Environment:` block below is
 * asserted in both directions by tools/tests/ToolsEnvRosterTest.php.
 */
const USAGE = <<<'TXT'
    usage: prune-low-rated-ansi.php [--apply|--help]

      (no flag)   DRY RUN. Print every ansi.json entry rated 0 or 1 — the
                  prunable set — each with its rating and whether its .ansi
                  file is on disk, then a per-category breakdown and totals.
                  Changes nothing on disk.
      --apply     Prune: drop those entries from ansi.json, unlink their .ansi
                  files, re-project ansi/README.md by running
                  tools/gen-ansi-readme.php, then run that generator's --check
                  as self-verification. A non-zero from either subprocess
                  propagates as this script's exit code.
      --help      Print this text and exit 0.

    Environment:
      SUGARCRAFT_ANSI_ROOT  The ansi/ directory to prune, instead of the sibling
            of this script. Forwarded to the generator subprocess when set; when
            unset both scripts resolve the same default independently, because
            they sit side by side under tools/. Exists so the guard can drive
            --apply against a throwaway tree instead of deleting committed art.

    Exit codes:
      0  Dry run completed, or the prune landed and its post-prune check agreed
      1  Post-prune --check reported drift — the prune landed, the artifacts disagree
      2  ansi.json or README.md missing, a prunable key malformed, or an unknown flag
    TXT;

// --help is answered before the root is resolved, mirroring gen-ansi-readme.php:
// the text documents SUGARCRAFT_ANSI_ROOT, and refusing to explain that variable
// because it is set wrong is the least useful possible moment to fail.
if (in_array($argv[1] ?? '', ['--help', '-h'], true)) {
    fwrite(STDOUT, USAGE . "\n");
    exit(0);
}

// ---------------------------------------------------------------------------
// Classification
// ---------------------------------------------------------------------------

/**
 * Split the index into prunable matches, kept-with-anomaly reports, and
 * matches whose KEY is unsafe.
 *
 * THE KEY SHAPE IS CHECKED HERE, AT THE BOUNDARY, rather than wherever a path
 * is later built: a rating-0 key like `cat/../evil` would otherwise compute a
 * deletion path outside the root. Callers must abort on a non-empty `malformed`
 * list before writing or unlinking anything — no path is ever derived from a
 * key this function did not vouch for.
 *
 * @param array<string,array<string,mixed>> $index
 * @return array{matches: array<string,int>, anomalies: list<string>, malformed: list<string>}
 */
function prune_classify(array $index): array
{
    $matches    = [];
    $anomalies  = [];
    $malformed  = [];

    foreach ($index as $key => $entry) {
        // A non-array entry cannot carry a rating at all; report and keep it
        // rather than letting an array access on a scalar raise noise mid-scan.
        if (!is_array($entry)) {
            $anomalies[] = sprintf('  %s  entry is %s, not an object — kept', $key, get_debug_type($entry));
            continue;
        }

        // Absent and null are the same state to this tool: never reviewed.
        if (!array_key_exists('rating', $entry) || $entry['rating'] === null) {
            continue;
        }

        $rating = $entry['rating'];
        if (!is_int($rating)) {
            $anomalies[] = sprintf(
                '  %s  rating %s (%s) is neither int nor null — kept fail-closed',
                $key,
                var_export($rating, true),
                get_debug_type($rating)
            );
            continue;
        }
        if ($rating !== 0 && $rating !== 1) {
            continue;
        }

        if (preg_match('#^[^/\\\\]+/[^/\\\\]+$#', (string) $key) !== 1) {
            $malformed[] = (string) $key;
            continue;
        }

        $matches[(string) $key] = $rating;
    }

    return ['matches' => $matches, 'anomalies' => $anomalies, 'malformed' => $malformed];
}

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

/**
 * Print the match set, the per-category breakdown and the totals, and collect
 * which matches actually have art on disk.
 *
 * THE SAME REPORT SERVES BOTH MODES ON PURPOSE: what --dry-run prints is
 * byte-for-byte what --apply acted on, so a vetted dry run is a promise the
 * apply keeps rather than a different code path that happens to look similar.
 *
 * @param array<string,int> $matches shape-validated by prune_classify
 * @param list<string>      $anomalies
 * @return array{present: list<string>, missing: list<string>}
 */
function prune_report(array $matches, array $anomalies, string $root, bool $apply): array
{
    // Natural order, so cat-2 precedes cat-10 — the order ansi.json itself is
    // kept in by the generator's --remeasure.
    uksort($matches, static fn (string $a, string $b): int => strnatcmp($a, $b));

    printf("prune-low-rated-ansi [%s] under %s — prunable ratings: 0, 1\n", $apply ? 'APPLY' : 'DRY RUN', $root);

    $present    = [];
    $missing    = [];
    $byCategory = [];
    foreach ($matches as $key => $rating) {
        $onDisk = is_file($root . '/' . $key);
        if ($onDisk) {
            $present[] = $key;
        } else {
            $missing[] = $key;
        }
        [$category]     = explode('/', $key, 2);
        $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
        printf("  %s  rating %d  art %s\n", $key, $rating, $onDisk ? 'present' : 'MISSING');
    }

    if ($byCategory !== []) {
        ksort($byCategory);
        $parts = [];
        foreach ($byCategory as $category => $count) {
            $parts[] = $category . ': ' . $count;
        }
        echo 'per-category: ' . implode(', ', $parts) . "\n";
    }

    if ($anomalies !== []) {
        echo "rating anomalies (kept fail-closed toward the art):\n";
        foreach ($anomalies as $line) {
            echo $line . "\n";
        }
    }

    printf(
        "totals: %d matched, %d present to unlink, %d already missing, %d anomalies kept\n",
        count($matches),
        count($present),
        count($missing),
        count($anomalies)
    );

    return ['present' => $present, 'missing' => $missing];
}

// ---------------------------------------------------------------------------
// The generator subprocess
// ---------------------------------------------------------------------------

/**
 * Run tools/gen-ansi-readme.php as a subprocess, stdout and stderr together.
 *
 * exec(), NOT proc_open(): the repo keeps fail-closed child-lifetime rosters
 * around proc_open, exec() waits for the child synchronously so no orphan is
 * ever possible, and exec() is the established tools/ subprocess shape
 * (ToolsEnvRosterTest::helpTextOf() and the guards under tools/tests/).
 * Every interpolated value carries escapeshellarg, per the AGENTS.md gotcha —
 * including the environment prefix, which is a shell word like any other.
 *
 * @param list<string> $args
 * @return array{exit:int,output:string}
 */
function prune_run_generator(string $generator, string|false $override, array $args): array
{
    $cmd = '';
    if ($override !== false && $override !== '') {
        $cmd .= 'SUGARCRAFT_ANSI_ROOT=' . escapeshellarg($override) . ' ';
    }
    $cmd .= escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($generator);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $exit   = 0;
    exec($cmd . ' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

foreach (array_slice($argv, 1) as $argument) {
    // --help already returned above, so anything that is not --apply now is
    // genuinely unknown; a typo'd flag must not silently mean a dry run.
    if ($argument !== '--apply') {
        fwrite(STDERR, "prune-low-rated-ansi: unknown argument '$argument' — see --help\n");
        exit(2);
    }
}

$override = getenv('SUGARCRAFT_ANSI_ROOT');
$root     = $override ?: dirname(__DIR__) . '/ansi';
$jsonPath = $root . '/ansi.json';
$mdPath   = $root . '/README.md';
$apply    = in_array('--apply', $argv, true);

foreach ([$jsonPath, $mdPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "prune-low-rated-ansi: missing $required\n");
        exit(2);
    }
}

/** @var array<string,array<string,mixed>> $index */
$index = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

$classification = prune_classify($index);

// Path safety BEFORE anything else touches disk: a malformed prunable key means
// the run stops here, with the offenders named, before one byte is written and
// one file is unlinked — in dry-run mode too, because a JSON that can carry
// `cat/../evil` today can carry it into an --apply tomorrow.
if ($classification['malformed'] !== []) {
    fwrite(STDERR, "prune-low-rated-ansi: refusing to run — these rating-0/1 keys are not '<category>/<name>' shaped: "
        . implode(', ', $classification['malformed']) . "\n");
    exit(2);
}

$art = prune_report($classification['matches'], $classification['anomalies'], $root, $apply);

if (!$apply) {
    printf("dry run: nothing changed on disk — rerun with --apply to prune %d entries\n", count($classification['matches']));
    exit(0);
}

if ($classification['matches'] === []) {
    // Nothing matched, so there is nothing to regenerate: the README already
    // projects this exact JSON, and running the generator would only churn it.
    echo "nothing to prune — no entry is rated 0 or 1\n";
    exit(0);
}

// 1. Drop the matching keys. unset() keeps the remaining order, which is the
//    natural order --remeasure sorts to, so the surviving file stays sorted.
foreach (array_keys($classification['matches']) as $key) {
    unset($index[$key]);
}

// 2. Write ansi.json with the generator's own encoding flags and trailing
//    newline, verbatim (see its --remeasure writer): a different json_encode
//    call would reformat every line and bury the prune in diff noise.
file_put_contents(
    $jsonPath,
    json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

// 3. Unlink the art. An already-missing file is a warning, not a failure — the
//    JSON entry going away is the repair, and --check's orphan census is the
//    place a half-deleted piece must surface if one ever reappears.
foreach ($art['present'] as $key) {
    if (!unlink($root . '/' . $key)) {
        fwrite(STDERR, "prune-low-rated-ansi: warning: could not unlink $key\n");
    }
}
foreach ($art['missing'] as $key) {
    fwrite(STDERR, "prune-low-rated-ansi: warning: $key had no art file on disk\n");
}

// 4. Let the generator own the README (see the header: a projection is
//    re-derived, never patched). Its exit code propagates because a prune that
//    landed a half-updated corpus is exactly the state an operator must see.
$generator = __DIR__ . '/gen-ansi-readme.php';

$regen = prune_run_generator($generator, $override, []);
if ($regen['exit'] !== 0) {
    fwrite(STDERR, "prune-low-rated-ansi: gen-ansi-readme.php exited {$regen['exit']} — ansi.json and the art ARE pruned,"
        . " but README.md was not re-projected:\n{$regen['output']}\n");
    exit($regen['exit']);
}

// 5. Self-verify the three artifacts agree. Non-zero here is loud on purpose:
//    silently-green self-verification would be no verification at all.
$check = prune_run_generator($generator, $override, ['--check']);
if ($check['exit'] !== 0) {
    fwrite(STDERR, "prune-low-rated-ansi: the prune landed, but the post-prune self-check reports drift (exit {$check['exit']}):\n"
        . "{$check['output']}\n");
    exit($check['exit']);
}

printf("post-prune self-check: %s\n", trim($check['output']));
exit(0);
