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

        $frameCount = count($pngPaths);
        $framerate = (float) $fps;

        $tempDir = sys_get_temp_dir() . '/candy-vcr-gif-' . getmypid();
        if (!mkdir($tempDir) && !is_dir($tempDir)) {
            throw new \RuntimeException("Failed to create temp dir: {$tempDir}");
        }

        try {
            foreach ($pngPaths as $index => $srcPath) {
                $dst = $tempDir . '/' . sprintf('frame%05d.png', $index);
                if (!copy($srcPath, $dst)) {
                    throw new \RuntimeException("Failed to copy frame {$index}: {$srcPath}");
                }
            }

            $inputPattern = $tempDir . '/frame%05d.png';
            $filterComplex = $this->buildFilterComplex($fps, $durations);

            $args = [
                '-framerate', (string) $framerate,
                '-i', $inputPattern,
                '-vf', $filterComplex,
                '-loop', '0',
            ];

            $args[] = $outputPath;

            $process = new Process([$this->ffmpegBin, ...$args]);
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

    /**
     * @param list<int>|null $durations
     */
    private function buildFilterComplex(int $fps, ?array $durations): string
    {
        $palettegen = 'palettegen=' . self::DEFAULT_PALETTEGEN_FLAGS;
        $paletteuse = 'paletteuse=' . self::DEFAULT_PALETTEUSE_FLAGS;

        return "split[s0][s1];[s0]{$palettegen}[p];[s1][p]{$paletteuse}";
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

    private function cleanup(string $tempDir): void
    {
        $files = glob($tempDir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($tempDir);
    }
}
