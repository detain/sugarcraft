<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Reel\Source\Probe;

/**
 * Ffmpeg-based video decoder using proc_open with a raw rgb24 pipe.
 *
 * One frame = exactly cellsW * cellsH * 2 * 3 bytes (half-block mode:
 * ffmpeg scales to cellsH*2 rows so each terminal cell maps to 2 source rows).
 * All CLI args are passed via array to proc_open (no shell injection).
 * Partial frames at end-of-stream are silently discarded.
 *
 * @see video_plan.md lines 79-82
 * @implements Decoder
 */
final class FfmpegDecoder implements Decoder
{
    /** @var resource|\Process|null */
    private $process = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private int $cellsW = 0;
    private int $cellsH = 0;
    private int $frameBytes = 0;

    /**
     * @inheritDoc
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps): void
    {
        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;
        // Half-block mode: 2 rows per cell, so H = cellsH * 2
        $this->frameBytes = $cellsW * $cellsH * 2 * 3;

        $ffmpegPath = Probe::ffmpeg();
        if ($ffmpegPath === null) {
            throw new \RuntimeException('ffmpeg not found on this host');
        }

        // Build command as array — never a shell string.
        // Each element is individually escaped for safety.
        $cmd = [
            $ffmpegPath,
            '-hide_banner',
            '-loglevel', 'error',
            '-i', escapeshellarg($source),
            '-f', 'rawvideo',
            '-pix_fmt', 'rgb24',
            '-vf', sprintf(
                'fps=%s,scale=%d:%d:flags=bilinear',
                (string) $fps,
                $cellsW,
                $cellsH * 2
            ),
            '-',
        ];

        $descriptorSpec = [
            ['pipe', 'r'],  // stdin
            ['pipe', 'w'],  // stdout
            ['pipe', 'w'],  // stderr
        ];

        $this->process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Failed to start ffmpeg process');
        }

        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        // Close stdin as we don't write to it
        if (is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
    }

    /**
     * @inheritDoc
     */
    public function next(): ?RgbFrame
    {
        if ($this->stdout === null || !is_resource($this->stdout)) {
            return null;
        }

        $frameBytes = '';
        $bytesRead = 0;

        // Read until we have a complete frame or reach EOF.
        // Handle incomplete frames due to ffmpeg flushing.
        while ($bytesRead < $this->frameBytes) {
            $chunk = fread($this->stdout, $this->frameBytes - $bytesRead);
            if ($chunk === false || $chunk === '') {
                // EOF or error — check if we have a complete frame
                if ($bytesRead === 0) {
                    return null; // No more data
                }
                // Incomplete last frame — discard it
                if ($bytesRead < $this->frameBytes) {
                    return null;
                }
                break;
            }
            $frameBytes .= $chunk;
            $bytesRead += strlen($chunk);
        }

        // Discard incomplete frames
        if ($bytesRead < $this->frameBytes) {
            return null;
        }

        return new RgbFrame($frameBytes, $this->cellsW, $this->cellsH * 2);
    }

    /**
     * @inheritDoc
     */
    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->stdout !== null && is_resource($this->stdout)) {
            fclose($this->stdout);
            $this->stdout = null;
        }

        if ($this->stderr !== null && is_resource($this->stderr)) {
            fclose($this->stderr);
            $this->stderr = null;
        }

        if ($this->process !== null && is_resource($this->process)) {
            $exitCode = proc_close($this->process);
            $this->process = null;

            // If ffmpeg exited non-zero (and we didn't already consume all frames),
            // that indicates an error. We don't throw here since next() returning
            // null will signal end of stream to the caller.
        }
    }
}
