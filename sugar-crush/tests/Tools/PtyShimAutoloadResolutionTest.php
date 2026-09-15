<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Pty\Spawn;

/**
 * Regression pin for the pty-shim autoload-resolution defect that made every
 * sugar-crush CI run touching candy-pty red: the shim is reached through the
 * `vendor/sugarcraft/candy-pty` PATH-REPO SYMLINK, PHP resolves `__DIR__` to
 * the real checkout (which carries no vendor/ in a CI job that installs only
 * the matrix lib's vendor), and the shim died at exit 3
 * `pty-shim: vendor/autoload.php not found near …`.
 *
 * WHY THESE TESTS BUILD A SANDBOX INSTEAD OF SKIPPING: the same lookup fails
 * in PRODUCTION for any consumer installing candy-pty as a path-repo
 * dependency in a tree where the lib dir has no vendor — a test-only skip
 * would hide a real defect. The sandbox reproduces the symlinked shape
 * deterministically, independently of what the host's own candy-pty happens
 * to have installed, and the discriminator is the shim's own exit code:
 *   rc 3 + "not found"  = autoload unresolvable (the bug),
 *   rc 2 + "usage"      = autoload resolved and required, command simply
 *                         absent — which isolates the resolution step
 *                         without needing pcntl/ffi/pty claims to succeed.
 */
final class PtyShimAutoloadResolutionTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        // Nesting under a dedicated base keeps every "walk up and miss"
        // candidate of the shim's ladder inside freshly created space:
        // whether /tmp/autoload.php exists is not ours to know, but whether
        // <base>/<uniq>/autoload.php exists is.
        $this->sandbox = \sys_get_temp_dir() . '/sc-pty-shim-reg/' . \uniqid('r', true) . '/nest';
        \mkdir($this->sandbox . '/lib/bin', 0o755, true);
        \copy(self::realShim(), $this->sandbox . '/lib/bin/pty-shim.php');
    }

    protected function tearDown(): void
    {
        // Only this test's own unique tree: concurrent phpunit processes
        // (shards) share the /tmp base, and an empty base rmdir just fails
        // quietly — never a recursive sweep of a shared directory.
        self::removeTree(\dirname($this->sandbox));
        @\rmdir(\dirname($this->sandbox, 2));
    }

    /**
     * The shape that broke: invocation travels through
     * vendor/sugarcraft/candy-pty (a symlink), `__DIR__` resolves to the
     * symlink TARGET, and the target's tree has no autoloader anywhere the
     * upward probes reach. The shim must still find the CONSUMER's
     * autoload.php — read lexically off the as-invoked path, because the
     * kernel resolves `..` physically after symlink traversal, so no
     * `..`-string candidate can land on the consumer's vendor from here.
     */
    public function testShimInvokedThroughSymlinkedVendorFindsConsumerAutoloader(): void
    {
        $autoload = $this->plantConsumer();
        $shim = $this->linkShimThroughVendorSymlink($autoload);

        [$rc, $output] = self::runShim($shim);

        self::assertSame(2, $rc, $output);
        self::assertStringContainsString('usage', $output);
        self::assertStringNotContainsString('not found', $output);
    }

    /**
     * The primary fix: Spawn hands the shim the caller's own autoloader as
     * `--autoload=`. Consuming it correctly means (a) the file is required
     * and (b) the token is stripped so the COMMAND, not the token, is
     * argv[1] — proven here because a consumed token leaves the shim with
     * script-only argv and the usage branch (rc 2); a token left in place
     * would instead be taken as the command and reach the claim/exec path.
     */
    public function testShimConsumesExplicitAutoloadFlagBeforeCommand(): void
    {
        $autoload = $this->plantConsumer();
        // Invoke from the TARGET dir (not the symlink): nothing in the
        // shim's own probes can find this tree's autoload by luck — the
        // flag is the only channel that can succeed.
        $shim = $this->sandbox . '/lib/bin/pty-shim.php';

        [$rc, $output] = self::runShim($shim, '--autoload=' . $autoload);

        self::assertSame(2, $rc, $output);
        self::assertStringContainsString('usage', $output);
    }

    /**
     * Fail-fast on a hand-off that names no real file: silence here would
     * turn a broken Spawn into the very "not found" misdirection that made
     * the original CI failure so expensive to diagnose. BOTH invalid shapes
     * — a path that is not a file, and the empty value (`--autoload=` with
     * nothing after the flag) — must be refused identically and loudly.
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidAutoloadFlags(): array
    {
        return [
            'missing file' => ['--autoload=/definitely/not/here.php'],
            'empty value' => ['--autoload='],
        ];
    }

    /**
     * @dataProvider invalidAutoloadFlags
     */
    public function testShimRefusesMissingAutoloadFlagLoudly(string $flag): void
    {
        $this->plantConsumer();

        [$rc, $output] = self::runShim($this->sandbox . '/lib/bin/pty-shim.php', $flag);

        self::assertSame(3, $rc, $output);
        self::assertStringContainsString('--autoload file not found', $output);
    }

    /**
     * The honesty gate: an autoload that RESOLVED and required cleanly but
     * does not define this package's machinery (exactly what a foreign
     * composer root could do to the lexical vendor-root cut) must surface
     * as the documented exit-3 autoload error — never as a class-not-found
     * fatal from the claim path. The stub sandbox autoload is the impotent
     * autoload; a real cmd argument is supplied so the usage branch cannot
     * be what ends the run.
     */
    public function testShimRefusesAnAutoloadThatCannotDefineTheClaimMachinery(): void
    {
        $autoload = $this->plantConsumer();

        [$rc, $output] = self::runShim($this->sandbox . '/lib/bin/pty-shim.php', '--autoload=' . $autoload, '/bin/true');

        self::assertSame(3, $rc, $output);
        self::assertStringContainsString('does not define ControllingTerminal', $output);
    }

    /**
     * The layouts the ladder already served must stay served: a COPIED
     * vendor install (composer's non-symlinked shape — published siblings)
     * resolves through the upward `__DIR__` probes, not the lexical
     * vendor-root fallback. Pinning this catches a rewrite of the ladder
     * that quietly drops the original candidates.
     */
    public function testShimStillResolvesAutoloadInCopiedVendorLayout(): void
    {
        $autoload = $this->plantConsumer();
        $copied = \dirname($autoload) . '/sugarcraft/candy-pty';
        \mkdir($copied . '/bin', 0o755, true);
        \copy(self::realShim(), $copied . '/bin/pty-shim.php');

        [$rc, $output] = self::runShim($copied . '/bin/pty-shim.php');

        self::assertSame(2, $rc, $output);
        self::assertStringNotContainsString('not found', $output);
    }

    /**
     * Spawn side of the fix: `wrapInShim()` prepends `[PHP_BINARY, shim,
     * --autoload=<this process's entry autoloader>, ...cmd]` — the entry
     * autoloader being the one sugar-crush CI actually installed, and the
     * command tail surviving untouched (including any literal
     * `--autoload=`-shaped user argument, which must survive BECAUSE only
     * the first, injected token is consumed).
     */
    public function testWrapInShimHandsOffCallerAutoloaderAndPreservesCommand(): void
    {
        if (!\extension_loaded('pcntl')) {
            self::markTestSkipped('wrapInShim guards on ext-pcntl; the sugar-crush CI matrix always has it.');
        }

        $method = new ReflectionMethod(Spawn::class, 'wrapInShim');
        $method->setAccessible(true);
        /** @var list<string> $argv */
        $argv = $method->invoke(null, ['/bin/sh', '-c', 'true', '--autoload=user-visible']);

        self::assertSame(\PHP_BINARY, $argv[0]);
        self::assertSame(\realpath(self::realShim()), $argv[1]);
        self::assertStringStartsWith('--autoload=', $argv[2]);
        self::assertSame(
            \realpath(\dirname(__DIR__, 2) . '/vendor/autoload.php'),
            \realpath(\substr($argv[2], \strlen('--autoload='))),
        );
        self::assertSame(['/bin/sh', '-c', 'true', '--autoload=user-visible'], \array_slice($argv, 3));
    }

    /**
     * `callerAutoloader()` resolves to THIS process's composer entrypoint —
     * the file the phpunit launcher included — not to anything derived from
     * Spawn's own (symlink-resolved) location. That identity is the entire
     * fix: it is what the shim cannot know from inside the child.
     */
    public function testCallerAutoloaderIsTheIncludedEntrypoint(): void
    {
        $method = new ReflectionMethod(Spawn::class, 'callerAutoloader');
        $method->setAccessible(true);
        $found = $method->invoke(null);

        self::assertIsString($found);
        self::assertSame(
            \realpath(\dirname(__DIR__, 2) . '/vendor/autoload.php'),
            $found,
            'the sugar-crush suite runs under exactly one entrypoint autoload.php; the scan must name it',
        );
    }

    /**
     * @return array{0:int,1:string}
     */
    private static function runShim(string $shim, string ...$args): array
    {
        $command = \PHP_BINARY . ' ' . \escapeshellarg($shim);
        foreach ($args as $arg) {
            $command .= ' ' . \escapeshellarg($arg);
        }

        $lines = [];
        \exec($command . ' 2>&1', $lines, $rc);

        return [(int) $rc, \implode("\n", $lines)];
    }

    /**
     * Creates `<sandbox>/nest/consumer/vendor/autoload.php` — a stub
     * standing in for the consuming project's composer entrypoint — and
     * returns its path. The sandbox lib dir deliberately has no vendor/.
     */
    private function plantConsumer(): string
    {
        $vendor = $this->sandbox . '/consumer/vendor';
        \mkdir($vendor, 0o755, true);
        $autoload = $vendor . '/autoload.php';
        \file_put_contents(
            $autoload,
            "<?php\n// sandbox stand-in for the consuming project's composer entrypoint\n",
        );

        return $autoload;
    }

    /**
     * Wires `consumer/vendor/sugarcraft/candy-pty` → `../../lib` (a path-repo
     * symlink into a checkout with no vendor of its own) and returns the
     * shim's AS-INVOKED path through the symlink — mirroring exactly how
     * composer's `$bin` proxy and Spawn's argv reach the shim in CI.
     */
    private function linkShimThroughVendorSymlink(string $consumerAutoload): string
    {
        $vendor = \dirname($consumerAutoload);
        \mkdir($vendor . '/sugarcraft', 0o755, true);
        \symlink($this->sandbox . '/lib', $vendor . '/sugarcraft/candy-pty');

        return $vendor . '/sugarcraft/candy-pty/bin/pty-shim.php';
    }

    /**
     * The shipped shim, located through the package root of the loaded
     * Spawn class — valid whether candy-pty is a monorepo sibling or an
     * installed copy.
     */
    private static function realShim(): string
    {
        $packageRoot = \dirname((new \ReflectionClass(Spawn::class))->getFileName(), 2);

        return $packageRoot . '/bin/pty-shim.php';
    }

    private static function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || $item->isFile()) {
                @\unlink($item->getPathname());
            } else {
                @\rmdir($item->getPathname());
            }
        }
        @\rmdir($path);
    }
}
