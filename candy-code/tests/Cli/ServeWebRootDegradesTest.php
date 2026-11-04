<?php

declare(strict_types=1);

namespace SugarCraft\CodeWeb;

/**
 * Test double for the suggest-only `sugarcraft/candy-code-web` package.
 *
 * candy-code does not require candy-code-web, so its real `Assets` is never
 * autoloadable here; `Serve::installedWebRoot()` reaches it through a string
 * FQCN + `method_exists` probe, which needs the class present to be exercised.
 * The double models the two resolvers the seam distinguishes:
 *
 *   - `distPath()`   — the HARD variant: returns the bundle dir when built,
 *                     raises a RuntimeException when `dist/` is absent.
 *   - `tryDistPath()`— the SOFT variant: returns the same dir, or null when
 *                     absent, and never throws.
 *
 * `$mode` picks which install a call reports. It defaults to `missing`, so an
 * incidental probe from any other test in this process answers the same null a
 * genuinely-absent package would — the double is behaviour-neutral until a
 * ServeWebRootDegradesTest method arms it.
 */
final class Assets
{
    /** @var 'missing'|'healthy' */
    public static string $mode = 'missing';

    /** Absolute path the healthy double hands back; set by the test. */
    public static string $healthyDir = '';

    public static function distPath(): string
    {
        if (self::$mode !== 'healthy') {
            throw new \RuntimeException('candy-code-web: dist/index.html is missing; the package install is incomplete.');
        }

        return self::$healthyDir;
    }

    public static function tryDistPath(): ?string
    {
        return self::$mode === 'healthy' ? self::$healthyDir : null;
    }
}

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Serve;
use SugarCraft\CodeWeb\Assets;

/**
 * CW-P2 — `candy-code serve --web` must degrade to the API-over-WebSocket-only
 * notice when the web bundle is not built, not crash at startup.
 *
 * The production defect: `Serve::installedWebRoot()` called the throwing
 * `Assets::distPath()` with no catch, so a package that composer registered but
 * never built raised a RuntimeException the caller turned into a hard
 * `failUsage` — instead of the null that routes to `StaticFiles::new(null)` and
 * the `cli.serve.announce.web_missing` line. The `is_string && is_dir` guard in
 * installedWebRoot was dead code on the healthy path because `distPath()` had
 * already thrown before it could run.
 *
 * These drive the private seam directly against the {@see Assets} double above:
 * the missing-bundle case is the load-bearing one — RED while installedWebRoot
 * still calls `distPath()` (the double throws), GREEN once it calls the soft
 * `tryDistPath()` (the double answers null). The healthy case proves the fix
 * left a real bundle resolving byte-for-byte unchanged.
 */
final class ServeWebRootDegradesTest extends TestCase
{
    /** @var list<string> temp dirs to sweep after the healthy probe */
    private array $temporaryDirs = [];

    protected function tearDown(): void
    {
        Assets::$mode = 'missing';
        Assets::$healthyDir = '';
        foreach ($this->temporaryDirs as $dir) {
            @\rmdir($dir);
        }
        $this->temporaryDirs = [];

        parent::tearDown();
    }

    /**
     * A half-installed bundle (composer saw the package, `dist/` was never
     * built) answers null — serve then serves the notice + API — rather than
     * throwing a RuntimeException that aborts startup.
     */
    public function testAMissingBundleDegradesToNullRatherThanThrowing(): void
    {
        Assets::$mode = 'missing';

        // The double throws here; the soft probe returns null. installedWebRoot
        // must take the null branch, so a reflection call returns instead of
        // propagating the exception the pre-fix code let escape.
        $root = self::callInstalledWebRoot();

        self::assertNull($root, 'a missing bundle degrades to the no-UI notice, it does not crash serve');
    }

    /**
     * A healthy install still resolves to the exact bundle directory — the fix
     * changed which probe is asked, not what a good bundle yields.
     */
    public function testAHealthyBundleResolvesUnchanged(): void
    {
        $dir = \sys_get_temp_dir() . '/g4a2-bundle-' . \uniqid('', true);
        self::assertIsBool(\mkdir($dir));
        $this->temporaryDirs[] = $dir;

        $canonical = \realpath($dir);
        self::assertIsString($canonical);

        Assets::$mode = 'healthy';
        Assets::$healthyDir = $canonical;

        self::assertSame($canonical, self::callInstalledWebRoot(), 'a built bundle is served from its canonical path');
    }

    /**
     * Invokes the private `Serve::installedWebRoot()` against the loaded
     * double. Before the fix the seam calls `distPath()` and the double throws
     * in missing mode, so the exception escapes this reflection call — the
     * precise behaviour the finding says must not happen at serve startup.
     * (PHP 8.1+ reflection is open by default; no setAccessible needed.)
     */
    private static function callInstalledWebRoot(): ?string
    {
        $method = new \ReflectionMethod(Serve::class, 'installedWebRoot');

        /** @var ?string $root */
        $root = $method->invoke(null);

        return $root;
    }
}
