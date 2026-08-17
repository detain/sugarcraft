<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\WorktreeConfig;
use SugarCraft\Crush\Agents\WorktreeManager;

/**
 * The ninth read path, driven end to end.
 *
 * {@see WorktreeManager::resolveWorktreeInclude()} takes TWO
 * repository-chosen inputs and bounded neither: WHERE the include list lives
 * (`worktreeIncludeFile`, a value out of `.sugar-crush/config.json`) and WHAT
 * that list names (one glob pattern per line). MEASURED on this host against
 * the ungated build, with a `.worktreeinclude` whose only line was
 * `../secret/id_rsa`:
 *
 *     read  <repoRoot>/../secret/id_rsa      -> OUTSIDE-SECRET sk-live-F00D
 *     wrote <worktreePath>/../secret/id_rsa
 *
 * — an exfiltration into the agent's own worktree tree AND a write outside the
 * worktree, from one committed line. Nothing in `src/` constructs a
 * {@see WorktreeManager} yet; that is the state the "DORMANT IS NOT UNGATED"
 * doctrine exists for, and it is why this file drives the class directly.
 *
 * WHAT THESE ASSERT ON, because "no exception was thrown" would prove nothing:
 * the secret's BYTES, and the absence of the file at both escape destinations.
 * Every fixture lives under `sys_get_temp_dir()`, outside the checkout, and no
 * symlink is ever created inside it.
 */
final class WorktreeIncludeContainmentTest extends TestCase
{
    private const SECRET = 'OUTSIDE-SECRET sk-live-F00D';

    private string $tmpRoot;
    private string $repoRoot;
    private string $worktree;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/sugarcrush_worktree_include_' . uniqid('', true);
        $this->repoRoot = $this->tmpRoot . '/repo';
        $this->worktree = $this->tmpRoot . '/trees/agent1';

        mkdir($this->repoRoot, 0o700, true);
        mkdir($this->worktree, 0o700, true);
        mkdir($this->tmpRoot . '/secret', 0o700, true);
        file_put_contents($this->tmpRoot . '/secret/id_rsa', self::SECRET . "\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);

        parent::tearDown();
    }

    private function manager(string $includeFile = '.worktreeinclude'): WorktreeManager
    {
        return new WorktreeManager(
            new WorktreeConfig(basePath: $this->tmpRoot . '/trees/', worktreeIncludeFile: $includeFile),
            $this->repoRoot,
        );
    }

    /** Every regular file anywhere under the temp root, as `path => bytes`. */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile()) {
                $found[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $found;
    }

    /** THE MEASURED ESCAPE: one `../` line reads outside the checkout. */
    public function testAParentTraversalPatternCopiesNothing(): void
    {
        file_put_contents($this->repoRoot . '/.worktreeinclude', "../secret/id_rsa\n");

        $this->manager()->resolveWorktreeInclude($this->worktree);

        $this->assertSame([], $this->filesUnder($this->tmpRoot . '/trees'));
        $this->assertStringNotContainsString(
            self::SECRET,
            implode("\n", $this->filesUnder($this->tmpRoot . '/trees')),
        );
    }

    /**
     * The WRITE half, separately: the destination path is built from the same
     * pattern, so `../` also placed the copy outside the worktree — one
     * directory up, beside its siblings.
     */
    public function testAParentTraversalPatternWritesNothingOutsideTheWorktree(): void
    {
        file_put_contents($this->repoRoot . '/.worktreeinclude', "../secret/id_rsa\n");

        $this->manager()->resolveWorktreeInclude($this->worktree);

        $this->assertFileDoesNotExist($this->tmpRoot . '/trees/secret/id_rsa');
    }

    /**
     * A pattern reaching outside through a SYMLINK inside the checkout — the
     * shape a lexical `..` guard cannot see, and the reason the source end is
     * resolved rather than merely spelled.
     */
    public function testAPatternReachingOutThroughAnInRepoSymlinkCopiesNothing(): void
    {
        symlink($this->tmpRoot . '/secret', $this->repoRoot . '/link');
        file_put_contents($this->repoRoot . '/.worktreeinclude', "link/id_rsa\n");

        $this->manager()->resolveWorktreeInclude($this->worktree);

        $this->assertStringNotContainsString(
            self::SECRET,
            implode("\n", $this->filesUnder($this->tmpRoot . '/trees')),
        );
    }

    /**
     * THE OTHER repository-chosen input: `worktreeIncludeFile` itself. Its value
     * comes out of `.sugar-crush/config.json`, so a committed value of
     * `../elsewhere/list` chose the whole pattern list from outside the tree.
     */
    public function testAnIncludeFileOutsideTheRepositoryRootIsRefused(): void
    {
        // WHAT THIS GATE IS MEASURED TO CHANGE, stated because two earlier
        // drafts of this test were false greens and the reason is worth keeping.
        //
        // It is NOT the copy: `copyGlob()`'s own pair already refuses every
        // pattern that leaves the repo root, and patterns are globbed relative
        // to the include file's OWN directory while the copy resolves against
        // `$repoRoot`, so an outside list cannot name anything the in-repo path
        // would not have named anyway. Both earlier drafts asserted on the copy
        // and stayed green with this gate deleted.
        //
        // What it changes is that the outside file is READ AT ALL — `file()` on
        // a path a committed config value chose — and that its contents then
        // reach `error_log()` through the pattern refusal. So THAT is what is
        // asserted: the file's own line must appear nowhere, and the refusal
        // must name the include file rather than the pattern.
        mkdir($this->tmpRoot . '/elsewhere', 0o700, true);
        file_put_contents($this->tmpRoot . '/elsewhere/list', "../../secret/id_rsa\n");

        $log = $this->tmpRoot . '/error.log';
        $previous = ini_set('error_log', $log);

        try {
            $this->manager('../elsewhere/list')->resolveWorktreeInclude($this->worktree);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $logged = is_file($log) ? (string) file_get_contents($log) : '';

        $this->assertStringContainsString('refusing worktreeIncludeFile', $logged);
        $this->assertStringNotContainsString(
            '../../secret/id_rsa',
            $logged,
            'the outside list was never read, so none of its lines can have reached the log',
        );
        $this->assertStringNotContainsString(
            self::SECRET,
            implode("\n", $this->filesUnder($this->tmpRoot . '/trees')),
        );
    }

    /**
     * THE CONTROL, and without it every assertion above is satisfied by a method
     * that copies nothing at all: an ordinary in-repo pattern still arrives.
     */
    public function testAnInRepoPatternIsStillCopied(): void
    {
        file_put_contents($this->repoRoot . '/.env', "IN-REPO-ENV\n");
        file_put_contents($this->repoRoot . '/.worktreeinclude', ".env\n");

        $this->manager()->resolveWorktreeInclude($this->worktree);

        $this->assertFileExists($this->worktree . '/.env');
        $this->assertStringContainsString('IN-REPO-ENV', (string) file_get_contents($this->worktree . '/.env'));
    }

    /**
     * A checkout that ships no `.worktreeinclude` at all — the overwhelmingly
     * common case — must be SILENT. The first spelling of the containment check
     * ran before the existence check, and since {@see \SugarCraft\Crush\Support\ContainedPath}
     * answers false for anything it cannot resolve, every such run reported
     * "resolves outside the repository root" about a file that was simply not
     * there. Seven of those lines appeared in one run of
     * {@see WorktreeManagerTest}.
     */
    public function testAnAbsentIncludeFileIsNotReportedAsAnEscape(): void
    {
        $log = $this->tmpRoot . '/error.log';
        $previous = ini_set('error_log', $log);

        try {
            $this->manager()->resolveWorktreeInclude($this->worktree);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $this->assertStringNotContainsString(
            'refusing',
            is_file($log) ? (string) file_get_contents($log) : '',
        );
    }

    /**
     * `..` is a traversal only as a whole SEGMENT — a substring guard would
     * refuse these two legitimate names.
     *
     * @return array<string, array{0: string}>
     */
    public static function legitimateNamesContainingTwoDots(): array
    {
        return [
            'a leading double dot' => ['..dotfile'],
            'a double dot inside a name' => ['a..b'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legitimateNamesContainingTwoDots')]
    public function testANameThatMerelyContainsTwoDotsIsStillCopied(string $name): void
    {
        file_put_contents($this->repoRoot . '/' . $name, "LEGIT\n");
        file_put_contents($this->repoRoot . '/.worktreeinclude', $name . "\n");

        $this->manager()->resolveWorktreeInclude($this->worktree);

        $this->assertFileExists($this->worktree . '/' . $name);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Links are unlinked, never descended into — these fixtures contain a
        // symlink to a sibling directory on purpose.
        foreach ((array) scandir($dir) as $entry) {
            if (!\is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                @unlink($path);

                continue;
            }

            $this->removeTree($path);
        }

        @rmdir($dir);
    }
}
