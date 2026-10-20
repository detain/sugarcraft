<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;

/**
 * Chafa graphics renderer — invokes the chafa command-line tool.
 *
 * Chafa is a command-line image-to-terminal converter that supports
 * true-color, transparency, and various output formats.
 */
final class ChafaRenderer implements Renderer
{
    use \SugarCraft\Image\Concerns\RenderValidationTrait;

    /**
     * D-07: hard ceiling on the bytes the render keeps from the child's
     * stdout. Legitimate chafa output at poster scale is a few megabytes;
     * anything past 32 MiB is runaway, not art. The drain keeps the first
     * this-many bytes and keeps reading to EOF beyond it (drain-to-null),
     * so the child never blocks on a full pipe nor dies of EPIPE.
     *
     * RULING (review s1-6 m1, accepted): a SUCCESS truncated at this cap
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

    /** D-07: read chunk for {@see drainStdout()}. */
    private const STDOUT_CHUNK_BYTES = 65536;

    /**
     * D-07: how many trailing bytes of the child's stderr sink ride into
     * the failure message — chafa's own sentences are one-liners, and 8 KiB
     * bounds the exception even if the child flooded diagnostics.
     */
    public const STDERR_TAIL_BYTES = 8 * 1024;

    /** D-07: post-stdout-EOF budget for the child to exit on its own. */
    private const REAP_WAIT_MS = 5000;

    /** D-07: budget a SIGTERM gets before the ladder reaches for SIGKILL. */
    private const TERM_GRACE_MS = 1000;

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
     * Whether the `chafa` binary is on PATH. Probed once per process (the result
     * is memoised) so a per-frame video render does not spawn a probe each frame.
     */
    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $proc = @proc_open(
            ['chafa', '--version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
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
            return self::$available = false;
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return self::$available = (proc_close($proc) === 0);
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

        $size = "{$width}x{$effectiveHeight}";
        $cmd = ['chafa', '--size=' . $size];
        if ($this->format !== null) {
            $cmd[] = '--format=' . $this->format;
        }
        $cmd = array_merge($cmd, $this->options);

        $cmd[] = self::scratchFile($image->bytes);

        // D-07: the child's diagnostics go to a private file sink, never to
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

        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['file', $errPath, 'w']];
        $process = @proc_open($cmd, $descriptorSpec, $pipes);

        try {
            if ($process === false) {
                foreach ($pipes ?? [] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                throw new \RuntimeException(Lang::t('chafa.not_found'));
            }

            if (!is_resource($process)) {
                throw new \RuntimeException(Lang::t('chafa.command_failed', ['error' => 'proc_open returned false']));
            }

            $stdout = self::drainStdout($pipes[1]);
            fclose($pipes[1]);

            $exitCode = self::reap($process);

            if ($exitCode !== 0) {
                $tail = self::stderrTail($errPath);
                throw new \RuntimeException(
                    Lang::t('chafa.command_failed', [
                        'error' => $tail === '' ? "exit code $exitCode" : "exit code $exitCode: $tail",
                    ])
                );
            }
        } finally {
            // Every settle path — success, failure, or a throw between the
            // spawn and here — releases the sink file.
            @unlink($errPath);
        }

        return $stdout;
    }

    /**
     * Read the child's stdout under {@see MAX_STDOUT_BYTES}: keep the head,
     * drain the remainder to null. Returning early without draining would
     * hand the child an EPIPE mid-write — a truncated encode reported as a
     * render we asked for. A blocking pipe never returns an empty success,
     * so "" (EOF or dead-pipe) ends the loop.
     *
     * @param resource $pipe
     */
    private static function drainStdout($pipe): string
    {
        $stdout = '';
        while (true) {
            $chunk = fread($pipe, self::STDOUT_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $room = self::MAX_STDOUT_BYTES - strlen($stdout);
            if ($room > 0) {
                $stdout .= strlen($chunk) <= $room ? $chunk : substr($chunk, 0, $room);
            }
            if (feof($pipe)) {
                break;
            }
        }

        return $stdout;
    }

    /**
     * Reap ladder for a child whose stdout has already EOF-ed: poll for a
     * natural exit, escalate TERM(15) -> KILL(9), always end in
     * proc_close() so the wait happens there regardless.
     *
     * The deadlines here are a test/slice budget, NEVER a kill-on-request-
     * time policy (E646 blanket-timeout ban): this function puts no bound
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
        $deadline = microtime(true) + self::REAP_WAIT_MS / 1000;
        while (self::childRunning($process) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if (self::childRunning($process)) {
            proc_terminate($process, 15);
            $grace = microtime(true) + self::TERM_GRACE_MS / 1000;
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
     * exit code is already in the message around this tail.
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

        return $tail === false ? '' : rtrim($tail);
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
     * Reset the memoised availability check.
     *
     * For long-running CLI tools that may install chafa after startup,
     * call this to force a fresh probe rather than relying on the
     * per-process memoised result.
     */
    public static function reset(): void
    {
        self::$available = null;
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
