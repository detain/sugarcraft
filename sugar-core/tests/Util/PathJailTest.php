<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\PathJail;

/**
 * Containment verdicts over a real temporary tree, so every symlink case is
 * exercised against the filesystem rather than a mock of it.
 */
final class PathJailTest extends TestCase
{
    private string $base;
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/sugar-core-pathjail-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/root/sub', 0700, true);
        mkdir($this->base . '/outside', 0700, true);
        file_put_contents($this->base . '/root/inside.txt', 'in');
        file_put_contents($this->base . '/root/sub/deep.txt', 'deep');
        file_put_contents($this->base . '/outside/secret.txt', 'secret');

        $this->root = realpath($this->base . '/root');
        $this->outside = realpath($this->base . '/outside');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->base);
    }

    /**
     * Create a symlink, or skip the test where the platform refuses one. Windows
     * runners without developer mode cannot create symlinks, and the rest of the
     * suite still needs to run there.
     */
    private function link(string $target, string $link): void
    {
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('symlink() not permitted on this platform');
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    // ---------------------------------------------------------------- resolve()

    public function testResolveAcceptsAnExistingFileInside(): void
    {
        $this->assertSame($this->root . '/inside.txt', PathJail::resolve($this->root, 'inside.txt'));
    }

    public function testResolveAcceptsAnAbsolutePathInside(): void
    {
        $this->assertSame(
            $this->root . '/sub/deep.txt',
            PathJail::resolve($this->root, $this->root . '/sub/deep.txt'),
        );
    }

    public function testResolveAcceptsTheRootItself(): void
    {
        $this->assertSame($this->root, PathJail::resolve($this->root, '.'));
    }

    public function testResolveAcceptsAMissingFileWhoseParentExists(): void
    {
        $this->assertSame($this->root . '/sub/new.txt', PathJail::resolve($this->root, 'sub/new.txt'));
    }

    public function testResolveRefusesAMissingFileWhoseParentIsMissing(): void
    {
        // resolve() anchors on the immediate parent. Deeper creation is
        // resolveForCreate()'s job, and this refusal is the base rule.
        $this->assertNull(PathJail::resolve($this->root, 'missing/new.txt'));
    }

    public function testResolveRefusesADotDotEscape(): void
    {
        $this->assertNull(PathJail::resolve($this->root, '../outside/secret.txt'));
    }

    public function testResolveRefusesAnAbsolutePathOutside(): void
    {
        $this->assertNull(PathJail::resolve($this->root, $this->outside . '/secret.txt'));
    }

    public function testResolveRefusesAnExistingSymlinkThatLeavesTheRoot(): void
    {
        $this->link($this->outside . '/secret.txt', $this->root . '/escape.txt');

        $this->assertNull(PathJail::resolve($this->root, 'escape.txt'));
    }

    public function testResolveRefusesADanglingLeafInsideTheRoot(): void
    {
        // Regression. The link's target is inside the root but does not exist.
        // Before this rule, the parent check accepted the path, and a write
        // would have followed the link once its target was created.
        $this->link($this->root . '/not-yet.txt', $this->root . '/dangling.txt');

        $this->assertNull(PathJail::resolve($this->root, 'dangling.txt'));
    }

    public function testResolveRefusesADanglingLeafPointingOutsideTheRoot(): void
    {
        // Regression. The link points outside the root at a file that does
        // not exist. The parent of the link is inside the root, so the old
        // parent-only check would have accepted it.
        $this->link($this->outside . '/not-yet.txt', $this->root . '/escape-dangling.txt');

        $this->assertNull(PathJail::resolve($this->root, 'escape-dangling.txt'));
    }

    public function testResolveRefusesAnEmptyPath(): void
    {
        // Regression. realpath('') answers with the process working directory,
        // and before this rule the empty path resolved to the root.
        $this->assertNull(PathJail::resolve($this->root, ''));
    }

    public function testResolveRefusesAnEmptyRoot(): void
    {
        // Regression. An empty root would have anchored containment on the
        // working directory the process happens to have.
        $this->assertNull(PathJail::resolve('', 'inside.txt'));
    }

    public function testResolveRefusesAMissingRoot(): void
    {
        $this->assertNull(PathJail::resolve($this->base . '/no-such-root', 'x'));
    }

    public function testResolveRefusesANulByteWithoutThrowing(): void
    {
        $this->assertNull(PathJail::resolve($this->root, "inside.txt\0"));
    }

    public function testTheOverflowHookAcceptsAnOutsidePath(): void
    {
        $accepted = PathJail::resolve(
            $this->root,
            $this->outside . '/secret.txt',
            static fn (string $resolved): ?string => $resolved,
        );

        $this->assertSame($this->outside . '/secret.txt', $accepted);
    }

    public function testTheOverflowHookReceivesTheCanonicalPath(): void
    {
        $seen = [];
        PathJail::resolve(
            $this->root,
            '../outside/secret.txt',
            function (string $resolved) use (&$seen): ?string {
                $seen[] = $resolved;

                return null;
            },
        );

        $this->assertSame([$this->outside . '/secret.txt'], $seen);
    }

    public function testTheOverflowHookReturningNullRefuses(): void
    {
        $this->assertNull(PathJail::resolve(
            $this->root,
            '../outside/secret.txt',
            static fn (string $resolved): ?string => null,
        ));
    }

    public function testTheOverflowHookReturningANonStringRefuses(): void
    {
        // An untyped hook can return anything. Only a string is an acceptance.
        $this->assertNull(PathJail::resolve(
            $this->root,
            '../outside/secret.txt',
            static fn (string $resolved) => 1,
        ));
    }

    public function testTheOverflowHookIsNotConsultedForAnInsidePath(): void
    {
        $consulted = false;
        $this->assertSame($this->root . '/inside.txt', PathJail::resolve(
            $this->root,
            'inside.txt',
            function (string $resolved) use (&$consulted): ?string {
                $consulted = true;

                return $resolved;
            },
        ));
        $this->assertFalse($consulted);
    }

    public function testTheOverflowHookIsNotConsultedForAMissingPath(): void
    {
        $consulted = false;
        $this->assertNull(PathJail::resolve(
            $this->root,
            '../outside/missing.txt',
            function (string $resolved) use (&$consulted): ?string {
                $consulted = true;

                return $resolved;
            },
        ));
        $this->assertFalse($consulted);
    }

    // -------------------------------------------------------- resolveForCreate()

    public function testResolveForCreateAcceptsDeeplyMissingDirectories(): void
    {
        $this->assertSame(
            $this->root . '/new/deep/x.txt',
            PathJail::resolveForCreate($this->root, 'new/deep/x.txt'),
        );
    }

    public function testResolveForCreateAcceptsAnExistingFile(): void
    {
        $this->assertSame($this->root . '/inside.txt', PathJail::resolveForCreate($this->root, 'inside.txt'));
    }

    public function testResolveForCreateRefusesATraversalThroughAMissingDirectory(): void
    {
        $this->assertNull(PathJail::resolveForCreate($this->root, 'nope/../../outside/x.txt'));
    }

    public function testResolveForCreateRefusesADanglingLink(): void
    {
        $this->link($this->outside . '/not-yet.txt', $this->root . '/dangling.txt');

        $this->assertNull(PathJail::resolveForCreate($this->root, 'dangling.txt'));
    }

    public function testResolveForCreateRefusesAnEmptyPath(): void
    {
        $this->assertNull(PathJail::resolveForCreate($this->root, ''));
    }

    public function testResolveForCreateDoesNotTakeAnOverflowPath(): void
    {
        // The overflow hook is resolve()'s alone. Creating a file outside the
        // root is never allowed, whatever the spill store would have vouched for.
        $this->assertNull(PathJail::resolveForCreate($this->root, $this->outside . '/new.txt'));
    }

    public function testResolveForCreateRefusesADotDotAfterASymlinkedDirectory(): void
    {
        // Regression. `a` is a link to outside/x. The kernel resolves `a/..` to
        // outside/, so `a/../escaped.txt` creates outside/escaped.txt. The lexical
        // collapse used to approve root/escaped.txt, and the write escaped the root.
        mkdir($this->base . '/outside/x', 0700);
        $this->link($this->outside . '/x', $this->root . '/a');

        $this->assertNull(PathJail::resolveForCreate($this->root, 'a/../escaped.txt'));
        $this->assertFalse(file_exists($this->outside . '/escaped.txt'));
    }

    public function testResolveForCreateRefusesADotDotAfterASymlinkedPrefixNestedDeeper(): void
    {
        // The link sits one level down, and the `..` follows a real directory that
        // only exists through the link. The check must walk every prefix, not
        // just the component directly before the `..`.
        mkdir($this->base . '/outside/x/b', 0700, true);
        $this->link($this->outside . '/x', $this->root . '/sub/a');

        $this->assertNull(PathJail::resolveForCreate($this->root, 'sub/a/b/../escaped.txt'));
        $this->assertFalse(file_exists($this->outside . '/x/escaped.txt'));
    }

    public function testResolveForCreateStillCollapsesADotDotThroughARealDirectory(): void
    {
        // The guard refuses only `..` that crosses a symlink. An ordinary `..`
        // through a real in-root directory is still collapsed to the in-root path.
        $this->assertSame($this->root . '/new.txt', PathJail::resolveForCreate($this->root, 'sub/../new.txt'));
    }

    // ------------------------------------------------------------- resolveDir()

    public function testResolveDirAcceptsAnExistingDirectoryInside(): void
    {
        $this->assertSame($this->root . '/sub', PathJail::resolveDir($this->root, 'sub'));
    }

    public function testResolveDirAcceptsTheRootItself(): void
    {
        $this->assertSame($this->root, PathJail::resolveDir($this->root, '.'));
    }

    public function testResolveDirRefusesAMissingDirectory(): void
    {
        $this->assertNull(PathJail::resolveDir($this->root, 'missing'));
    }

    public function testResolveDirRefusesADirectoryOutside(): void
    {
        $this->assertNull(PathJail::resolveDir($this->root, '../outside'));
    }

    // ------------------------------------------------------------------ confine()

    public function testConfineAcceptsAnExistingFileInside(): void
    {
        $this->assertSame($this->root . '/inside.txt', PathJail::confine($this->root, 'inside.txt'));
    }

    public function testConfineAcceptsANewFileInside(): void
    {
        $this->assertSame($this->root . '/sub/new.txt', PathJail::confine($this->root, 'sub/new.txt'));
    }

    public function testConfineRefusesAnExistingFileOutside(): void
    {
        $this->assertNull(PathJail::confine($this->root, '../outside/secret.txt'));
    }

    public function testConfineRefusesADanglingLeafInsideTheRoot(): void
    {
        $this->link($this->root . '/not-yet.txt', $this->root . '/dangling.txt');

        $this->assertNull(PathJail::confine($this->root, 'dangling.txt'));
    }

    public function testConfineRefusesANulByte(): void
    {
        $this->assertNull(PathJail::confine($this->root, "x\0y"));
    }

    public function testConfineRefusesADotDotAfterASymlinkedDirectory(): void
    {
        // Regression. confine() approves a write when resolveForCreate() does, so
        // it must refuse the same symlink-then-`..` shape.
        mkdir($this->base . '/outside/x', 0700);
        $this->link($this->outside . '/x', $this->root . '/a');

        $this->assertNull(PathJail::confine($this->root, 'a/../escaped.txt'));
        $this->assertFalse(file_exists($this->outside . '/escaped.txt'));
    }

    // ------------------------------------------------------------ within() / below()

    public function testWithinAcceptsAPathInsideTheBoundary(): void
    {
        $this->assertTrue(PathJail::within($this->root . '/sub/deep.txt', $this->root));
    }

    public function testWithinAcceptsTheBoundaryItself(): void
    {
        // A link back onto the directory is a cycle, not an escape.
        $this->assertTrue(PathJail::within($this->root, $this->root));
    }

    public function testWithinRefusesASiblingThatSharesThePrefix(): void
    {
        // `/…/root` must not contain `/…/root-evil`.
        mkdir($this->base . '/root-evil');

        $this->assertFalse(PathJail::within($this->base . '/root-evil', $this->root));
    }

    public function testWithinRefusesAPathThatDoesNotExist(): void
    {
        $this->assertFalse(PathJail::within($this->root . '/missing', $this->root));
    }

    public function testWithinRefusesAnEmptyPathOrBoundary(): void
    {
        $this->assertFalse(PathJail::within('', $this->root));
        $this->assertFalse(PathJail::within($this->root, ''));
    }

    public function testWithinRefusesADanglingLinkEvenWhenItsTargetWouldBeInside(): void
    {
        $this->link($this->root . '/not-yet.txt', $this->root . '/dangling.txt');

        $this->assertFalse(PathJail::within($this->root . '/dangling.txt', $this->root));
    }

    public function testBelowAcceptsAPathStrictlyInside(): void
    {
        $this->assertTrue(PathJail::below($this->root . '/sub', $this->root));
    }

    public function testBelowRefusesTheBoundaryItself(): void
    {
        // A checkout root is the developer's working tree, not a curated
        // directory, so equality is refused where within() accepts it.
        $this->assertFalse(PathJail::below($this->root, $this->root));
    }

    public function testBelowRefusesAPathOutside(): void
    {
        $this->assertFalse(PathJail::below($this->outside, $this->root));
    }

    public function testBelowRefusesAPathThatDoesNotExist(): void
    {
        $this->assertFalse(PathJail::below($this->root . '/missing', $this->root));
    }

    // ------------------------------------------------- malformed and edge roots

    public function testTheFilesystemRootContainsEveryCanonicalPath(): void
    {
        // Regression. The root `/` used to build the prefix `//`, so every
        // canonical descendant was refused.
        $this->assertSame($this->root . '/inside.txt', PathJail::resolve('/', $this->root . '/inside.txt'));
        $this->assertSame($this->root, PathJail::resolveDir('/', $this->root));
        $this->assertSame($this->root . '/new/x.txt', PathJail::resolveForCreate('/', $this->root . '/new/x.txt'));
        $this->assertTrue(PathJail::within($this->root, '/'));
        $this->assertTrue(PathJail::below($this->root, '/'));
        $this->assertFalse(PathJail::below('/', '/'));
    }

    public function testAnEmptyRootIsRefusedByEveryMethodEvenWhenTheTargetExistsInTheWorkingDirectory(): void
    {
        // Regression. realpath('') answers with the working directory, so an
        // empty root made any relative target under the working directory look
        // confined. The target is created in the working directory here, which
        // is the case the report described.
        $previous = getcwd();
        $this->assertNotFalse($previous);
        chdir($this->root);
        try {
            $this->assertNull(PathJail::resolve('', 'inside.txt'));
            $this->assertNull(PathJail::resolveForCreate('', 'inside.txt'));
            $this->assertNull(PathJail::resolveDir('', '.'));
            $this->assertNull(PathJail::confine('', 'inside.txt'));
        } finally {
            chdir($previous);
        }
    }

    public function testAMalformedRootIsRefusedWithoutThrowingInEveryMethod(): void
    {
        // Regression. confine() and resolveDir() used to call realpath() before
        // the NUL check, and realpath() throws a ValueError on a NUL byte.
        $this->assertNull(PathJail::resolve("\0", 'inside.txt'));
        $this->assertNull(PathJail::resolveForCreate("\0", 'inside.txt'));
        $this->assertNull(PathJail::resolveDir("\0", '.'));
        $this->assertNull(PathJail::confine("\0", 'inside.txt'));
    }

    public function testAComparisonWithANulByteIsRefusedWithoutThrowing(): void
    {
        // Regression. within() and below() passed the NUL straight to realpath().
        $this->assertFalse(PathJail::within("{$this->root}/inside.txt\0", $this->root));
        $this->assertFalse(PathJail::within($this->root, "{$this->root}\0"));
        $this->assertFalse(PathJail::below("{$this->root}/sub\0", $this->root));
    }
}
