<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * Visual regression: re-render each curated tape and diff against the
 * committed golden GIFs. PhpGifEncoder is byte-deterministic on a given
 * host, but the rendered bytes depend on the host libgd / GD font-cache
 * build, which varies between local-dev hosts and CI runners. To keep the
 * regression signal without forcing per-runner golden refreshes we
 * compare via:
 *
 *   1) A host-capability probe (ext-gd round-trip + the bundled font
 *      file) AND a golden-host raster fingerprint (FreeType build —
 *      see {@see self::rasterGate()}). A host that CANNOT render like
 *      the golden host is skipped — it has no business judging pixels.
 *      A host that CAN render and then diverges is failing the gate, no
 *      matter how large the divergence: whole-frame and single-row
 *      damage both fail loudly (VCR-S44).
 *   2) SHA-256 fast-path — bit-identical means we're done.
 *   3) Pixel-tolerance walk — pixels differing by more than 8 in any
 *      channel count; the total may reach 2% of the canvas AND no single
 *      row may reach 15% of itself, so localized damage hidden in the
 *      average is caught too.
 *
 * FfmpegGifEncoder gets an SSIM check (>= 0.95); when the SSIM filter
 * can't parse cleanly the fallback walks EVERY frame (ffmpeg passthrough
 * extraction; a changed frame count is itself a failure), not just the
 * composed canvas.
 *
 * Refresh procedure documented in candy-vcr/CALIBER_LEARNINGS.md.
 */
final class VisualRegressionTest extends TestCase
{
    private const SSIM_THRESHOLD = 0.95;
    private const PIXEL_DIFF_PCT_THRESHOLD = 0.01; // 1% of pixels may differ by >8 per channel
    /**
     * Looser PHP-encoder tolerance: libgd builds differ enough between
     * local-dev (matching golden) and Ubuntu CI runners that a
     * pixel-perfect assertion regularly fires on cosmetic-only deltas.
     * 2% absorbs that noise without losing the regression signal — a
     * real encoder bug lands at double-digit pixel diff.
     */
    private const PHP_PIXEL_DIFF_PCT_THRESHOLD = 0.02;
    /**
     * Divergence at which we declare the whole frame broken (fallback
     * font, inverted palette, theme plumbing failure). Since VCR-S44
     * this is a FAIL band, not a skip: "too broken to be a regression"
     * is backwards for a gate whose job is catching breakage — hosts
     * that merely render differently are excluded by
     * {@see self::capabilityProbe()} BEFORE any pixel is judged.
     */
    private const WHOLE_FRAME_DIFF = 0.50;
    /**
     * No single row of the canvas may differ by more than this fraction
     * of its own pixels, even when the all-frame average stays under the
     * total budget (one corrupted text row is ~0.1% of a 480-row canvas).
     */
    private const ROW_DIFF_SUB_BUDGET = 0.15;

    private string $tmpDir;

    /** Memoised golden-host raster gate reason (one FreeType probe per process). */
    private static ?string $rasterGateReason = null;
    private static bool $rasterGateComputed = false;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy-vcr-visual-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException("Failed to create temp dir: {$this->tmpDir}");
        }
    }

    protected function tearDown(): void
    {
        // The frame-pair extractor writes subdirectories; clean deepest
        // first so every rmdir sees an empty directory.
        $dirs = array_merge([$this->tmpDir], glob($this->tmpDir . '/*', GLOB_ONLYDIR) ?: []);
        usort($dirs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (!is_dir($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function tapeProvider(): iterable
    {
        $tapeDir = __DIR__ . '/../golden/tapes';
        $files = glob($tapeDir . '/*.tape') ?: [];
        sort($files);
        foreach ($files as $tape) {
            $name = preg_replace('/\.tape$/', '', basename($tape)) ?? basename($tape);
            yield $name => [$tape];
        }
    }

    #[DataProvider('tapeProvider')]
    #[Group('golden')]
    public function testPhpGoldenWithinTolerance(string $tapePath): void
    {
        $probe = self::capabilityProbe();
        if ($probe !== null) {
            self::markTestSkipped($probe);
        }

        $rasterGate = self::rasterGate();
        if ($rasterGate !== null) {
            self::markTestSkipped($rasterGate);
        }

        $name = preg_replace('/\.tape$/', '', basename($tapePath)) ?? basename($tapePath);
        $goldenPath = __DIR__ . '/../golden/' . $name . '.php.gif';

        if (!is_file($goldenPath)) {
            self::markTestSkipped("No PHP golden for {$name}");
        }

        $producedPath = $this->tmpDir . '/' . $name . '.php.gif';
        TapeToGif::new(['encoder' => 'php'])->render($tapePath, $producedPath, [
            'encoder' => 'php',
        ]);

        self::assertFileExists($producedPath, "renderer did not produce {$producedPath}");

        // Fast-path: byte-identical short-circuit (still true on hosts
        // whose libgd matches the golden-rendering host).
        $producedHash = hash_file('sha256', $producedPath);
        $goldenHash = hash_file('sha256', $goldenPath);
        if ($producedHash === $goldenHash) {
            self::assertSame($goldenHash, $producedHash);
            return;
        }

        // Slow-path: pixel-walk tolerance check. Pixels differing by >8
        // in any channel count as "different"; <= PHP_PIXEL_DIFF_PCT_THRESHOLD
        // of total pixels may differ, AND no single row may exceed the
        // sub-budget of itself (VCR-S44: localized row damage hid in the
        // canvas average before).
        $stats = self::pixelDiffStats($producedPath, $goldenPath);
        if ($stats === null) {
            $diffNotePath = $this->writeDiffNote($name, $goldenPath, $producedPath);
            self::fail(sprintf(
                "PHP golden hash mismatch for %s and pixel-diff could not be computed\n"
                . "  golden  : %s (%s)\n  produced: %s (%s)\n  diff    : %s",
                $name,
                $goldenPath,
                $goldenHash,
                $producedPath,
                $producedHash,
                $diffNotePath,
            ));
        }

        $verdict = self::classifyDiff(
            $stats,
            self::PHP_PIXEL_DIFF_PCT_THRESHOLD,
            self::ROW_DIFF_SUB_BUDGET,
            self::WHOLE_FRAME_DIFF,
        );
        if ($verdict !== null) {
            // Capability probe passed, so ANY magnitude — including the
            // old ≥50% "fundamentally different image" band — is a real
            // regression signal and fails. (findings 098-era law: the
            // gate's job is catching breakage.)
            $diffImagePath = $this->writeDiffImage($name, $goldenPath, $producedPath);
            self::fail(sprintf(
                "%s for %s\n  golden  : %s\n  produced: %s\n  diff    : %s",
                $verdict,
                $name,
                $goldenPath,
                $producedPath,
                $diffImagePath,
            ));
        }

        // Within tolerance — record an explicit assertion so PHPUnit
        // doesn't flag the test as "risky / no assertions".
        self::assertLessThan(self::PHP_PIXEL_DIFF_PCT_THRESHOLD, $stats['pct']);
    }

    #[DataProvider('tapeProvider')]
    #[Group('golden')]
    public function testFfmpegGoldenSsim(string $tapePath): void
    {
        if (!self::ffmpegAvailable()) {
            self::markTestSkipped('ffmpeg not on PATH');
        }

        $probe = self::capabilityProbe();
        if ($probe !== null) {
            self::markTestSkipped($probe);
        }

        $rasterGate = self::rasterGate();
        if ($rasterGate !== null) {
            self::markTestSkipped($rasterGate);
        }

        $name = preg_replace('/\.tape$/', '', basename($tapePath)) ?? basename($tapePath);
        $goldenPath = __DIR__ . '/../golden/' . $name . '.ffmpeg.gif';

        if (!is_file($goldenPath)) {
            self::markTestSkipped("No ffmpeg golden for {$name}");
        }

        $producedPath = $this->tmpDir . '/' . $name . '.ffmpeg.gif';
        TapeToGif::new(['encoder' => 'ffmpeg'])->render($tapePath, $producedPath, [
            'encoder' => 'ffmpeg',
        ]);

        self::assertFileExists($producedPath);

        // Identical hash short-circuit — produced GIF matches the golden bit-for-bit.
        $producedHash = hash_file('sha256', $producedPath);
        $goldenHash = hash_file('sha256', $goldenPath);
        if ($producedHash === $goldenHash) {
            self::assertSame($goldenHash, $producedHash);
            return;
        }

        $ssim = self::measureSsim($producedPath, $goldenPath);
        if ($ssim !== null) {
            self::assertGreaterThanOrEqual(
                self::SSIM_THRESHOLD,
                $ssim,
                sprintf('SSIM %.4f < threshold %.2f for %s', $ssim, self::SSIM_THRESHOLD, $name),
            );
            return;
        }

        // SSIM parse failed. VCR-S44: the old fallback judged the FIRST
        // composed frame only — setup chrome on the sleep-heavy/animation
        // idles waved through later-frame regressions. Walk every frame
        // pair instead; a changed frame count is itself a hard failure.
        $pair = self::compareFramePairs($producedPath, $goldenPath, $this->tmpDir);
        if ($pair['performed']) {
            self::assertNull($pair['reason'], 'every extracted frame pair sat within budget');
            return;
        }

        // Extraction was impossible on this host — last-resort single
        // canvas walk, judged with the same fail-loud classifier.
        $stats = self::pixelDiffStats($producedPath, $goldenPath);
        if ($stats === null) {
            self::markTestSkipped("Cannot compute either SSIM or pixel-diff for {$name}");
        }
        $verdict = self::classifyDiff($stats, self::PIXEL_DIFF_PCT_THRESHOLD, self::ROW_DIFF_SUB_BUDGET, self::WHOLE_FRAME_DIFF);
        if ($verdict !== null) {
            self::fail(sprintf('%s for %s', $verdict, $name));
        }
        self::assertLessThan(self::PIXEL_DIFF_PCT_THRESHOLD, $stats['pct']);
    }

    private static function ffmpegAvailable(): bool
    {
        $result = shell_exec('command -v ffmpeg 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }

    /**
     * Run ffmpeg's SSIM filter and parse the All:N number from stderr.
     * Returns null if ffmpeg fails or output can't be parsed.
     */
    private static function measureSsim(string $produced, string $golden): ?float
    {
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel info -i %s -i %s -filter_complex %s -f null - 2>&1',
            escapeshellarg($produced),
            escapeshellarg($golden),
            escapeshellarg('[0:v][1:v]ssim'),
        );
        $output = shell_exec($cmd);
        if (!is_string($output)) {
            return null;
        }
        // Looking for: "SSIM ... All:0.987654 (19.123)"
        if (preg_match('/All:\s*([0-9]+\.[0-9]+)/', $output, $m) === 1) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * Host-capability probe (VCR-S44, finding TEST-04 layer 1). A host
     * that cannot render like the golden host has no business judging
     * pixels — it skips. Once the probe passes, EVERY divergence
     * magnitude is a fail: the old ≥50% "fundamentally different image"
     * skip band waved through exactly the whole-frame bugs the gate
     * exists to catch (fallback font, inverted palette, theme plumbing).
     *
     * @return null when the host may judge pixels, else the skip reason
     */
    private static function capabilityProbe(): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            return 'ext-gd is unavailable; the pixel walk cannot run on this host';
        }
        if (!is_file(dirname(__DIR__, 2) . '/fonts/JetBrainsMono-Regular.ttf')) {
            return 'the bundled JetBrainsMono face is missing; this host cannot reproduce the golden renderer';
        }
        // GD round-trip self-test: a known red pixel must survive encode+decode.
        $src = imagecreatetruecolor(4, 4);
        if ($src === false) {
            return 'imagecreatetruecolor failed on this host';
        }
        $red = imagecolorallocate($src, 255, 0, 0);
        if ($red !== false) {
            imagesetpixel($src, 1, 1, $red);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'vcr-probe');
        if ($tmp === false) {
            imagedestroy($src);
            return 'tempnam failed; cannot run the GD self-test';
        }
        $tmp .= '.png';
        $encoded = imagepng($src, $tmp);
        imagedestroy($src);
        if (!$encoded) {
            @unlink($tmp);
            return 'GD could not encode a PNG on this host';
        }
        $bytes = @file_get_contents($tmp);
        @unlink($tmp);
        if (!is_string($bytes)) {
            return 'GD could not read back its own PNG output';
        }
        $back = @imagecreatefromstring($bytes);
        if ($back === false) {
            return 'GD could not decode its own PNG output';
        }
        $px = imagecolorat($back, 1, 1);
        imagedestroy($back);
        if (!is_int($px) || (($px >> 16) & 0xff) < 200) {
            return 'GD channel reads are unreliable on this host';
        }
        return null;
    }

    /**
     * Golden-host raster fingerprint (CI-env gate, 2026-11-14): SHA-256
     * over the RGB pixels of a fixed `imagettftext` canvas — the same
     * FreeType primitive {@see \SugarCraft\Vcr\Raster\Glyphs} uses to
     * paint the tapes. The goldens encode the glyph raster of the host
     * they were blessed on; hosts whose FreeType/GD build rasterizes
     * that face differently (the ubuntu-26.04 CI runners diverge on
     * every text-bearing golden at ~99.98% of whole-frame pixels while
     * the non-text datasets pass) must skip, not fail, and above all
     * must not re-bless the goldens to a degraded raster (no-silent-
     * golden law). The fingerprint covers ONLY the host raster stack:
     * an encoder or pipeline regression does not move it, so a capable
     * host still judges every tape golden (VCR-S44 fail-loud intact).
     *
     * Re-pin together with the goldens: run scripts/refresh-goldens.php
     * on the blessed host, then
     *   php -r '$i=imagecreatetruecolor(220,40);$b=imagecolorallocate($i,15,17,26);$f=imagecolorallocate($i,255,255,255);imagefilledrectangle($i,0,0,219,39,$b);imagettftext($i,22.0,0.0,4,30,$f,"candy-vcr/fonts/JetBrainsMono-Regular.ttf","Hq5g/|~@");$s="";for($y=0;$y<40;$y++){for($x=0;$x<220;$x++){$p=imagecolorat($i,$x,$y);$s.=chr(($p>>16)&255).chr(($p>>8)&255).chr($p&255);}}echo hash("sha256",$s),"\n";'
     * and paste the digest into {@see self::GOLDEN_RASTER_FINGERPRINT}.
     */
    private const GOLDEN_RASTER_FINGERPRINT = 'd63183649397c38f9ded7f32531ec3f8e7741106c7cdb203093652db7fb063c7';

    /**
     * @return null when this host rasterizes like the golden host, else
     *               the skip reason
     */
    private static function rasterGate(): ?string
    {
        if (!self::$rasterGateComputed) {
            self::$rasterGateComputed = true;
            self::$rasterGateReason = self::computeRasterGate();
        }
        return self::$rasterGateReason;
    }

    private static function computeRasterGate(): ?string
    {
        if (!function_exists('imagettftext')) {
            return 'PHP GD lacks FreeType (imagettftext); this host cannot reproduce the golden renderer';
        }
        $fontPath = dirname(__DIR__, 2) . '/fonts/JetBrainsMono-Regular.ttf';
        if (!is_file($fontPath)) {
            return 'the bundled JetBrainsMono face is missing; this host cannot reproduce the golden renderer';
        }
        $fingerprint = self::rasterFingerprint($fontPath);
        if ($fingerprint === null) {
            return 'FreeType could not rasterize the bundled JetBrainsMono face on this host';
        }
        if ($fingerprint !== self::GOLDEN_RASTER_FINGERPRINT) {
            return sprintf(
                'this host rasterizes the bundled face as %s, but the goldens were blessed on a %s host: '
                . 'its FreeType/GD build cannot reproduce the golden renderer, so it has no business judging golden pixels',
                $fingerprint,
                self::GOLDEN_RASTER_FINGERPRINT,
            );
        }
        return null;
    }

    /**
     * @return null 64-char hex digest of the probe canvas, or null when
     *               the host could not produce one
     */
    private static function rasterFingerprint(string $fontPath): ?string
    {
        $canvas = imagecreatetruecolor(220, 40);
        if ($canvas === false) {
            return null;
        }
        $background = imagecolorallocate($canvas, 15, 17, 26);
        $foreground = imagecolorallocate($canvas, 255, 255, 255);
        if ($background === false || $foreground === false || !imagefilledrectangle($canvas, 0, 0, 219, 39, $background)) {
            imagedestroy($canvas);
            return null;
        }
        // Ascenders, descenders, curves, hinting-sensitive strokes. The
        // point size is a LITERAL 22.0 — deliberately NOT Compiler's
        // DEFAULT_FONT_SIZE read live: the fingerprint must depend only
        // on the host raster stack, so an intentional font-size change
        // still fails the goldens loudly instead of silently shifting
        // the probe and hiding the regression (the literal then re-pins
        // with the goldens on the blessed host).
        if (@imagettftext($canvas, 22.0, 0.0, 4, 30, $foreground, $fontPath, 'Hq5g/|~@') === false) {
            imagedestroy($canvas);
            return null;
        }
        $raw = '';
        for ($y = 0; $y < 40; $y++) {
            for ($x = 0; $x < 220; $x++) {
                $pixel = imagecolorat($canvas, $x, $y);
                if (!is_int($pixel)) {
                    imagedestroy($canvas);
                    return null;
                }
                $raw .= chr(($pixel >> 16) & 0xff) . chr(($pixel >> 8) & 0xff) . chr($pixel & 0xff);
            }
        }
        imagedestroy($canvas);
        return hash('sha256', $raw);
    }

    /**
     * Decode two images (any GD-readable format — GIF or PNG), walk
     * pixels, and report the fraction (0..1) differing by more than 8 in
     * any channel, plus the worst single row's own-diff fraction.
     * imagecreatefromstring keeps this helper usable for synthetic PNG
     * fixtures in the harness test as well as real GIFs.
     *
     * @return array{pct:float,worstRow:float,worstRowY:int}|null
     */
    private static function pixelDiffStats(string $a, string $b): ?array
    {
        $bytesA = @file_get_contents($a);
        $bytesB = @file_get_contents($b);
        if (!is_string($bytesA) || !is_string($bytesB)) {
            return null;
        }
        $imgA = @imagecreatefromstring($bytesA);
        $imgB = @imagecreatefromstring($bytesB);
        if ($imgA === false || $imgB === false) {
            if ($imgA !== false) {
                imagedestroy($imgA);
            }
            if ($imgB !== false) {
                imagedestroy($imgB);
            }
            return null;
        }
        try {
            $w = min(imagesx($imgA), imagesx($imgB));
            $h = min(imagesy($imgA), imagesy($imgB));
            if ($w <= 0 || $h <= 0) {
                return null;
            }
            $diff = 0;
            $rowDiffs = array_fill(0, $h, 0);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $ca = imagecolorat($imgA, $x, $y);
                    $cb = imagecolorat($imgB, $x, $y);
                    if (!is_int($ca) || !is_int($cb)) {
                        continue;
                    }
                    $ra = ($ca >> 16) & 0xff;
                    $ga = ($ca >> 8) & 0xff;
                    $ba = $ca & 0xff;
                    $rb = ($cb >> 16) & 0xff;
                    $gb = ($cb >> 8) & 0xff;
                    $bb = $cb & 0xff;
                    if (abs($ra - $rb) > 8 || abs($ga - $gb) > 8 || abs($ba - $bb) > 8) {
                        $diff++;
                        $rowDiffs[$y]++;
                    }
                }
            }
            $total = $w * $h;
            if ($total <= 0) {
                return null;
            }
            $worstRowY = 0;
            for ($y = 1; $y < $h; $y++) {
                if ($rowDiffs[$y] > $rowDiffs[$worstRowY]) {
                    $worstRowY = $y;
                }
            }
            return [
                'pct' => (float) ($diff / $total),
                'worstRow' => (float) ($rowDiffs[$worstRowY] / $w),
                'worstRowY' => $worstRowY,
            ];
        } finally {
            imagedestroy($imgA);
            imagedestroy($imgB);
        }
    }

    /**
     * Pure classifier over one canvas walk — the whole failure policy of
     * the tolerance walk lives here so the harness test can judge it
     * without rendering a tape. Whole-frame band first (its message must
     * say so — operators previously read it as a skip invitation).
     *
     * @param array{pct:float,worstRow:float,worstRowY:int} $stats
     * @return null when within budget, else the failure reason
     */
    private static function classifyDiff(array $stats, float $totalBudget, float $rowBudget, float $wholeFrameFloor): ?string
    {
        if ($stats['pct'] >= $wholeFrameFloor) {
            return sprintf(
                'whole-frame divergence: %.4f%% of pixels differ (floor %.2f%%) — a host that passed the capability probe must not paint like this',
                $stats['pct'] * 100.0,
                $wholeFrameFloor * 100.0,
            );
        }
        if ($stats['worstRow'] >= $rowBudget) {
            return sprintf(
                'localized row damage: row %d differs %.4f%% of its own pixels (sub-budget %.2f%%) while the canvas total sat at %.4f%%',
                $stats['worstRowY'],
                $stats['worstRow'] * 100.0,
                $rowBudget * 100.0,
                $stats['pct'] * 100.0,
            );
        }
        if ($stats['pct'] >= $totalBudget) {
            return sprintf(
                'pixel-diff %.4f%% >= threshold %.2f%%',
                $stats['pct'] * 100.0,
                $totalBudget * 100.0,
            );
        }
        return null;
    }

    /**
     * ffmpeg passthrough-extract every frame of a GIF as PNGs.
     *
     * @return list<string> sorted frame paths, or null when extraction is impossible
     */
    private static function extractFrames(string $gif, string $outDir): ?array
    {
        if (!is_dir($outDir) && !mkdir($outDir, 0700, true) && !is_dir($outDir)) {
            return null;
        }
        $pattern = $outDir . '/f_%05d.png';
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -y -i %s -fps_mode passthrough %s 2>/dev/null',
            escapeshellarg($gif),
            escapeshellarg($pattern),
        );
        exec($cmd, $ignored, $status);
        if ($status !== 0) {
            return null;
        }
        $frames = glob($outDir . '/f_*.png');
        if ($frames === false || $frames === []) {
            return null;
        }
        sort($frames);
        return $frames;
    }

    /**
     * Full-frame comparison for the SSIM-unparseable fallback: extract
     * both GIFs frame-by-frame, demand equal frame counts (animation
     * length IS structure — a changed count fails the gate), and walk
     * each pair. `performed:false` means extraction was impossible on
     * this host and the caller must fall back to the composed canvas.
     *
     * @return array{performed:bool,reason:?string}
     */
    private static function compareFramePairs(string $produced, string $golden, string $scratch): array
    {
        $dirA = $scratch . '/pairs-produced';
        $dirB = $scratch . '/pairs-golden';
        $framesA = self::extractFrames($produced, $dirA);
        $framesB = self::extractFrames($golden, $dirB);
        if ($framesA === null || $framesB === null) {
            return ['performed' => false, 'reason' => null];
        }
        if (count($framesA) !== count($framesB)) {
            return [
                'performed' => true,
                'reason' => sprintf(
                    'animation length changed: produced %d frames, golden %d',
                    count($framesA),
                    count($framesB),
                ),
            ];
        }
        foreach ($framesA as $i => $frameA) {
            $stats = self::pixelDiffStats($frameA, $framesB[$i]);
            if ($stats === null) {
                return ['performed' => false, 'reason' => null];
            }
            $verdict = self::classifyDiff($stats, self::PIXEL_DIFF_PCT_THRESHOLD, self::ROW_DIFF_SUB_BUDGET, self::WHOLE_FRAME_DIFF);
            if ($verdict !== null) {
                return [
                    'performed' => true,
                    'reason' => sprintf('%s (frame %d of %d)', $verdict, $i + 1, count($framesA)),
                ];
            }
        }
        return ['performed' => true, 'reason' => null];
    }

    /**
     * Render a PNG visualising per-pixel differences (white = same,
     * red intensity = magnitude of channel delta). Returns the path so
     * CI logs can point operators at the artifact for inspection.
     */
    private function writeDiffImage(string $name, string $golden, string $produced): string
    {
        $notePath = '/tmp/' . $name . '-diff.png';
        if (!function_exists('imagecreatefromgif')) {
            return '(gd unavailable; cannot render diff)';
        }
        $imgA = @imagecreatefromgif($golden);
        $imgB = @imagecreatefromgif($produced);
        if ($imgA === false || $imgB === false) {
            if ($imgA !== false) {
                imagedestroy($imgA);
            }
            if ($imgB !== false) {
                imagedestroy($imgB);
            }
            return '(could not load source GIFs)';
        }
        try {
            $w = min(imagesx($imgA), imagesx($imgB));
            $h = min(imagesy($imgA), imagesy($imgB));
            $out = imagecreatetruecolor($w, $h);
            if ($out === false) {
                return '(imagecreatetruecolor failed)';
            }
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $ca = imagecolorat($imgA, $x, $y);
                    $cb = imagecolorat($imgB, $x, $y);
                    if (!is_int($ca) || !is_int($cb)) {
                        continue;
                    }
                    $da = abs((($ca >> 16) & 0xff) - (($cb >> 16) & 0xff));
                    $dg = abs((($ca >> 8) & 0xff) - (($cb >> 8) & 0xff));
                    $db = abs(($ca & 0xff) - ($cb & 0xff));
                    $mag = max($da, $dg, $db);
                    if ($mag <= 8) {
                        $color = imagecolorallocate($out, 255, 255, 255);
                    } else {
                        $intensity = min(255, $mag * 4);
                        $color = imagecolorallocate($out, 255, 255 - $intensity, 255 - $intensity);
                    }
                    if ($color !== false) {
                        imagesetpixel($out, $x, $y, $color);
                    }
                }
            }
            imagepng($out, $notePath);
            imagedestroy($out);
        } finally {
            imagedestroy($imgA);
            imagedestroy($imgB);
        }
        return $notePath;
    }

    /**
     * On hash-mismatch, render a tiny "diff note" file beside the produced GIF
     * recording sizes + first-mismatching byte. Cheap and CI-friendly.
     */
    private function writeDiffNote(string $name, string $golden, string $produced): string
    {
        $goldenBytes = @file_get_contents($golden);
        $producedBytes = @file_get_contents($produced);
        if (!is_string($goldenBytes) || !is_string($producedBytes)) {
            return $this->tmpDir . '/diff-note-unavailable.txt';
        }
        $firstDiff = -1;
        $min = min(strlen($goldenBytes), strlen($producedBytes));
        for ($i = 0; $i < $min; $i++) {
            if ($goldenBytes[$i] !== $producedBytes[$i]) {
                $firstDiff = $i;
                break;
            }
        }
        $note = sprintf(
            "Golden mismatch for %s\nGolden : %d bytes\nProduced: %d bytes\nFirst differing offset: %d\n",
            $name,
            strlen($goldenBytes),
            strlen($producedBytes),
            $firstDiff,
        );
        $notePath = $this->tmpDir . '/' . $name . '.diff.txt';
        @file_put_contents($notePath, $note);
        return $notePath;
    }
}
