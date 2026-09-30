<?php

declare(strict_types=1);

/**
 * tools/gen-ansi-readme.php — ansi/ansi.json is the source of truth for the
 * 3,932-row tables in ansi/README.md, and this tool projects one onto the other.
 *
 * WHY THE JSON OWNS THE README AND NOT THE REVERSE. Both files carry the same
 * four facts about every piece of art — resolution, required depth, colour count,
 * description — and until this tool existed only the README carried them at all.
 * The JSON was seeded FROM the README, which made the README authoritative by
 * accident of ordering rather than by design. That is the wrong way round: the
 * JSON is the machine-readable artifact other things consume, it holds two fields
 * the table has no column for (`rating`, `tags`), and a 3,932-row markdown table
 * is not a data store anyone can safely hand-edit. So the tables are generated and
 * the JSON is edited.
 *
 * Modes:
 *   php tools/gen-ansi-readme.php            Rewrite every table in ansi/README.md from ansi.json.
 *   php tools/gen-ansi-readme.php --check    Generate in-memory and fail (exit 1) on any drift —
 *                                            the CI guard. Also fails when ansi.json and the
 *                                            .ansi files on disk disagree about what exists.
 *   php tools/gen-ansi-readme.php --remeasure  Recompute resolution/depth/colors in ansi.json
 *                                            from the art files, preserving description/rating/tags,
 *                                            and add an entry for any new .ansi file.
 *
 * THE THREE MEASURED COLUMNS ARE RE-DERIVED, NOT COPIED. Every convention below was
 * recovered by fitting candidate definitions against the 3,931 rows the README already
 * had and keeping only the one that reproduced all of them; each is annotated with the
 * score it earned, because a plausible-but-wrong definition is the failure mode here and
 * the near-misses are what prove the chosen one is not a coincidence.
 *
 * Set SUGARCRAFT_ANSI_ROOT to point at a fixture tree instead of the real ansi/
 * directory — that is how tools/tests/GenAnsiReadmeTest.php drives this script
 * without writing to the repo. Mirrors tools/gen-docs.php and tools/check-path-repos.php.
 *
 * THE `Environment:` SECTION BELOW IS NOT DECORATION. Every variable this script reads
 * must have a row there and every row must name a variable it reads — asserted in both
 * directions by tools/tests/ToolsEnvRosterTest.php. That guard caught this file the
 * first time it ran, which is the argument for it: the override had been added, used by
 * the new test, and documented nowhere a contributor would look.
 */
const USAGE = <<<'TXT'
    usage: gen-ansi-readme.php [--check|--remeasure|--help]

      (no flag)    Rewrite every table in ansi/README.md from ansi/ansi.json.
      --check      Generate in memory and exit 1 on any drift — between the README
                   and the JSON, or between the JSON and the .ansi files it describes.
                   Run by tools/tests/GenAnsiReadmeTest.php, which the `tools-guards`
                   CI job picks up as part of its tools/tests/ directory suite.
      --remeasure  Recompute resolution/depth/colors in ansi.json from the art,
                   preserving description/rating/tags, adopting new .ansi files and
                   dropping entries whose file is gone. Use after adding art.
      --help       Print this text and exit 0.

    Environment:
      SUGARCRAFT_ANSI_ROOT  The ansi/ directory to read AND WRITE under, instead of the
                            sibling of this script. Exists for the same reason
                            SUGARCRAFT_GEN_DOCS_ROOT does: both default modes here
                            overwrite a file, so the guard has to drive this script
                            against a throwaway tree rather than the committed corpus.

    Exit codes:
      0  The requested work succeeded, or --check found nothing wrong
      1  --check found drift
      2  ansi.json or ansi/README.md is missing under the resolved root
    TXT;

// --help is answered before the root is resolved, deliberately: the text above documents
// SUGARCRAFT_ANSI_ROOT, and refusing to explain that variable because it is set wrong is
// the least useful possible moment to fail.
if (in_array($argv[1] ?? '', ['--help', '-h'], true)) {
    fwrite(STDOUT, USAGE . "\n");
    exit(0);
}

// ---------------------------------------------------------------------------
// Measurement
// ---------------------------------------------------------------------------

/**
 * The three measured facts about one .ansi file.
 *
 * @return array{width:int,height:int,colors:int,depth:string}
 */
function ansi_measure(string $path): array
{
    $raw = (string) file_get_contents($path);

    // COLOURS ARE COUNTED PER ROLE, NOT AS A SET. A hue used as both a foreground
    // and a background counts twice. That is not the definition anyone would pick
    // from scratch, but it is the one the corpus was measured with: scored against
    // the README it is 3,931/3,931 exact, where the intuitive distinct-colour union
    // manages only 2,468. Changing it would silently invalidate every row.
    $fg = $bg = $depths = [];
    preg_match_all("/\x1b\[([0-9;]*)m/", $raw, $matches);
    foreach ($matches[1] as $params) {
        $p = $params === '' ? ['0'] : explode(';', $params);
        for ($i = 0, $n = count($p); $i < $n; $i++) {
            if (!ctype_digit($p[$i])) {
                continue;
            }
            $code = (int) $p[$i];

            if ($code === 38 || $code === 48) {
                // 38;2;r;g;b and 38;5;n. A run truncated by a malformed sequence is
                // not a colour, so every operand must be present before it is consumed —
                // reading past the end would otherwise invent one and shift the parse.
                if (($p[$i + 1] ?? '') === '2' && isset($p[$i + 4])) {
                    $token = 'rgb:' . $p[$i + 2] . ',' . $p[$i + 3] . ',' . $p[$i + 4];
                    $depths['truecolor'] = true;
                    $i += 4;
                } elseif (($p[$i + 1] ?? '') === '5' && isset($p[$i + 2])) {
                    $index = (int) $p[$i + 2];
                    $token = 'idx:' . $index;
                    // Indices 0-15 of the 256-colour palette ARE the 16 basic slots, so
                    // they cost no extra depth on the palette axis. The 38;5; FORM still
                    // needs a 256-capable parser, which would make such a file "256"
                    // despite spending 16 colours — MEASURED across the corpus, no file
                    // reaches this branch without also using an index above 15, so the
                    // distinction never changes an answer here. It is written down
                    // because the first file that breaks that will do so silently.
                    $depths[$index > 15 ? '256' : '16'] = true;
                    $i += 2;
                } else {
                    continue;
                }
                if ($code === 38) {
                    $fg[$token] = true;
                } else {
                    $bg[$token] = true;
                }
                continue;
            }

            if ($code >= 30 && $code <= 37) {
                $fg['idx:' . ($code - 30)] = true;
                $depths['16'] = true;
            } elseif ($code >= 90 && $code <= 97) {
                $fg['idx:' . ($code - 82)] = true;
                $depths['16'] = true;
            } elseif ($code >= 40 && $code <= 47) {
                $bg['idx:' . ($code - 40)] = true;
                $depths['16'] = true;
            } elseif ($code >= 100 && $code <= 107) {
                $bg['idx:' . ($code - 92)] = true;
                $depths['16'] = true;
            }
        }
    }

    // DEPTH IS THE SHALLOWEST TERMINAL THAT RENDERS THE PIECE FAITHFULLY, and it comes
    // from the SGR FORM rather than the colour count — a file painted in seven shades of
    // 38;2;r;g;b still demands a truecolor terminal. MEASURED: 33 files are truecolor
    // with <=8 colours and 131 are 256 with <=8, so a count-derived depth would
    // under-report every one of them. Scored 3,931/3,931 against the README's own
    // Depth column on the first attempt, which is the evidence that this SGR walk
    // agrees with whatever produced the corpus.
    $depth = isset($depths['truecolor'])
        ? 'truecolor'
        : (isset($depths['256']) ? '256' : (isset($depths['16']) ? '16' : 'mono'));

    // HEIGHT DISCARDS TRAILING LINES THAT ARE EMPTY IN THE RAW TEXT — before escapes are
    // stripped, not after. The difference is not academic: 10 files in this corpus end
    // with a bare "\x1b[0m" reset epilogue and NO final newline. Strip escapes first and
    // that tail looks like a blank line to discard, costing the piece a real row; test
    // it raw and it is correctly kept. Raw-trim scores 3,931/3,931, strip-then-trim 3,921.
    $lines = explode("\n", str_replace("\r", '', $raw));
    while ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }

    // Width is the widest line's CODEPOINT count, which is what the corpus was measured
    // with (3,931/3,931) — not its display width. The two differ on the 36 files holding
    // a wide or combining character, where display width is arguably the truer answer;
    // it is not used, because re-defining it would rewrite 36 rows to no one's benefit.
    $width = 0;
    foreach ($lines as $line) {
        $stripped = (string) preg_replace("/\x1b\[[0-9;?]*[a-zA-Z]/", '', $line);
        $cells = mb_strlen($stripped, 'UTF-8');
        if ($cells > $width) {
            $width = $cells;
        }
    }

    return [
        'width'  => $width,
        'height' => count($lines),
        'colors' => count($fg) + count($bg),
        'depth'  => $depth,
    ];
}

// ---------------------------------------------------------------------------
// Rendering the tables
// ---------------------------------------------------------------------------

/**
 * Is this a slot-series piece rather than one of the 224 originals?
 *
 * The two naming schemes are the only thing that separates them, and they never
 * collide: a slot file is "<category>-<slot>-<depth>-<size>-<n>.ansi" and an
 * original is "<n>-<name>.ansi", so the category prefix decides it.
 */
function ansi_is_slot(string $name, string $category): bool
{
    return str_starts_with($name, $category . '-');
}

/**
 * One markdown row. Slot tables carry a Depth column, the two original tables do not.
 */
function ansi_row(string $label, array $entry, bool $withDepth): string
{
    // A description may legitimately contain a pipe — "ruler and|v|strips" does —
    // and an unescaped one silently splits the row into extra cells. That is not a
    // hypothetical: it is why the honey-bounce row read as undocumented to the first
    // parser written against this table.
    $description = str_replace('|', '\\|', $entry['description']);

    $cells = $withDepth
        ? [$entry['resolution'], (string) $entry['depth'], (string) $entry['colors'], $description]
        : [$entry['resolution'], (string) $entry['colors'], $description];

    return '| `' . $label . '` | ' . implode(' | ', $cells) . ' |';
}

/**
 * Every row of one section, in the order that section uses.
 *
 * THE TWO SECTION KINDS SORT DIFFERENTLY, and both orders are inherited rather than
 * chosen. The original tables are in natural order, so 9- precedes 10-; the slot
 * tables are in plain lexicographic order, so 10- precedes 2-. Imposing one order on
 * both would reorder ~3,700 rows for no reason, so each is reproduced as it stands.
 *
 * @param  array<string,array<string,mixed>> $index
 * @return list<string>
 */
function ansi_section_rows(array $index, string $category, bool $slots): array
{
    $names = [];
    foreach ($index as $entry) {
        if ($entry['category'] === $category && ansi_is_slot($entry['name'], $category) === $slots) {
            $names[] = $entry['name'];
        }
    }
    sort($names, $slots ? SORT_STRING : SORT_NATURAL);

    $rows = [];
    foreach ($names as $name) {
        $entry = $index[$category . '/' . $name];
        // Slot tables label a piece by bare filename; the original tables prefix the
        // directory, because they predate the one-directory-per-category layout.
        $rows[] = ansi_row($slots ? $name : $category . '/' . $name, $entry, $slots);
    }

    return $rows;
}

/**
 * Rewrite every table body in the README, leaving all prose untouched.
 *
 * WHY IT PATCHES IN PLACE RATHER THAN EMITTING THE WHOLE FILE. The README is mostly
 * hand-written: a header, 22 section headings in a deliberate non-alphabetical order,
 * and BEGIN/END markers around the slot sections. Regenerating all of that would mean
 * encoding it in the JSON, which is a data store about pictures and has no business
 * holding prose. So the tool finds each table by its header row and replaces only the
 * contiguous run of data rows beneath it.
 *
 * @param array<string,array<string,mixed>> $index
 */
function ansi_render_readme(string $readme, array $index): string
{
    $lines  = explode("\n", $readme);
    $out    = [];
    $section = null;

    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = $lines[$i];

        if (preg_match('/^#{2,3}\s+(.+?)(?:\s+—\s+slot series)?\s*$/u', $line, $m)) {
            $section = trim($m[1]);
            $out[] = $line;
            continue;
        }

        $isHeader = $line === '| File | Size | Colors | Description |'
            || $line === '| File | Size | Depth | Colors | Description |';

        if (!$isHeader || $section === null) {
            $out[] = $line;
            continue;
        }

        $withDepth = str_contains($line, '| Depth |');
        // The two original sections are titled "SugarCraft" and "sugar-crush"; the
        // slot sections are titled by category. Only the first needs a translation.
        $category = $section === 'SugarCraft' ? 'sugarcraft' : $section;

        $out[] = $line;
        $out[] = $lines[++$i];                    // the |---|---| separator, copied as-is
        foreach (ansi_section_rows($index, $category, $withDepth) as $row) {
            $out[] = $row;
        }
        // Skip whatever rows were there before; everything after them is prose again.
        while ($i + 1 < $n && str_starts_with($lines[$i + 1], '| `')) {
            $i++;
        }
    }

    return implode("\n", $out);
}

/**
 * Patch the header's counts, which are otherwise a standing invitation to rot.
 *
 * THIS IS NOT BELT-AND-BRACES. The sentence this rewrites read "**224 pieces**" for as
 * long as the file existed — a figure that was correct when the 224 originals were all
 * there was, and stayed on the page across 3,708 subsequent additions. Every number
 * here is therefore derived on every run, exactly as tools/gen-docs.php owns the
 * homepage's library counts and for the same reason.
 *
 * @param array<string,array<string,mixed>> $index
 */
function ansi_render_counts(string $readme, array $index): string
{
    $categories = $originals = $slots = [];
    $byDepth = ['16' => 0, '256' => 0, 'truecolor' => 0];
    foreach ($index as $entry) {
        $categories[$entry['category']] = true;
        if (ansi_is_slot($entry['name'], $entry['category'])) {
            $slots[$entry['category']] = ($slots[$entry['category']] ?? 0) + 1;
        } else {
            $originals[$entry['category']] = ($originals[$entry['category']] ?? 0) + 1;
        }
        if (isset($byDepth[$entry['depth']])) {
            $byDepth[$entry['depth']]++;
        }
    }

    $fmt = static fn (int $v): string => number_format($v);
    $paragraph = sprintf(
        '**%s pieces** across %d categories. %s come from the original run — %s in `sugarcraft/`, '
        . '%s in `sugar-crush/`, generated by 50 ANSI-art agents (one directory per agent, merged and '
        . 'renamed `<n>-<name>.ansi` on reorganization). The other %s are the per-library slot series, '
        . 'named `<category>-<slot>-<depth>-<size>-<n>.ansi`. By depth: %s 16-color · %s 256-color · %s truecolor.',
        $fmt(count($index)),
        count($categories),
        $fmt(array_sum($originals)),
        $fmt($originals['sugarcraft'] ?? 0),
        $fmt($originals['sugar-crush'] ?? 0),
        $fmt(array_sum($slots)),
        $fmt($byDepth['16']),
        $fmt($byDepth['256']),
        $fmt($byDepth['truecolor'])
    );

    return (string) preg_replace(
        '/^\*\*[\d,]+ pieces\*\*.*$/m',
        // A description could contain a $-sequence that preg_replace would expand.
        str_replace(['\\', '$'], ['\\\\', '\\$'], $paragraph),
        $readme,
        1
    );
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

$root     = getenv('SUGARCRAFT_ANSI_ROOT') ?: dirname(__DIR__) . '/ansi';
$jsonPath = $root . '/ansi.json';
$mdPath   = $root . '/README.md';

$check     = in_array('--check', $argv, true);
$remeasure = in_array('--remeasure', $argv, true);

foreach ([$jsonPath, $mdPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "gen-ansi-readme: missing $required\n");
        exit(2);
    }
}

/** @var array<string,array<string,mixed>> $index */
$index = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

// The .ansi files on disk are the ultimate authority on WHAT EXISTS; the JSON is the
// authority on what each one IS. A file present in one and not the other is drift that
// neither the README nor the JSON can express, so it is reported before anything else.
$onDisk = [];
foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    foreach (glob($dir . '/*.ansi') ?: [] as $file) {
        $onDisk[basename($dir) . '/' . basename($file)] = $file;
    }
}
$orphanedRows  = array_diff(array_keys($index), array_keys($onDisk));
$untrackedArt  = array_diff(array_keys($onDisk), array_keys($index));

if ($remeasure) {
    foreach ($untrackedArt as $key) {
        [$category, $name] = explode('/', $key, 2);
        $index[$key] = [
            'name' => $name, 'category' => $category, 'resolution' => '', 'width' => 0,
            // rating starts UNSET (null), not 0: on the 0-9 scale ansi-view offers,
            // 0 is a real score, and freshly adopted art has never been reviewed.
            'height' => 0, 'depth' => '', 'colors' => 0, 'description' => '', 'rating' => null, 'tags' => [],
        ];
    }
    foreach ($orphanedRows as $key) {
        unset($index[$key]);
    }
    foreach ($index as $key => $entry) {
        $m = ansi_measure($onDisk[$key]);
        $index[$key] = [
            'name'        => $entry['name'],
            'category'    => $entry['category'],
            'resolution'  => $m['width'] . 'x' . $m['height'],
            'width'       => $m['width'],
            'height'      => $m['height'],
            'depth'       => $m['depth'],
            'colors'      => $m['colors'],
            'description' => $entry['description'],
            'rating'      => $entry['rating'],
            'tags'        => $entry['tags'],
        ];
    }
    uksort($index, static fn (string $a, string $b): int => strnatcmp($a, $b));
    file_put_contents($jsonPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    printf("remeasured %d entries (+%d new, -%d removed)\n", count($index), count($untrackedArt), count($orphanedRows));
    exit(0);
}

$current  = (string) file_get_contents($mdPath);
$expected = ansi_render_counts(ansi_render_readme($current, $index), $index);

if (!$check) {
    file_put_contents($mdPath, $expected);
    printf("wrote %s from %d ansi.json entries\n", $mdPath, count($index));
    exit(0);
}

$problems = [];
foreach ($untrackedArt as $key) {
    $problems[] = "  $key exists on disk but has no ansi.json entry";
}
foreach ($orphanedRows as $key) {
    $problems[] = "  $key has an ansi.json entry but no file on disk";
}
foreach ($index as $key => $entry) {
    if (!isset($onDisk[$key])) {
        continue;
    }
    $m = ansi_measure($onDisk[$key]);
    if ($entry['resolution'] !== $m['width'] . 'x' . $m['height']) {
        $problems[] = "  $key resolution: ansi.json says {$entry['resolution']}, the file measures {$m['width']}x{$m['height']}";
    }
    if ((string) $entry['depth'] !== $m['depth']) {
        $problems[] = "  $key depth: ansi.json says {$entry['depth']}, the file measures {$m['depth']}";
    }
    if ((int) $entry['colors'] !== $m['colors']) {
        $problems[] = "  $key colors: ansi.json says {$entry['colors']}, the file measures {$m['colors']}";
    }
    if (trim((string) $entry['description']) === '') {
        $problems[] = "  $key has no description";
    }
}
if ($expected !== $current) {
    $problems[] = '  ansi/README.md does not match ansi.json — run `php tools/gen-ansi-readme.php`';
}

if ($problems !== []) {
    fwrite(STDERR, "gen-ansi-readme --check: " . count($problems) . " problem(s)\n");
    // A whole-corpus mismatch would otherwise bury the signal in thousands of lines.
    fwrite(STDERR, implode("\n", array_slice($problems, 0, 40)) . "\n");
    if (count($problems) > 40) {
        fwrite(STDERR, '  ... and ' . (count($problems) - 40) . " more\n");
    }
    exit(1);
}

printf("ansi.json and ansi/README.md agree across %d pieces\n", count($index));
exit(0);
