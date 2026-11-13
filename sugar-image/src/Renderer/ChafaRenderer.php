<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;

/**
 * Chafa graphics renderer — invokes the chafa command-line tool.
 *
 * Chafa is a command-line image-to-terminal converter that supports
 * true-color, transparency, and various output formats. The binary is
 * located by {@see ChafaBinary} — a fixed absolute roster or an
 * explicit operator override; a bare PATH `chafa` is never executed.
 */
final class ChafaRenderer implements Renderer, ConfigFingerprintProvider
{
    use \SugarCraft\Image\Concerns\RenderValidationTrait;

    /**
     * Hard ceiling on the bytes the render keeps from the child's
     * stdout. Legitimate chafa output at poster scale is a few megabytes;
     * anything past 32 MiB is runaway, not art. The drain keeps the first
     * this-many bytes and keeps reading to EOF beyond it (drain-to-null),
     * so the child never blocks on a full pipe nor dies of EPIPE.
     *
     * RULING (accepted): a SUCCESS truncated at this cap
     * returns the kept head silently — that is intended, not an oversight.
     * A real sixel/kitty payload past 32 MiB is a misuse case (no terminal
     * would display it), and a loud signal on the success path would punish
     * the only caller that cannot act on it; the failure path keeps its own
     * bounded channel instead — the {@see STDERR_TAIL_BYTES} stderr tail
     * rides the exception. Chosen over the alternatives the review named
     * (throw, or a renderTruncated flag) precisely because truncation here
     * never corrupts a render the caller could still use.
     */
    public const MAX_STDOUT_BYTES = 32 * 1024 * 1024;

    /** Read chunk for {@see drainStdout()}. */
    private const STDOUT_CHUNK_BYTES = 65536;

    /**
     * How many trailing bytes of the child's stderr sink ride into
     * the failure message — chafa's own sentences are one-liners, and 8 KiB
     * bounds the exception even if the child flooded diagnostics. The tail
     * passes through {@see Sanitize::untrusted()} at {@see self::stderrTail()},
     * its only reader, so the surviving bound is prose only (see there).
     */
    public const STDERR_TAIL_BYTES = 8 * 1024;

    /** Post-stdout-EOF budget for the child to exit on its own. */
    private const REAP_WAIT_MS = 5000;

    /** Budget a SIGTERM gets before the ladder reaches for SIGKILL. */
    private const TERM_GRACE_MS = 1000;

    /**
     * No-progress budget for {@see drainStdout()}: milliseconds the child
     * may hold stdout open without writing a single byte before the drain
     * gives up and hands the process to the reap ladder. The clock RESETS
     * on every byte, so a slow-but-progressing encode — the poster whose
     * legitimate work may run minutes — is never punished; only silence
     * is (finding 007). Ten seconds of dead air on an open pipe is a
     * wedge, not art.
     */
    private const DRAIN_NO_PROGRESS_MS = 10_000;

    /** @internal Test seams — bound the ladder/drain so wedge arms run in
     *              milliseconds; null restores each default. Cleared by reset(). */
    private static ?int $reapWaitMs = null;

    /** @internal */
    private static ?int $termGraceMs = null;

    /** @internal */
    private static ?int $drainBudgetMs = null;

    /**
     * Memoised result of {@see available()} — null means not yet probed,
     * true/false are cached for the lifetime of the process.
     *
     * This memoisation is intentional: spawning `chafa --version` on every
     * frame of an animation would be catastrophic for performance. The cost
     * is that if chafa is installed after process startup, {@see reset()}
     * must be called to re-probe. For short-lived CLI tools this is fine;
     * for long-running daemons consider calling reset() after package
     * installation if you need to use chafa dynamically.
     */
    private static ?bool $available = null;

    /**
     * The first banner line the availability probe's `--version` spawn
     * answered — '' when no binary resolved or the probe produced no
     * output, null when not yet probed. {@see configFingerprint()} folds
     * it (with the resolved path) into the cache key so an upgraded or
     * swapped chafa cannot keep serving pre-switch frames forever under
     * a stable key (finding 015).
     */
    private static ?string $version = null;

    /**
     * Successful renders, keyed by (bytes|geometry|config fingerprint),
     * insertion-ordered and capped at {@see RENDER_MEMO_MAX} so a
     * long-lived TUI pays a bounded, known frame size of memory for the
     * repaint fast path (finding 103). Cleared by {@see reset()}.
     *
     * @var array<string,string>
     */
    private static array $renderCache = [];

    /** Bound of the repaint memo — eight frames of ANSI, then FIFO. */
    private const RENDER_MEMO_MAX = 8;

    /**
     * Reused per-process scratch file for the chafa input image, plus the
     * content hash of whatever is currently written to it.
     *
     * An animation renders many frames; a fresh tempnam() + full rewrite +
     * unlink per frame is wasteful. Instead we keep ONE temp file for the
     * process and only rewrite it when the image bytes actually change (keyed
     * by hash), so a static image re-rendered on every TUI redraw skips the
     * write entirely. The file is unlinked on shutdown.
     *
     * @var array{path: string, hash: string}|null
     */
    private static ?array $scratch = null;

    /**
     * @param list<string> $options Additional chafa CLI options (e.g. ['--colors=256', '--work=n'])
     * @param string|null  $format  chafa output format: 'sixels', 'iterm', 'kitty', or
     *                              'symbols'. null leaves chafa's own default (symbols)
     *                              — the high-quality character-art mode. Pass 'sixels'
     *                              to drive a fast, full-quality sixel encode in C
     *                              (far faster than the pure-PHP {@see SixelRenderer}).
     */
    public function __construct(
        private readonly array $options = [],
        private readonly ?string $format = null,
    ) {}

    /**
     * Whether a gate-passing `chafa` binary resolves and answers
     * `--version`. Resolution is the {@see ChafaBinary} roster/override
     * choke — a bare PATH `chafa` is deliberately NOT executed. Probed
     * once per process (the result is memoised) so a per-frame video
     * render does not spawn a probe each frame.
     */
    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $binary = ChafaBinary::path();
        if ($binary === null) {
            self::$version = '';
            return self::$available = false;
        }

        $outPath = @tempnam(sys_get_temp_dir(), 'schafaout');
        if ($outPath === false) {
            self::$version = '';
            return self::$available = false;
        }

        $proc = @proc_open(
            [$binary, '--version'],
            // fd 0 anchored to /dev/null (finding 012, house law per
            // TmuxPassthrough/Detect): an unspecified stdin leg inherits
            // THIS process's fd 0, so a chafa build that touches stdin
            // could consume user input — or block forever waiting for an
            // EOF the TUI never sends — from a mere availability probe.
            // The banner rides a FILE sink, not a pipe (finding 015):
            // the probe's stdout must survive reap() to be read, and
            // proc_close() closes the pipe legs on the way — a read
            // after the settle would be structurally empty, while a
            // read before it re-opens the unbounded-wait door finding
            // 005 just closed. The sink is this library's own shape for
            // "child output we read after the child is gone".
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outPath, 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            // proc_open() may have created pipes before failing — close them
            // to avoid file descriptor leaks in long-running processes. When
            // the binary is missing entirely $pipes stays null (foreach on
            // null raises a warning, which failOnWarning turns fatal).
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            @unlink($outPath);
            self::$version = '';
            return self::$available = false;
        }

        // Settle through the reap ladder, not proc_close's unbounded wait
        // (finding 005): a binary that answers `--version` by wedging —
        // never exiting — used to park HERE forever, and because the memo
        // was only written after the wait, every later call re-probed and
        // re-parked. reap() bounds the leave (TERM -> KILL) and the memo
        // lands either way: a process that cannot finish its own version
        // string inside the budget has honestly told us it is not a usable
        // backend, and this process will not ask it twice.
        $exit = self::reap($proc);

        $raw = (string) @file_get_contents($outPath);
        @unlink($outPath);
        $newline       = strpos($raw, "\n");
        self::$version = rtrim($newline === false ? $raw : substr($raw, 0, $newline));
        return self::$available = ($exit === 0);
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

        // Resolved once up front and REFUSED before anything is built
        // (finding 046): the old `$binary ?? ''` planted an empty argv[0]
        // into every command line the binary could not serve, and only a
        // distant `? false :` stand-in at the spawn kept it from reaching
        // the OS — a landmine for any future call site that trusts $cmd.
        // The refusal still throws exactly what it always threw for a
        // missing chafa (same class, same message); what changes is that
        // it no longer first pays the scratch write and sink creation
        // for a spawn that can never happen.
        $binary = ChafaBinary::path();
        if ($binary === null) {
            throw new \RuntimeException(Lang::t('chafa.not_found'));
        }

        $size = "{$width}x{$effectiveHeight}";

        // A TUI re-painting the SAME unchanged source every tick used to
        // pay the full child cost each time (finding 103). The
        // scratch-reuse half already skipped the rewrite; this is the
        // other half — the encoded ANSI is memoised keyed by
        // (bytes, geometry, config fingerprint incl. binary identity),
        // so an unchanged repaint spawns nothing at all. Only successes
        // are stored: one transient chafa failure must not replay stale
        // bytes forever, it must face a fresh attempt.
        $memoKey = hash('xxh3', $image->bytes . '|' . $size . '|' . $this->configFingerprint());
        $cached  = self::$renderCache[$memoKey] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $cmd = [$binary, '--size=' . $size];
        if ($this->format !== null) {
            $cmd[] = '--format=' . $this->format;
        }
        $cmd = array_merge($cmd, $this->options);

        $cmd[] = self::scratchFile($image->bytes);

        // The child's diagnostics go to a private file sink, never to
        // this process's stderr. An inherited fd 2 let chafa's own error
        // sentences scribble over the TUI frame AND discarded the failure
        // reason — the user saw "exit code 1" while the actual sentence went
        // to the tty. tempnam() creates the file 0600; the 'w' open reuses
        // that mode. A file sink also satisfies the NO READER-LESS PIPES
        // law (AsyncRenderer): there is never a reader to block.
        $errPath = tempnam(sys_get_temp_dir(), 'schafaerr');
        if ($errPath === false) {
            throw new \RuntimeException(Lang::t('chafa.sink_failed'));
        }

        $descriptorSpec = [
            // fd 0 on /dev/null (finding 012): a render child inherits
            // our stdin otherwise, and a chafa that reads it would eat
            // user input or wait for an EOF that never comes.
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $errPath, 'w'],
        ];
        $process = @proc_open($cmd, $descriptorSpec, $pipes);
        $reaped = false;

        try {
            if ($process === false) {
                // argv[0] is gate-verified, so a false here means the
                // spawn itself was refused (fork pressure, execve
                // failure); settleLeave below releases any partial
                // pipes, and not_found stays the caller-visible answer.
                throw new \RuntimeException(Lang::t('chafa.not_found'));
            }

            if (!is_resource($process)) {
                throw new \RuntimeException(Lang::t('chafa.command_failed', ['error' => 'proc_open returned false']));
            }

            $stdout = self::drainStdout($pipes[1]);
            fclose($pipes[1]);

            $exitCode = self::reap($process);
            $reaped = true;

            if ($exitCode !== 0) {
                $tail = self::stderrTail($errPath);
                throw new \RuntimeException(
                    Lang::t('chafa.command_failed', [
                        'error' => $tail === '' ? "exit code $exitCode" : "exit code $exitCode: $tail",
                    ])
                );
            }

            self::rememberRender($memoKey, $stdout);
        } finally {
            // Every settle path — success, failure, or a throw between
            // the spawn and here — releases the sink file, and since
            // finding 010 also the pipe and the child: a throw inside
            // drainStdout()/reap() used to abandon a LIVE child with an
            // open fd, unaccounted in the child-lifetime ledger.
            // settleLeave() is idempotent, so the normal paths pay
            // nothing for the insurance.
            self::settleLeave($process, $pipes ?? [], $reaped);
            @unlink($errPath);
        }

        return $stdout;
    }

    /**
     * Close-side settle for every departure from render() (finding 010):
     * release the pipes, and unless the normal path already reaped, walk
     * the child through the SAME TERM→KILL ladder {@see reap()} defines.
     * Safe to call twice and safe to call after the normal path — every
     * leg is guarded, so re-settling a settled leave is silent (the
     * failOnWarning suite is the pin that it really is).
     *
     * @param resource|false $process
     * @param array<int,resource> $pipes
     */
    private static function settleLeave($process, array $pipes, bool $reaped): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if ($process !== false && is_resource($process) && !$reaped) {
            self::reap($process);
        }
    }

    /**
     * Store one successful encode, evicting oldest-first at the cap.
     * PHP arrays keep insertion order; because a live hit never
     * re-inserts, "oldest inserted" is a cheap FIFO — good enough for
     * eight slots and it keeps the memo a constant few frames of RAM.
     */
    private static function rememberRender(string $key, string $ansi): void
    {
        self::$renderCache[$key] = $ansi;

        if (count(self::$renderCache) > self::RENDER_MEMO_MAX) {
            unset(self::$renderCache[array_key_first(self::$renderCache)]);
        }
    }

    /**
     * Read the child's stdout under {@see MAX_STDOUT_BYTES}: keep the head,
     * drain the remainder to null. Returning early without draining would
     * hand the child an EPIPE mid-write — a truncated encode reported as a
     * render we asked for. A blocking pipe never returns an empty success,
     * so "" (EOF or dead-pipe) ends the loop, and the no-progress watchdog
     * (finding 007) ends it too once {@see DRAIN_NO_PROGRESS_MS} passes
     * without a single byte — the wedged child is then the reap ladder's
     * problem, not the caller's parked thread.
     *
     * @param resource $pipe
     */
    private static function drainStdout($pipe): string
    {
        $stdout   = '';
        $deadline = self::drainDeadline();
        while (true) {
            $read  = [$pipe];
            $write = $except = null;
            $micro = max(0, (int) (($deadline - microtime(true)) * 1_000_000));
            $ready = @stream_select($read, $write, $except, intdiv($micro, 1_000_000), $micro % 1_000_000);
            if ($ready === false || $ready === 0) {
                // Error, or silence past the budget: break into the reap
                // ladder. A healthy child reaching EOF answers select with
                // readable + a false/'' read below, so the only thing this
                // door closes on its own is a non-answerer.
                break;
            }

            $chunk = fread($pipe, self::STDOUT_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $deadline = self::drainDeadline();
            $room     = self::MAX_STDOUT_BYTES - strlen($stdout);
            if ($room > 0) {
                $stdout .= strlen($chunk) <= $room ? $chunk : substr($chunk, 0, $room);
            }
            if (feof($pipe)) {
                break;
            }
        }

        return $stdout;
    }

    /** Progress-reset clock for the drain watchdog (seam-aware). */
    private static function drainDeadline(): float
    {
        return microtime(true) + (self::$drainBudgetMs ?? self::DRAIN_NO_PROGRESS_MS) / 1000;
    }

    /**
     * Reap ladder for a child whose stdout has already EOF-ed: poll for a
     * natural exit, escalate TERM(15) -> KILL(9), always end in
     * proc_close() so the wait happens there regardless.
     *
     * The deadlines here are a test/slice budget, NEVER a kill-on-request-
     * time policy (the no-blanket-timeout rule): this function puts no bound
     * on chafa's rendering work — {@see drainStdout()} reads to EOF however
     * long a legitimate encode takes (a poster may run minutes). It bounds
     * only the wait for a child that has ALREADY closed stdout to leave the
     * process table; past that point, waiting forever can never rescue slow
     * work, only mask a wedged process. Signal numbers are literals because
     * ext-pcntl is not a requirement of this lib.
     *
     * @param resource $process
     */
    private static function reap($process): int
    {
        $deadline = microtime(true) + (self::$reapWaitMs ?? self::REAP_WAIT_MS) / 1000;
        while (self::childRunning($process) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (self::childRunning($process)) {
            proc_terminate($process, 15);
            $grace = microtime(true) + (self::$termGraceMs ?? self::TERM_GRACE_MS) / 1000;
            while (self::childRunning($process) && microtime(true) < $grace) {
                usleep(10_000);
            }
            if (self::childRunning($process)) {
                proc_terminate($process, 9);
            }
        }

        return proc_close($process);
    }

    private static function childRunning($process): bool
    {
        return proc_get_status($process)['running'];
    }

    /**
     * Last {@see STDERR_TAIL_BYTES} of the child's stderr sink, fail-soft:
     * an unreadable sink costs us the sentence, never the exception — the
     * exit code is already in the message around this tail. Forged OSC/SGR/CSI
     * is swept here, at the single read choke (sec-audit M1): the sink is a
     * hostile-adjacent channel because chafa echoes bytes from the image it
     * converts and a rogue or wrapper binary controls this fd outright.
     */
    private static function stderrTail(string $path): string
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            $offset = max(0, $size - self::STDERR_TAIL_BYTES);
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                return '';
            }
            $tail = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        // TAB/LF/CR survive {@see Sanitize::untrusted()}; the rtrim above the
        // sweep bounds prose length, the sweep bounds what can repaint.
        return $tail === false ? '' : Sanitize::untrusted(rtrim($tail));
    }

    /**
     * Path to a temp file containing $bytes, reusing a single per-process
     * scratch file and skipping the write when its content is already current
     * (matched by hash). Replaces a fresh tempnam()+write+unlink per frame.
     *
     * @throws \RuntimeException  if the scratch file cannot be created/written
     */
    private static function scratchFile(string $bytes): string
    {
        $hash = hash('xxh3', $bytes);

        $scratch = self::$scratch;
        if ($scratch !== null && is_file($scratch['path'])) {
            if ($scratch['hash'] === $hash) {
                return $scratch['path']; // already current — skip the rewrite
            }
            if (file_put_contents($scratch['path'], $bytes) === false) {
                throw new \RuntimeException(Lang::t('image_source.temp_failed'));
            }
            self::$scratch = ['path' => $scratch['path'], 'hash' => $hash];

            return $scratch['path'];
        }

        $path = tempnam(sys_get_temp_dir(), 'chafa');
        if ($path === false) {
            throw new \RuntimeException(Lang::t('image_source.temp_failed'));
        }
        if (file_put_contents($path, $bytes) === false) {
            @unlink($path);
            throw new \RuntimeException(Lang::t('image_source.temp_failed'));
        }
        self::$scratch = ['path' => $path, 'hash' => $hash];

        // The file outlives each render (that is the point — it is reused), so
        // clean it up when the process ends rather than per-call.
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    /**
     * @internal Test seam — the current reused scratch-file path, or null if
     *           none has been created yet.
     */
    public static function currentScratchPath(): ?string
    {
        return self::$scratch['path'] ?? null;
    }

    /**
     * @internal Test seam — drop and unlink the reused scratch file so the
     *           next render() re-creates it. Never needed in production.
     */
    public static function resetScratch(): void
    {
        if (self::$scratch !== null) {
            @unlink(self::$scratch['path']);
            self::$scratch = null;
        }
    }

    public function name(): string
    {
        return 'chafa';
    }

    /**
     * The CLI options and the output format both change chafa's
     * encoded bytes for identical input, so both ride the fingerprint —
     * Mosaic::chafa('--colors=16') must never serve a '--colors=256'
     * cache entry (or a symbols-format one) back.
     *
     * The binary IDENTITY rides too (finding 015): the resolved absolute
     * path and the probed `--version` banner, each folded to a fixed-width
     * xxh3 segment. Without them an upgraded chafa — or a
     * SUGAR_IMAGE_CHAFA/PATH switch to a differently-built one — keeps
     * its old stable key and serves pre-switch frames forever, silently.
     * First use forces the bounded availability probe (one spawn per
     * process, reaped on budget); when no identity is available both
     * segments are the hashes of '' — a stable key across runs, exactly
     * the no-churn posture the pre-fix code had for that host.
     */
    public function configFingerprint(): string
    {
        return 'opts=' . implode(',', $this->options)
            . '|format=' . ($this->format ?? '')
            . '|binary=' . hash('xxh3', ChafaBinary::path() ?? '')
            . '|version=' . hash('xxh3', self::versionString());
    }

    /**
     * The memoised probe banner; probing on demand so a fingerprint is
     * never computed while the identity is merely UNASKED (an unasked
     * probe would be the same silent-stale door finding 015 closes).
     */
    private static function versionString(): string
    {
        if (self::$version === null) {
            self::available();
        }

        return self::$version ?? '';
    }

    /**
     * Reset the memoised availability check AND the memoised binary
     * resolution, so a later call re-reads {@see ChafaBinary::ENV_OVERRIDE}
     * and re-scans the roster.
     *
     * For long-running CLI tools that may install chafa after startup,
     * call this to force a fresh probe rather than relying on the
     * per-process memoised result.
     */
    public static function reset(): void
    {
        self::$available   = null;
        self::$version     = null;
        self::$renderCache = [];
        self::$reapWaitMs  = null;
        self::$termGraceMs = null;
        self::$drainBudgetMs = null;
        ChafaBinary::reset();
    }

    /**
     * @internal Test seam — bound the reap ladder (natural-exit wait, then
     *           TERM grace) so wedge arms finish in milliseconds instead of
     *           the production seconds. Null restores each default; reset()
     *           clears both.
     */
    public static function setReapBudgetsForTesting(?int $waitMs, ?int $termGraceMs): void
    {
        self::$reapWaitMs  = $waitMs;
        self::$termGraceMs = $termGraceMs;
    }

    /**
     * @internal Test seam — bound drainStdout()'s no-progress budget so the
     *           silent-child arm runs fast; null restores {@see
     *           self::DRAIN_NO_PROGRESS_MS}. reset() clears it.
     */
    public static function setDrainNoProgressBudgetMsForTesting(?int $ms): void
    {
        self::$drainBudgetMs = $ms;
    }

    public function supportsAlpha(): bool
    {
        return true;
    }

    public function isInline(): bool
    {
        return true;
    }

    /**
     * Chafa invokes an external command — no persistent image identity
     * to delete. Returns the empty string.
     */
    public function delete(string $imageId): string
    {
        return '';
    }
}
