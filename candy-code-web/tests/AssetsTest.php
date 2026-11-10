<?php

declare(strict_types=1);

namespace SugarCraft\CodeWeb\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeWeb\Assets;

/**
 * Pins the committed bundle the shim hands to `candy-code serve`.
 *
 * These assertions run against the real `dist/`, not a fixture: what ships
 * to Packagist IS this directory, so a build that forgot its manifest, leaked
 * a source map or an `.env`, or referenced an asset it did not emit would
 * otherwise reach users as a blank page.
 */
final class AssetsTest extends TestCase
{
    /**
     * Outputs intentionally emitted at the bundle root with a STABLE url,
     * exempt from the content-hash rule. A service worker must never change
     * its own address (its registration scope is the url it was served at,
     * and candy-code serves it with a year-long cache), so moving `sw.js`
     * under `assets/` with a hash would stale every installed worker for a
     * year. Roster is bidirectional: an entry that stops being root-stable
     * goes red here, and a hashed build that keeps a stale row goes red too.
     */
    private const ROOT_STABLE_OUTPUTS = ['sw.js'];

    /** @var list<string> temporary package roots to sweep after each test */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            foreach (\glob($root . '/dist/*') ?: [] as $file) {
                @\unlink((string) $file);
            }
            @\rmdir($root . '/dist');
            @\rmdir($root);
        }
        $this->temporaryRoots = [];

        parent::tearDown();
    }

    public function testDistPathIsTheCanonicalBundleDirectory(): void
    {
        $dist = Assets::distPath();

        self::assertSame(\realpath(__DIR__ . '/../dist'), $dist);
        self::assertFileExists($dist . '/index.html');
        self::assertStringEndsNotWith('/', $dist);
    }

    public function testTryDistPathAgreesWithDistPathOnTheRealBundle(): void
    {
        // The soft variant must never disagree with the hard one where both
        // answer: candy-code's installedWebRoot() branches on the null.
        self::assertSame(Assets::distPath(), Assets::tryDistPath());
    }

    public function testManifestHasOnePageEntryAndEveryFileItNamesExists(): void
    {
        $manifest = Assets::manifest();
        $dist = Assets::distPath();
        $index = (string) \file_get_contents($dist . '/index.html');

        // "Entry" is Vite's word for both the page bundle and a service
        // worker build — the bundle is ONE entry chunk when the page is
        // asked for exactly. The service worker rides the root roster below.
        $pageEntries = [];
        foreach ($manifest as $source => $chunk) {
            if (($chunk['isEntry'] ?? false) !== true) {
                continue;
            }

            $file = $chunk['file'] ?? null;
            if (\is_string($file) && \str_contains($index, 'src="./' . $file . '"')) {
                $pageEntries[] = $source;
            }
        }

        self::assertSame(['index.html'], $pageEntries, 'index.html references exactly one entry chunk');

        foreach ($manifest as $source => $chunk) {
            self::assertIsString($chunk['file'] ?? null, "manifest entry {$source} names its output file");
            $files = [$chunk['file'], ...($chunk['css'] ?? []), ...($chunk['assets'] ?? [])];
            foreach ($files as $file) {
                self::assertFileExists($dist . '/' . $file, "manifest entry {$source} → {$file}");
                if (\in_array($file, self::ROOT_STABLE_OUTPUTS, true)) {
                    continue;
                }

                self::assertMatchesRegularExpression(
                    '~^assets/[\w.-]+-[\w-]{8}\.\w+$~',
                    $file,
                    "emitted assets carry a content hash, so serve can cache them immutably ({$file})",
                );
            }
        }
    }

    public function testRootStableRosterMatchesTheBundleExactly(): void
    {
        $hashed = [];
        foreach (Assets::manifest() as $chunk) {
            foreach ([$chunk['file'], ...($chunk['css'] ?? []), ...($chunk['assets'] ?? [])] as $file) {
                if (\is_string($file) && !\preg_match('~^assets/[\w.-]+-[\w-]{8}\.\w+$~', $file)) {
                    $hashed[] = $file;
                }
            }
        }

        // Forward: every unhashed output is a rostered stable url.
        self::assertSame(
            self::ROOT_STABLE_OUTPUTS,
            $hashed,
            'root-level unhashed outputs must be rostered (and stay out of assets/ on purpose)',
        );
    }

    public function testIndexHtmlLoadsTheManifestEntryByRelativeUrl(): void
    {
        $index = (string) \file_get_contents(Assets::distPath() . '/index.html');
        $entry = Assets::manifest()['index.html'];

        self::assertStringContainsString('src="./' . $entry['file'] . '"', $index);
        foreach ($entry['css'] ?? [] as $css) {
            self::assertStringContainsString('href="./' . $css . '"', $index);
        }
        self::assertStringNotContainsString('/src-web/', $index, 'the dev entry never ships');
    }

    public function testVersionMatchesPackageJson(): void
    {
        $package = \json_decode(
            (string) \file_get_contents(__DIR__ . '/../package.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertSame($package['version'], Assets::version());
    }

    public function testNoSourceMapsOrEnvFilesLeakIntoTheBundle(): void
    {
        $dist = Assets::distPath();
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS),
        );

        $seen = 0;
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $seen++;
            $name = $file->getFilename();
            self::assertStringEndsNotWith('.map', $name, "source map leaked: {$name}");
            self::assertStringStartsNotWith('.env', $name, "env file leaked: {$name}");
            if (\in_array($file->getExtension(), ['js', 'css', 'html'], true)) {
                self::assertStringNotContainsString(
                    'sourceMappingURL',
                    (string) \file_get_contents($file->getPathname()),
                    "{$name} points at a source map",
                );
            }
        }
        self::assertGreaterThan(0, $seen);
    }

    public function testDiagnoseReportsAWellFormedPackageRoot(): void
    {
        $root = $this->makeBundleRoot();
        self::writeBundleFiles($root, manifest: '{"index.html":{"file":"assets/a-DEADBEEF.js","isEntry":true}}', buildInfo: '{"version":"9.9.9"}');

        $report = Assets::diagnose($root);

        self::assertNull($report['error']);
        self::assertSame($root . '/dist', $report['dist']);
        self::assertSame(['index.html'], \array_keys($report['manifest']));
        self::assertSame('9.9.9', $report['version']);
    }

    /**
     * Every failure branch of the throwing API, reached through the reporting
     * seam: a broken install must be NAMEABLE (the serve doctor reads this),
     * and each message here is the message distPath/manifest/version throws.
     *
     * @return array<string, array{0: bool, 1: array<string, ?string>, 2: string}>
     *         [writeHealthyBundle, overrides (null = delete), error needle]
     */
    public static function brokenInstallBranches(): array
    {
        return [
            'nothing but an empty dist' => [false, [], 'dist/index.html is missing'],
            'index.html deleted' => [true, ['index.html' => null], 'dist/index.html is missing'],
            'manifest file absent' => [true, ['manifest.json' => null], 'dist/manifest.json is missing'],
            'manifest unreadable JSON' => [true, ['manifest.json' => '{'], 'dist/manifest.json is not valid JSON'],
            'manifest JSON is not an object' => [true, ['manifest.json' => '5'], 'dist/manifest.json is not a JSON object'],
            'build-info absent' => [true, ['build-info.json' => null], 'dist/build-info.json is missing'],
            'build-info not an object' => [true, ['build-info.json' => '"x"'], 'dist/build-info.json is not a JSON object'],
            'build-info carries no version' => [true, ['build-info.json' => '{}'], 'build-info.json carries no version'],
            'build-info version is not a string' => [true, ['build-info.json' => '{"version":7}'], 'carries no version'],
        ];
    }

    /** @param array<string, ?string> $overrides file name => content, or null to delete */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenInstallBranches')]
    public function testDiagnoseNamesEachBrokenInstallBranch(bool $healthyStart, array $overrides, string $expectedNeedle): void
    {
        $root = $this->makeBundleRoot();
        if ($healthyStart) {
            self::writeBundleFiles($root, manifest: '{"index.html":{"file":"assets/a-DEADBEEF.js"}}', buildInfo: '{"version":"9.9.9"}');
        }
        foreach ($overrides as $name => $content) {
            $path = $root . '/dist/' . $name;
            if ($content === null) {
                @\unlink($path);
                continue;
            }
            \file_put_contents($path, $content);
        }

        $report = Assets::diagnose($root);

        self::assertNotNull($report['error'], 'the broken branch must be reported, not papered over');
        self::assertStringContainsString($expectedNeedle, (string) $report['error']);
        self::assertNull($report['manifest']);
        self::assertNull($report['version']);
    }

    public function testTheServeShimContractIsNameStable(): void
    {
        // candy-code's Serve::installedWebRoot() reaches this class through
        // a string FQCN + method_exists probe (composer `suggest`, no hard
        // dep), which is invisible to static analysis in BOTH packages:
        // renaming the namespace or the probed method silently drops the UI to
        // the "web_missing" fallback. This pin is the seam's only tripwire.
        //
        // CW-P2: the probe must name the SOFT `tryDistPath()` (null on a
        // half-built bundle) — pinning `distPath` here would let a future edit
        // restore the throwing call that crashes serve at startup, the exact
        // defect this test exists to prevent. The `is_string && is_dir` branch
        // in installedWebRoot only does work against a non-throwing probe.
        // The seam lives in the serve subsystem, not in one file: the G4-H
        // extraction moved Serve::installedWebRoot() (and with it the probe)
        // from Serve.php into Serve/ServeStartup.php. Scan the shell plus the
        // whole Serve/ directory so a future internal split of candy-code's
        // serve code cannot silently blind this tripwire.
        $serveDir = \dirname(__DIR__, 2) . '/candy-code/src/Cli';
        $servePath = $serveDir . '/Serve.php';
        if (!\is_file($servePath)) {
            // Split-repo checkout of the web package alone: the sibling
            // server is not in this tree (carve-out mirrors candy-code's
            // WebStringsDriftTest shape).
            self::markTestSkipped('candy-code sibling absent — split-repo checkout');
        }

        self::assertSame('SugarCraft\CodeWeb\Assets', Assets::class);
        self::assertTrue(\method_exists(Assets::class, 'tryDistPath'), 'Serve probes tryDistPath() by name');

        $serve = (string) \file_get_contents($servePath);
        foreach (glob($serveDir . '/Serve/*.php') ?: [] as $part) {
            $serve .= (string) \file_get_contents($part);
        }
        self::assertStringContainsString("'SugarCraft\\\\CodeWeb\\\\Assets'", $serve, 'Serve probes this exact FQCN string');
        self::assertStringContainsString("method_exists(\$assets, 'tryDistPath')", $serve, 'the probed method keeps its name');
        self::assertStringContainsString('$assets::tryDistPath()', $serve, 'the serve path calls the soft probe');
        self::assertStringNotContainsString('$assets::distPath()', $serve, 'the serve path never calls the throwing distPath() (CW-P2)');
    }

    /**
     * CW-P2 load-bearing positive: a half-installed bundle — `dist/index.html`
     * absent — is the graceful-degradation input serve branches on. The soft
     * resolver behind `tryDistPath()` answers null there (no throw), which is
     * what keeps `--web` alive as API-over-WebSocket-only; the throwing
     * resolver behind `distPath()` raises, and serve never catches it.
     */
    public function testTryDistPathAnswersNullForAMissingBundleWithoutThrowing(): void
    {
        $root = $this->makeBundleRoot();

        // Nothing in dist/ — not even index.html: composer registered the
        // package, the bundle was simply never built.
        self::assertNull(Assets::diagnose($root)['dist'], 'the empty root is not a bundle');

        // Reach the same resolver the public soft probe delegates to, scoped
        // to this synthetic half-install. It must return null, not throw.
        $soft = new \ReflectionMethod(Assets::class, 'tryResolveDist');
        self::assertNull($soft->invoke(null, $root), 'a missing bundle answers null, never throws');

        // The hard resolver behind distPath() — the call the buggy serve used —
        // is the one that raises on this same root, which is the whole defect.
        $this->expectException(\RuntimeException::class);
        $hard = new \ReflectionMethod(Assets::class, 'resolveDist');
        $hard->invoke(null, $root);
    }

    private function makeBundleRoot(): string
    {
        $root = \sys_get_temp_dir() . '/codeweb-assets-' . \uniqid('', true);
        self::assertIsBool(\mkdir($root . '/dist', 0o777, true));
        $this->temporaryRoots[] = $root;

        return $root;
    }

    private static function writeBundleFiles(string $root, string $manifest, string $buildInfo): void
    {
        \file_put_contents($root . '/dist/index.html', '<html></html>');
        \file_put_contents($root . '/dist/manifest.json', $manifest);
        \file_put_contents($root . '/dist/build-info.json', $buildInfo);
    }
}
