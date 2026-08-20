<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\WorktreeConfig;
use SugarCraft\Crush\Agents\WorktreeManager;

/**
 * WHICH TREE'S `.sugar-crush/config.json` configures a manager for a given repo.
 *
 * {@see WorktreeManager::__construct()} used to construct its config as a bare
 * `WorktreeConfig::new()`, which falls through to
 * {@see WorktreeConfig::defaultConfigDir()} — `dirname(__DIR__, 3)`, the
 * directory ABOVE the package — while `$repoRoot` sat in the same constructor
 * unused. So a manager for `/srv/other-repo` WOULD have taken its cleanup
 * period and its include-file NAME out of whichever tree the package happened
 * to be installed in, with {@see WorktreeManager::resolveWorktreeInclude()}
 * resolving that name against `$repoRoot`: a value read from tree A applied to
 * tree B.
 *
 * THE CONDITIONAL IS NOT HEDGING. That branch never ran: `$config` was a
 * PROMOTED readonly property and the branch assigned it a second time, so
 * `new WorktreeManager()` and `WorktreeManager::new($root)` both raised
 * `Error: Cannot modify readonly property WorktreeManager::$config` under PHP
 * 8.3 — measured on this host against the pristine class. Nothing in `src/`
 * constructs this class and every prior test passed an explicit
 * {@see WorktreeConfig}, which is why no suite ever entered it. The two defects
 * are sequential: making the branch reachable is what makes the config origin
 * observable at all, and that is what the cases below measure.
 *
 * THE FIXTURE PROVES IT DISCRIMINATES, at runtime, rather than trusting a
 * remembered number. Every test below first asserts that the value it is about
 * to look for is NOT what the old code path would have produced — the old path
 * is still callable as a bare `WorktreeConfig::new()`, so the contrast is
 * derived on the spot instead of hardcoded. If this checkout's own
 * `.sugar-crush/config.json` ever happened to name `copy-list.txt`, these
 * assertions fail loudly with "the fixture no longer discriminates" rather than
 * passing vacuously, which is the failure mode a literal `'.worktreeinclude'`
 * expectation would have hidden.
 */
final class WorktreeManagerConfigOriginTest extends TestCase
{
    private const VIA_PROJECT_CONFIG = 'PROJECT-CONFIG-CHOSE-THIS';
    private const VIA_PACKAGE_DEFAULT = 'PACKAGE-DEFAULT-CHOSE-THIS';

    /** The include-list filename the temp repo's own config.json names. */
    private const PROJECT_INCLUDE_FILE = 'copy-list.txt';

    /** Days the temp repo's own config.json names. Not 7 (the code default). */
    private const PROJECT_CLEANUP_DAYS = 3;

    private string $tmpRoot;
    private string $repoRoot;
    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/sugarcrush_worktree_origin_' . uniqid('', true);
        $this->repoRoot = $this->tmpRoot . '/repo';
        $this->worktree = $this->tmpRoot . '/trees/agent1';

        mkdir($this->repoRoot . '/.sugar-crush', 0o700, true);
        mkdir($this->worktree, 0o700, true);

        file_put_contents(
            $this->repoRoot . '/.sugar-crush/config.json',
            (string) json_encode([
                'worktreeIncludeFile' => self::PROJECT_INCLUDE_FILE,
                'worktreeCleanupPeriodDays' => self::PROJECT_CLEANUP_DAYS,
            ]),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);

        parent::tearDown();
    }

    /**
     * THE BEHAVIOURAL HALF: which include list actually gets read and copied.
     *
     * Both candidate lists exist in the temp repo and each names a DIFFERENT
     * file, so the bytes that land in the worktree say which config file won —
     * "no exception was thrown" would prove nothing here.
     */
    public function testTheIncludeListNamedByTheRepositoryUnderManagementIsTheOneRead(): void
    {
        $bare = WorktreeConfig::new();
        self::assertNotSame(
            self::PROJECT_INCLUDE_FILE,
            $bare->worktreeIncludeFile,
            'Fixture no longer discriminates: the package-default config dir now names '
            . self::PROJECT_INCLUDE_FILE . ' too, so this test could pass without the fix. '
            . 'Pick a different PROJECT_INCLUDE_FILE.',
        );

        // The list the temp repo's OWN config.json points at.
        file_put_contents($this->repoRoot . '/' . self::PROJECT_INCLUDE_FILE, "chosen.txt\n");
        file_put_contents($this->repoRoot . '/chosen.txt', self::VIA_PROJECT_CONFIG);

        // The list the package-default config.json points at — present so that
        // reading the wrong config is observable as a copy, not merely as a
        // missing file. Named at runtime for the same reason as above.
        file_put_contents($this->repoRoot . '/' . $bare->worktreeIncludeFile, "shadow.txt\n");
        file_put_contents($this->repoRoot . '/shadow.txt', self::VIA_PACKAGE_DEFAULT);

        (new WorktreeManager(null, $this->repoRoot))->resolveWorktreeInclude($this->worktree);

        self::assertFileExists($this->worktree . '/chosen.txt');
        self::assertSame(self::VIA_PROJECT_CONFIG, file_get_contents($this->worktree . '/chosen.txt'));
        self::assertFileDoesNotExist(
            $this->worktree . '/shadow.txt',
            'The include list named by the package-default config dir was read for a manager '
            . 'that was handed a repository root.',
        );
    }

    /**
     * The other file-backed field, which has no reachable behavioural probe:
     * {@see WorktreeManager::cleanupStaleWorktrees()} consumes it only through
     * `git` subprocesses against real worktrees. Read off the instance instead,
     * and stated as exactly that — this pins WHERE the number came from, not
     * what the sweep does with it.
     */
    public function testTheCleanupPeriodComesFromTheRepositoryUnderManagement(): void
    {
        $bare = WorktreeConfig::new();
        self::assertNotSame(
            self::PROJECT_CLEANUP_DAYS,
            $bare->worktreeCleanupPeriodDays,
            'Fixture no longer discriminates: the package-default config dir now says '
            . self::PROJECT_CLEANUP_DAYS . ' days too. Pick a different PROJECT_CLEANUP_DAYS.',
        );

        self::assertSame(
            self::PROJECT_CLEANUP_DAYS,
            $this->configOf(new WorktreeManager(null, $this->repoRoot))->worktreeCleanupPeriodDays,
        );
    }

    /**
     * THE COMPATIBILITY PIN. `$repoRoot === ''` is this class's own constructor
     * default and means "no repository named"; it must keep reaching
     * {@see WorktreeConfig::defaultConfigDir()} rather than resolving
     * `/.sugar-crush/config.json` at the filesystem root, which is what passing
     * the empty string straight through would have done.
     *
     * Both sides are derived, so the ORIGIN-EQUALITY half stays true whatever the
     * ambient config file says.
     *
     * AND THE DIRECTORY ITSELF, which equality of origin does NOT pin: a
     * mutation from `\dirname(__DIR__, 3)` to `\dirname(__DIR__, 2)` in
     * {@see WorktreeConfig::defaultConfigDir()} moves BOTH sides together, so
     * this test stayed green under it while its own name says "the package
     * default config dir". The kill came from
     * {@see WorktreeConfigTest} instead — correct, but not from the test named
     * for it. So the directory is now asserted from THIS file's location rather
     * than from the expression under test: `dirname(__DIR__, 2)` here is the
     * package root (`sugar-crush/`), and the documented default is the directory
     * CONTAINING the package, so one more `dirname` is the answer. Neither side
     * of that comparison is the `src/` expression.
     */
    public function testNoRepositoryRootStillReadsThePackageDefaultConfigDir(): void
    {
        $packageRoot = \dirname(__DIR__, 2);
        self::assertFileExists($packageRoot . '/composer.json', 'this is the package root');
        self::assertSame(
            \dirname($packageRoot),
            WorktreeConfig::defaultConfigDir(),
            'the documented default is the directory CONTAINING the package',
        );

        $bare = WorktreeConfig::new();
        $manager = $this->configOf(new WorktreeManager());

        self::assertSame($bare->worktreeIncludeFile, $manager->worktreeIncludeFile);
        self::assertSame($bare->worktreeCleanupPeriodDays, $manager->worktreeCleanupPeriodDays);
    }

    /**
     * A repository with no `.sugar-crush/config.json` of its own falls to the
     * CODE defaults, not to the package-default directory's file — otherwise
     * "read the repo's settings" would silently mean "read the repo's settings,
     * or mine if it has none", which is the cross-domain read again with an
     * extra step.
     */
    public function testARepositoryWithoutASettingsFileGetsTheCodeDefaults(): void
    {
        $emptyRepo = $this->tmpRoot . '/bare-repo';
        mkdir($emptyRepo, 0o700, true);

        $config = $this->configOf(new WorktreeManager(null, $emptyRepo));

        self::assertSame('.worktreeinclude', $config->worktreeIncludeFile);
        self::assertSame(7, $config->worktreeCleanupPeriodDays);
    }

    private function configOf(WorktreeManager $manager): WorktreeConfig
    {
        $property = new \ReflectionProperty(WorktreeManager::class, 'config');

        return $property->getValue($manager);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
