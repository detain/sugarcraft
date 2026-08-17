<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ContainedPath;

/**
 * The shared containment predicate, tested where it lives.
 *
 * Its callers -- FOUR files as measured this session
 * (`grep -rn 'ContainedPath::' src/`): {@see \SugarCraft\Crush\Workflows\WorkflowRegistry},
 * {@see \SugarCraft\Crush\Skills\SkillLoader},
 * {@see \SugarCraft\Crush\Agents\AgentPresetRegistry} and
 * {@see \SugarCraft\Crush\Commands\CommandLoader} -- each pin it through their
 * own fixtures, and that is the right place to prove a TIER behaves. It is the
 * wrong place to prove the PREDICATE does: none of them can reach a boundary
 * of `/`, and the difference between {@see ContainedPath::within()} and
 * {@see ContainedPath::below()} is a single `bool` that only the equality case
 * distinguishes. Both arms of that case are asserted here, in both directions,
 * because the class doc-block's whole claim is that they differ.
 */
final class ContainedPathTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_contained_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/root/inner/deeper', 0o700, true);
        mkdir($this->dir . '/outside', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);

        parent::tearDown();
    }

    public function testAPathBelowTheBoundaryIsContainedByBothQuestions(): void
    {
        $root = $this->dir . '/root';

        $this->assertTrue(ContainedPath::within($root . '/inner', $root));
        $this->assertTrue(ContainedPath::below($root . '/inner', $root));
        $this->assertTrue(ContainedPath::within($root . '/inner/deeper', $root));
        $this->assertTrue(ContainedPath::below($root . '/inner/deeper', $root));
    }

    public function testAPathOutsideTheBoundaryIsContainedByNeither(): void
    {
        $root = $this->dir . '/root';

        $this->assertFalse(ContainedPath::within($this->dir . '/outside', $root));
        $this->assertFalse(ContainedPath::below($this->dir . '/outside', $root));
    }

    /**
     * The one case the two methods answer differently, and the reason the class
     * has two methods at all: a repository committing
     * `.sugar-crush/workflows -> ..` resolves its workflows directory exactly
     * onto the checkout root, which `within()` accepted and `below()` refuses.
     */
    public function testEqualityIsContainedForAnEntryAndRefusedForATrustAnchor(): void
    {
        $root = $this->dir . '/root';

        $this->assertTrue(ContainedPath::within($root, $root));
        $this->assertFalse(ContainedPath::below($root, $root));
    }

    /**
     * Equality is decided on the RESOLVED paths, so a link pointing at the
     * boundary is the same answer as the boundary spelled literally -- which is
     * the spelling an attacker actually has, since they commit a link and not a
     * path.
     */
    public function testEqualityIsDecidedAfterResolutionNotOnTheSpelling(): void
    {
        $root = $this->dir . '/root';
        symlink($root, $this->dir . '/link-to-root');
        symlink('..', $root . '/inner/up');

        $this->assertTrue(ContainedPath::within($this->dir . '/link-to-root', $root));
        $this->assertFalse(ContainedPath::below($this->dir . '/link-to-root', $root));

        // `inner/up` resolves to `root`, so it is the boundary, not something
        // inside it -- a string comparison of the unresolved path would have
        // called it contained by both.
        $this->assertTrue(ContainedPath::within($root . '/inner/up', $root));
        $this->assertFalse(ContainedPath::below($root . '/inner/up', $root));
    }

    /**
     * The trailing separator on both sides, without which the boundary is
     * decorative: `<dir>/rootevil` shares `<dir>/root` as a string prefix.
     */
    public function testASiblingSharingTheBoundarysNamePrefixIsNotContained(): void
    {
        mkdir($this->dir . '/rootevil', 0o700, true);

        $this->assertFalse(ContainedPath::within($this->dir . '/rootevil', $this->dir . '/root'));
        $this->assertFalse(ContainedPath::below($this->dir . '/rootevil', $this->dir . '/root'));
    }

    /**
     * A boundary of `/` contains every absolute path, and it is the arm the
     * `rtrim()` in compare() would otherwise break: `rtrim('/', '/')` is the
     * empty string, so the separator is appended rather than assumed.
     *
     * Reachable in production through `--root /`, which
     * {@see \SugarCraft\Crush\Cli\ArgvParser} accepts because `/` is a
     * directory; {@see \SugarCraft\Crush\Workflows\WorkflowRegistry} then hands
     * it here as the trust anchor.
     */
    public function testTheFilesystemRootAsBoundaryContainsEveryResolvablePath(): void
    {
        $this->assertTrue(ContainedPath::within($this->dir . '/root', '/'));
        $this->assertTrue(ContainedPath::below($this->dir . '/root', '/'));

        // ...and is still refused as its own trust anchor, by the same
        // equality rule as any other directory.
        $this->assertTrue(ContainedPath::within('/', '/'));
        $this->assertFalse(ContainedPath::below('/', '/'));
    }

    /**
     * An unresolvable side is refused rather than treated as absent: a dangling
     * link is not something to read, and a boundary that does not resolve is
     * not a boundary. Both methods, because "false when either side will not
     * resolve" is a claim the class doc-block makes about the pair.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unresolvableSides')]
    public function testAnUnresolvableSideIsRefusedByBothQuestions(bool $danglingPath, bool $missingBoundary): void
    {
        $root = $this->dir . '/root';
        symlink($this->dir . '/nothing-here', $root . '/dangling');

        $path = $danglingPath ? $root . '/dangling' : $root . '/inner';
        $boundary = $missingBoundary ? $this->dir . '/no-such-dir' : $root;

        $this->assertFalse(ContainedPath::within($path, $boundary));
        $this->assertFalse(ContainedPath::below($path, $boundary));
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function unresolvableSides(): array
    {
        return [
            'dangling entry, real boundary' => [true, false],
            'real entry, missing boundary' => [false, true],
            'both unresolvable' => [true, true],
        ];
    }

    /**
     * The empty string is the shape {@see \SugarCraft\Crush\Workflows\WorkflowRegistry}'s
     * expandPath() used to produce for `--root /`, and `realpath('')` is the
     * process CWD rather than `false` -- so without compare()'s explicit guard
     * an empty boundary anchors containment at `getcwd()` and an empty path is
     * judged as `getcwd()`. Asserted from a CWD deliberately set to an ancestor
     * of the fixture, which is the arrangement under which the CWD fallback
     * would answer TRUE and so the only one that can tell the guard apart from
     * the fallback happening to disagree.
     */
    public function testTheEmptyStringIsNeverABoundaryAndNeverAContainedPath(): void
    {
        $root = $this->dir . '/root';
        $originalCwd = getcwd();
        $this->assertNotFalse($originalCwd);

        // Stand INSIDE the fixture, one level below $root: that is the one
        // arrangement in which the CWD fallback would answer TRUE for both
        // spellings -- `<cwd>/deeper` is below the CWD, and the CWD is below
        // $root -- so a false here distinguishes the guard from the fallback
        // merely happening to disagree.
        chdir((string) realpath($root . '/inner'));

        try {
            $cwd = (string) getcwd();
            $this->assertTrue(ContainedPath::below($cwd . '/deeper', $cwd));
            $this->assertTrue(ContainedPath::below($cwd, $root));

            $this->assertFalse(ContainedPath::below($cwd . '/deeper', ''));
            $this->assertFalse(ContainedPath::within($cwd . '/deeper', ''));
            $this->assertFalse(ContainedPath::below('', $root));
            $this->assertFalse(ContainedPath::within('', $root));
        } finally {
            chdir($originalCwd);
        }
    }

    /**
     * THE THIRD LIMIT, executable. `ContainedPath` documents its TOCTOU window
     * and its unresolvable-path behaviour; the hard link was the one bound not
     * written beside them, and a limit stated only in prose is a limit the next
     * reviewer rediscovers.
     *
     * Both questions answer TRUE for a hard link inside the boundary to a file
     * outside it, and reading it returns the outside file's bytes. That is
     * `realpath()` behaving correctly rather than failing: a hard link is not a
     * reference to another path, it is a second name for the same inode, and
     * there is no "original" to resolve to.
     *
     * OUT OF THE THREAT MODEL every caller here is written against, which is a
     * CLONED REPOSITORY: git can neither represent nor commit a hard link, so no
     * `git clone` produces one. Asserted as the CURRENT answer, not as a
     * desirable one — if a future caller's threat model includes a local user
     * with write access inside the checkout, this is the assertion that will
     * have to change and it will say so by failing.
     */
    public function testAHardLinkIsNotSeenByEitherQuestion(): void
    {
        $root = $this->dir . '/root';
        file_put_contents($this->dir . '/outside/secret.txt', "HARD-LINK-BYTES\n");

        if (!@link($this->dir . '/outside/secret.txt', $root . '/hard.txt')) {
            $this->markTestSkipped('this filesystem does not support hard links');
        }

        $this->assertTrue(ContainedPath::within($root . '/hard.txt', $root));
        $this->assertTrue(ContainedPath::below($root . '/hard.txt', $root));
        $this->assertSame(
            fileinode($this->dir . '/outside/secret.txt'),
            fileinode($root . '/hard.txt'),
            'one inode, two names — which is why there is nothing for realpath() to resolve',
        );
        $this->assertSame("HARD-LINK-BYTES\n", file_get_contents($root . '/hard.txt'));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // is_link() BEFORE is_dir(): a link to a directory satisfies both, and
        // recursing through it would delete the target's contents rather than
        // the link -- these fixtures deliberately contain links to their own
        // ancestors, so that recursion does not terminate either.
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }

            $this->removeDirectory($path);
        }

        rmdir($dir);
    }
}
