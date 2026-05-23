<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

use Symfony\Component\Process\Process;

/**
 * Default GIF encoder using ffmpeg.
 *
 * Writes frames as PNGs to a temp directory and invokes ffmpeg with
 * two-pass palette generation for quality GIF output at acceptable file size.
 *
 * When per-frame `$durations` are supplied (milliseconds), a concat
 * demuxer list is built so each frame holds for its own duration —
 * this is what makes `Sleep 2s` in a tape produce a real 2-second
 * pause in the GIF instead of being flattened by frame dedup.
 *
 * Mirrors charmbracelet/x/vhs FfmpegGifEncoder.
 */
final class FfmpegGifEncoder implements GifEncoder
{
    private const DEFAULT_FFMPEG_BIN = 'ffmpeg';
    private const DEFAULT_PALETTEGEN_FLAGS = 'stats_mode=diff';
    private const DEFAULT_PALETTEUSE_FLAGS = 'dither=bayer:bayer_scale=5';

    private string $ffmpegBin;
    private bool $available;

    public function __construct(string $ffmpegBin = self::DEFAULT_FFMPEG_BIN)
    {
        $this->ffmpegBin = $ffmpegBin;
        $this->available = $this->detectAvailability();
    }

    public function encode(
        array $pngPaths,
        string $outputPath,
        int $fps = 30,
        ?array $durations = null,
    ): bool {
        if (!$this->available) {
            throw new \RuntimeException(
                'FfmpegGifEncoder requires ffmpeg but it is not available. ' .
                'Use PhpGifEncoder as a fallback.'
            );
        }

        if ($pngPaths === []) {
            throw new \RuntimeException('No frames provided to encode');
        }

        $useVfr = $durations !== null && count($durations) === count($pngPaths);

        $tempDir = $this->createTempDir();
        try {
            $filter = $this->buildFilterComplex();

            if ($useVfr) {
                $concatList = $this->buildConcatList($pngPaths, $durations, $tempDir);
                $args = [
                    $this->ffmpegBin,
                    '-y',
                    '-f', 'concat',
                    '-safe', '0',
                    '-i', $concatList,
                    '-vf', $filter,
                    '-loop', '0',
                    $outputPath,
                ];
            } else {
                foreach ($pngPaths as $index => $srcPath) {
                    $dst = $tempDir . '/frame' . sprintf('%05d', $index) . '.png';
                    if (!copy($srcPath, $dst)) {
                        throw new \RuntimeException("Failed to copy frame {$index}: {$srcPath}");
                    }
                }
                $args = [
                    $this->ffmpegBin,
                    '-y',
                    '-framerate', (string) $fps,
                    '-i', $tempDir . '/frame%05d.png',
                    '-vf', $filter,
                    '-loop', '0',
                    $outputPath,
                ];
            }

            $process = new Process($args);
            $process->setTimeout(300);
            $exitCode = $process->run();

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    'ffmpeg failed with exit code ' . $exitCode . ': ' . $process->getErrorOutput()
                );
            }

            return is_file($outputPath) && filesize($outputPath) > 0;
        } finally {
            $this->cleanup($tempDir);
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function name(): string
    {
        return 'ffmpeg';
    }

    private function buildFilterComplex(): string
    {
        $palettegen = 'palettegen=' . self::DEFAULT_PALETTEGEN_FLAGS;
        $paletteuse = 'paletteuse=' . self::DEFAULT_PALETTEUSE_FLAGS;
        return "split[s0][s1];[s0]{$palettegen}[p];[s1][p]{$paletteuse}";
    }

    /**
     * Build a concat demuxer list with one `file` + `duration` pair per
     * frame. Each duration is in seconds. The final frame is repeated
     * (concat demuxer quirk — the last entry's duration is otherwise
     * ignored).
     *
     * @param list<string> $pngPaths
     * @param list<int>    $durationsMs
     */
    private function buildConcatList(array $pngPaths, array $durationsMs, string $tempDir): string
    {
        $listPath = $tempDir . '/concat.txt';
        $lines = [];
        $count = count($pngPaths);
        for ($i = 0; $i < $count; $i++) {
            $absPath = realpath($pngPaths[$i]);
            if ($absPath === false) {
                throw new \RuntimeException("Frame file vanished before encode: {$pngPaths[$i]}");
            }
            $escapedPath = "'" . str_replace("'", "'\\''", $absPath) . "'";
            $durationSec = max($durationsMs[$i] / 1000.0, 0.02);
            $lines[] = 'file ' . $escapedPath;
            $lines[] = 'duration ' . sprintf('%.4f', $durationSec);
        }
        $lastPath = realpath($pngPaths[$count - 1]);
        if ($lastPath !== false) {
            $lines[] = 'file ' . "'" . str_replace("'", "'\\''", $lastPath) . "'";
        }

        if (file_put_contents($listPath, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException("Failed to write concat list: {$listPath}");
        }
        return $listPath;
    }

    private function detectAvailability(): bool
    {
        $process = new Process([$this->ffmpegBin, '-version']);
        $process->setTimeout(5);

        try {
            $process->run();
            return $process->isSuccessful();
        } catch (\Exception) {
            return false;
        }
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/candy-vcr-gif-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create temp dir: {$dir}");
        }
        return $dir;
    }

    private function cleanup(string $tempDir): void
    {
        $files = glob($tempDir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($tempDir);
    }
}
