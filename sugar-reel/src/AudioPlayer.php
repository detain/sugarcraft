<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

use SugarCraft\Reel\Source\Probe;

/**
 * Audio playback subprocess wrapper for video files.
 *
 * Spawns ffplay (`-nodisp -autoexit`) or mpv (`--no-video`) as a video-less
 * audio companion. Per the v1 design (video_plan.md lines 36-38) the audio
 * is not a position-reporting master clock — ffplay exposes no playhead — so
 * instead the Player starts audio and resets its own wall clock at the same
 * instant, then paces video off that clock with frame-skip resync. pause()/
 * resume() keep audio aligned with playback so A/V stay roughly in sync.
 *
 * Graceful degradation:
 * - If the audio subprocess exits immediately (no audio track), isPlaying()
 *   returns false without error.
 * - If neither ffplay nor mpv is available, start() is a silent no-op.
 *
 * No single upstream — the audio-companion + wall-clock pacing approach is
 * drawn from maxcurzi/tplay and joelibaceta/video-to-ascii.
 */
class AudioPlayer
{
    /**
     * @param string  $videoPath Absolute path to the video file
     * @param int|null $startMs  Optional start offset in milliseconds (for seek)
     */
    public function __construct(
        private readonly string $videoPath,
        private readonly ?int $startMs = null,
    ) {
    }

    /**
     * Spawn the audio subprocess (ffplay or mpv) if a suitable binary
     * is available on this host.
     *
     * Uses proc_open() with an array command form for safe argument
     * handling — no shell interpolation.
     *
     * Silent no-op when:
     * - Neither ffplay nor mpv is installed (Probe::ffplay() returns null
     *   and `command -v mpv` also fails).
     * - The audio process exits immediately (isPlaying() will return false).
     */
    public function start(): void
    {
        // Mark as started even when no binary is available, so callers can
        // distinguish "playback has begun" from "never played" and avoid
        // re-spawning on resume.
        $this->started = true;

        $cmd = $this->buildCommand();
        if ($cmd === null) {
            // Neither audio binary is available — silent degradation.
            return;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin — unused but required
            1 => ['pipe', 'w'],  // stdout — discarded
            2 => ['pipe', 'w'],  // stderr — discarded
        ];

        $pipes = [];
        $this->processHandle = @proc_open($cmd, $descriptorSpec, $pipes);

        // Guard against proc_open failure (returns false/0 when binary missing).
        if ($this->processHandle === false || $this->processHandle === 0) {
            // Clean up any partially-created pipe FDs on failure.
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
            $this->processHandle = null;

            return;
        }

        // Close unused stdin/stdout/stderr to avoid deadlock.
        \fclose($pipes[0]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
    }

    /**
     * Stop the audio subprocess by sending SIGTERM.
     *
     * Safe to call even if the process has already exited.
     */
    public function stop(): void
    {
        if (!is_resource($this->processHandle)) {
            return;
        }

        proc_terminate($this->processHandle, SIGTERM);
        proc_close($this->processHandle);
        $this->processHandle = null;
    }

    /**
     * True once start() has been called (regardless of whether a binary was
     * actually available). Lets the Player start audio on first play and
     * resume() it on subsequent unpauses rather than re-spawning.
     */
    public function hasStarted(): bool
    {
        return $this->started;
    }

    /**
     * Suspend audio output (SIGSTOP) so it stays aligned with a paused video.
     *
     * Safe no-op when no process is running. POSIX-only; on platforms without
     * job-control signals this is a best-effort no-op.
     */
    public function pause(): void
    {
        if (!is_resource($this->processHandle) || !\defined('SIGSTOP')) {
            return;
        }
        proc_terminate($this->processHandle, SIGSTOP);
    }

    /**
     * Resume previously-paused audio output (SIGCONT).
     *
     * Safe no-op when no process is running.
     */
    public function resume(): void
    {
        if (!is_resource($this->processHandle) || !\defined('SIGCONT')) {
            return;
        }
        proc_terminate($this->processHandle, SIGCONT);
    }

    /**
     * True when the audio subprocess is still running.
     *
     * Returns false when:
     * - The process has not been started (start() was never called).
     * - The process exited immediately (no audio track).
     * - The process was stopped via stop().
     */
    public function isPlaying(): bool
    {
        if (!is_resource($this->processHandle)) {
            return false;
        }

        $status = proc_get_status($this->processHandle);
        // proc_get_status returns false after proc_close, so guard.
        if ($status === false) {
            return false;
        }

        return $status['running'];
    }

    /**
     * Build the audio subprocess command array.
     *
     * Prefers ffplay (via Probe::ffplay()) over mpv.
     * Returns null when neither binary is available.
     *
     * @return list<string>|null Command array for proc_open(), or null
     */
    protected function buildCommand(): ?array
    {
        // Prefer ffplay.
        $ffplayPath = Probe::ffplay();
        if ($ffplayPath !== null) {
            $cmd = [$ffplayPath, '-nodisp', '-autoexit'];
            if ($this->startMs !== null) {
                $cmd[] = '-ss';
                $cmd[] = (string)($this->startMs / 1000.0);
            }
            $cmd[] = $this->videoPath;
            return $cmd;
        }

        // Fall back to mpv. --no-video keeps it audio-only (no window);
        // --really-quiet suppresses its status output on our discarded pipes.
        $mpvPath = $this->findMpv();
        if ($mpvPath !== null) {
            $cmd = [$mpvPath, '--no-video', '--really-quiet'];
            if ($this->startMs !== null) {
                // Numeric string from division — safe, no shell-special chars.
                $cmd[] = '--start=' . (string)($this->startMs / 1000.0) . 's';
            }
            $cmd[] = $this->videoPath;
            return $cmd;
        }

        return null;
    }

    /**
     * Locate the mpv binary via `command -v` (Unix) or `where` (Windows).
     *
     * Mirrors the which() pattern from Probe::which() but specific to mpv.
     */
    private function findMpv(): ?string
    {
        $shell = DIRECTORY_SEPARATOR === '\\'
            ? 'where mpv 2>NUL'
            : 'command -v mpv 2>/dev/null';
        $out = @shell_exec($shell);
        if (!is_string($out) || trim($out) === '') {
            return null;
        }
        $first = strtok(trim($out), "\r\n");
        return $first ?: null;
    }

    /** @var resource|null */
    private $processHandle = null;

    /** True once start() has been invoked. */
    private bool $started = false;
}
