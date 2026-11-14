<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;

/**
 * VCR-S44 (finding TEST-04): the failure policy of the golden-comparison
 * harness, judged on synthetic canvases without rendering a tape. The
 * silent-failure bands this row closes — a >=50% diff that SKIPPED green,
 * a corrupted text row hidden in the canvas average, and an ffmpeg
 * fallback that read one composed frame — are each pinned here as FAIL
 * verdicts, on inputs the real walk would have waved through.
 */
final class VisualRegressionHarnessTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        if (!function_exists('imagepng')) {
            $this->markTestSkipped('synthetic canvases need ext-gd');
        }
        $this->tmpDir = sys_get_temp_dir() . '/candy-vcr-vrharness-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException("Failed to create temp dir: {$this->tmpDir}");
        }
    }

    protected function tearDown(): void
    {
        // Extraction scratch nests two levels deep — clean CHILD_FIRST.
        if (!is_dir($this->tmpDir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    public function testProbePassesOnAFullGateHost(): void
    {
        $reason = self::call('capabilityProbe');
        // The repo ALWAYS ships the bundled face, so the probe can only
        // report GD problems — and on every CI runner (and this host) it
        // reports none.
        $this->assertNull($reason, 'capability probe must pass on a gd+font host, got: ' . var_export($reason, true));
    }

    public function testRasterFingerprintIsADeterministicDigest(): void
    {
        if (!function_exists('imagettftext')) {
            $this->markTestSkipped('the raster fingerprint needs GD built with FreeType');
        }
        $fontPath = dirname(__DIR__, 2) . '/fonts/JetBrainsMono-Regular.ttf';
        if (!is_file($fontPath)) {
            $this->markTestSkipped('the raster fingerprint needs the bundled face');
        }

        $first = self::call('rasterFingerprint', $fontPath);
        $second = self::call('rasterFingerprint', $fontPath);

        $this->assertIsString($first, 'a gd+Freetype+font host must produce a raster fingerprint');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $first);
        $this->assertSame($first, $second, 'the fingerprint must be stable within a process');
    }

    public function testRasterGateReportsAReasonOrNothingNeverJunk(): void
    {
        $reason = self::call('rasterGate');
        $this->assertTrue($reason === null || (is_string($reason) && $reason !== ''));
        $this->assertSame($reason, self::call('rasterGate'), 'the gate is memoised per process');
    }

    public function testIdenticalCanvasesWalkZero(): void
    {
        $png = $this->solidPng('a', 60, 60, [10, 20, 30]);
        $stats = self::call('pixelDiffStats', $png, $png);
        $this->assertSame(0.0, $stats['pct']);
        $this->assertSame(0.0, $stats['worstRow']);
    }

    public function testWholeFrameDivergenceIsAFailReasonNotASkip(): void
    {
        $a = $this->solidPng('wa', 80, 80, [0, 0, 0]);
        $b = $this->solidPng('wb', 80, 80, [255, 255, 255]);
        $stats = self::call('pixelDiffStats', $a, $b);
        $this->assertSame(1.0, $stats['pct']);
        $verdict = self::call(
            'classifyDiff',
            $stats,
            0.02, // PHP_PIXEL_DIFF_PCT_THRESHOLD
            0.15, // ROW_DIFF_SUB_BUDGET
            0.50, // WHOLE_FRAME_DIFF
        );
        $this->assertIsString($verdict, 'a total-inverted frame must carry a failure reason');
        $this->assertStringContainsString('whole-frame divergence', $verdict);
    }

    public function testCorruptedRowBelowTheCanvasAverageStillFails(): void
    {
        // 500-row canvas, one fully differing 100-px row = 0.2% of the
        // canvas — invisible to the total budget, the exact shape of the
        // "one corrupted text row" regression from the finding.
        $a = $this->solidPng('ra', 100, 500, [5, 5, 5]);
        $b = $this->rowBrokenPng('rb', 100, 500, [5, 5, 5], 250);
        $stats = self::call('pixelDiffStats', $a, $b);
        $this->assertLessThan(0.02, $stats['pct'], 'damage must hide inside the old total budget');
        $this->assertSame(250, $stats['worstRowY']);
        $this->assertGreaterThanOrEqual(0.99, $stats['worstRow']);
        $verdict = self::call('classifyDiff', $stats, 0.02, 0.15, 0.50);
        $this->assertIsString($verdict);
        $this->assertStringContainsString('localized row damage', $verdict);
        $this->assertStringContainsString('row 250', $verdict);
    }

    public function testScatteredNoiseWithinBothBudgetsPasses(): void
    {
        // libgd anti-aliasing jitter class: 1% of pixels differ, spread
        // thinly (<= a handful per row) — the tolerance walk must wave it
        // through, which is why the budgets exist.
        $a = $this->solidPng('sa', 200, 200, [40, 40, 40]);
        $b = $this->scatterPng('sb', 200, 200, [40, 40, 40], 600);
        $stats = self::call('pixelDiffStats', $a, $b);
        $this->assertLessThan(0.02, $stats['pct']);
        $this->assertLessThan(0.15, $stats['worstRow']);
        $this->assertNull(self::call('classifyDiff', $stats, 0.02, 0.15, 0.50));
    }

    public function testTotalOverBudgetClassifiesAsThresholdFailure(): void
    {
        $a = $this->solidPng('ta', 100, 500, [7, 7, 7]);
        $b = $this->scatterPng('tb', 100, 500, [7, 7, 7], 1400); // 2.8% of 50 000
        $stats = self::call('pixelDiffStats', $a, $b);
        $this->assertGreaterThanOrEqual(0.02, $stats['pct']);
        $this->assertLessThan(0.15, $stats['worstRow'], 'scattered, not localized');
        $verdict = self::call('classifyDiff', $stats, 0.02, 0.15, 0.50);
        $this->assertIsString($verdict);
        $this->assertStringContainsString('pixel-diff', $verdict);
    }

    public function testFramePairWalkDetectsAChangedAnimationLength(): void
    {
        if (!self::ffmpegOnPath()) {
            $this->markTestSkipped('ffmpeg not on PATH');
        }
        $short = $this->lavGif('short', 0.2);
        $long = $this->lavGif('long', 1.0);

        $pair = self::call('compareFramePairs', $long, $short, $this->tmpDir . '/pairing');
        $this->assertTrue($pair['performed'], 'ffmpeg extraction must run when ffmpeg runs');
        $this->assertIsString($pair['reason']);
        $this->assertStringContainsString('animation length changed', $pair['reason']);

        $same = self::call('compareFramePairs', $short, $short, $this->tmpDir . '/pairing2');
        $this->assertTrue($same['performed']);
        $this->assertNull($same['reason'], 'identical GIFs must pass the pair walk');
    }

    /**
     * @param mixed ...$args
     */
    private static function call(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(VisualRegressionTest::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    private static function ffmpegOnPath(): bool
    {
        $result = shell_exec('command -v ffmpeg 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function solidPng(string $name, int $w, int $h, array $rgb): string
    {
        $img = imagecreatetruecolor($w, $h);
        $this->assertNotFalse($img);
        $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        $this->assertNotFalse($color);
        imagefill($img, 0, 0, $color);
        $path = $this->tmpDir . '/' . $name . '.png';
        $this->assertTrue(imagepng($img, $path));
        imagedestroy($img);
        return $path;
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function rowBrokenPng(string $name, int $w, int $h, array $rgb, int $badRow): string
    {
        $path = $this->solidPng($name, $w, $h, $rgb);
        $img = imagecreatefromstring((string) file_get_contents($path));
        $this->assertNotFalse($img);
        $inverted = imagecolorallocate($img, 255 - $rgb[0], 255 - $rgb[1], 255 - $rgb[2]);
        $this->assertNotFalse($inverted);
        imageline($img, 0, $badRow, $w - 1, $badRow, $inverted);
        $this->assertTrue(imagepng($img, $path));
        imagedestroy($img);
        return $path;
    }

    /**
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function scatterPng(string $name, int $w, int $h, array $rgb, int $diffPixels): string
    {
        $path = $this->solidPng($name, $w, $h, $rgb);
        $img = imagecreatefromstring((string) file_get_contents($path));
        $this->assertNotFalse($img);
        $hot = imagecolorallocate($img, 255, 0, 0);
        $this->assertNotFalse($hot);
        // Deterministic even spread so row-locality is a known property.
        $step = intdiv($w * $h, max(1, $diffPixels));
        for ($k = 0; $k < $diffPixels; $k++) {
            $at = ($k * $step + $k * 7) % ($w * $h);
            imagesetpixel($img, $at % $w, intdiv($at, $w), $hot);
        }
        $this->assertTrue(imagepng($img, $path));
        imagedestroy($img);
        return $path;
    }

    private function lavGif(string $name, float $duration): string
    {
        $path = $this->tmpDir . '/' . $name . '.gif';
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -y -f lavfi -i %s -vf %s %s',
            escapeshellarg('testsrc2=size=64x64:rate=10:duration=' . $duration),
            escapeshellarg('format=rgb24'),
            escapeshellarg($path),
        );
        exec($cmd, $ignored, $status);
        if ($status !== 0 || !is_file($path)) {
            $this->markTestSkipped('ffmpeg could not mint a test gif on this host');
        }
        return $path;
    }
}
