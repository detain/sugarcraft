<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandRegistry;

/**
 * `COMPARISON.md` IS THE FLAGSHIP HONESTY DOCUMENT — AND NOTHING DERIVED ITS
 * COUNTS. The 2026-11 re-audit found ~50 stale cells concentrated exactly where
 * waves shipped without a doc flip: the §2 scoreboard never recounted after §3
 * rows moved, locale/tool/method/file counts froze at their first-audit values,
 * and bottom-line prose repeated gaps the matrix itself no longer showed.
 *
 * This guard closes the DERIVABLE half permanently, mirroring the recompute
 * rules of the campaign applier (scoreboard recounts from §3 glyph cells; the
 * locale/registry/schema figures recount from live code):
 *
 *  - §2 scoreboard rows, both category lines and the Total line, must equal
 *    the glyph census of the §3 taxonomy rows (`-N` rows are excluded, exactly
 *    as the §2 heading's "(N taxonomy rows)" counts them);
 *  - every matrix cell glyph — ALL 409 rows, all six product columns, `-N`
 *    rows included — must equal the leading glyph of that product's bullet in
 *    the row's Appendix A block, which must exist and carry exactly one bullet
 *    per product (the 2026-11 recheck caught a flip wave mirroring only 4
 *    synced blocks and leaving 26 ids desynced; this leg makes that class
 *    un-regrowable);
 *  - `lang/` file and key counts must match the PLAT-10 cell and detail;
 *  - `CommandRegistry::all()`, `slashCommands()` and `paletteEntries()` must
 *    match the CMD-01/CMD-08 cells and the CMD-01 appendix "(N files" figure;
 *  - the generated protocol schema's method and event counts must match the
 *    WEB-02 cell and the §4.12 narrative sentence.
 *
 * Cells that are honest prose without a cheap oracle (theme file layout, TLS
 * flag semantics, wizard behaviour) were flipped with this lane but are NOT
 * pinned here — the guard's rule is: every figure it prints, it re-derives.
 *
 * @see \SugarCraft\Code\Tests\Config\ReadmeRosterDriftTest for the sibling doc-drift family
 */
final class ComparisonTruthDriftTest extends TestCase
{
    /** Same glyph vocabulary and order as the campaign applier. */
    private const GLYPHS = ['✅', '🟡', '🔌', '🧪', '❌', '➖', '❓', '·'];

    /** Column order inside every matrix and scoreboard row. */
    private const COLUMN_COUNT = 6;

    private static function docLines(): array
    {
        $path = dirname(__DIR__, 2) . '/COMPARISON.md';
        if (!is_file($path)) {
            self::fail('COMPARISON.md must exist beside the lib root at ' . $path);
        }

        return explode("\n", (string) file_get_contents($path));
    }

    /** @return array{0:int,1:int} start (inclusive) and end (exclusive) line of §3 */
    private static function matrixBounds(array $lines): array
    {
        $start = null;
        $end = null;
        foreach ($lines as $i => $line) {
            if ($start === null) {
                if (str_starts_with($line, '## 3. Full feature matrix')) {
                    $start = $i;
                }

                continue;
            }

            if (str_starts_with($line, '## 4. ')) {
                $end = $i;
                break;
            }
        }

        if ($start === null || $end === null) {
            self::fail('COMPARISON.md lost its §3 matrix bounds (## 3. Full feature matrix ... ## 4. )');
        }

        return [$start, $end];
    }

    /**
     * Split an 8-cell matrix row (ID, feature, six product cells) into its
     * trimmed cells — the applier's split_row for §3.
     *
     * @return list<string>
     */
    private static function splitMatrixRow(string $line): array
    {
        $cells = self::splitRow($line, 8);

        return array_map(trim(...), $cells);
    }

    /** @return list<string> */
    private static function splitRow(string $line, int $expected): array
    {
        $trimmed = trim($line);
        $inner = substr($trimmed, 1, -1);
        if ($inner === false) {
            self::fail('row too short to be a table row: ' . substr($trimmed, 0, 80));
        }

        $cells = explode('|', $inner);
        if (count($cells) !== $expected) {
            self::fail("row does not have {$expected} cells: " . substr($trimmed, 0, 80));
        }

        return $cells;
    }

    /** @return list<string> the six product cells of one matrix row */
    private static function productCells(string $rowId): array
    {
        $lines = self::docLines();
        [$start, $end] = self::matrixBounds($lines);
        $hits = [];
        for ($i = $start; $i < $end; $i++) {
            if (str_starts_with($lines[$i], '| ' . $rowId . ' |')) {
                $hits[] = $i;
            }
        }

        if (count($hits) !== 1) {
            self::fail("matrix row {$rowId}: " . count($hits) . ' matches in §3');
        }

        $cells = self::splitMatrixRow($lines[$hits[0]]);

        return array_slice($cells, 2, self::COLUMN_COUNT);
    }

    /** The candy-code appendix bullet of one row block — starts `- **candy-code** <glyph> — `. */
    private static function candyCodeDetail(string $rowId): string
    {
        $lines = self::docLines();
        $open = '<details><summary><b>' . $rowId . '</b>';
        $hits = array_keys(array_filter($lines, static fn (string $l): bool => str_starts_with($l, $open)));
        if (count($hits) !== 1) {
            self::fail("appendix block {$rowId}: " . count($hits) . ' matches');
        }

        for ($i = $hits[0] + 1; $i < count($lines); $i++) {
            if ($lines[$i] === '</details>') {
                break;
            }

            if (str_starts_with($lines[$i], '- **candy-code**')) {
                return $lines[$i];
            }
        }

        self::fail("appendix block {$rowId} carries no candy-code bullet");
    }

    /** @param list<string> $lines */
    private static function narrativeText(array $lines, string $marker): string
    {
        $joined = implode("\n", $lines);
        if (!str_contains($joined, $marker)) {
            self::fail('COMPARISON.md lost the narrative marker ' . $marker);
        }

        return $joined;
    }

    public function testTheScoreboardEqualsTheMatrixGlyphCensus(): void
    {
        $lines = self::docLines();
        [$start, $end] = self::matrixBounds($lines);

        $counts = [];
        $order = [];
        $category = null;
        for ($i = $start; $i < $end; $i++) {
            if (preg_match('/^\| \*\*([A-Z]+)\*\* \|/u', $lines[$i], $m) === 1) {
                $category = $m[1];
                $order[] = $category;
                $counts[$category] = array_fill(0, self::COLUMN_COUNT, []);
                continue;
            }

            if (preg_match('/^\| ([A-Z]+)-(N?)(\d+) \|/u', $lines[$i], $m) !== 1 || $m[2] !== '') {
                continue;
            }

            self::assertSame($category, $m[1], "row {$m[1]}-{$m[3]} sits outside its {$category} category");
            $cells = self::splitMatrixRow($lines[$i]);
            foreach (array_slice($cells, 2) as $k => $cell) {
                $glyph = mb_substr($cell, 0, 1, 'UTF-8');
                if (!in_array($glyph, self::GLYPHS, true)) {
                    self::fail("bad glyph in {$m[1]}-{$m[3]} column {$k}: " . substr($cell, 0, 30));
                }

                $counts[$category][$k][$glyph] = ($counts[$category][$k][$glyph] ?? 0) + 1;
            }
        }

        $format = static function (array $perGlyph): string {
            $parts = [];
            foreach (self::GLYPHS as $glyph) {
                if (($perGlyph[$glyph] ?? 0) > 0) {
                    $parts[] = $glyph . $perGlyph[$glyph];
                }
            }

            return implode(' ', $parts);
        };

        $scoreboardStart = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '## 2. Scoreboard')) {
                $scoreboardStart = $i;
                break;
            }
        }
        self::assertNotNull($scoreboardStart, 'COMPARISON.md lost its §2 scoreboard');

        $taxonomyRows = 0;
        foreach ($order as $cat) {
            $taxonomyRows += array_sum($counts[$cat][0]);
        }

        $mismatches = [];
        $seenCategories = [];
        $total = array_fill(0, self::COLUMN_COUNT, []);
        for ($i = $scoreboardStart; $i < $start; $i++) {
            $line = $lines[$i];
            if (!str_starts_with($line, '| ') || str_starts_with($line, '| Category') || str_starts_with($line, '|---')) {
                continue;
            }

            $cells = array_map(trim(...), self::splitRow($line, 1 + self::COLUMN_COUNT));
            $label = $cells[0];
            if (preg_match('/^([A-Z]+) — /u', $label, $m) === 1 && isset($counts[$m[1]])) {
                $cat = $m[1];
                $seenCategories[] = $cat;
                $expected = array_map($format, $counts[$cat]);
                foreach (array_fill(0, self::COLUMN_COUNT, null) as $k => $_) {
                    $total[$k] = array_merge_recursive([], $total[$k]);
                    foreach ($counts[$cat][$k] as $glyph => $n) {
                        $total[$k][$glyph] = ($total[$k][$glyph] ?? 0) + $n;
                    }
                }

                for ($k = 0; $k < self::COLUMN_COUNT; $k++) {
                    if ($cells[1 + $k] !== $expected[$k]) {
                        $mismatches[] = "{$label}: column {$k} says {$cells[1 + $k]}, matrix says {$expected[$k]}";
                    }
                }

                continue;
            }

            if (str_starts_with($label, '**Total')) {
                if (preg_match('/\((\d+) taxonomy rows\)/u', $label, $m) === 1) {
                    self::assertSame($taxonomyRows, (int) $m[1], 'the Total row\'s taxonomy count drifted from §3');
                }

                $expected = array_map(static fn (array $perGlyph): string => '**' . $format($perGlyph) . '**', $total);
                for ($k = 0; $k < self::COLUMN_COUNT; $k++) {
                    if ($cells[1 + $k] !== $expected[$k]) {
                        $mismatches[] = "Total: column {$k} says {$cells[1 + $k]}, matrix says {$expected[$k]}";
                    }
                }
            }
        }

        self::assertSame($order, $seenCategories, 'the §2 scoreboard lists a different category set than §3 uses');
        if (preg_match('/^## 2\. Scoreboard \((\d+) taxonomy rows per product\)/u', $lines[$scoreboardStart], $m) === 1) {
            self::assertSame($taxonomyRows, (int) $m[1], 'the §2 heading\'s taxonomy count drifted from §3');
        }

        self::assertSame([], $mismatches, "COMPARISON.md's §2 scoreboard no longer recounts §3:\n  - " . implode("\n  - ", $mismatches));
    }

    public function testTheMatrixGlyphsMatchEveryAppendixDetailBullet(): void
    {
        $lines = self::docLines();
        [$start, $end] = self::matrixBounds($lines);

        // The six product names, taken from the §3 header row itself — the
        // guard never hard-codes the roster of products or of rows.
        $header = null;
        for ($i = $start; $i < $end; $i++) {
            if (str_starts_with($lines[$i], '| ID | Feature |')) {
                $header = $lines[$i];
                break;
            }
        }
        self::assertNotNull($header, 'COMPARISON.md lost the §3 matrix header row');
        $products = array_slice(self::splitMatrixRow($header), 2);
        self::assertCount(self::COLUMN_COUNT, $products, 'the §3 header does not carry six product columns');

        // id => six leading glyphs, every taxonomy AND `-N` row.
        $matrix = [];
        for ($i = $start; $i < $end; $i++) {
            if (preg_match('/^\| ([A-Z]+-N?\d+) \|/u', $lines[$i], $m) !== 1) {
                continue;
            }

            if (isset($matrix[$m[1]])) {
                self::fail("matrix row {$m[1]} appears twice in §3");
            }

            $glyphs = [];
            foreach (array_slice(self::splitMatrixRow($lines[$i]), 2) as $cell) {
                $glyph = mb_substr($cell, 0, 1, 'UTF-8');
                if (!in_array($glyph, self::GLYPHS, true)) {
                    self::fail("bad glyph in {$m[1]}: " . substr($cell, 0, 30));
                }

                $glyphs[] = $glyph;
            }

            $matrix[$m[1]] = $glyphs;
        }
        self::assertNotEmpty($matrix, 'no matrix rows parsed from §3');

        // Appendix A: id => product => glyph, one bullet per product per block.
        $appendixStart = null;
        $appendixEnd = count($lines);
        foreach ($lines as $i => $line) {
            if ($appendixStart === null && str_starts_with($line, '## Appendix A')) {
                $appendixStart = $i;
                continue;
            }

            if ($appendixStart !== null && str_starts_with($line, '## Appendix B')) {
                $appendixEnd = $i;
                break;
            }
        }
        self::assertNotNull($appendixStart, 'COMPARISON.md lost Appendix A');

        $appendix = [];
        $current = null;
        $bullet = '/^- \*\*(.+?)\*\* (' . implode('|', self::GLYPHS) . ')(?:\s|$)/u';
        for ($i = $appendixStart; $i < $appendixEnd; $i++) {
            $line = $lines[$i];
            if (preg_match('/^<details><summary><b>([A-Z]+-N?\d+)<\/b>/u', $line, $m) === 1) {
                if (isset($appendix[$m[1]])) {
                    self::fail("appendix block {$m[1]} appears twice");
                }

                $current = $m[1];
                $appendix[$current] = [];
                continue;
            }

            if ($line === '</details>') {
                $current = null;
                continue;
            }

            if ($current === null || preg_match($bullet, $line, $m) !== 1) {
                continue;
            }

            if (isset($appendix[$current][$m[1]])) {
                self::fail("appendix block {$current} carries two {$m[1]} bullets");
            }

            $appendix[$current][$m[1]] = $m[2];
        }

        $failures = [];
        foreach ($matrix as $rowId => $glyphs) {
            if (!isset($appendix[$rowId])) {
                $failures[] = "{$rowId}: §3 has the row, Appendix A has no detail block";
                continue;
            }

            $labels = array_keys($appendix[$rowId]);
            $missing = implode(', ', array_diff($products, $labels));
            $foreign = implode(', ', array_diff($labels, $products));
            if ($missing !== '' || $foreign !== '') {
                $failures[] = "{$rowId}: appendix bullets are not the six product columns"
                    . ($missing !== '' ? " (missing: {$missing})" : '')
                    . ($foreign !== '' ? " (unexpected: {$foreign})" : '');
                continue;
            }

            foreach ($products as $k => $product) {
                $appendixGlyph = $appendix[$rowId][$product];
                if ($appendixGlyph !== $glyphs[$k]) {
                    $failures[] = "{$rowId} [{$product}]: matrix says {$glyphs[$k]}, Appendix A says {$appendixGlyph}";
                }
            }
        }

        foreach (array_diff(array_keys($appendix), array_keys($matrix)) as $extra) {
            $failures[] = "{$extra}: Appendix A has a detail block §3 does not carry";
        }

        self::assertSame(
            [],
            $failures,
            "COMPARISON.md's matrix and its Appendix A detail no longer agree:\n  - " . implode("\n  - ", $failures),
        );
    }

    public function testTheLocaleFiguresMatchTheLangDirectory(): void
    {
        $langDir = dirname(__DIR__, 2) . '/lang';
        $files = array_values(array_filter(
            (array) scandir($langDir),
            static fn (string $f): bool => str_ends_with($f, '.php'),
        ));
        self::assertNotEmpty($files, 'lang/ must ship locale files');

        $perFile = [];
        foreach ($files as $file) {
            $perFile[$file] = substr_count((string) file_get_contents($langDir . '/' . $file), '=> ');
        }

        $keyCount = $perFile['en.php'];
        self::assertSame(
            [$keyCount],
            array_values(array_unique($perFile)),
            'the doc claims strict key parity across locales; at least one file differs',
        );

        $cell = self::productCells('PLAT-10')[0];
        self::assertStringContainsString(count($files) . ' locales', $cell, 'PLAT-10 candy-code cell names a stale locale count');
        self::assertStringContainsString(number_format($keyCount, 0, '.', ',') . ' keys', $cell, 'PLAT-10 candy-code cell names a stale key count');

        $detail = self::candyCodeDetail('PLAT-10');
        self::assertStringContainsString(count($files) . ' locale files', $detail, 'PLAT-10 appendix detail names a stale locale count');
        self::assertStringContainsString(number_format($keyCount, 0, '.', ',') . ' keys', $detail, 'PLAT-10 appendix detail names a stale key count');
    }

    public function testTheCommandCountsMatchTheRegistry(): void
    {
        $all = count(CommandRegistry::all());
        $slash = count(CommandRegistry::slashCommands());
        $palette = count(CommandRegistry::paletteEntries());

        $cmd01 = self::productCells('CMD-01');
        self::assertStringContainsString($all . ' built-ins', $cmd01[0], 'CMD-01 candy-code cell names a stale built-in count');
        self::assertStringContainsString($slash . ' slash commands', $cmd01[1], 'CMD-01 candy-code-web cell names a stale slash-command count');
        self::assertStringContainsString('(' . $all . ' files', self::candyCodeDetail('CMD-01'), 'CMD-01 appendix detail names a stale spec-file count');

        $specFiles = array_values(array_filter(
            (array) scandir(dirname(__DIR__, 2) . '/builtin-commands'),
            static fn (string $f): bool => str_ends_with($f, '.php'),
        ));
        self::assertSame($all, count($specFiles), 'builtin-commands/ file count drifted from the registry');

        self::assertStringContainsString(
            $palette . ' palette rows',
            self::productCells('CMD-08')[0],
            'CMD-08 candy-code cell names a stale palette row count',
        );
    }

    public function testTheProtocolCountsMatchTheGeneratedSchema(): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/docs/protocol/candy-code.v1.schema.json';
        self::assertFileExists($schemaPath);
        $schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
        $methods = count((array) ($schema['methods'] ?? []));
        $events = count((array) ($schema['events'] ?? []));
        self::assertGreaterThan(0, $methods);

        $web02 = self::productCells('WEB-02');
        self::assertStringContainsString($methods . ' methods, ' . $events . ' events', $web02[0], 'WEB-02 candy-code cell names stale schema counts');

        $narrative = self::narrativeText(self::docLines(), 'methods and ');
        self::assertStringContainsString(
            $methods . ' methods and ' . $events . ' event types',
            $narrative,
            'the §4.12 narrative names stale schema counts',
        );

        self::assertStringContainsString(
            'of the ' . $methods . ' methods',
            $web02[1],
            'WEB-02 candy-code-web cell cites a stale method total',
        );
        self::assertStringContainsString(
            'of the ' . $methods . ' protocol methods',
            $narrative,
            'the §4.12 web sentence cites a stale method total',
        );
        self::assertArrayHasKey('session.import', (array) $schema['methods'], 'the doc names session.import as the never-requested verb');
    }
}
