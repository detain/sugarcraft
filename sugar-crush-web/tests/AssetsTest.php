<?php

declare(strict_types=1);

namespace SugarCraft\CrushWeb\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CrushWeb\Assets;

/**
 * Pins the committed bundle the shim hands to `sugarcrush serve`.
 *
 * These assertions run against the real `dist/`, not a fixture: what ships
 * to Packagist IS this directory, so a build that forgot its manifest, leaked
 * a source map or an `.env`, or referenced an asset it did not emit would
 * otherwise reach users as a blank page.
 */
final class AssetsTest extends TestCase
{
    public function testDistPathIsTheCanonicalBundleDirectory(): void
    {
        $dist = Assets::distPath();

        self::assertSame(\realpath(__DIR__ . '/../dist'), $dist);
        self::assertFileExists($dist . '/index.html');
        self::assertStringEndsNotWith('/', $dist);
    }

    public function testManifestHasOneEntryAndEveryFileItNamesExists(): void
    {
        $manifest = Assets::manifest();
        $dist = Assets::distPath();

        $entries = \array_filter($manifest, static fn (array $chunk): bool => ($chunk['isEntry'] ?? false) === true);
        self::assertCount(1, $entries, 'the bundle has exactly one entry chunk');
        self::assertArrayHasKey('index.html', $entries);

        foreach ($manifest as $source => $chunk) {
            self::assertIsString($chunk['file'] ?? null, "manifest entry {$source} names its output file");
            $files = [$chunk['file'], ...($chunk['css'] ?? []), ...($chunk['assets'] ?? [])];
            foreach ($files as $file) {
                self::assertFileExists($dist . '/' . $file, "manifest entry {$source} → {$file}");
                self::assertMatchesRegularExpression(
                    '~^assets/[\w.-]+-[\w-]{8}\.\w+$~',
                    $file,
                    'emitted assets carry a content hash, so serve can cache them immutably',
                );
            }
        }
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
}
