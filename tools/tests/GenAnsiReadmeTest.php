<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * The guard for `tools/gen-ansi-readme.php`, which owns 3,932 rows of
 * `ansi/README.md` and derives every one of them from `ansi/ansi.json`.
 *
 * WHY THIS FILE EXISTS RATHER THAN TRUSTING THE GENERATOR. The same four facts about
 * every piece of art now live in two places, and the whole point of generating one
 * from the other is that they cannot disagree. That guarantee is worth exactly as
 * much as the thing enforcing it: without a guard, a hand-edit to a table row looks
 * completely normal in review, survives until someone next runs the generator, and is
 * then silently reverted. That is not hypothetical for this repo — `tools/gen-docs.php`
 * shipped a `--check` mode described in its own header as "a CI drift guard" that no
 * workflow ran, and hand-edits to generated pages published to the public site for as
 * long as that was true.
 *
 * WHAT MAKES THE ANSI CORPUS ITS OWN CASE. The tables also assert MEASURABLE facts —
 * resolution, required colour depth, colour count — about 3,932 files that are sitting
 * right there. So this guard checks something `gen-docs` cannot: not merely that the
 * two documents agree with each other, but that both agree with the art. A description
 * can only be reviewed by a human; a resolution cannot be wrong without the file saying so.
 *
 * THE MEASUREMENT CONVENTIONS ARE PINNED WITH HAND-BUILT FIXTURES, not by re-deriving
 * them from the corpus. Each of the three was recovered by fitting candidates against
 * the 3,931 rows that already existed, and each has a plausible near-miss that scores
 * almost as well — a colour union (2,468/3,931), a strip-then-trim height (3,921/3,931).
 * A near-miss passes any test written by measuring the corpus and comparing to itself,
 * so the fixtures below encode the DISTINGUISHING case for each convention: the input
 * on which the right definition and the tempting wrong one give different answers.
 */
final class GenAnsiReadmeTest extends TestCase
{
    use TmpTree;

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('gen_ansi_readme_test_');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    // -----------------------------------------------------------------
    // Driving the script
    // -----------------------------------------------------------------

    /** @param list<string> $args @return array{exit:int,output:string} */
    private function runScript(string $root, array $args = []): array
    {
        // escapeshellARG, not escapeshellCMD, for the same reason GenDocsTest spells it
        // that way: PHP_BINARY is one argument, and an interpreter under a path with a
        // space in it otherwise splits into two argv entries.
        $cmd = 'SUGARCRAFT_ANSI_ROOT=' . \escapeshellarg($root)
            . ' ' . \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(\dirname(__DIR__) . '/gen-ansi-readme.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . \escapeshellarg($arg);
        }
        $output = [];
        $exitCode = 0;
        \exec($cmd . ' 2>&1', $output, $exitCode);
        return ['exit' => $exitCode, 'output' => \implode("\n", $output)];
    }

    // -----------------------------------------------------------------
    // Fixture construction
    // -----------------------------------------------------------------

    /**
     * An ansi/ tree with both section kinds, both naming schemes and both sort orders.
     *
     * IT MIRRORS THE REAL LAYOUT RATHER THAN INVENTING ONE, and the `sugarcraft/` directory
     * is load-bearing: section titles map to categories verbatim EXCEPT for the originals
     * table, which is headed "SugarCraft" and holds `sugarcraft/`. A fixture using only
     * invented category names would never execute that translation, and the first draft of
     * this file did exactly that — the originals table generated empty and every assertion
     * about it failed at once.
     */
    private function makeFixtureRoot(): string
    {
        $root = $this->tmpDir;
        \mkdir($root . '/demo', 0777, true);
        \mkdir($root . '/sugarcraft', 0777, true);

        foreach ($this->pieces() as $path => $bytes) {
            \file_put_contents($root . '/' . $path, $bytes);
        }
        \file_put_contents($root . '/ansi.json', \json_encode($this->indexFixture(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
        \file_put_contents($root . '/README.md', $this->readmeFixture());

        // Seed the fixture into a consistent state so every test can perturb exactly one
        // thing. Both steps are needed and in this order: the JSON above carries only the
        // curated fields, so --remeasure fills in the metrics the art proves, and only then
        // can the tables be generated from it. Building the expected tables by hand here
        // would just be a second implementation of the generator.
        foreach ([['--remeasure'], []] as $args) {
            $seed = $this->runScript($root, $args);
            $this->assertSame(0, $seed['exit'], 'fixture seeding failed: ' . $seed['output']);
        }

        return $root;
    }

    /**
     * The art itself. Each file is built to pin one measurement convention.
     *
     * @return array<string,string>
     */
    private function pieces(): array
    {
        return [
            // TRUECOLOR AT THREE COLOURS. The distinguishing case for depth: a piece
            // spending almost no colours, written with 38;2;r;g;b. Depth must read
            // "truecolor" from the SGR FORM. Any count-derived rule calls this "16".
            'sugarcraft/9-sparse-truecolor.ansi' =>
                "\x1b[38;2;255;0;0mA\x1b[38;2;0;255;0mB\x1b[38;2;0;0;255mC\x1b[0m\n",

            // THE SAME HUE AS FOREGROUND AND BACKGROUND. The distinguishing case for the
            // colour count: red appears in both roles, so the per-role convention says 2
            // and a distinct-colour union says 1.
            'sugarcraft/10-shared-hue.ansi' =>
                "\x1b[38;5;196m\x1b[48;5;196mXX\x1b[0m\n",

            // A RESET EPILOGUE WITH NO FINAL NEWLINE. The distinguishing case for height:
            // three rows of art, then "\n\x1b[0m". Trimming on the RAW text keeps that
            // last row (4); stripping escapes first makes it look blank and drops it (3).
            'demo/demo-1-16-s-1.ansi' =>
                "\x1b[31mrow one\x1b[0m\n\x1b[32mrow two\x1b[0m\n\x1b[33mrow three\x1b[0m\n\x1b[0m",

            // 256-COLOUR, and the widest line is not the first — a width taken from
            // line 1 rather than the maximum would report 3 instead of 11.
            'demo/demo-1-256-s-1.ansi' =>
                "\x1b[38;5;201mabc\x1b[0m\n\x1b[38;5;51melevenchar\x1b[0m!\n",

            // PLAIN 16-COLOUR, and lexicographically after the -256- piece while being
            // numerically before it, which is what proves the slot table sorts as strings.
            'demo/demo-2-16-s-1.ansi' =>
                "\x1b[94mbright\x1b[0m\n",
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function indexFixture(): array
    {
        $entry = static fn (string $category, string $name, string $desc): array => [
            'name' => $name, 'category' => $category, 'resolution' => '', 'width' => 0,
            'height' => 0, 'depth' => '', 'colors' => 0, 'description' => $desc,
            'rating' => 0, 'tags' => [],
        ];

        return [
            // A PIPE IN A DESCRIPTION. Until the honey-bounce row was rewritten, the real // @rename-keep historical provenance
            // corpus contained exactly one of these, and an unescaped pipe silently splits
            // the row into extra cells — which is how that row read as undocumented to the
            // first parser written against this table. The corpus no longer exercises the
            // escaping path at all, so it is pinned here or it is pinned nowhere.
            'sugarcraft/9-sparse-truecolor.ansi' => $entry('sugarcraft', '9-sparse-truecolor.ansi', 'three points of light, |v| bars either side'),
            'sugarcraft/10-shared-hue.ansi'      => $entry('sugarcraft', '10-shared-hue.ansi', 'one hue worn as both ink and ground'),
            'demo/demo-1-16-s-1.ansi'            => $entry('demo', 'demo-1-16-s-1.ansi', 'three ruled rows in red, green and gold'),
            'demo/demo-1-256-s-1.ansi'           => $entry('demo', 'demo-1-256-s-1.ansi', 'a short magenta line above a longer cyan one'),
            'demo/demo-2-16-s-1.ansi'            => $entry('demo', 'demo-2-16-s-1.ansi', 'a single bright-blue word'),
        ];
    }

    private function readmeFixture(): string
    {
        return <<<'MD'
            # Fixture ANSI art

            **1 pieces** across 1 categories. 0 come from the original run — 0 in `sugarcraft/`, 0 in `candy-code/`. The other 0 are the per-library slot series. By depth: 0 16-color · 0 256-color · 0 truecolor.

            Prose the generator must not touch.

            ## SugarCraft

            | File | Size | Colors | Description |
            |---|---|---|---|

            More untouched prose.

            <!-- BEGIN demo SLOTS -->
            ### demo — slot series

            | File | Size | Depth | Colors | Description |
            |---|---|---|---|---|
            <!-- END demo SLOTS -->
            MD;
    }

    private function readme(string $root): string
    {
        $path = $root . '/README.md';
        $this->assertFileExists($path);
        return (string) \file_get_contents($path);
    }

    // -----------------------------------------------------------------
    // The measurement conventions
    // -----------------------------------------------------------------

    /**
     * Depth comes from the escape-code form, never from how many colours are spent.
     */
    public function testDepthIsDerivedFromTheSequenceFormNotTheColourCount(): void
    {
        $root = $this->makeFixtureRoot();
        $this->runScript($root, ['--remeasure']);
        $index = $this->decodeIndex($root);

        // Three colours, but written as RGB: this is the row a count-derived rule gets wrong.
        $this->assertSame('truecolor', $index['sugarcraft/9-sparse-truecolor.ansi']['depth']);
        $this->assertSame(3, $index['sugarcraft/9-sparse-truecolor.ansi']['colors']);

        $this->assertSame('256', $index['demo/demo-1-256-s-1.ansi']['depth']);
        $this->assertSame('16', $index['demo/demo-1-16-s-1.ansi']['depth']);
        // Bright/aixterm codes (90-97) are part of the 16-colour set, not an escalation.
        $this->assertSame('16', $index['demo/demo-2-16-s-1.ansi']['depth']);
    }

    /**
     * A hue used as both ink and ground counts twice.
     */
    public function testColoursAreCountedPerRoleSoASharedHueCountsTwice(): void
    {
        $root = $this->makeFixtureRoot();
        $this->runScript($root, ['--remeasure']);

        // 2 under the per-role convention the corpus uses; 1 under a distinct-colour union.
        $this->assertSame(2, $this->decodeIndex($root)['sugarcraft/10-shared-hue.ansi']['colors']);
    }

    /**
     * Height trims trailing blank lines from the RAW text, before escapes are stripped.
     */
    public function testHeightKeepsARowWhoseOnlyContentIsAResetEpilogue(): void
    {
        $root = $this->makeFixtureRoot();
        $this->runScript($root, ['--remeasure']);
        $index = $this->decodeIndex($root);

        // Three visible rows plus the "\x1b[0m" tail. Strip-then-trim would say 9x3.
        $this->assertSame('9x4', $index['demo/demo-1-16-s-1.ansi']['resolution']);
        // And width is the widest line, not the first: "elevenchar!" is 11, "abc" is 3.
        $this->assertSame('11x2', $index['demo/demo-1-256-s-1.ansi']['resolution']);
    }

    // -----------------------------------------------------------------
    // Generation
    // -----------------------------------------------------------------

    /**
     * The two table kinds differ in columns, labels and sort order, and each is reproduced.
     */
    public function testTheTwoSectionKindsGetDifferentColumnsLabelsAndSortOrders(): void
    {
        $readme = $this->readme($this->makeFixtureRoot());

        // Originals: directory-prefixed label, no Depth column, NATURAL order (9 before 10).
        $this->assertStringContainsString(
            "| `sugarcraft/9-sparse-truecolor.ansi` | 3x1 | 3 | three points of light, \\|v\\| bars either side |\n"
            . "| `sugarcraft/10-shared-hue.ansi` | 2x1 | 2 | one hue worn as both ink and ground |",
            $readme
        );

        // Slots: bare label, a Depth column, LEXICOGRAPHIC order (1-256 before 2-16).
        $this->assertStringContainsString(
            "| `demo-1-16-s-1.ansi` | 9x4 | 16 | 3 | three ruled rows in red, green and gold |\n"
            . "| `demo-1-256-s-1.ansi` | 11x2 | 256 | 2 | a short magenta line above a longer cyan one |\n"
            . "| `demo-2-16-s-1.ansi` | 6x1 | 16 | 1 | a single bright-blue word |",
            $readme
        );
    }

    /**
     * A pipe inside a description is escaped, so the row keeps its cell count.
     *
     * The corpus stopped exercising this the moment the one row containing a pipe was
     * rewritten, which is precisely when a guard stops being redundant.
     */
    public function testAPipeInADescriptionIsEscapedRatherThanSplittingTheRow(): void
    {
        $readme = $this->readme($this->makeFixtureRoot());

        $row = null;
        foreach (\explode("\n", $readme) as $line) {
            if (\str_starts_with($line, '| `sugarcraft/9-sparse-truecolor.ansi`')) {
                $row = $line;
            }
        }
        $this->assertNotNull($row);
        $this->assertStringContainsString('\\|v\\|', $row);
        // Four columns means three unescaped delimiters between the outer pair.
        $this->assertCount(4, \preg_split('/(?<!\\\\)\|/', \trim($row, '| ')) ?: []);
    }

    /** Prose, headings and the BEGIN/END markers survive a regeneration untouched. */
    public function testProseAndMarkersAreLeftAlone(): void
    {
        $readme = $this->readme($this->makeFixtureRoot());

        $this->assertStringContainsString('Prose the generator must not touch.', $readme);
        $this->assertStringContainsString('More untouched prose.', $readme);
        $this->assertStringContainsString('<!-- BEGIN demo SLOTS -->', $readme);
        $this->assertStringContainsString('<!-- END demo SLOTS -->', $readme);
        $this->assertStringContainsString('### demo — slot series', $readme);
    }

    /**
     * The header counts are derived on every run.
     *
     * The fixture's header ships deliberately WRONG ("1 pieces ... 0 truecolor"), because
     * the real one said "224 pieces" across 3,708 subsequent additions. A patcher that
     * silently no-ops leaves a fixture that looks fine unless the seed values are wrong.
     */
    public function testTheHeaderCountsAreRecomputedFromTheIndex(): void
    {
        $readme = $this->readme($this->makeFixtureRoot());

        $this->assertStringContainsString('**5 pieces** across 2 categories', $readme);
        $this->assertStringContainsString('2 come from the original run — 2 in `sugarcraft/`, 0 in `candy-code/`', $readme);
        $this->assertStringContainsString('The other 3 are the per-library slot series', $readme);
        $this->assertStringContainsString('By depth: 2 16-color · 2 256-color · 1 truecolor.', $readme);
        $this->assertStringNotContainsString('**1 pieces**', $readme);
    }

    /** Regeneration is idempotent — a second run changes nothing. */
    public function testRegenerationIsIdempotent(): void
    {
        $root = $this->makeFixtureRoot();
        $once = $this->readme($root);
        $this->runScript($root);

        $this->assertSame($once, $this->readme($root));
    }

    // -----------------------------------------------------------------
    // --check, in both polarities
    // -----------------------------------------------------------------

    /**
     * A guard that never goes red is indistinguishable from no guard, so every drift
     * this mode claims to catch is provoked here and asserted to fail.
     *
     * @dataProvider driftProvider
     */
    public function testCheckGoesRedOnDrift(callable $perturb, string $expectedSubstring): void
    {
        $root = $this->makeFixtureRoot();
        $this->assertSame(0, $this->runScript($root, ['--check'])['exit'], 'fixture should start clean');

        $perturb($root, $this);

        $result = $this->runScript($root, ['--check']);
        $this->assertSame(1, $result['exit'], "--check passed on drifted tree.\n" . $result['output']);
        $this->assertStringContainsString($expectedSubstring, $result['output']);
    }

    /** @return array<string,array{0:callable,1:string}> */
    public static function driftProvider(): array
    {
        return [
            'a hand-edited table row' => [
                static function (string $root): void {
                    \file_put_contents($root . '/README.md', \str_replace(
                        'a single bright-blue word',
                        'a description nobody put in the json',
                        (string) \file_get_contents($root . '/README.md')
                    ));
                },
                'does not match ansi.json',
            ],
            'a description edited in the json but not regenerated' => [
                static function (string $root, self $t): void {
                    $index = $t->decodeIndex($root);
                    $index['demo/demo-2-16-s-1.ansi']['description'] = 'rewritten, never regenerated';
                    $t->writeIndex($root, $index);
                },
                'does not match ansi.json',
            ],
            'art on disk with no json entry' => [
                static function (string $root): void {
                    \file_put_contents($root . '/demo/demo-3-16-s-1.ansi', "\x1b[31mnew\x1b[0m\n");
                },
                'exists on disk but has no ansi.json entry',
            ],
            'a json entry whose art is gone' => [
                static function (string $root): void {
                    \unlink($root . '/demo/demo-2-16-s-1.ansi');
                },
                'has an ansi.json entry but no file on disk',
            ],
            'a stale resolution' => [
                static function (string $root, self $t): void {
                    $index = $t->decodeIndex($root);
                    $index['demo/demo-1-16-s-1.ansi']['resolution'] = '9x3';
                    $t->writeIndex($root, $index);
                },
                'resolution: ansi.json says 9x3, the file measures 9x4',
            ],
            'a stale depth' => [
                static function (string $root, self $t): void {
                    $index = $t->decodeIndex($root);
                    $index['sugarcraft/9-sparse-truecolor.ansi']['depth'] = '16';
                    $t->writeIndex($root, $index);
                },
                'depth: ansi.json says 16, the file measures truecolor',
            ],
            'a stale colour count' => [
                static function (string $root, self $t): void {
                    $index = $t->decodeIndex($root);
                    $index['sugarcraft/10-shared-hue.ansi']['colors'] = 1;
                    $t->writeIndex($root, $index);
                },
                'colors: ansi.json says 1, the file measures 2',
            ],
            'an empty description' => [
                static function (string $root, self $t): void {
                    $index = $t->decodeIndex($root);
                    $index['demo/demo-2-16-s-1.ansi']['description'] = '   ';
                    $t->writeIndex($root, $index);
                },
                'has no description',
            ],
        ];
    }

    /**
     * --remeasure repairs stale metrics without touching the curated fields.
     *
     * Those two halves are the whole contract: everything the art can prove is
     * recomputed, and everything only a human can supply is carried across. A
     * remeasure that reset `rating` and `tags` would be silently destructive.
     */
    public function testRemeasureFixesMetricsAndPreservesCuration(): void
    {
        $root = $this->makeFixtureRoot();
        $index = $this->decodeIndex($root);
        $index['demo/demo-1-16-s-1.ansi']['resolution'] = '1x1';
        $index['demo/demo-1-16-s-1.ansi']['depth'] = 'mono';
        $index['demo/demo-1-16-s-1.ansi']['colors'] = 99;
        $index['demo/demo-1-16-s-1.ansi']['rating'] = 5;
        $index['demo/demo-1-16-s-1.ansi']['tags'] = ['keepme'];
        $this->writeIndex($root, $index);

        $this->assertSame(0, $this->runScript($root, ['--remeasure'])['exit']);
        $entry = $this->decodeIndex($root)['demo/demo-1-16-s-1.ansi'];

        $this->assertSame('9x4', $entry['resolution']);
        $this->assertSame('16', $entry['depth']);
        $this->assertSame(3, $entry['colors']);
        $this->assertSame('three ruled rows in red, green and gold', $entry['description']);
        $this->assertSame(5, $entry['rating']);
        $this->assertSame(['keepme'], $entry['tags']);
    }

    /** --remeasure adopts new art and drops entries whose file is gone. */
    public function testRemeasureAdoptsNewArtAndDropsVanishedEntries(): void
    {
        $root = $this->makeFixtureRoot();
        \file_put_contents($root . '/demo/demo-3-16-s-1.ansi', "\x1b[31mnew\x1b[0m\n");
        \unlink($root . '/demo/demo-2-16-s-1.ansi');

        $this->assertSame(0, $this->runScript($root, ['--remeasure'])['exit']);
        $index = $this->decodeIndex($root);

        $this->assertArrayHasKey('demo/demo-3-16-s-1.ansi', $index);
        $this->assertSame('3x1', $index['demo/demo-3-16-s-1.ansi']['resolution']);
        $this->assertArrayNotHasKey('demo/demo-2-16-s-1.ansi', $index);
        // The adopted entry has no description yet, so --check must still refuse it.
        $this->assertSame(1, $this->runScript($root, ['--check'])['exit']);
    }

    // -----------------------------------------------------------------
    // The real tree
    // -----------------------------------------------------------------

    /**
     * The guard, pointed at the actual corpus.
     *
     * Every test above runs against a five-file fixture and proves the generator behaves;
     * this one is the assertion that the repository is in fact in that state. It is the
     * reason a contributor who hand-edits a row, or adds art without a JSON entry, or
     * edits a description in only one of the two files, finds out at test time.
     */
    public function testTheCommittedCorpusIsConsistent(): void
    {
        $result = $this->runScript(\dirname(__DIR__, 2) . '/ansi', ['--check']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('agree across', $result['output']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array<string,array<string,mixed>> */
    private function decodeIndex(string $root): array
    {
        return \json_decode((string) \file_get_contents($root . '/ansi.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string,array<string,mixed>> $index */
    private function writeIndex(string $root, array $index): void
    {
        \file_put_contents(
            $root . '/ansi.json',
            \json_encode($index, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n"
        );
    }
}
