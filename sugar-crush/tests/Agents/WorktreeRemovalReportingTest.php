<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\WorktreeConfig;
use SugarCraft\Crush\Agents\WorktreeIsolationMode;
use SugarCraft\Crush\Agents\WorktreeManager;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;

/**
 * `removeWorktree()` CAN NOW TELL "REMOVED" FROM "STILL ON DISK", AND REFUSES
 * TO DROP THE REGISTRY ENTRY OVER FILES THAT ARE STILL THERE.
 *
 * `removeDirectory()` returned `void` and swallowed every failure it could
 * have: an unreadable directory returned early, a failed `unlink()` and a
 * failed `rmdir()` raised a PHP warning and carried on. Its one caller then ran
 * `unset($this->registry[$agentId])` unconditionally. So the registry — which
 * is the whole of what {@see WorktreeManager} knows, since `worktreeExists()`
 * is nothing but a lookup in it — would report a worktree gone while its files
 * sat on disk, and the next `createWorktree()` for that agent id would be aimed
 * at an occupied path.
 *
 * THIS IS DORMANT-CODE WORK AND THE DORMANCY IS PINNED BELOW rather than
 * assumed. Nothing in `src/` or `bin/` constructs a `WorktreeManager`. That
 * claim is made here with a SUPERSET of the scanner that first made it: not
 * only "no `new WorktreeManager` and no `WorktreeManager::new()`" but "no
 * static call of ANY name on the class at all", which is the shape that turned
 * out to matter for `SglangProvider` — built through
 * `SglangProvider::openAiCompatible()`, invisible to a `new`-shaped walk, and
 * emphatically not dormant. `WorktreeManager` has no such factory, so the two
 * classes' identical zeroes mean genuinely different things and this file says
 * which is which.
 *
 * THE CLASS IS NOT WIRED HERE. Wiring it is a change with a caller-side design
 * to it (who owns cleanup, on what schedule, against which repo) and none of
 * that belongs in a removal-reporting fix. Rule 6's alternative — pin the
 * dormancy so the seam cannot rot unnoticed — is what this file does.
 */
final class WorktreeRemovalReportingTest extends TestCase
{
    private string $tmpRoot;

    private string $repoRoot;

    private WorktreeManager $manager;

    private string|false $previousErrorLog;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        // getmypid() in the name, not a bare uniqid(): five suites share one
        // uid-keyed TMPDIR during an audit round and uniqid() is
        // microtime-derived rather than process-unique (db90e768).
        $this->tmpRoot = sys_get_temp_dir() . '/sc_r49b_wt_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0755, true);

        $this->repoRoot = $this->tmpRoot . '/repo';
        mkdir($this->repoRoot, 0755, true);
        shell_exec('git init -q ' . escapeshellarg($this->repoRoot) . ' 2>&1');
        shell_exec('git -C ' . escapeshellarg($this->repoRoot) . ' -c user.email=t@example.invalid '
            . '-c user.name=T commit -q --allow-empty -m init 2>&1');

        $this->manager = new WorktreeManager(
            new WorktreeConfig(
                basePath: $this->tmpRoot . '/worktrees/',
                autoCleanup: false,
                isolationMode: WorktreeIsolationMode::Worktree,
            ),
            $this->repoRoot,
        );

        // WorktreeManager's diagnostics go to RuntimeNoticeSink::warn(), which
        // is error_log() plus record(). Divert both so a provoked failure does
        // not print into the suite's own stderr or leak into another test.
        RuntimeNoticeSink::reset();
        $log = tempnam(sys_get_temp_dir(), 'sc_r49b_wtlog_');
        self::assertIsString($log);
        $this->logFile = $log;
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
        RuntimeNoticeSink::reset();

        self::forceRemoveTree($this->tmpRoot);

        parent::tearDown();
    }

    /**
     * THE HAPPY PATH STILL EMPTIES THE TREE AND STILL REPORTS SO.
     *
     * The positive control for every negative assertion in this file: a scanner
     * — or here, a remover — that answered `false` for everything would satisfy
     * "refuses when it could not remove" perfectly and be useless.
     */
    public function testRemoveDirectoryEmptiesANestedTreeAndSaysTrue(): void
    {
        $tree = $this->tmpRoot . '/happy';
        mkdir($tree . '/a/b', 0755, true);
        file_put_contents($tree . '/top.txt', 'x');
        file_put_contents($tree . '/a/mid.txt', 'x');
        file_put_contents($tree . '/a/b/deep.txt', 'x');

        self::assertTrue(self::removeDirectory($this->manager, $tree));
        self::assertDirectoryDoesNotExist($tree);
    }

    /**
     * A PATH THAT IS NOT A DIRECTORY IS ANSWERED FOR, NOT ASSUMED AWAY.
     *
     * The old early return said `void` for all three of these and the caller
     * read that as success. Absent really is removed; a FILE and a DANGLING
     * SYMLINK are things this method did not remove, and saying "gone" for them
     * would be the same lie in a smaller place.
     */
    public function testRemoveDirectoryDistinguishesAbsentFromSomethingElseBeingThere(): void
    {
        self::assertTrue(self::removeDirectory($this->manager, $this->tmpRoot . '/never-existed'));

        $file = $this->tmpRoot . '/a-file';
        file_put_contents($file, 'x');
        self::assertFalse(self::removeDirectory($this->manager, $file));
        self::assertFileExists($file);

        $dangling = $this->tmpRoot . '/dangling';
        symlink($this->tmpRoot . '/no-such-target', $dangling);
        self::assertFalse(self::removeDirectory($this->manager, $dangling));
        self::assertTrue(is_link($dangling));
    }

    /**
     * A TREE THAT CANNOT BE EMPTIED REPORTS FALSE AND IS LEFT WHERE IT IS.
     *
     * Denial by mode bits, following the eight existing sites in this suite
     * that already do it (`chmod(..., 0000)` in the Bootstrap and ArgvParser
     * tests) — the suite assumes it is not running as root. That assumption is
     * ASSERTED here rather than left implicit: under root the chmod is a no-op,
     * the removal would succeed, and every assertion below would pass for the
     * wrong reason. The guard fails loudly instead of skipping, because a skip
     * is a green tick over an unmeasured claim.
     */
    public function testAnUnremovableTreeReportsFalseAndSurvives(): void
    {
        $tree = $this->tmpRoot . '/locked';
        mkdir($tree . '/nested', 0755, true);
        file_put_contents($tree . '/nested/pinned.txt', 'x');
        file_put_contents($tree . '/loose.txt', 'x');
        self::assertTrue(chmod($tree . '/nested', 0555));

        self::assertFalse(
            is_writable($tree . '/nested'),
            'the mode-bit denial this test is built on did not take — running as root, or on a '
                . 'filesystem that ignores mode bits. Every assertion below would pass vacuously.',
        );

        self::assertFalse(self::removeDirectory($this->manager, $tree));
        self::assertFileExists($tree . '/nested/pinned.txt');
        self::assertDirectoryExists($tree);

        // AND IT STILL DID WHAT IT COULD. A remover that bailed at the first
        // obstacle would also answer false, and would leave far more behind.
        self::assertFileDoesNotExist($tree . '/loose.txt');
    }

    /**
     * THE REFUSAL ITSELF: an unremovable worktree keeps its registry entry.
     *
     * This is the whole point of the boolean. Before it, this same scenario
     * left the files on disk AND deleted the registry entry, so the manager
     * agreed the worktree was gone and `createWorktree()` for that agent id
     * would have been pointed straight back at the occupied path.
     */
    public function testAWorktreeThatCannotBeRemovedKeepsItsRegistryEntry(): void
    {
        $agentId = 'stuck-agent';
        $path = $this->manager->createWorktree($agentId);

        mkdir($path . '/pinned', 0755, true);
        file_put_contents($path . '/pinned/file.txt', 'x');
        self::assertTrue(chmod($path . '/pinned', 0555));
        self::assertFalse(is_writable($path . '/pinned'), 'the mode-bit denial did not take');

        try {
            $this->manager->removeWorktree($agentId);
            self::fail('removeWorktree() returned normally over a worktree still on disk');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('still on disk', $e->getMessage());
            self::assertStringContainsString($agentId, $e->getMessage());
        }

        self::assertDirectoryExists($path);
        self::assertArrayHasKey(
            $agentId,
            $this->manager->listWorktrees(),
            'the registry entry was dropped over files that are still there — the exact lie this '
                . 'change exists to stop',
        );

        // Cleanup needs the write bit back before tearDown can recurse.
        chmod($path . '/pinned', 0755);
    }

    /**
     * AND A WORKTREE THAT REALLY IS GONE STILL LEAVES THE REGISTRY.
     *
     * The other polarity. Without it, "keeps the entry on failure" is satisfied
     * by a method that never drops the entry at all.
     */
    public function testASuccessfulRemovalStillDropsTheRegistryEntry(): void
    {
        $agentId = 'clean-agent';
        $path = $this->manager->createWorktree($agentId);
        self::assertDirectoryExists($path);

        $this->manager->removeWorktree($agentId);

        self::assertDirectoryDoesNotExist($path);
        self::assertArrayNotHasKey($agentId, $this->manager->listWorktrees());
    }

    /**
     * NOTHING IN `src/` OR `bin/` CONSTRUCTS A `WorktreeManager`, BY ANY SHAPE.
     *
     * Stronger than the `new`-shaped claim that first established this, and
     * deliberately so: `SglangProvider` scores the same zero on a `new`-shaped
     * walk and is built on every run, through a static factory with a name of
     * its own. This walk reports static calls of ANY name, so the two zeroes
     * are no longer the same evidence.
     *
     * IF THIS EVER REDS IT IS GOOD NEWS AND NOT A REGRESSION — someone wired
     * the class. The response is to repoint this test at the new reachability
     * and rewrite the four doc-blocks that currently argue dormancy, not to
     * revert the wiring.
     */
    public function testNothingInSrcOrBinBuildsAWorktreeManagerByAnyShape(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = self::phpSources($root);

        // The roster half of the control: [] over an empty roster is also [].
        self::assertContains($root . '/src/Agents/WorktreeManager.php', $files);
        self::assertContains($root . '/bin/sugarcrush', $files);

        $sites = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, $file . ' could not be read, so this scan cannot speak for it');
            foreach (self::constructionShapes('WorktreeManager', $source) as $shape) {
                $sites[] = substr($file, \strlen($root) + 1) . ': ' . $shape;
            }
        }

        self::assertSame([], $sites);

        // KNOWN-POSITIVE THROUGH THE SAME SCANNER IN THE SAME TEST. Every shape
        // it must catch, including the named factory a `new`-only walk misses,
        // and the non-constructions it must not count.
        self::assertSame(
            ['new', 'new', 'new', 'new', 'openAiCompatible', '<dynamic method name>'],
            self::constructionShapes('WorktreeManager', <<<'PHP'
                <?php
                $a = new WorktreeManager();
                $b = new \SugarCraft\Crush\Agents\WorktreeManager($c);
                $c = Agents\WorktreeManager::new('/repo');
                $d = new Agents\WorktreeManager($c);
                $e = WorktreeManager::openAiCompatible($c);
                $f = WorktreeManager::{$method}($c);
                $g = WorktreeManager::class;
                $h = WorktreeManager::SOME_CONST;
                $i = new WorktreeConfig();
                $j = WorktreeConfig::new();
                class X { public static function new(): self { return new self(); } }
                PHP),
            'constructionShapes() has gone blind, so the empty assertion above is vacuous',
        );
    }

    // -------------------------------------------------------------------------

    private static function removeDirectory(WorktreeManager $manager, string $path): bool
    {
        return (bool) (new \ReflectionMethod(WorktreeManager::class, 'removeDirectory'))
            ->invoke($manager, $path);
    }

    /** @return list<string> absolute paths, sorted */
    private static function phpSources(string $root): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/src',
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        $files[] = $root . '/bin/sugarcrush';
        sort($files);

        return $files;
    }

    /**
     * Every construction-capable reference to `$class` in `$source`, in source
     * order: `'new'` for a `new Foo`/`Foo::new()` site, the method name for any
     * other static call, and `'<dynamic method name>'` for a static call whose
     * name this walk cannot read.
     *
     * The dynamic case is recorded rather than dropped for the reason a guard
     * asserting an absence always must: `Foo::{$m}()` could construct the class
     * as readily as a literal name, and answering `[]` for it would leave a
     * hole shaped exactly like the next defect.
     *
     * @return list<string>
     */
    private static function constructionShapes(string $class, string $source): array
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        $shapes = [];
        foreach ($significant as $i => $token) {
            if (\is_array($token) && $token[0] === T_NEW) {
                $previous = $significant[$i - 1] ?? null;
                if (\is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    // `Foo::new(` — PHP 8.3.6 lexes that `new` as T_NEW, not
                    // T_STRING, which is why the factory needs its own arm. The
                    // same rule excludes `public static function new()`, whose
                    // T_NEW is preceded by T_FUNCTION.
                    if (
                        ($significant[$i + 1] ?? null) === '('
                        && self::shortName($significant[$i - 2] ?? null) === $class
                    ) {
                        $shapes[] = 'new';
                    }

                    continue;
                }
                if (self::shortName($significant[$i + 1] ?? null) === $class) {
                    $shapes[] = 'new';
                }

                continue;
            }

            if (!\is_array($token) || $token[0] !== T_DOUBLE_COLON) {
                continue;
            }
            if (self::shortName($significant[$i - 1] ?? null) !== $class) {
                continue;
            }

            $method = $significant[$i + 1] ?? null;
            if (\is_array($method) && \in_array($method[0], [T_NEW, T_CLASS], true)) {
                continue;
            }
            if (\is_array($method) && $method[0] === T_STRING) {
                // A CONSTANT IS NOT A CALL. `Foo::SOME_CONST` lexes its name as
                // T_STRING exactly as a method does; the `(` is the difference.
                if (($significant[$i + 2] ?? null) === '(') {
                    $shapes[] = $method[1];
                }

                continue;
            }

            $shapes[] = '<dynamic method name>';
        }

        return $shapes;
    }

    private static function shortName(array|string|null $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }
        if (!\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $segments = explode('\\', $token[1]);
        $short = array_pop($segments);

        return $short === '' ? null : $short;
    }

    private static function forceRemoveTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff((array) @scandir($path), ['.', '..']) as $item) {
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                @chmod($child, 0755);
                self::forceRemoveTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
