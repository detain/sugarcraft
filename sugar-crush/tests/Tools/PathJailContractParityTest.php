<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail as WorktreeJail;
use SugarCraft\Crush\Agents\PathJailConfig;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\PathJailInterface;

/**
 * The guardrail for crush_code.md P8.14.
 *
 * sugar-crush keeps two path-containment types on purpose — the stateless
 * algorithm {@see PathJail} (root per call, used by the workspace-jailed
 * built-in tools) and the bound {@see WorktreeJail} (one sub-agent worktree,
 * held by a tool for its lifetime). The hazard the plan names is not that both
 * exist, it is that a caller cannot tell which contract it is holding, and that
 * two independent implementations can drift into two different jails.
 *
 * So this suite pins the relationship rather than either implementation: one
 * escape corpus, run through BOTH rooted at the same directory, asserting
 * byte-identical verdicts. A second containment implementation, or a loosened
 * check on one side only, fails here.
 */
final class PathJailContractParityTest extends TestCase
{
    private string $base;
    private string $root;
    private string $outside;

    /**
     * A sibling directory whose absolute path has the jail root's absolute
     * path as a STRING prefix (`…/root` vs `…/rootevil`). It exists so the
     * corpus can catch the classic prefix hole — a containment check written
     * as `str_starts_with($resolved, $rootReal)` without the trailing `/`
     * treats everything in here as in-jail.
     */
    private string $siblingPrefix;
    private WorktreeJail $bound;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/sugarcrush_pjparity_' . uniqid('', true);
        $this->root = $this->base . '/root';
        $this->outside = $this->base . '/outside';
        $this->siblingPrefix = $this->root . 'evil';

        mkdir($this->root . '/x/y/z', 0o755, true);
        mkdir($this->root . '/sub', 0o755, true);
        mkdir($this->outside, 0o755, true);
        mkdir($this->siblingPrefix, 0o755, true);
        file_put_contents($this->outside . '/passwd', 'secret');
        file_put_contents($this->siblingPrefix . '/stolen.txt', 'sibling-prefix secret');
        file_put_contents($this->root . '/sub/here.txt', 'in-jail');
        symlink($this->outside, $this->root . '/evil');
        // A BROKEN link: exists, is a symlink, and is invisible to both
        // is_dir() and realpath(). See the 'dangling symlink' corpus rows.
        symlink($this->base . '/never-created', $this->root . '/dangling');

        $this->bound = new WorktreeJail($this->root, new PathJailConfig());
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                $this->rrmdir($path);
            }
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // The bound jail answers to the shared contract
    // -------------------------------------------------------------------------

    public function testTheWorktreeJailImplementsTheSharedContract(): void
    {
        $this->assertInstanceOf(PathJailInterface::class, $this->bound);
        $this->assertSame($this->root, $this->bound->root());
    }

    public function testJailPathIsAnAliasOfTheHonestlyNamedExpandPath(): void
    {
        // jailPath() reads like it enforces the jail; expandPath() does not.
        // The rename is the P8.14 fix, the alias is why no caller broke.
        foreach (['', '/etc/passwd', '../foo.txt', 'src/A.php'] as $path) {
            $this->assertSame(
                $this->bound->expandPath($path),
                $this->bound->jailPath($path),
                "alias diverged for '{$path}'",
            );
        }
    }

    public function testExpandPathProvesNothingOnItsOwn(): void
    {
        // The documented footgun, pinned so nobody "fixes" expandPath() into
        // silently returning a checked path and leaves isAllowed() callers
        // believing they still hold the raw candidate.
        $this->assertSame('/etc/passwd', $this->bound->expandPath('/etc/passwd'));
        $this->assertFalse($this->bound->isAllowed($this->bound->expandPath('/etc/passwd')));
    }

    // -------------------------------------------------------------------------
    // Parity: identical verdicts from the static algorithm and the bound jail
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function createCorpus(): array
    {
        return [
            'plain file' => ['a.txt', true],
            'nested missing dirs' => ['x/y/z/a.txt', true],
            'dot segments inside' => ['./sub/./a.txt', true],
            'traversal via missing dir' => ['nope/../../escaped.txt', false],
            'bare traversal' => ['../escaped.txt', false],
            'absolute outside' => ['/etc/passwd', false],
            'through symlinked dir, new file' => ['evil/newfile.txt', false],
            'through symlinked dir, existing file' => ['evil/passwd', false],
            'through symlinked dir, deep' => ['evil/a/b/c.txt', false],
            'sibling sharing the root prefix, existing' => ['../rootevil/stolen.txt', false],
            'sibling sharing the root prefix, new file' => ['../rootevil/planted.txt', false],
            'under a dangling symlink' => ['dangling/x.txt', false],
            'the dangling symlink itself' => ['dangling', false],
        ];
    }

    /**
     * @dataProvider createCorpus
     */
    public function testResolveForCreateAgreesAcrossBothJails(string $path, bool $allowed): void
    {
        $static = PathJail::resolveForCreate($this->root, $path);
        $bound = $this->bound->resolveForCreate($path);

        $this->assertSame($static, $bound, "jails disagreed on '{$path}'");
        $this->assertSame($allowed, $static !== null, "containment changed for '{$path}'");
    }

    /**
     * The read/search corpus, with the ABSOLUTE verdict each entry must get.
     *
     * The expected columns are load-bearing, not decoration. Because the bound
     * jail delegates every decision to the static algorithm, the two agree by
     * construction — an agreement-only assertion stays green through ANY
     * symmetric loosening of the shared algorithm, including the classic
     * missing-trailing-slash prefix hole that `../rootevil/stolen.txt` pins.
     * Parity catches divergence; these columns catch weakening.
     *
     * `resolve()` and `resolveDir()` differ on purpose for a missing path —
     * `resolve()` accepts a missing file whose parent exists (that is how
     * Write/Edit address a file about to be created), `resolveDir()` never
     * does, because its callers (Glob/Grep) are about to walk the result and
     * there is nothing to walk — so each gets its own column. Note that
     * `resolveDir()` gates on EXISTENCE, not on directory-ness: it hands back
     * an existing regular file too. That is deliberate — it is a containment
     * predicate, not a type check, and a regular file inside the jail is inside
     * the jail — but do NOT read it as "the callers do their own `is_dir()`",
     * because on the jailed branch they do not: `Glob::execute()` and
     * `Grep::execute()` both put `is_dir()` on the no-root `elseif` arm only.
     * With a root set, `resolveDir()`'s answer goes straight through, so
     * `Grep(root, 'sub/here.txt')` greps that single file and `Glob(root,
     * 'sub/here.txt')` reports 'not a directory'. Both outcomes stay inside the
     * jail, which is why the fix was to make Glob's message accurate rather
     * than to tighten `resolveDir()`.
     *
     * @return array<string, array{string, bool, bool}> path, resolve() allowed, resolveDir() allowed
     */
    public static function readCorpus(): array
    {
        return [
            'the jail root itself' => ['.', true, true],
            'existing dir' => ['sub', true, true],
            'existing file' => ['sub/here.txt', true, true],
            'deep existing dir' => ['x/y/z', true, true],
            'sibling escape' => ['../outside/passwd', false, false],
            'absolute outside' => ['/etc/passwd', false, false],
            'symlinked dir' => ['evil', false, false],
            'file under symlinked dir' => ['evil/passwd', false, false],
            'missing file, parent exists' => ['sub/notyet.txt', true, false],
            'missing file, parent missing' => ['nope/notyet.txt', false, false],
            'sibling sharing the root prefix, dir' => ['../rootevil', false, false],
            'sibling sharing the root prefix, file' => ['../rootevil/stolen.txt', false, false],
        ];
    }

    /**
     * The third column is named and ignored rather than left off the signature:
     * PHP silently drops surplus provider columns, so an unnamed one reads like
     * a two-column provider and invites a later "cleanup" that deletes the
     * column {@see testResolveDirAgreesAcrossBothJails} depends on.
     *
     * @dataProvider readCorpus
     */
    public function testResolveAgreesAcrossBothJails(string $path, bool $allowed, bool $unusedResolveDir): void
    {
        $static = PathJail::resolve($this->root, $path);

        $this->assertSame($static, $this->bound->resolve($path), "resolve() disagreed on '{$path}'");
        $this->assertSame($allowed, $static !== null, "resolve() containment changed for '{$path}'");
    }

    /**
     * @dataProvider readCorpus
     */
    public function testResolveDirAgreesAcrossBothJails(string $path, bool $unusedResolve, bool $allowed): void
    {
        $static = PathJail::resolveDir($this->root, $path);

        $this->assertSame($static, $this->bound->resolveDir($path), "resolveDir() disagreed on '{$path}'");
        $this->assertSame($allowed, $static !== null, "resolveDir() containment changed for '{$path}'");
    }

    /**
     * Every rejected read-corpus entry, asserted straight at the bound jail.
     *
     * The parity tests above already pin the verdicts, but they read them out
     * of the static algorithm; this one never consults it, so a bound jail that
     * stopped delegating and grew its own weaker check fails here too.
     */
    public function testTheWorktreeJailRejectsEveryRejectedReadPath(): void
    {
        foreach (self::readCorpus() as $label => [$path, $resolveAllowed, $resolveDirAllowed]) {
            if (!$resolveAllowed) {
                $this->assertNull($this->bound->resolve($path), "resolve(): {$label}");
            }
            if (!$resolveDirAllowed) {
                $this->assertNull($this->bound->resolveDir($path), "resolveDir(): {$label}");
            }
        }
    }

    // -------------------------------------------------------------------------
    // The escapes that must stay rejected, asserted on the bound jail directly
    // -------------------------------------------------------------------------

    public function testTheWorktreeJailRejectsEveryKnownEscape(): void
    {
        foreach (self::createCorpus() as $label => [$path, $allowed]) {
            if ($allowed) {
                continue;
            }
            $this->assertNull($this->bound->resolveForCreate($path), $label);
        }
    }

    public function testResolveForCreateAcceptsPathsWhoseParentsDoNotExistYet(): void
    {
        $resolved = $this->bound->resolveForCreate('x/y/z/deeper/still/a.txt');

        $this->assertNotNull($resolved);
        $this->assertStringStartsWith(realpath($this->root) . '/', $resolved);
    }

    // -------------------------------------------------------------------------
    // A NUL byte, asserted at the ALGORITHM rather than at each tool
    // -------------------------------------------------------------------------

    /**
     * Root/path pairs whose NUL makes `realpath()` and `is_dir()` THROW a
     * `ValueError` instead of returning false.
     *
     * @return array<string, array{bool, string}> NUL is in the ROOT?, path
     */
    public static function nulCorpus(): array
    {
        return [
            'NUL in a relative path' => [false, "a\0.txt"],
            'NUL mid-path' => [false, "sub/here\0.txt"],
            'NUL at the end' => [false, "sub/here.txt\0"],
            'NUL in an absolute path' => [false, "/etc/passwd\0"],
            'NUL and nothing else' => [false, "\0"],
            'NUL in an escaping path' => [false, "../outside/passwd\0"],
            'NUL in the root' => [true, 'sub/here.txt'],
        ];
    }

    /**
     * Every entry point screens a NUL byte, at the algorithm itself.
     *
     * The rows in `WorktreeJailRoutingTest::nulByteCallers()` cover the per-tool
     * guards; nothing covered {@see PathJail::unusable()}, which is the reason
     * those guards can be a courtesy (a better error message in each tool's own
     * vocabulary) rather than the load-bearing check. Rewriting `unusable()` to
     * `return false` left the whole of `tests/Tools` and `tests/Agents` green,
     * so the class-doc claim that it is fail-closed "for every caller, present
     * and future" was unenforced. This is the test that enforces it.
     *
     * A `ValueError` escaping any of these assertions IS the failure: it is
     * precisely the uncaught throw the screen exists to convert into a verdict.
     *
     * @dataProvider nulCorpus
     */
    public function testEveryEntryPointRejectsANulByte(bool $inRoot, string $path): void
    {
        $root = $inRoot ? $this->root . "\0" : $this->root;
        $bound = $inRoot ? new WorktreeJail($root, new PathJailConfig()) : $this->bound;

        $this->assertNull(PathJail::resolve($root, $path), 'static resolve()');
        $this->assertNull(PathJail::resolveForCreate($root, $path), 'static resolveForCreate()');
        $this->assertNull(PathJail::resolveDir($root, $path), 'static resolveDir()');

        $this->assertNull($bound->resolve($path), 'bound resolve()');
        $this->assertNull($bound->resolveForCreate($path), 'bound resolveForCreate()');
        $this->assertNull($bound->resolveDir($path), 'bound resolveDir()');
        $this->assertFalse($bound->isAllowed($path), 'bound isAllowed()');
    }

    // -------------------------------------------------------------------------
    // A jail whose configured root is not canonical
    // -------------------------------------------------------------------------

    /**
     * Containment holds, and stays scoped to `realpath($root)`, when the jail
     * is configured with a symlinked root.
     *
     * This is where the migration is STRICTLY STRONGER rather than equivalent,
     * and the reason the "identical verdicts" claim in
     * {@see \SugarCraft\Crush\Agents\PathJail::isAllowed()} is written as scoped
     * to a canonical root. The predicate that was replaced compared against the
     * raw configured string, so a jail spelled `<base>/rootlink` denied every
     * file inside its own worktree. The corpus below asserts both halves of the
     * fix at once: in-jail paths are allowed again, escapes still are not, and
     * nothing outside `realpath($root)` comes back.
     */
    public function testASymlinkedRootStillContainsAndNoLongerDeniesItsOwnFiles(): void
    {
        symlink($this->root, $this->base . '/rootlink');
        $jail = new WorktreeJail($this->base . '/rootlink', new PathJailConfig());
        $canonical = realpath($this->root);

        foreach (['sub/here.txt', 'sub', 'x/y/z', '.'] as $inJail) {
            $resolved = $jail->resolve($inJail);
            $this->assertNotNull($resolved, "symlinked root denied in-jail '{$inJail}'");
            $this->assertTrue(
                $resolved === $canonical || str_starts_with($resolved, $canonical . '/'),
                "symlinked root returned '{$resolved}' outside realpath(root) for '{$inJail}'",
            );
        }

        foreach (['../outside/passwd', '/etc/passwd', 'evil/passwd', '../rootevil/stolen.txt'] as $escape) {
            $this->assertNull($jail->resolve($escape), "symlinked root allowed escape '{$escape}'");
            $this->assertNull($jail->resolveForCreate($escape), "symlinked root allowed create '{$escape}'");
            $this->assertNull($jail->resolveDir($escape), "symlinked root allowed dir '{$escape}'");
        }
    }

    /**
     * The same, for a root spelled with a trailing slash — the other way a
     * configured root differs from its canonical form.
     */
    public function testATrailingSlashRootStillContainsAndNoLongerDeniesItsOwnFiles(): void
    {
        $jail = new WorktreeJail($this->root . '/', new PathJailConfig());
        $canonical = realpath($this->root);

        $this->assertSame($canonical . '/sub/here.txt', $jail->resolve('sub/here.txt'));
        $this->assertTrue($jail->isAllowed('sub/here.txt'));
        $this->assertNull($jail->resolve('../outside/passwd'));
        $this->assertNull($jail->resolveForCreate('../rootevil/planted.txt'));
        $this->assertNull($jail->resolveDir('../rootevil'));
    }
}
