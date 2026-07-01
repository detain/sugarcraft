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
     * @param string  $videoPath Path to the video file, OR an http(s) URL —
     *                            ffplay/mpv both stream a network source natively,
     *                            so a media client can play a signed stream URL.
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

        // File sinks (the OS null device) rather than pipes: ffplay/mpv write
        // status chatter to stderr, and a reader-less stderr PIPE that we close
        // immediately can hand the child a SIGPIPE on its first write and kill
        // it. A file sink opens no parent-side FD, so there is nothing to race
        // against and nothing to clean up.
        $devNull = DIRECTORY_SEPARATOR === '\\' ? '\\\\.\\NUL' : '/dev/null';
        $descriptorSpec = [
            0 => ['file', $devNull, 'r'],  // stdin — unused
            1 => ['file', $devNull, 'w'],  // stdout — discarded
            2 => ['file', $devNull, 'w'],  // stderr — discarded
        ];

        $pipes = [];
        $this->processHandle = @proc_open($cmd, $descriptorSpec, $pipes);

        // Guard against proc_open failure (returns false when it cannot spawn).
        if ($this->processHandle === false) {
            $this->processHandle = null;

            return;
        }
        // No pipe cleanup needed — file sinks open no parent-side pipes.
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
     * Suspend audio playback by terminating the subprocess.
     *
     * SIGSTOP is ineffective under PTY (child runs in different process group),
     * so we SIGTERM the subprocess and store the exit code. resume() restarts
     * from the stored startMs position.
     *
     * Safe no-op when no process is running.
     */
    public function pause(): void
    {
        if (!is_resource($this->processHandle)) {
            return;
        }
        proc_terminate($this->processHandle, SIGTERM);
        $exitCode = proc_close($this->processHandle);
        $this->processHandle = null;
        $this->exitCode = $exitCode;
    }

    /**
     * Resume audio playback.
     *
     * If the process was killed by pause() (processHandle is null), restart
     * from the stored startMs position. Otherwise, terminate the existing
     * process and restart to avoid SIGCONT PTY issues (SIGCONT has the same
     * PTY problems as SIGSTOP).
     *
     * Safe no-op when no process has ever been started.
     */
    public function resume(): void
    {
        if (is_resource($this->processHandle)) {
            // SIGCONT has PTY issues like SIGSTOP; terminate and restart.
            proc_terminate($this->processHandle, SIGTERM);
            proc_close($this->processHandle);
            $this->processHandle = null;
        }
        $this->start(); // start() respects startMs
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
        $mpvPath = Probe::mpv();
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

    /** @var resource|null */
    private $processHandle = null;

    /** True once start() has been invoked. */
    private bool $started = false;

    /** Exit code from the last process termination, or null if still running. */
    private ?int $exitCode = null;

    /**
     * Return the exit code from the last process termination.
     *
     * Returns null when:
     * - The process has never been started.
     * - The process is still running.
     * - The process was stopped via stop() (which discards the exit code).
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }
}
