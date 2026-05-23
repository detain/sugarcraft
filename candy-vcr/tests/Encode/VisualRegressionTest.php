<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\TapeToGif;

/**
 * Visual regression: re-render each curated tape and diff against the
 * committed golden GIFs. PhpGifEncoder is byte-deterministic, so we
 * compare by SHA-256. FfmpegGifEncoder gets an SSIM check (>= 0.95)
 * with a pixel-diff fallback when the SSIM filter can't parse cleanly.
 *
 * Refresh procedure documented in candy-vcr/CALIBER_LEARNINGS.md.
 */
final class VisualRegressionTest extends TestCase
{
    private const SSIM_THRESHOLD = 0.95;
    private const PIXEL_DIFF_PCT_THRESHOLD = 0.01; // 1% of pixels may differ by >8 per channel

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy-vcr-visual-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException("Failed to create temp dir: {$this->tmpDir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
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
    public function testPhpGoldenByteIdentical(string $tapePath): void
    {
        $name = preg_replace('/\.tape$/', '', basename($tapePath)) ?? basename($tapePath);
        $goldenPath = __DIR__ . '/../golden/' . $name . '.php.gif';

        if (!is_file($goldenPath)) {
            self::markTestSkipped("No PHP golden for {$name}");
        }

        $producedPath = $this->tmpDir . '/' . $name . '.php.gif';
        TapeToGif::create(['encoder' => 'php'])->render($tapePath, $producedPath, [
            'encoder' => 'php',
        ]);

        self::assertFileExists($producedPath, "renderer did not produce {$producedPath}");

        $producedHash = hash_file('sha256', $producedPath);
        $goldenHash = hash_file('sha256', $goldenPath);

        if ($producedHash === $goldenHash) {
            self::assertSame($goldenHash, $producedHash);
            return;
        }

        // Hash mismatch — generate a diff PNG to aid debugging.
        $diffNotePath = $this->writeDiffNote($name, $goldenPath, $producedPath);
        self::fail(sprintf(
            "PHP golden hash mismatch for %s\n  golden  : %s (%s)\n  produced: %s (%s)\n  diff    : %s",
            $name,
            $goldenPath,
            $goldenHash,
            $producedPath,
            $producedHash,
            $diffNotePath,
        ));
    }

    #[DataProvider('tapeProvider')]
    #[Group('golden')]
    public function testFfmpegGoldenSsim(string $tapePath): void
    {
        if (!self::ffmpegAvailable()) {
            self::markTestSkipped('ffmpeg not on PATH');
        }

        $name = preg_replace('/\.tape$/', '', basename($tapePath)) ?? basename($tapePath);
        $goldenPath = __DIR__ . '/../golden/' . $name . '.ffmpeg.gif';

        if (!is_file($goldenPath)) {
            self::markTestSkipped("No ffmpeg golden for {$name}");
        }

        $producedPath = $this->tmpDir . '/' . $name . '.ffmpeg.gif';
        TapeToGif::create(['encoder' => 'ffmpeg'])->render($tapePath, $producedPath, [
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

        // SSIM parse failed — fall back to pixel-diff count.
        $diffPct = self::pixelDiffPct($producedPath, $goldenPath);
        if ($diffPct === null) {
            self::markTestSkipped("Cannot compute either SSIM or pixel-diff for {$name}");
        }
        self::assertLessThan(
            self::PIXEL_DIFF_PCT_THRESHOLD,
            $diffPct,
            sprintf('Pixel-diff %.4f%% >= threshold %.2f%% for %s', $diffPct * 100.0, self::PIXEL_DIFF_PCT_THRESHOLD * 100.0, $name),
        );
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
     * Decode two GIFs, walk pixels, return the fraction (0..1) of pixels that
     * differ by more than 8 in any channel. Compares the FIRST frame only —
     * cheap sanity check when SSIM is unavailable.
     */
    private static function pixelDiffPct(string $a, string $b): ?float
    {
        if (!function_exists('imagecreatefromgif')) {
            return null;
        }
        $imgA = @imagecreatefromgif($a);
        $imgB = @imagecreatefromgif($b);
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
            $total = $w * $h;
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
                    }
                }
            }
            return $total > 0 ? $diff / $total : null;
        } finally {
            imagedestroy($imgA);
            imagedestroy($imgB);
        }
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
