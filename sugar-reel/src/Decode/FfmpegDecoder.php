<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Decode;

use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Source\Probe;

/**
 * Ffmpeg-based video decoder using proc_open with a raw rgb24 pipe.
 *
 * For HalfBlock mode: ffmpeg scales to cellsH*2 rows so each terminal cell
 * maps to 2 source rows. One frame = cellsW * cellsH * 2 * 3 bytes.
 * For other modes: ffmpeg scales to cellsH rows. One frame = cellsW * cellsH * 3 bytes.
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
    private int $frameH = 0;

    /**
     * @inheritDoc
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null): void
    {
        $this->cellsW = $cellsW;
        $this->cellsH = $cellsH;

        // HalfBlock mode: 2 rows per cell, so ffmpeg scales to cellsH*2.
        // Other modes: ffmpeg scales to cellsH.
        $isHalfBlock = $mode === null || $mode === Mode::HalfBlock;
        $this->frameH = $isHalfBlock ? $cellsH * 2 : $cellsH;
        $this->frameBytes = $cellsW * $this->frameH * 3;

        $ffmpegPath = Probe::ffmpeg();
        if ($ffmpegPath === null) {
            throw new \RuntimeException('ffmpeg not found on this host');
        }

        // Build command as array — never a shell string.
        // No escaping needed; proc_open passes args directly with no shell.
        $cmd = [
            $ffmpegPath,
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $source,
            '-f', 'rawvideo',
            '-pix_fmt', 'rgb24',
            '-vf', sprintf(
                'fps=%s,scale=%d:%d:flags=bilinear',
                (string) $fps,
                $cellsW,
                $this->frameH
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

        return new RgbFrame($frameBytes, $this->cellsW, $this->frameH);
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
