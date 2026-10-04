<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Crush\Context\Rule;
use SugarCraft\Crush\Context\RepoMapBlock;
use SugarCraft\Crush\Context\RuleLoader;

/**
 * Audit 15d-17: the count caps of the two tree walks in `src/Context/` are
 * applied in SORTED order, and every entry the walk lists spends a visit
 * budget.
 *
 * Both walks used to cap in RecursiveDirectoryIterator order, which is readdir
 * order: hash order on ext4, creation order on tmpfs. Past a cap, WHICH rules
 * loaded and which source directories were counted therefore depended on the
 * filesystem a clone sat on. Readdir order cannot be set from a test, so each
 * fixture here is CREATED in a shuffled order (fixed seed) and asserts that the
 * survivors are exactly the sorted-first entries; under the old walk the
 * surviving set followed the directory's hash order instead and these went red.
 * The budget tests prove that entries which are not rules / not `.php` now
 * count: the old walks only ever counted the files they were looking for.
 */
final class SortedWalkCapTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/sugarcrush_sortedwalk_' . bin2hex(random_bytes(8));
        mkdir($this->sandbox, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->sandbox);

        parent::tearDown();
    }

    // -- RuleLoader ------------------------------------------------------------

    public function testTheRuleFileCapKeepsTheSortedFirstFilesWhateverOrderTheyWereCreatedIn(): void
    {
        $cap = self::ruleLoaderConstant('MAX_FILES');
        $rules = $this->projectRulesDir('repo-cap');

        $names = [];
        for ($i = 0; $i < $cap + 16; $i++) {
            $names[] = sprintf('r%03d', $i);
        }
        foreach (self::shuffled($names) as $name) {
            file_put_contents($rules . '/' . $name . '.md', "---\nname: {$name}\n---\nB\n");
        }

        $loader = new RuleLoader($this->sandbox . '/repo-cap');
        $loaded = $loader->loadProjectRules();

        self::assertSame(
            array_slice($names, 0, $cap),
            self::ruleNames($loaded),
            'the rules that survive the file cap are the sorted first ones, not the first ones readdir happened to list',
        );

        $skipped = array_map(
            static fn(string $path): string => basename($path, '.md'),
            array_keys($loader->skippedFiles()),
        );
        sort($skipped, SORT_STRING);
        self::assertSame(array_slice($names, $cap), $skipped, 'exactly the sorted tail is recorded as cap-skipped');
    }

    public function testTheRuleFileCapIsSortedAcrossSubdirectoriesToo(): void
    {
        // A cap decided per-directory in readdir order could also depend on
        // which SUBDIRECTORY readdir listed first; the candidates are sorted as
        // whole paths, so `s0/...` always outranks `s1/...`.
        $cap = self::ruleLoaderConstant('MAX_FILES');
        $rules = $this->projectRulesDir('repo-nested');

        // Eight subdirectories of a fifth of the cap each, so the cap falls
        // part-way through the sixth: only a fully sorted selection survives,
        // whatever order readdir lists the directories or their files in.
        $paths = [];
        foreach (['s0', 's1', 's2', 's3', 's4', 's5', 's6', 's7'] as $sub) {
            mkdir($rules . '/' . $sub);
            for ($i = 0; $i < intdiv($cap, 5); $i++) {
                $paths[] = sprintf('%s/n%03d', $sub, $i);
            }
        }
        foreach (self::shuffled($paths) as $path) {
            file_put_contents($rules . '/' . $path . '.md', "N\n");
        }

        $loaded = (new RuleLoader($this->sandbox . '/repo-nested'))->loadProjectRules();

        self::assertSame(
            array_slice($paths, 0, $cap),
            array_map(static fn(Rule $rule): string => $rule->key, $loaded),
            'the surviving keys are the sorted first paths of the whole tier',
        );
    }

    public function testEveryEntryOfTheRuleWalkSpendsItsVisitBudgetAndTheCutIsRecorded(): void
    {
        $budget = self::ruleLoaderConstant('MAX_WALK_ENTRIES');
        self::assertGreaterThan(self::ruleLoaderConstant('MAX_FILES'), $budget, 'the walk budget is a backstop above the read cap');

        $rules = $this->projectRulesDir('repo-budget');

        // `a.md` sorts before every `a#####.txt` (`.` is 0x2E, `0` is 0x30) and
        // `z.md` after all of them, so with `budget` non-rule files in between
        // the budget is spent before the walk reaches `z.md`. The old walk
        // counted only `*.md` files and loaded both.
        file_put_contents($rules . '/a.md', "---\nname: first\n---\nA\n");
        file_put_contents($rules . '/z.md', "---\nname: last\n---\nZ\n");
        for ($i = 0; $i < $budget; $i++) {
            touch(sprintf('%s/a%05d.txt', $rules, $i));
        }

        $loader = new RuleLoader($this->sandbox . '/repo-budget');
        $loaded = $loader->loadProjectRules();

        self::assertSame(['first'], self::ruleNames($loaded), 'the walk stopped on its entry budget before z.md');

        $skipped = $loader->skippedFiles();
        $realRules = (string) realpath($rules);
        self::assertArrayHasKey($realRules, $skipped, 'the cut is recorded against the tier directory, never silent');
        self::assertStringContainsString('walk budget', $skipped[$realRules]);
    }

    public function testARuleTreeUnderTheWalkBudgetRecordsNoCut(): void
    {
        $rules = $this->projectRulesDir('repo-small');
        file_put_contents($rules . '/one.md', "---\nname: one\n---\nO\n");
        for ($i = 0; $i < 10; $i++) {
            touch(sprintf('%s/note%02d.txt', $rules, $i));
        }

        $loader = new RuleLoader($this->sandbox . '/repo-small');

        self::assertSame(['one'], self::ruleNames($loader->loadProjectRules()));
        self::assertSame([], $loader->skippedFiles());
    }

    // -- RepoMapBlock ----------------------------------------------------------

    public function testTheSourceFileCapCountsTheSortedFirstDirectoriesWhateverOrderTheyWereCreatedIn(): void
    {
        $base = $this->sandbox . '/src';
        mkdir($base);
        $dirs = [];
        for ($i = 0; $i < 24; $i++) {
            $dirs[] = sprintf('d%02d', $i);
        }
        foreach (self::shuffled($dirs) as $dir) {
            mkdir($base . '/' . $dir);
            file_put_contents($base . '/' . $dir . '/A.php', '<?php');
        }

        // Five files left under MAX_SOURCE_FILES: the walk must spend them on
        // the five sorted-first directories, wherever readdir put them.
        $visited = RepoMapBlock::MAX_SOURCE_FILES - 5;
        $counts = self::walk($base, $visited);

        self::assertSame(array_fill_keys(array_slice($dirs, 0, 5), 1), $counts);
        self::assertSame(RepoMapBlock::MAX_SOURCE_FILES, $visited);
    }

    public function testEveryEntryOfTheSourceWalkSpendsTheWalkBudgetNotOnlyPhpFiles(): void
    {
        $base = $this->sandbox . '/src';
        mkdir($base);
        foreach (['d.php', 'b.txt', 'c.php', 'a.txt'] as $name) {
            file_put_contents($base . '/' . $name, '<?php');
        }

        // Three entries left: `a.txt`, `b.txt` and `c.php` spend them in sorted
        // order, so `d.php` is never reached. The old walk counted only the two
        // `.php` files against its cap and would have reported both.
        $visited = 0;
        $walked = RepoMapBlock::MAX_WALK_ENTRIES - 3;
        $counts = self::walk($base, $visited, $walked);

        self::assertSame(['' => 1], $counts);
        self::assertSame(1, $visited);
        self::assertSame(RepoMapBlock::MAX_WALK_ENTRIES, $walked);
    }

    public function testTheWalkBudgetIsSharedAcrossEveryPsr4RootOfOneCapture(): void
    {
        $root = $this->sandbox . '/pkg';
        foreach (['one', 'two'] as $dir) {
            mkdir($root . '/' . $dir, 0o755, true);
            file_put_contents($root . '/' . $dir . '/A.php', '<?php');
        }

        $walked = RepoMapBlock::MAX_WALK_ENTRIES - 1;
        $visited = 0;
        self::assertSame(['' => 1], self::walk($root . '/one', $visited, $walked));
        self::assertSame([], self::walk($root . '/two', $visited, $walked), 'the second root inherits the spent budget');
    }

    public function testTheSortedSourceWalkStillSkipsSymlinkedVendorAndDotDirectories(): void
    {
        $base = $this->sandbox . '/src';
        foreach (['Real', 'vendor', 'node_modules', '.hidden', 'outside'] as $dir) {
            mkdir($base . '/' . $dir, 0o755, true);
            file_put_contents($base . '/' . $dir . '/A.php', '<?php');
        }
        symlink($base . '/Real', $base . '/Linked');
        symlink($base . '/Real/A.php', $base . '/Top.php');

        $visited = 0;
        $counts = self::walk($base, $visited);
        ksort($counts);

        // A symlinked FILE counts as the file; a symlinked DIRECTORY is never
        // entered - exactly what the RecursiveDirectoryIterator walk did.
        self::assertSame(['' => 1, 'Real' => 1, 'outside' => 1], $counts);
    }

    public function testTheWalkBudgetIsTheLiteralItsDocBlockArgues(): void
    {
        self::assertSame(150000, RepoMapBlock::MAX_WALK_ENTRIES);
        self::assertSame(4096, self::ruleLoaderConstant('MAX_WALK_ENTRIES'));
        self::assertSame(
            5 * RepoMapBlock::MAX_SOURCE_FILES,
            RepoMapBlock::MAX_WALK_ENTRIES,
            'the doc-block argues the walk budget as five times the source-file cap',
        );
    }

    // -- helpers ---------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private static function walk(string $base, int &$visited, ?int &$walked = null): array
    {
        $walked ??= 0;
        $walker = \Closure::bind(
            static fn(string $b, int &$v, int &$w): array => RepoMapBlock::phpFileDirectories($b, $v, $w),
            null,
            RepoMapBlock::class,
        );

        return $walker($base, $visited, $walked);
    }

    private function projectRulesDir(string $repo): string
    {
        $rules = $this->sandbox . '/' . $repo . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);

        return $rules;
    }

    private static function ruleLoaderConstant(string $name): int
    {
        $value = (new ReflectionClass(RuleLoader::class))->getConstant($name);
        self::assertIsInt($value);

        return $value;
    }

    /**
     * A fixed-seed shuffle, so the creation order differs from sorted order
     * identically on every run.
     *
     * @param list<string> $items
     *
     * @return list<string>
     */
    private static function shuffled(array $items): array
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(1517));

        return $rng->shuffleArray($items);
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<string>
     */
    private static function ruleNames(array $rules): array
    {
        return array_map(static fn(Rule $rule): string => $rule->name, $rules);
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::rmrf($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
