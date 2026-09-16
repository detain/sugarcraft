<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

use SugarCraft\Reel\Source\HttpHeaders;
use SugarCraft\Reel\Source\Probe;
use SugarCraft\Reel\Support\BoundedReaper;
use SugarCraft\Reel\Support\FfmpegCommandBuilder;

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
     * @param (\Closure():float)|null $clock Monotonic seconds source for position
     *                            tracking; injectable so the pause/resume elapsed
     *                            arithmetic is testable without sleeping. Defaults
     *                            to hrtime() — a true monotonic clock, immune to
     *                            NTP steps that would corrupt the banked position
     *                            (a negative elapsed on wall clock would silently
     *                            drop `-ss` and respawn from 0). Accepted as a
     *                            Closure because PHP forbids the `callable`
     *                            keyword as a property type.
     * @param array<array-key, string> $headers HTTP request headers for a remote
     *                            http(s) source — the same credentials the video
     *                            decoder presents (findings #52: a signed stream
     *                            used to play video with audio muted-by-401).
     *                            Validated here, at the boundary, exactly as
     *                            {@see \SugarCraft\Reel\Decode\FfmpegDecoder::open()}
     *                            validates them, and dropped (with a log line) for
     *                            a local path, where there is no request to ride on.
     */
    public function __construct(
        private readonly string $videoPath,
        private readonly ?int $startMs = null,
        private readonly ?\Closure $clock = null,
        array $headers = [],
    ) {
        $this->seekMs = $startMs ?? 0;

        // Assign once: `$headers` is readonly, so the local-source bail-out has to
        // settle the value before the write rather than overwrite it afterwards.
        $parsed = HttpHeaders::parse($headers);
        if (!$parsed->isEmpty() && !FfmpegCommandBuilder::isNetworkSource($videoPath)) {
            error_log(Lang::t('header.ignored_local_source.audio', [
                'count' => count($parsed->pairs()),
                'source' => self::redactCredentials($videoPath),
            ]));
            $parsed = HttpHeaders::none();
        }
        $this->headers = $parsed;
    }

    /**
     * Strip userinfo (`scheme://user:[email protected]`) from a source before it is
     * interpolated into a log line.
     *
     * WHY: `isNetworkSource()` only matches http(s), so an `rtsp` or `ftp` URL whose
     * authority embeds credentials (a `user:password` pair before the host separator)
     * takes the header-DROP branch — and the drop
     * notice prints the source verbatim, password included, straight into error_log.
     * Refusing to send credentials and then logging them is no refusal at all.
     */
    private static function redactCredentials(string $source): string
    {
        return (string) preg_replace('#^(\w+://)[^/@]*@#', '$1***@', $source);
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
        // Stamp the monotonic time this (re)start so pause() can advance the
        // tracked position by however long the subprocess actually played.
        // Set only on a real spawn: with no binary there is no playback to
        // account for.
        $this->runningSince = ($this->clock ?? self::monotonic())();
        // No pipe cleanup needed — file sinks open no parent-side pipes.
    }

    /**
     * Stop the audio subprocess by terminating it in BOUNDED time.
     *
     * Safe to call even if the process has already exited.
     *
     * WHAT THIS USED TO BE: `proc_terminate()` then `proc_close()` with no
     * escalation between them. E366 measured that pair: it blocks —
     * `proc_close()` waits — so a player that ignores SIGTERM pinned the
     * caller's shutdown indefinitely. The ladder in
     * {@see BoundedReaper} escalates TERM→9 and confirms the exit, so the
     * reap below cannot inherit a deadline it cannot keep.
     */
    public function stop(): void
    {
        if (!is_resource($this->processHandle)) {
            return;
        }

        $this->bankElapsed();
        BoundedReaper::terminateNow($this->processHandle);
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
     * SIGSTOP is ineffective under PTY (the child runs in a different process
     * group), and its exit status is never checked, so a silent failure would
     * leave audio playing against a paused video. Instead we TERM→KILL the
     * subprocess in bounded time and record how long it actually played, so
     * resume() restarts from the advanced position rather than the original
     * seek — the A/V-sync gap the old SIGSTOP path opened when the signal was
     * dropped.
     *
     * Safe no-op when no process is running.
     */
    public function pause(): void
    {
        if (!is_resource($this->processHandle)) {
            return;
        }
        // Bank the elapsed play time into the tracked seek position BEFORE killing,
        // so resume() picks up where the viewer paused, not where playback started.
        $this->bankElapsed();
        BoundedReaper::terminateNow($this->processHandle);
        $exitCode = proc_close($this->processHandle);
        $this->processHandle = null;
        $this->exitCode = $exitCode;
    }

    /**
     * Bank the play time since the last real spawn into the tracked position.
     * Every teardown of a running subprocess (pause, resume's terminate-and-
     * restart, stop) calls this first, so position survives regardless of which
     * path ended the child. No-op when nothing is running (runningSince null).
     */
    private function bankElapsed(): void
    {
        if ($this->runningSince === null) {
            return;
        }

        $now = ($this->clock ?? self::monotonic())();
        $this->seekMs += (int) round(($now - $this->runningSince) * 1000);
        $this->runningSince = null;
    }

    /**
     * The default position clock: hrtime() nanoseconds as float seconds.
     * Monotonic by contract — a wall-clock default could step backwards via
     * NTP and bank a negative elapsed, silently rewinding (and, being > 0
     * guarded, then dropping) the respawn `-ss`.
     *
     * @return \Closure():float
     */
    private static function monotonic(): \Closure
    {
        return static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * Resume audio playback from the tracked position.
     *
     * If the process was killed by pause() (processHandle is null), restart from
     * the position pause() advanced to. Otherwise, bank the time played so far,
     * terminate the existing process and restart — SIGCONT has the same PTY
     * problems as SIGSTOP, so resume is always a respawn, and an already-running
     * resume() must not discard its unbanked segment.
     *
     * With no prior start() this spawns fresh from the original seek — which is
     * how Player::play() begins audio on a never-yet-started companion; it is not
     * a literal no-op.
     */
    public function resume(): void
    {
        if (is_resource($this->processHandle)) {
            // SIGCONT has PTY issues like SIGSTOP; terminate and restart —
            // banking first so this segment's play time is not lost.
            $this->bankElapsed();
            BoundedReaper::terminateNow($this->processHandle);
            proc_close($this->processHandle);
            $this->processHandle = null;
        }
        $this->start(); // start() honours the advanced $seekMs via buildCommand()
    }

    /**
     * The audio position, in milliseconds, that a (re)spawn would start from —
     * the original seek advanced by every pause's elapsed play time. Bare accessor
     * per the library's no-`get` rule; exposed so pause/resume position tracking is
     * observable and testable without a real audio device.
     */
    public function position(): int
    {
        return $this->seekMs;
    }

    /**
     * E366's other measured half: a handle that simply goes out of scope
     * does NOT wait for its child — PHP's resource destructor reaps an
     * already-exited child and ABANDONS a live one, reparenting an ffplay
     * to init with every inherited descriptor still open. stop() is
     * idempotent, so an explicit call before teardown still wins; this is
     * the backstop for the path where nobody remembered to.
     */
    public function __destruct()
    {
        $this->stop();
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
     * The argv shape (flags, order, `-ss`/`--start` formatting, and now the
     * header passthrough) comes from {@see FfmpegCommandBuilder}, shared with the
     * video decoder so one fix cannot land on one child and be forgotten on the
     * other. Pinioned by `tests/Support/ArgvGoldenTest`.
     *
     * @return list<string>|null Command array for proc_open(), or null
     */
    protected function buildCommand(): ?array
    {
        // Prefer ffplay.
        $ffplayPath = Probe::ffplay();
        if ($ffplayPath !== null) {
            return FfmpegCommandBuilder::ffplayAudioCommand($ffplayPath, $this->videoPath, $this->seekMs, $this->headers);
        }

        // Fall back to mpv. --no-video keeps it audio-only (no window);
        // --really-quiet suppresses its status output on our discarded pipes.
        $mpvPath = Probe::mpv();
        if ($mpvPath !== null) {
            return FfmpegCommandBuilder::mpvAudioCommand($mpvPath, $this->videoPath, $this->seekMs, $this->headers);
        }

        return null;
    }

    /** @var resource|null */
    private $processHandle = null;

    /** True once start() has been invoked. */
    private bool $started = false;

    /**
     * Current play position in milliseconds — the constructor seek offset advanced
     * by every banked play segment (pause/resume/stop each add elapsed monotonic
     * time). start()/buildCommand() spawn from here so a resume continues instead
     * of replaying from the original seek.
     */
    private int $seekMs;

    /** Monotonic time the running subprocess started, or null when not playing. */
    private ?float $runningSince = null;

    /**
     * Validated request headers for a network source, empty otherwise — parsed
     * once in the constructor so every (re)spawn presents them without re-checking
     * (a pause/resume respawn that forgot the credentials would fail the signed
     * stream's second request, exactly like the decoder rebuild did).
     */
    private readonly HttpHeaders $headers;

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
