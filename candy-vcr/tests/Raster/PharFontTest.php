<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\Glyphs;

/**
 * The phar:// font door (finding candy-vcr-023).
 *
 * The published phar ships fonts/ INSIDE the archive; FreeType reads plain
 * paths only, so a phar:// name that passes is_file()/is_readable() still
 * renders every tile as a bare background rectangle. These tests mint a real
 * phar (in a `-d phar.readonly=0` subprocess — the ini is unchangeable at
 * runtime), resolve the bundled family through the archive, and pin that
 * load() hands GD a real, inking path — and that an unusable face throws
 * instead of shipping blank glyphs.
 *
 * The CI smoke seat (render-tape off a built phar in the fleet dev-phar
 * channel) is deliberately NOT here: it belongs to the shared channel wave
 * (owner gate G10, candy-gifview-003 lineage).
 */
final class PharFontTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            self::removeTree($path);
        }
        $this->cleanup = [];
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function testRealFilesystemPathsPassThroughUntouched(): void
    {
        $path = self::PACKAGE_FONT;
        self::assertSame($path, FontLoader::materializeForGd($path));
    }

    public function testLoadExtractsPharFontToARealReadablePath(): void
    {
        $phar = $this->buildFontPhar(alias: 'vcrfonts.phar');
        $loader = new FontLoader(['phar://' . $phar . '/fonts']);

        $path = $loader->load('VcrPharMono', 14.0);

        self::assertStringStartsNotWith('phar://', $path, 'load() must never hand GD a phar:// path.');
        self::assertTrue(is_file($path));
        self::assertTrue(is_readable($path));

        // Bytes preserved: the extracted face is the same font, not a stub.
        $bundled = file_get_contents(self::PACKAGE_FONT);
        self::assertIsString($bundled);
        self::assertSame($bundled, file_get_contents($path), 'extraction must be byte-exact');

        // Stable per-process: a second load reuses the extracted file.
        self::assertSame($path, $loader->load('VcrPharMono', 14.0));
    }

    public function testImplicitPharSpellingIsMaterializedToo(): void
    {
        // The form that actually shipped blank on builds that translate it:
        // a running archive's path seen as /abs/app.phar/inner — no phar://
        // prefix. Capability-gated: this host's CLI resolves only the
        // explicit scheme, so the arm is pinned wherever translation exists.
        $phar = $this->buildFontPhar(alias: null);
        if (!is_dir($phar . '/fonts')) {
            $this::markTestSkipped('host PHP does not translate implicit phar paths.');
        }
        $loader = new FontLoader([$phar . '/fonts']);

        $path = $loader->load('VcrPharMono', 14.0);

        self::assertStringStartsNotWith($phar, $path, 'an archive-internal path must never reach GD');
        self::assertTrue(is_file($path));
    }

    public function testTilesRenderedFromAPharFontHaveInk(): void
    {
        $phar = $this->buildFontPhar(alias: 'vcrfonts.phar');
        $loader = new FontLoader(['phar://' . $phar . '/fonts']);
        $glyphs = new Glyphs(9, 16, $loader, 'VcrPharMono');

        $tile = $glyphs->tile('A', 7, 0, false, false, false);

        // The blank-artifact signature is a uniform background rectangle;
        // a real glyph means the tile carries more than one colour.
        self::assertGreaterThan(1, self::distinctColors($tile), 'phar font must paint ink pixels, not just the background');
    }

    public function testUnusableFaceThrowsInsteadOfShippingBlankGlyphs(): void
    {
        $dir = $this->tempDir();
        $junk = $dir . '/BrokenMono.ttf';
        self::assertNotFalse(file_put_contents($junk, 'not a ttf at all'));

        $loader = new FontLoader([$dir]);
        $glyphs = new Glyphs(9, 16, $loader, 'BrokenMono');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Font face unusable');
        $glyphs->tile('A', 7, 0, false, false, false);
    }

    public function testUnreadablePharPathFailsLoud(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read bundled font from archive');
        FontLoader::materializeForGd('phar:///does/not/exist.ttf');
    }

    private const PACKAGE_FONT = __DIR__ . '/../../fonts/JetBrainsMono-Regular.ttf';

    private function buildFontPhar(?string $alias): string
    {
        if (!extension_loaded('phar')) {
            $this->markTestSkipped('ext-phar unavailable.');
        }

        $dir = $this->tempDir();
        $familyDir = $dir . '/root/fonts/VcrPharMono';
        self::assertTrue(mkdir($familyDir, 0o755, true));
        $font = self::PACKAGE_FONT;
        self::assertTrue(copy($font, $familyDir . '/VcrPharMono-Regular.ttf'));

        $pharPath = $dir . '/vcrfonts.phar';
        $builder = $dir . '/build.php';
        self::assertNotFalse(file_put_contents($builder, <<<'PHP'
            <?php
            [$src, $dest, $alias] = [$argv[1], $argv[2], $argv[3] ?? null];
            $phar = $alias === null || $alias === ''
                ? new Phar($dest, 0)
                : new Phar($dest, 0, $alias);
            $phar->setStub('<?php __HALT_COMPILER();');
            $phar->buildFromDirectory($src);
            PHP));

        // phar.readonly is on in most suites/CLI defaults and cannot be changed
        // at runtime — mint the archive in a subprocess like the real channel does.
        $cmd = sprintf(
            '%s -d phar.readonly=0 -d memory_limit=256M -f %s -- %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($builder),
            escapeshellarg($dir . '/root'),
            escapeshellarg($pharPath),
            escapeshellarg($alias ?? ''),
        );
        exec($cmd . ' 2>&1', $output, $status);
        self::assertSame(0, $status, 'phar subprocess build failed: ' . implode("\n", $output));
        self::assertTrue(is_file($pharPath));

        return $pharPath;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/vcr-phar-font-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0o755, true));
        $this->cleanup[] = $dir;

        return $dir;
    }

    /**
     * @return int number of distinct RGB colours in the tile
     */
    private static function distinctColors(\GdImage $tile): int
    {
        $seen = [];
        for ($y = 0; $y < imagesy($tile); $y++) {
            for ($x = 0; $x < imagesx($tile); $x++) {
                $c = imagecolorat($tile, $x, $y);
                $seen[(($c >> 16) & 0xff) . ',' . (($c >> 8) & 0xff) . ',' . ($c & 0xff)] = true;
            }
        }

        return count($seen);
    }
}
