<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * GIF encoder using ffmpeg with two-pass palette generation.
 *
 * Uses proc_open with bash process substitution for VFR concat file input.
 * Writes temporary PNG frames to disk and invokes ffmpeg for encoding.
 * Cleans up temp files after encoding (even on failure).
 *
 * Mirrors charmbracelet/x/vhs FfmpegGifEncoder.
 */
final class FfmpegGifEncoder implements GifEncoder
{
    public function __construct(
        private string $ffmpegPath = 'ffmpeg',
        private string $tempDir = '/tmp',
    ) {
    }

    public function encode(
        \Iterator $frames,
        int $cols,
        int $rows,
        array $frameHolds,
        string $outputPath,
    ): void {
        $tempFiles = [];
        $frameIndex = 0;
        $pngPaths = [];

        try {
            $frames->rewind();
            while ($frames->valid()) {
                $frame = $frames->current();
                $hold = $frameHolds[$frameIndex] ?? (1.0 / 30.0);

                $pngPath = $this->tempDir . '/frame' . str_pad((string) $frameIndex, 5, '0', STR_PAD_LEFT) . '.png';
                $pngPaths[] = ['path' => $pngPath, 'hold' => $hold];

                $this->writePng($frame, $pngPath);
                $tempFiles[] = $pngPath;

                $frameIndex++;
                $frames->next();
            }

            if (count($pngPaths) === 0) {
                \assert($cols >= 1 && $rows >= 1);
                $emptyImage = imagecreatetruecolor((int) $cols, (int) $rows);
                $pngPath = $this->tempDir . '/frame' . str_pad('0', 5, '0', STR_PAD_LEFT) . '.png';
                $this->writePng($emptyImage, $pngPath);
                $tempFiles[] = $pngPath;
                $pngPaths[] = ['path' => $pngPath, 'hold' => 0.033];
                imagedestroy($emptyImage);
            }

            $this->runFfmpeg($pngPaths, $cols, $rows, $outputPath);
        } finally {
            foreach ($tempFiles as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Write a GdImage as PNG to a file path.
     */
    private function writePng(\GdImage $image, string $path): void
    {
        $result = imagepng($image, $path, 6);
        if ($result === false) {
            throw new \RuntimeException("Failed to write PNG to {$path}");
        }
    }

    /**
     * Build and run the ffmpeg command.
     *
     * @param list<array{path:string, hold:float}> $frames
     */
    private function runFfmpeg(array $frames, int $cols, int $rows, string $outputPath): void
    {
        $allHoldsEqual = $this->allHoldsEqual($frames);
        $framerate = $this->detectFramerate($frames);

        if ($allHoldsEqual && count($frames) > 0) {
            $this->runFfmpegCfr($frames, $framerate, $cols, $rows, $outputPath);
        } else {
            $this->runFfmpegVfr($frames, $cols, $rows, $outputPath);
        }
    }

    /**
     * @param list<array{path:string, hold:float}> $frames
     */
    private function allHoldsEqual(array $frames): bool
    {
        if (count($frames) < 2) {
            return true;
        }
        $first = $frames[0]['hold'];
        foreach (array_slice($frames, 1) as $f) {
            if (abs($f['hold'] - $first) > 0.0001) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<array{path:string, hold:float}> $frames
     */
    private function detectFramerate(array $frames): float
    {
        if (count($frames) === 0) {
            return 30.0;
        }
        $first = $frames[0]['hold'];
        if ($first <= 0) {
            return 30.0;
        }
        return 1.0 / $first;
    }

    /**
     * Run ffmpeg with CFR (constant frame rate) using -framerate input.
     *
     * @param list<array{path:string, hold:float}> $frames
     */
    private function runFfmpegCfr(
        array $frames,
        float $framerate,
        int $cols,
        int $rows,
        string $outputPath,
    ): void {
        $inputPattern = $this->tempDir . '/frame%05d.png';

        $cmd = [
            $this->ffmpegPath,
            '-y',
            '-framerate',
            (string) $framerate,
            '-i',
            $inputPattern,
            '-vf',
            'split[s0][s1];[s0]palettegen=stats_mode=diff[p];[s1][p]paletteuse=dither=bayer:bayer_scale=5',
            '-loop',
            '0',
            $outputPath,
        ];

        $this->execFfmpeg($cmd);
    }

    /**
     * Run ffmpeg with VFR using concat demuxer via process substitution.
     *
     * @param list<array{path:string, hold:float}> $frames
     */
    private function runFfmpegVfr(
        array $frames,
        int $cols,
        int $rows,
        string $outputPath,
    ): void {
        $framesStr = '';
        foreach ($frames as $f) {
            $escapedPath = addslashes($f['path']);
            $duration = number_format($f['hold'], 6, '.', '');
            $framesStr .= "file '{$escapedPath}'\nduration {$duration}\n";
        }
        $lastPath = addslashes($frames[count($frames) - 1]['path']);
        $framesStr .= "file '{$lastPath}'\n";

        $cmd = [
            $this->ffmpegPath,
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            '/dev/stdin',
            '-vf',
            'split[s0][s1];[s0]palettegen=stats_mode=diff[p];[s1][p]paletteuse=dither=bayer:bayer_scale=5',
            '-loop',
            '0',
            $outputPath,
        ];

        $this->execFfmpegWithStdin($cmd, $framesStr);
    }

    /**
     * @param list<string> $cmd
     */
    private function execFfmpeg(array $cmd): void
    {
        $process = new Process($cmd);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'ffmpeg failed: ' . $process->getErrorOutput(),
            );
        }
    }

    /**
     * Execute ffmpeg with data piped to stdin (for VFR concat).
     *
     * @param list<string> $cmd
     */
    private function execFfmpegWithStdin(array $cmd, string $stdinData): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = null;
        $env = null;

        $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        if ($process === false) {
            throw new \RuntimeException('proc_open failed for ffmpeg');
        }

        fwrite($pipes[0], $stdinData);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'ffmpeg failed (exit ' . $exitCode . '): ' . $stderr,
            );
        }
    }
}
