<?php

declare(strict_types=1);

namespace SugarCraft\Video;

use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Video\Source\HttpHeaders;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Video\Support\FfmpegCommandBuilder;

/**
 * Audio playback subprocess wrapper for video files.
 *
 * Spawns ffplay (`-nodisp -autoexit`) or mpv (`--no-video`) as a video-less
 * audio companion. By design the audio
 * is not a position-reporting master clock — ffplay exposes no playhead — so
 * instead the Player starts audio and resets its own wall clock at the same
 * instant, then paces video off that clock with frame-skip resync. pause()/
 * resume() keep audio aligned with playback so A/V stay roughly in sync.
 *
 * Graceful degradation:
 * - If the audio subprocess exits immediately (no audio track), isPlaying()
 *   returns false without error.
 * - If neither ffplay nor mpv is available, start() announces the fact once
 *   on error_log and stays off (SV-M23/023) — audio is optional by contract,
 *   so the missing-binary path is a loud degrade, never a throw, and never
 *   the silent shadow the other refuse paths argue against.
 *
 * No single upstream — the audio-companion + wall-clock pacing approach is
 * drawn from maxcurzi/tplay and joelibaceta/video-to-ascii.
 *
 * Intentionally non-final: a real test seam, mirroring Probe's header (same
 * pattern in this lib). The suite extends this class to fake the child —
 * overriding buildCommand() to return an argv that never spawns anything
 * (and, in the Player-side spy, start()/stop() to count respawns) — and to
 * script the pacing clock through the constructor seam; five fixtures do
 * exactly that, so the openness is deliberate policy, not drift.
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
     *                            decoder presents (a signed stream
     *                            used to play video with audio muted-by-401).
     *                            Validated here, at the boundary, exactly as
     *                            {@see \SugarCraft\Video\Decode\FfmpegDecoder::open()}
     *                            validates them, and dropped (with a log line) for
     *                            a local path, where there is no request to ride on.
     */
    public function __construct(
        private readonly string $videoPath,
        private readonly ?int $startMs = null,
        private readonly ?\Closure $clock = null,
        array $headers = [],
        private bool $muted = false,
        private readonly bool $hostRestricted = false,
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
     * Strip the userinfo segment — everything from `scheme://` up to the LAST `@`
     * before the first path separator — from a source before it is
     * interpolated into a log line.
     *
     * WHY: `isNetworkSource()` only matches http(s), so an `rtsp` or `ftp` URL whose
     * authority embeds credentials (a username/password pair before the host separator)
     * takes the header-DROP branch — and the drop
     * notice prints the source verbatim, password included, straight into error_log.
     * Refusing to send credentials and then logging them is no refusal at all.
     */
    /**
     * Query parameter names whose VALUE is a credential and must never reach
     * a display surface (SV-L75), matched case-insensitively against the
     * lower-cased key. Presigned object-store URLs — the documented
     * signed-stream feature — carry their secrets in the QUERY, where the
     * userinfo pass cannot see them: AWS SigV4 (x-amz-*), GCS signing
     * (x-goog-*, Signature/Expires pairs), the bare short forms (sig/sas
     * style), Azure SAS (se/sk/sp/sv/sr/st/skt/sas arrive as one signed
     * bundle), plus the generic token/key/auth/secret/password/jwt family
     * numbered tails (`api_key_2`) included. Matching is anchored per
     * parameter and runs only after a `?` — a path segment can never match.
     */
    private const SECRET_QUERY_KEY_PATTERN = '/^(sign|signature|token|key|apikey|api[-_]?key'
        . '|auth|auth[-_]?token|jwt|secret|password|credential|expires'
        . '|access[-_]?token|refresh[-_]?token|session|session[-_]?id|sessiontoken'
        . '|x-amz[-_a-z0-9]*|x-goog[-_a-z0-9]*|x-azure[-_a-z0-9]*'
        . '|aws[-_]?token|google[-_]?access[-_]?token|sig(?:nature)?v[24]|sig'
        . '|se|sk|sp|sv|sr|st|skt|sas|spr)$/';

    /**
     * Floor between audio respawns (SV-L87/087). Every Space toggle used to
     * run the full terminate-and-respawn ladder synchronously — hundreds of
     * milliseconds of real work per keystroke. The design is forced, not
     * careless: SIGSTOP/SIGCONT are unreliable under a PTY (that is why
     * pause is a kill), and the honest mute check disproved a cheaper path —
     * ffplay exposes no control channel to a stdin-less child and the mpv
     * route spawns without an IPC socket, so mute-by-argument would itself
     * respawn. A resume() landing inside this floor of the last spawn is
     * therefore deferred to the next heartbeat that finds the floor elapsed:
     * rapid toggles collapse into at most one respawn per interval, and the
     * README carries the latency contract.
     */
    private const MIN_RESTART_INTERVAL_SEC = 0.25;

    /** Testing seam for {@see MIN_RESTART_INTERVAL_SEC}; null restores the real floor. */
    private static ?float $restartIntervalOverride = null;

    public static function setRestartIntervalForTesting(?float $seconds): void
    {
        self::$restartIntervalOverride = $seconds;
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
     * Monotonic instant of the last REAL spawn — survives pause(), whose
     * kill clears runningSince, because the coalescing gate (SV-L87/087)
     * needs the memory of when the ladder last ran, not whether audio is
     * currently up.
     */
    private ?float $lastSpawnAt = null;

    /** True while a resume() defers its respawn to the next due heartbeat (SV-L87/087). */
    private bool $resumePending = false;

    /** Once-per-instance flag for the missing-binary announcement (SV-M23/023). */
    private bool $binaryMissingAnnounced = false;

    /**
     * Highest monotonic instant at which the running subprocess was OBSERVED
     * alive (spawn stamps it, isPlaying() lifts it) — the billing ceiling of
     * SV-L89/089: a child that died unwatched is billed to this, not to the
     * parent's wall-clock at teardown time.
     */
    private ?float $lastAliveAt = null;

    /**
     * Validated request headers for a network source, empty otherwise — parsed
     * once in the constructor so every (re)spawn presents them without re-checking
     * (a pause/resume respawn that forgot the credentials would fail the signed
     * stream's second request, exactly like the decoder rebuild did).
     */
    private readonly HttpHeaders $headers;

    /**
     * Exit status of the last OBSERVED termination (SV-L49/049), or null while
     * no child has ended in front of us. Every capture point refreshes it:
     * the closes in pause()/stop()/resume(), and the not-running observation
     * inside isPlaying() for a child that died naturally. A fresh spawn
     * clears it — the running child has not terminated yet.
     */
    private ?int $exitCode = null;

    public static function redactCredentials(string $source): string
    {
        // Public because FfmpegDecoder redacts the same source for the same
        // reason (header-drop log, decode-failure message) — one masking
        // rule, two surfaces, no drift.
        //
        // DISPLAY-ONLY LAW: the result feeds error_log and refusal messages,
        // never argv — the spawned child keeps the real URL, so the query
        // scrub can never break a signature.
        //
        // Greedy within the authority (the class excludes `/`, so it cannot cross
        // into the path): a non-conforming URL with several `@` signs masks ALL of
        // the userinfo, not just the run before the first `@`.
        $masked = (string) preg_replace('#^(\w+://)[^/]*@#', '$1***@', $source);

        $queryAt = strpos($masked, '?');
        if ($queryAt === false) {
            return $masked;
        }

        $fragmentAt = strpos($masked, '#', $queryAt);
        $head = substr($masked, 0, $queryAt + 1);
        $query = $fragmentAt === false ? substr($masked, $queryAt + 1) : substr($masked, $queryAt + 1, $fragmentAt - $queryAt - 1);
        $fragment = $fragmentAt === false ? '' : substr($masked, $fragmentAt);

        $parts = [];
        foreach (explode('&', $query) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $parts[] = $pair; // flagless parameter: no value to hide
                continue;
            }
            $name = substr($pair, 0, $eq);
            $parts[] = preg_match(self::SECRET_QUERY_KEY_PATTERN, strtolower($name)) === 1
                ? $name . '=(redacted)'
                : $pair;
        }

        return $head . implode('&', $parts) . $fragment;
    }

    /**
     * Spawn the audio subprocess (ffplay or mpv) if a suitable binary
     * is available on this host.
     *
     * Uses proc_open() with an array command form for safe argument
     * handling — no shell interpolation.
     *
     * Silent or announced no-op when:
     * - Neither ffplay nor mpv is installed (Probe::ffplay() returns null
     *   and `command -v mpv` also fails) — announced once on error_log
     *   (SV-M23/023), because a host that silently never had audio is the
     *   shadow this lib's refuse-loud law exists to prevent.
     * - The audio source is option-shaped: a path starting with '-' would be
     *   parsed as an ffplay/mpv command-line OPTION rather than a file, and
     *   mpv's --script= flag is arbitrary Lua execution — this route only
     *   ever speaks local paths and http(s) URLs, so such a source is never
     *   legitimate. Refusing it silently keeps a hostile argument out of the
     *   child's argv.
     * - The audio process exits immediately (isPlaying() will return false).
     */
    public function start(): void
    {
        // M24: mute is the user's explicit no — do not latch started, do not spawn.
        if ($this->muted) {
            return;
        }

        // Mark as started even when no binary is available, so callers can
        // distinguish "playback has begun" from "never played" and avoid
        // re-spawning on resume.
        $this->started = true;

        // SV-L87/087: an explicit start() is itself the answer to a deferred
        // resume — clear the intent BEFORE the re-entrancy guard below asks
        // isPlaying(), whose heartbeat would otherwise promote (call start()
        // again) and double-spawn behind the guard's back.
        $this->resumePending = false;

        // m3: resume/retoggle paths call start() again on a still-live
        // companion — a second spawn would play over the first and orphan a
        // child until the next stop(). Already playing means already playing.
        if ($this->isPlaying()) {
            return;
        }

        // M9: an option-shaped source ('-something') would be parsed as an
        // ffplay/mpv OPTION (mpv's --script= is arbitrary Lua = code
        // execution). This route only speaks local files and http(s) URLs.
        if (str_starts_with($this->videoPath, '-')) {
            return;
        }

        $cmd = $this->buildCommand();
        if ($cmd === null) {
            // Neither audio binary is available — silent degradation is what
            // every host saw for a year; SV-M23/023 makes it a LOUD degrade:
            // announce-not-throw (audio is optional by contract, video keeps
            // playing), once per instance — the fact cannot change
            // mid-session and a respawn ladder must not shout per toggle.
            if (!$this->binaryMissingAnnounced) {
                $this->binaryMissingAnnounced = true;
                error_log(Lang::t('audio.binary_missing'));
            }

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
        // M8: scrubbed env (no proxy/secret steering or leaking through the child).
        $this->processHandle = @proc_open($cmd, $descriptorSpec, $pipes, null, FfmpegCommandBuilder::scrubbedEnv());

        // Guard against proc_open failure (returns false when it cannot spawn).
        if ($this->processHandle === false) {
            $this->processHandle = null;

            return;
        }
        // Stamp the monotonic time this (re)start so pause() can advance the
        // tracked position by however long the subprocess actually played.
        // Set only on a real spawn: with no binary there is no playback to
        // account for. The alive-upper-bound starts at the same instant: a
        // spawn is an observation, nothing later is claimed until someone
        // sees the child running (SV-L89/089).
        $this->runningSince = ($this->clock ?? self::monotonic())();
        $this->lastAliveAt = $this->runningSince;
        // SV-L87/087: this spawn IS the deferred resume's answer, when there
        // was one — and the gate's memory starts here for the next toggle.
        $this->lastSpawnAt = $this->runningSince;
        $this->resumePending = false;
        // SV-L49/049: this child has not terminated yet — the status belongs
        // to the last one. Clearing here is what lets exitCode() answer
        // null-then-final-code across a resume, never a replayed stale.
        $this->exitCode = null;
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
     * caller's shutdown indefinitely. The canonical ladder in
     * {@see BoundedShutdown} escalates TERM→9 and confirms the exit, so the
     * reap below cannot inherit a deadline it cannot keep.
     */
    public function stop(): void
    {
        $this->resumePending = false; // SV-L87/087: teardown outranks a deferred resume

        if (!is_resource($this->processHandle)) {
            return;
        }

        $this->bankElapsed();
        BoundedShutdown::terminateAndAwaitExit($this->processHandle);
        // SV-L49/049: stop() used to throw the close status away, leaving
        // exitCode() either null or — worse — a stale pause()-era code once a
        // respawn died later. Capture the same way pause() does.
        $this->exitCode = proc_close($this->processHandle);
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
        // SV-L87/087: pausing again cancels an unspawned deferred resume —
        // the viewer changed their mind inside the floor, nothing to silence.
        $this->resumePending = false;

        if (!is_resource($this->processHandle)) {
            return;
        }
        // Bank the elapsed play time into the tracked seek position BEFORE killing,
        // so resume() picks up where the viewer paused, not where playback started.
        $this->bankElapsed();
        BoundedShutdown::terminateAndAwaitExit($this->processHandle);
        $exitCode = proc_close($this->processHandle);
        $this->processHandle = null;
        $this->exitCode = $exitCode;
    }

    /**
     * Bank the play time since the last real spawn into the tracked position.
     * Every teardown of a running subprocess (pause, resume's terminate-and-
     * restart, stop) calls this first, so position survives regardless of which
     * path ended the child. No-op when nothing is running (runningSince null).
     *
     * SV-L89/089: the segment billed is bounded by the last moment the child
     * was KNOWN alive, not by parent wall-clock. A companion that ended on its
     * own (`-autoexit` at EOF) sits unobserved until the next teardown; the
     * old arithmetic billed the whole dead interval into seekMs, so the respawn
     * `-ss` overshot past the true position — or past EOF, producing a
     * silent-immediate-exit companion the UI painted as playing. A fresh
     * liveness probe here keeps the healthy case exact (nothing is ever
     * under-billed while the child demonstrably runs); a dead child stops
     * billing at its last observed-alive sample, and the settle clears the
     * running anchor so a later pause()/stop() cannot bill the same segment
     * twice.
     */
    private function bankElapsed(): void
    {
        if ($this->runningSince === null) {
            return;
        }

        $now = ($this->clock ?? self::monotonic())();
        $upper = max($this->runningSince, $this->lastAliveAt ?? $this->runningSince);

        if (is_resource($this->processHandle)
            && ($status = proc_get_status($this->processHandle)) !== false
            && $status['running']) {
            // Observed alive RIGHT NOW: the whole segment is real play time.
            $upper = $now;
        }

        if ($upper > $this->runningSince) {
            $this->seekMs += (int) round(($upper - $this->runningSince) * 1000);
        }
        $this->runningSince = null;
        $this->lastAliveAt = null;
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
            BoundedShutdown::terminateAndAwaitExit($this->processHandle);
            $this->exitCode = proc_close($this->processHandle); // SV-L49/049: capture, like pause()
            $this->processHandle = null;
        }
        // SV-L87/087: a resume landing inside the restart floor of the last
        // spawn does not churn the ladder now — the next heartbeat promotes
        // it once the floor has elapsed (and a pause() cancels it outright).
        // An unhurried resume still spawns on the spot, zero added latency.
        if (!$this->restartDue()) {
            $this->resumePending = true;

            return;
        }
        $this->resumePending = false;
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
     * M24: silence the companion — kills a live child and refuses future
     * start()/resume() until unmute(). The video clock is unaffected.
     */
    public function mute(): void
    {
        $this->muted = true;

        if ($this->isPlaying()) {
            $this->stop();
        }
    }

    public function unmute(): void
    {
        $this->muted = false;
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    /**
     * True when the audio subprocess is still running.
     *
     * Returns false when:
     * - The process has not been started (start() was never called).
     * - The process exited immediately (no audio track).
     * - The process was stopped via stop().
     * - A resume is deferred by the restart floor and not yet due (SV-L87/087).
     */
    public function isPlaying(): bool
    {
        // SV-L87/087: every poll is a heartbeat — a deferred resume whose
        // floor has elapsed spawns before the answer is computed, so the
        // deferral lasts at most until the next tick, never silently longer.
        if ($this->resumePending && $this->restartDue()) {
            $this->resumePending = false;
            $this->start();
        }

        if (!is_resource($this->processHandle)) {
            return false;
        }

        $status = proc_get_status($this->processHandle);
        // proc_get_status returns false after proc_close, so guard.
        if ($status === false) {
            return false;
        }

        if ($status['running']) {
            // SV-L89/089: an alive observation is data — it lifts the
            // billing ceiling to this instant, so a child that dies later
            // with no one watching still gets billed exactly as far as it
            // was last seen playing.
            $this->lastAliveAt = ($this->clock ?? self::monotonic())();

            return true;
        }

        // Death observed: settle the segment HERE (bounded by the last alive
        // sample) and clear the running anchor, so no later teardown bills
        // parent wall-clock across a long-dead companion. Settling is
        // bookkeeping only — it never spawns, never reaps, never throws.
        $this->bankElapsed();

        // SV-L49/049: the same observation carries the final status. PHP's
        // proc_get_status() reaps via waitpid(WNOHANG) on the spot (the M22
        // lesson), so a not-running child answers with the very code a
        // proc_close() would have returned — and signal deaths report -1,
        // the same value proc_close() reserves for them. Recording it here
        // is what keeps exitCode() from replaying a stale code from the
        // previous pause/resume generation after a natural death.
        $this->exitCode = (int) ($status['exitcode'] ?? -1);

        return false;
    }

    /**
     * The Player's per-frame heartbeat for the companion (SV-L87/087): the
     * poll below promotes a restart deferred by the floor and records the
     * alive-observation that bounds position billing (SV-L89/089). Cheap and
     * idempotent — it spawns nothing outside a due promotion.
     */
    public function tick(): void
    {
        $this->isPlaying();
    }

    /**
     * Whether the restart floor (SV-L87/087) has elapsed since the last real
     * spawn. Never-spawned is trivially due: there is no ladder to protect.
     */
    private function restartDue(): bool
    {
        if ($this->lastSpawnAt === null) {
            return true;
        }

        return (($this->clock ?? self::monotonic())() - $this->lastSpawnAt) >= self::minRestartInterval();
    }

    /** The live floor value — the test seam may bend it, never remove it. */
    private static function minRestartInterval(): float
    {
        return self::$restartIntervalOverride ?? self::MIN_RESTART_INTERVAL_SEC;
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
            return FfmpegCommandBuilder::ffplayAudioCommand($ffplayPath, $this->videoPath, $this->seekMs, $this->headers, $this->hostRestricted);
        }

        // Fall back to mpv. --no-video keeps it audio-only (no window);
        // --really-quiet suppresses its status output on our discarded pipes.
        $mpvPath = Probe::mpv();
        if ($mpvPath !== null) {
            return FfmpegCommandBuilder::mpvAudioCommand($mpvPath, $this->videoPath, $this->seekMs, $this->headers, $this->hostRestricted);
        }

        return null;
    }

    /**
     * Return the exit status of the last OBSERVED termination.
     *
     * Returns null when nothing has terminated in front of us: no process
     * was ever started, the (re)spawn is still running, or no binary was
     * available. Every observation refreshes it — the closes in
     * pause()/stop()/resume() and the not-running sighting in isPlaying()
     * (SV-L49/049) — so the answer tracks the newest child, never a code
     * left behind by an older generation.
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }
}
