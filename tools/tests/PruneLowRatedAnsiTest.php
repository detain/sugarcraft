<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * The guard for `tools/prune-low-rated-ansi.php`, the one tools/ script that
 * DELETES art: it drops every ansi/ansi.json entry rated 0 or 1, unlinks the
 * matching .ansi files, and re-projects ansi/README.md via the generator.
 *
 * WHY EVERY DESTRUCTIVE ASSERTION RUNS AGAINST A THROWAWAY TREE. The committed
 * corpus carries hundreds of rating-0/1 entries, so a guard that exercised
 * --apply in-repo would prune the corpus to pass its own test, and the CI job
 * would be an act of vandalism with a green check beside it. Everything here
 * builds a fixture under sys_get_temp_dir() and points SUGARCRAFT_ANSI_ROOT at
 * it, exactly the way GenAnsiReadmeTest.php drives the generator; the real tree
 * is only ever read, via the tool's dry-run polarity on… no, not even that —
 * the fixture pins every behaviour, and ToolsEnvRosterTest keeps the knob's
 * documentation honest in both directions.
 *
 * WHY THE FIXTURE HAND-OLDS THE MEASUREMENTS INSTEAD OF CALLING --remeasure.
 * The generator's --remeasure rewrites every entry as `'rating' => $entry['rating']`,
 * which would silently GIVE the missing-rating fixture entry an explicit null and
 * erase the exact shape this prune must keep. So the JSON ships pre-correct: every
 * piece is the same two-green-chars file whose measurement is '2x1', '16', 1 — and
 * makeFixtureRoot asserts `--check` is green before any test perturbs anything, so
 * a hand-typed metric that stops matching the bytes fails loudly at the seed.
 *
 * THE THREE KEPT POLARITIES ARE EACH THE USER'S EXPLICIT CARVE-OUT, pinned
 * separately because each fails a different way: a null rating means "unreviewed"
 * and pruning it would delete unjudged art on absence of evidence; a missing
 * rating key must survive --apply with the key still ABSENT (an "repair" that
 * writes null would rewrite curated data the tool has no business touching);
 * and a present-but-non-int rating is corruption the tool reports and keeps
 * rather than guesses a verdict from.
 */
final class PruneLowRatedAnsiTest extends TestCase
{
    use TmpTree;

    /** Every fixture piece is these bytes: measures 2x1, depth 16, one colour. */
    private const PIECE = "\x1b[32mab\x1b[0m\n";

    /** The two prunable keys, and the five keeps, named so assertions quote intent. */
    private const PRUNED_ZERO  = 'cat/cat-1-16-s-1.ansi';
    private const PRUNED_ONE   = 'cat/cat-2-16-s-1.ansi';
    private const KEPT_TWO     = 'cat/cat-3-16-s-1.ansi';
    private const KEPT_FIVE    = 'cat/cat-4-16-s-1.ansi';
    private const KEPT_NULL    = 'cat/cat-5-16-s-1.ansi';
    private const KEPT_ABSENT  = 'cat/cat-6-16-s-1.ansi';
    private const KEPT_ANOMALY = 'cat/cat-7-16-s-1.ansi';

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('prune_low_rated_ansi_test_');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    // -----------------------------------------------------------------
    // Driving the scripts
    // -----------------------------------------------------------------

    /** @param list<string> $args @return array{exit:int,output:string} */
    private function runTool(string $root, array $args = []): array
    {
        return $this->runScript('prune-low-rated-ansi.php', $root, $args);
    }

    /** @param list<string> $args @return array{exit:int,output:string} */
    private function runGenerator(string $root, array $args = []): array
    {
        return $this->runScript('gen-ansi-readme.php', $root, $args);
    }

    /**
     * @param list<string> $args
     * @return array{exit:int,output:string}
     */
    private function runScript(string $script, string $root, array $args): array
    {
        // escapeshellarg on every interpolated value, PHP_BINARY included, for
        // the same reason GenAnsiReadmeTest.php spells it that way: an
        // interpreter under a path with a space must stay one argv entry.
        $cmd = 'SUGARCRAFT_ANSI_ROOT=' . \escapeshellarg($root)
            . ' ' . \escapeshellarg(\PHP_BINARY)
            . ' ' . \escapeshellarg(\dirname(__DIR__) . '/' . $script);
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
     * A consistent ansi/ tree: seven entries under one `cat` category, ratings
     * spanning 0, 1, kept ints, null, absent-key and a string anomaly.
     *
     * SEEDED THROUGH THE GENERATOR ITSELF (bare mode, once) rather than by
     * hand-typing table rows: the README shape is gen-ansi-readme.php's to know,
     * and re-implementing its row format here would be a second projection that
     * can drift from the first. The count line is deliberately wrong until that
     * run patches it, because the counts being derived-not-copied is the very
     * property the prune leans on when it delegates the README.
     */
    private function makeFixtureRoot(): string
    {
        $root = $this->tmpDir;
        \mkdir($root . '/cat', 0777, true);

        foreach ($this->indexFixture() as $key => $entry) {
            \file_put_contents($root . '/' . $key, self::PIECE);
            $this->assertSame('cat', $entry['category']);
        }
        \file_put_contents($root . '/ansi.json', $this->encodeIndex($this->indexFixture()));
        \file_put_contents($root . '/README.md', $this->readmeSeed());

        $seed = $this->runGenerator($root);
        $this->assertSame(0, $seed['exit'], 'fixture seeding failed: ' . $seed['output']);

        $clean = $this->runGenerator($root, ['--check']);
        $this->assertSame(0, $clean['exit'], 'fixture did not seed check-clean: ' . $clean['output']);

        return $root;
    }

    /** @return array<string,array<string,mixed>> */
    private function indexFixture(): array
    {
        // One entry shape, seven ratings. The measurements are the truth for
        // PIECE (see the class doc-block); the descriptions keep the README
        // rows distinguishable if a render ever needs eyeballing.
        $entry = static fn (string $name, string $description): array => [
            'name' => $name, 'category' => 'cat', 'resolution' => '2x1', 'width' => 2,
            'height' => 1, 'depth' => '16', 'colors' => 1, 'description' => $description,
            'rating' => 2, 'tags' => [],
        ];

        $absentKey = $entry('cat-6-16-s-1.ansi', 'the unreviewed piece whose rating key is absent');
        unset($absentKey['rating']);

        $index = [
            self::PRUNED_ZERO    => $entry('cat-1-16-s-1.ansi', 'the piece scored zero'),
            self::PRUNED_ONE     => $entry('cat-2-16-s-1.ansi', 'the piece scored one'),
            self::KEPT_TWO       => $entry('cat-3-16-s-1.ansi', 'the piece scored two'),
            self::KEPT_FIVE      => $entry('cat-4-16-s-1.ansi', 'the piece scored five'),
            self::KEPT_NULL      => $entry('cat-5-16-s-1.ansi', 'the unreviewed piece rated null'),
            self::KEPT_ABSENT    => $absentKey,
            self::KEPT_ANOMALY   => $entry('cat-7-16-s-1.ansi', 'the piece whose rating corrupted to a string'),
        ];
        $index[self::PRUNED_ZERO]['rating']  = 0;
        $index[self::PRUNED_ONE]['rating']   = 1;
        $index[self::KEPT_FIVE]['rating']    = 5;
        $index[self::KEPT_NULL]['rating']    = null;
        $index[self::KEPT_ANOMALY]['rating'] = '3';

        return $index;
    }

    /** @return array<string,array<string,mixed>> */
    private function expectedKeptIndex(): array
    {
        $index = $this->indexFixture();
        unset($index[self::PRUNED_ZERO], $index[self::PRUNED_ONE]);
        return $index;
    }

    private function readmeSeed(): string
    {
        return <<<'MD'
            # Fixture ANSI art

            **99 pieces** across 9 categories. deliberately wrong counts for the seeder to patch.

            Prose the generator must not touch.

            ## cat — slot series

            | File | Size | Depth | Colors | Description |
            |---|---|---|---|---|
            MD;
    }

    /** @param array<string,array<string,mixed>> $index */
    private function encodeIndex(array $index): string
    {
        return \json_encode($index, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** @return array<string,array<string,mixed>> */
    private function decodeIndex(string $root): array
    {
        return \json_decode((string) \file_get_contents($root . '/ansi.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string,array<string,mixed>> $index */
    private function writeIndex(string $root, array $index): void
    {
        \file_put_contents($root . '/ansi.json', $this->encodeIndex($index));
    }

    /**
     * Every file under the root, path => bytes, for whole-tree identity claims.
     *
     * @return array<string,string>
     */
    private function snapshotTree(string $root): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $entry) {
            if ($entry->isFile()) {
                $files[\substr($entry->getPathname(), \strlen($root) + 1)] = (string) \file_get_contents($entry->getPathname());
            }
        }
        \ksort($files);
        return $files;
    }

    // -----------------------------------------------------------------
    // --help
    // -----------------------------------------------------------------

    /**
     * --help works even pointed at a root that does not exist, because it is
     * answered BEFORE the root is resolved — the sibling generator's reasoning,
     * and the reason a contributor with a mistyped SUGARCRAFT_ANSI_ROOT can
     * still read what that variable means.
     */
    public function testHelpIsAnsweredBeforeTheRootIsResolved(): void
    {
        $result = $this->runTool(\sys_get_temp_dir() . '/absent-root-' . \uniqid('', true), ['--help']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('usage: prune-low-rated-ansi.php', $result['output']);
        $this->assertStringContainsString('SUGARCRAFT_ANSI_ROOT', $result['output']);
        $this->assertStringContainsString('DRY RUN', $result['output']);
    }

    // -----------------------------------------------------------------
    // Dry run: the safe default
    // -----------------------------------------------------------------

    /**
     * The no-flag mode lists exactly the 0/1 entries — with the null, absent-key
     * and string-rating keeps visible in the roles the tool gives them — and
     * leaves the ENTIRE tree byte-identical.
     *
     * THE WHOLE-TREE SNAPSHOT IS THE POINT OF THIS TEST. "Changes nothing on
     * disk" is the promise every other behaviour of this tool rests on; an
     * assertion over the three artifact files would miss a stray temp write,
     * and the fixture is small enough that nothing has to be left unchecked.
     */
    public function testDryRunListsExactlyThePrunableEntriesAndChangesNothing(): void
    {
        $root = $this->makeFixtureRoot();
        $before = $this->snapshotTree($root);

        $result = $this->runTool($root);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('cat/cat-1-16-s-1.ansi  rating 0  art present', $result['output']);
        $this->assertStringContainsString('cat/cat-2-16-s-1.ansi  rating 1  art present', $result['output']);
        $this->assertStringContainsString('per-category: cat: 2', $result['output']);
        $this->assertStringContainsString(
            'totals: 2 matched, 2 present to unlink, 0 already missing, 1 anomalies kept',
            $result['output']
        );
        $this->assertStringContainsString("rating '3' (string) is neither int nor null — kept fail-closed", $result['output']);
        $this->assertStringContainsString('dry run: nothing changed on disk', $result['output']);

        // The keeps are not listed in the match section at all; only the
        // anomaly's own report line may name cat-7.
        $this->assertStringNotContainsString(self::KEPT_TWO . '  rating', $result['output']);
        $this->assertStringNotContainsString(self::KEPT_NULL . '  rating', $result['output']);
        $this->assertStringNotContainsString(self::KEPT_ABSENT, $result['output']);

        $this->assertSame($before, $this->snapshotTree($root), 'the dry run touched the tree');
    }

    // -----------------------------------------------------------------
    // --apply: the three artifacts
    // -----------------------------------------------------------------

    /**
     * --apply removes the matching keys from ansi.json and unlinks exactly
     * their art, in one pass, and nothing else changes.
     *
     * THE BYTE-EXACT JSON ASSERTION CARRIES THREE CLAIMS AT ONCE: the right key
     * set survives, in the surviving ORDER (unset keeps order, so the natural
     * sort --remeasure maintains survives the prune), and under the generator's
     * OWN encoding flags plus trailing newline — a prune that reflowed ansi.json
     * would bury the real diff in thousands of reformatted lines.
     */
    public function testApplyPrunesTheJsonAndUnlinksExactlyThePrunedArt(): void
    {
        $root = $this->makeFixtureRoot();
        $before = $this->snapshotTree($root);

        $result = $this->runTool($root, ['--apply']);

        $this->assertSame(0, $result['exit'], $result['output']);

        $this->assertSame($this->encodeIndex($this->expectedKeptIndex()), (string) \file_get_contents($root . '/ansi.json'));
        $this->assertFileDoesNotExist($root . '/' . self::PRUNED_ZERO);
        $this->assertFileDoesNotExist($root . '/' . self::PRUNED_ONE);

        // Survivors byte-identical: the kept .ansi files, and everything the
        // prune must not have touched even while touching the rest.
        foreach ([self::KEPT_TWO, self::KEPT_FIVE, self::KEPT_NULL, self::KEPT_ABSENT, self::KEPT_ANOMALY] as $kept) {
            $this->assertSame($before[$kept], (string) \file_get_contents($root . '/' . $kept), $kept . ' art was modified');
        }

        // The category directory survives because keeps still live in it.
        $this->assertFileExists($root . '/cat/' . basename(self::KEPT_TWO));
    }

    /**
     * The README loses exactly the pruned rows and keeps everything else, and
     * the "**N pieces**" header recount is what PROVES the delegation, not a
     * row patch: only gen-ansi-readme.php re-derives those counts, so a green
     * recount here means the README was regenerated rather than hand-cut.
     */
    public function testApplyDelegatesTheReadmeToTheGeneratorAndTheCountsRecount(): void
    {
        $root = $this->makeFixtureRoot();

        $result = $this->runTool($root, ['--apply']);
        $this->assertSame(0, $result['exit'], $result['output']);

        $readme = (string) \file_get_contents($root . '/README.md');
        $this->assertStringNotContainsString('| `' . basename(self::PRUNED_ZERO) . '` |', $readme);
        $this->assertStringNotContainsString('| `' . basename(self::PRUNED_ONE) . '` |', $readme);
        foreach ([self::KEPT_TWO, self::KEPT_FIVE, self::KEPT_NULL, self::KEPT_ABSENT, self::KEPT_ANOMALY] as $kept) {
            $this->assertStringContainsString('| `' . basename($kept) . '` |', $readme, $kept . ' lost its README row');
        }
        $this->assertStringContainsString('**5 pieces**', $readme);
        $this->assertStringContainsString('Prose the generator must not touch.', $readme);

        $this->assertStringContainsString('post-prune self-check: ansi.json and ansi/README.md agree across 5 pieces', $result['output']);

        // And the check is re-run here independently: the tool's own final step
        // could pass on stale bytes if it read something other than what it wrote.
        $check = $this->runGenerator($root, ['--check']);
        $this->assertSame(0, $check['exit'], $check['output']);
    }

    /**
     * The three kept rating polarities survive --apply untouched, which is the
     * user's explicit carve-out stated in data: 0 is a score, null and absent
     * are the ABSENCE of a score, and a string is corruption — none of the
     * three is a verdict to prune on.
     *
     * THE ABSENT-KEY HALF IS THE EASY ONE TO BREAK AND THE REASON IT IS PINNED
     * AGAINST THE FILE RATHER THAN THE ARRAY: any "repair" that defaults missing
     * ratings to null while rewriting the JSON would pass every behavioural test
     * above and silently overwrite curation the tool never owns.
     */
    public function testApplyKeepsUnreviewedAbsentAndAnomalousRatingsUntouched(): void
    {
        $root = $this->makeFixtureRoot();

        $this->assertSame(0, $this->runTool($root, ['--apply'])['exit']);

        $index = $this->decodeIndex($root);
        $this->assertNull($index[self::KEPT_NULL]['rating']);
        $this->assertArrayNotHasKey('rating', $index[self::KEPT_ABSENT]);
        $this->assertSame('3', $index[self::KEPT_ANOMALY]['rating']);
        $this->assertSame(2, $index[self::KEPT_TWO]['rating']);
        $this->assertSame(5, $index[self::KEPT_FIVE]['rating']);
        $this->assertSame(
            [self::KEPT_TWO, self::KEPT_FIVE, self::KEPT_NULL, self::KEPT_ABSENT, self::KEPT_ANOMALY],
            \array_keys($index),
            'the surviving key order is not the seed order minus the pruned pair'
        );
    }

    // -----------------------------------------------------------------
    // The warning polarity: pruned entry whose art is already gone
    // -----------------------------------------------------------------

    /**
     * A prunable key with no file on disk warns and carries on: the JSON entry
     * going away IS the repair of the orphan, and failing the run would leave
     * the corpus half-pruned for a state the tool was summoned to fix.
     */
    public function testApplyWarnsRatherThanFailingWhenAPrunableFileIsAlreadyMissing(): void
    {
        $root = $this->makeFixtureRoot();
        \unlink($root . '/' . self::PRUNED_ZERO);

        $result = $this->runTool($root, ['--apply']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('totals: 2 matched, 1 present to unlink, 1 already missing', $result['output']);
        $this->assertStringContainsString('warning: cat/cat-1-16-s-1.ansi had no art file on disk', $result['output']);
        $this->assertFileDoesNotExist($root . '/' . self::PRUNED_ONE);
        $index = $this->decodeIndex($root);
        $this->assertArrayNotHasKey(self::PRUNED_ZERO, $index);
        $this->assertArrayNotHasKey(self::PRUNED_ONE, $index);

        $check = $this->runGenerator($root, ['--check']);
        $this->assertSame(0, $check['exit'], $check['output']);
    }

    // -----------------------------------------------------------------
    // Refusals: nothing is deleted, nothing is written
    // -----------------------------------------------------------------

    /**
     * A rating-0 key that is not `<category>/<name>` shaped aborts BOTH modes
     * with exit 2 and an untouched tree.
     *
     * THE ABORT IS CHECKED IN DRY-RUN TOO, deliberately: the shape rule exists
     * so no deletion path is ever computed from a malformed key, and a dry run
     * that reported `cat/../evil` as a listable entry would train operators to
     * --apply past it. The message naming the key is what turns the refusal
     * into a diagnosis.
     */
    public function testAMalformedPrunableKeyAbortsEveryModeWithNothingDeleted(): void
    {
        $root = $this->makeFixtureRoot();
        $index = $this->decodeIndex($root);
        $poison = $this->indexFixture()[self::PRUNED_ZERO];
        $poison['name'] = 'evil';
        $index['cat/../evil'] = $poison;
        $this->writeIndex($root, $index);
        $planted = $this->snapshotTree($root);

        $dry = $this->runTool($root);
        $this->assertSame(2, $dry['exit'], $dry['output']);
        $this->assertStringContainsString('cat/../evil', $dry['output']);
        $this->assertStringContainsString("not '<category>/<name>' shaped", $dry['output']);

        $apply = $this->runTool($root, ['--apply']);
        $this->assertSame(2, $apply['exit'], $apply['output']);
        $this->assertStringContainsString('cat/../evil', $apply['output']);

        $this->assertSame($planted, $this->snapshotTree($root), 'the refusal still touched the tree');
        foreach ([self::PRUNED_ZERO, self::PRUNED_ONE] as $prunable) {
            $this->assertFileExists($root . '/' . $prunable);
        }
    }

    /**
     * A missing required file is exit 2 naming it, mirroring the sibling. The
     * two polarities peel off ONE seeded tree — ansi.json is checked first, so
     * the README-missing case must run while the JSON is still there.
     */
    public function testMissingRequiredFilesExitTwoNamingThePath(): void
    {
        $root = $this->makeFixtureRoot();
        $this->assertSame(0, $this->runTool($root)['exit'], 'control: complete fixture dry-runs');

        \unlink($root . '/README.md');
        $noReadme = $this->runTool($root, ['--apply']);
        $this->assertSame(2, $noReadme['exit'], $noReadme['output']);
        $this->assertStringContainsString('missing ' . $root . '/README.md', $noReadme['output']);

        \unlink($root . '/ansi.json');
        $noJson = $this->runTool($root);
        $this->assertSame(2, $noJson['exit'], $noJson['output']);
        $this->assertStringContainsString('missing ' . $root . '/ansi.json', $noJson['output']);
    }

    /**
     * An unrecognised flag exits 2 rather than silently meaning "dry run": a
     * typo like `--aply` under the safe-default design would otherwise print a
     * convincing report and change nothing, and its operator would have every
     * reason to believe the prune landed.
     */
    public function testAnUnknownFlagIsRefusedNotMistakenForTheDefault(): void
    {
        $root = $this->makeFixtureRoot();

        $result = $this->runTool($root, ['--frobnicate']);

        $this->assertSame(2, $result['exit'], $result['output']);
        $this->assertStringContainsString("unknown argument '--frobnicate'", $result['output']);
        $this->assertStringContainsString('--help', $result['output']);
    }

    /**
     * --apply with an empty match set says so and rewrites nothing — including
     * the README, whose regeneration a no-op run would churn for no entry.
     */
    public function testApplyWithNoMatchedEntriesReportsAndChangesNothing(): void
    {
        $root = $this->makeFixtureRoot();
        $index = $this->decodeIndex($root);
        unset($index[self::PRUNED_ZERO], $index[self::PRUNED_ONE]);
        $this->writeIndex($root, $index);
        // Drop the string anomaly too so the totals line below is zero-clean.
        $index = $this->decodeIndex($root);
        $index[self::KEPT_ANOMALY]['rating'] = 2;
        $this->writeIndex($root, $index);
        $before = $this->snapshotTree($root);

        $result = $this->runTool($root, ['--apply']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('totals: 0 matched, 0 present to unlink, 0 already missing, 0 anomalies kept', $result['output']);
        $this->assertStringContainsString('nothing to prune', $result['output']);
        $this->assertSame($before, $this->snapshotTree($root), 'the no-op prune still touched the tree');
    }
}
