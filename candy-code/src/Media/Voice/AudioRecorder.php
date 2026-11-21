<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Voice;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\VoiceTakeStoppedMsg;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use Throwable;

use function React\Promise\resolve;

/**
 * Captures one microphone take to a WAV file (C-TUI-6.l, GAP-MOD-23).
 *
 * THE BACKEND LADDER IS EXISTENCE, NOT HOPE. `arecord` (ALSA) first, then
 * `sox`, located through {@see ProcessContainment::locateOnPath()} so the
 * same lookup answers on Windows ({@see WindowsShell::locate()}). When
 * neither binary exists — every stock macOS and Windows box, and any Linux
 * host without alsa-utils or sox — the recorder reports `no_backend`.
 * That is the ruling's posture: reported, never faked. There is no silent
 * empty take anywhere in this family: every failure path carries a token
 * the session turns into a visible refusal.
 *
 * THE CAP LIVES IN THE ARGV, NOT IN A TIMER. arecord gets `-d <max>` and
 * sox gets `trim 0 <max>`, both from `voice.maxSeconds` (clamped 5-600 in
 * {@see VoiceConfig}). The recorder child therefore stops itself even when
 * this process dies beside it — an orphaned take is a capped take. That is
 * the only ceiling on the child; there is deliberately NO wall-clock kill
 * of a recording the user is still speaking into (E646's direction: bound
 * the operation, do not timeout the user).
 *
 * THE TAKE LANDS AT 0600 UNDER AN OWNED 0700 ROOT. The directory is
 * `~/.candy-code/cache/voice/`, created 0700 and then re-verified by
 * {@see PrivateDir::ensure()} — a planted symlink or a widened mode
 * refuses the take instead of following it. The file is reserved with
 * fopen('xb') under umask 0077 before the child starts, so the mode is
 * settled on the temp inode before any audio ever hits the disk, and the
 * recorder's own truncation keeps that mode (same inode).
 *
 * STOP IS SIGINT, THEN THE LADDER, AND IT IS A CMD. arecord and sox
 * finalize the WAV header on SIGINT, so a stopped take is a readable file;
 * only if the child ignores it does the TERM/KILL escalation ladder run.
 * {@see stop()} returns that sequence as a Cmd whose waits are loop timers
 * ({@see BoundedShutdown::escalateAsync()} for the ladder), so a recorder
 * that ignores SIGINT costs the TUI nothing while the grace runs (AUD §7
 * rank 6: the old blocking stop froze the UI for up to about 2.5 s). A
 * cancel (Esc) skips the finalize — the take is being thrown away — deletes
 * the file at once and runs the TERM/KILL ladder on the same loop timers, so
 * the Esc press returns at once too. Only the exit path
 * ({@see teardownAll()}) keeps the synchronous ladder
 * ({@see BoundedShutdown::escalate()}): the shutdown hook runs with no loop
 * left to yield to, and it finishes any cancel still in flight.
 *
 * NO AUDIO BYTES ARE EVER LOGGED. Nothing here reads the file: the child
 * writes it, {@see Transcriber::transcribe()} uploads it once and unlinks
 * it, and stdout/stderr of the child are pinned to /dev/null so a chatty
 * recorder cannot leak a path or level into the transcript.
 */
final class AudioRecorder
{
    public const BACKEND_ALSA = 'arecord';
    public const BACKEND_SOX = 'sox';

    /** Refusal tokens; the session maps them through Lang. */
    public const REFUSAL_NO_BACKEND = 'no_backend';
    public const REFUSAL_NO_HOME = 'no_home';
    public const REFUSAL_CACHE_REFUSED = 'cache_refused';
    public const REFUSAL_SPAWN_FAILED = 'spawn_failed';

    /** Bytes a finalized WAV header occupies: a take no larger than this captured no samples. */
    public const WAV_HEADER_BYTES = 44;

    /** SIGINT finalizes the header on both backends; an integer literal per the BoundedShutdown signal rule. */
    private const SIGNAL_INTERRUPT = 2;

    /** How long a well-behaved recorder may take to act on SIGINT before the ladder runs. */
    private const INTERRUPT_GRACE_SECONDS = 1.5;

    private const TERMINATE_GRACE_SECONDS = 0.5;
    private const KILL_GRACE_SECONDS = 0.5;
    private const POLL_INTERVAL_US = 2_000;

    /** @var array<string, array{process: mixed, startedAt: float}> live takes keyed by WAV path */
    private static array $live = [];

    /**
     * Takes whose stop Cmd has been issued but whose recorder has not been
     * seen gone yet, keyed by WAV path. Kept so a stop Cmd that never runs
     * (a dropped Cmd, a test that only inspects it) still leaves the child
     * reachable by {@see resetForTesting()} and the shutdown teardown.
     *
     * @var array<string, mixed>
     */
    private static array $stopping = [];

    /**
     * The ladder timer of each cancel still in flight, keyed by WAV path, so
     * the exit path and {@see resetForTesting()} can disarm it once they have
     * torn the child down themselves.
     *
     * @var array<string, TimerInterface>
     */
    private static array $cancelTimers = [];

    /**
     * First backend on PATH, or '' when neither exists. Injectable so the
     * argv ladder and the refusal path are testable on a host that does
     * have a microphone (this file's own tests run anywhere).
     *
     * @param (callable(string): string)|null $locator
     */
    public static function probe(?callable $locator = null): string
    {
        $find = $locator ?? static fn(string $binary): string => ProcessContainment::locateOnPath($binary);

        foreach ([self::BACKEND_ALSA, self::BACKEND_SOX] as $binary) {
            if ($find($binary) !== '') {
                return $binary;
            }
        }

        return '';
    }

    /**
     * The argv for one take, pinned by {@see AudioRecorderTest}. The path is
     * absolute by the time it reaches here, so no cwd dependency rides along.
     *
     * @return list<string>
     */
    public static function argv(string $backend, int $maxSeconds, string $wavPath): array
    {
        $cap = (string) $maxSeconds;

        return match ($backend) {
            self::BACKEND_ALSA => [
                self::BACKEND_ALSA,
                '-q',
                '-f',
                'S16_LE',
                '-r',
                '16000',
                '-c',
                '1',
                '-d',
                $cap,
                $wavPath,
            ],
            self::BACKEND_SOX => [
                self::BACKEND_SOX,
                '-q',
                '-d',
                '-r',
                '16000',
                '-c',
                '1',
                '-b',
                '16',
                $wavPath,
                'trim',
                '0',
                $cap,
            ],
            default => [],
        };
    }

    /**
     * Start a take. Returns `recording` (and it is non-null only when the
     * child is actually running) or a refusal `token` with a `reason` for
     * the tokens that have one. Never both, never neither-without-a-token.
     *
     * @param (callable(string): string)|null $locator
     * @return array{recording: ?AudioRecording, token: string, reason: string}
     */
    public static function start(VoiceConfig $config, ?callable $locator = null): array
    {
        $refuse = static fn(string $token, string $reason = ''): array =>
            ['recording' => null, 'token' => $token, 'reason' => $reason];

        $backend = self::probe($locator);
        if ($backend === '') {
            return $refuse(self::REFUSAL_NO_BACKEND);
        }

        $home = HomeDirectory::owned();

        if ($home === null) {
            return $refuse(self::REFUSAL_NO_HOME);
        }

        $root = \rtrim($home, '/') . '/.candy-code/cache';

        if (!\is_dir($root) && !self::mkdir0700($root)) {
            return $refuse(self::REFUSAL_NO_HOME);
        }

        $dir = $root . '/voice';
        $whyDir = '';

        if (!\is_dir($dir) && !self::mkdir0700($dir)) {
            $whyDir = 'the voice cache directory could not be created';
        }

        if ($whyDir === '') {
            try {
                // ensure() re-verifies 0700 + ownership + not-a-symlink on
                // every take; a widened or planted directory refuses, it is
                // never followed (PrivateDir law).
                PrivateDir::ensure($dir, 'voice recordings');
            } catch (Throwable $refusal) {
                $whyDir = $refusal->getMessage();
            }
        }

        if ($whyDir !== '') {
            return $refuse(self::REFUSAL_CACHE_REFUSED, $whyDir);
        }

        // pid prefix AND the more-entropy flag (ProcessUniqueTempName law):
        // two recorders in one instant must never reserve the same take path.
        $wav = $dir . '/take-' . \gmdate('Ymd-His') . '-' . \uniqid(getmypid() . '_', true) . '.wav';
        $reserve = self::reserveTake($wav);

        if ($reserve !== '') {
            return $refuse(self::REFUSAL_CACHE_REFUSED, $reserve);
        }

        $argv = self::argv($backend, $config->maxSeconds, $wav);
        $process = self::spawn($argv);

        if ($process === null) {
            @\unlink($wav);

            return $refuse(self::REFUSAL_SPAWN_FAILED, 'the recorder child could not be started');
        }

        self::$live[$wav] = ['process' => $process, 'startedAt' => \microtime(true)];

        return [
            'recording' => new AudioRecording($wav, $backend, $config->maxSeconds, \microtime(true)),
            'token' => '',
            'reason' => '',
        ];
    }

    /**
     * End a take, as a Cmd: SIGINT (header finalize), a bounded grace, then
     * the TERM/KILL ladder, every wait a loop timer so the TUI keeps
     * painting. The Cmd resolves to a {@see VoiceTakeStoppedMsg} once the
     * recorder is gone (or its kill budget is spent); `hasAudio` is false
     * when the take left no readable sample behind, since an
     * interrupted-then-killed recorder can die before writing anything.
     *
     * The take leaves the live set at once, so a cancel of the same take
     * cannot signal it twice, and a second stop of a take whose child is
     * still pending returns a Cmd that resolves to no Msg: only the first
     * Cmd drives the ladder and answers with the take. The child stays
     * reachable through the pending-stop set until the ladder sees it gone.
     * A take with no recorder behind it resolves on the Cmd's first run.
     *
     * @return \Closure(): \SugarCraft\Core\AsyncCmd
     */
    public static function stop(AudioRecording $recording): \Closure
    {
        $entry = self::$live[$recording->path] ?? null;

        if ($entry === null && \array_key_exists($recording->path, self::$stopping)) {
            return Cmd::promise(static fn (): PromiseInterface => resolve(null));
        }

        if ($entry !== null) {
            unset(self::$live[$recording->path]);
            self::$stopping[$recording->path] = $entry['process'];
        }

        return Cmd::promise(static function () use ($recording): PromiseInterface {
            $deferred = new Deferred();
            $settle = static function () use ($recording, $deferred): void {
                unset(self::$stopping[$recording->path]);
                $deferred->resolve(new VoiceTakeStoppedMsg($recording, self::hasAudio($recording->path)));
            };

            $process = self::$stopping[$recording->path] ?? null;

            if ($process === null || self::gone($process)) {
                self::reap($process);
                $settle();

                return $deferred->promise();
            }

            self::interruptThenEscalate(Loop::get(), $process, $settle);

            return $deferred->promise();
        });
    }

    /** True while a stop Cmd or a cancel is still waiting on a recorder child. */
    public static function stopping(): bool
    {
        return self::$stopping !== [];
    }

    /**
     * Throw the take away: no finalize wait, delete the file either way. The
     * TERM/KILL ladder runs on loop timers, because the Esc press that calls
     * this runs inside update() with the loop live; the child stays in the
     * pending set until the ladder sees it gone, so a new take is refused
     * while it may still hold the microphone and the exit path still
     * reaches it.
     */
    public static function cancel(AudioRecording $recording): void
    {
        $entry = self::$live[$recording->path] ?? null;

        if ($entry !== null) {
            unset(self::$live[$recording->path]);
            self::teardownAsync($recording->path, $entry['process']);
        }

        @\unlink($recording->path);
    }

    /**
     * Synchronous teardown of every recorder this process still holds, live
     * or mid-stop, for the exit path: once the process is exiting no loop
     * timer will run, so a pending stop Cmd would otherwise leave its child
     * behind. Live takes are deleted (an abandoned take is a cancelled one);
     * a take whose stop was already issued keeps its file.
     */
    public static function teardownAll(): void
    {
        foreach (self::$live as $path => $entry) {
            self::teardown($entry['process']);
            @\unlink($path);
        }

        foreach (self::$stopping as $process) {
            self::teardown($process);
        }

        self::disarmCancelTimers();
        self::$live = [];
        self::$stopping = [];
    }

    /** A take whose file holds more than a bare header carries samples. */
    public static function hasAudio(string $wavPath): bool
    {
        // The recorder child writes the take OUTSIDE this process, long after
        // reserveTake()'s fopen/chmod filled PHP's per-path stat cache;
        // without this drop, a filled take could answer from the cached
        // zero-byte size and stop() would report a silent-empty lie.
        \clearstatcache(true, $wavPath);
        if (!\is_file($wavPath)) {
            return false;
        }

        $size = @\filesize($wavPath);

        return \is_int($size) && $size > self::WAV_HEADER_BYTES;
    }

    /** Drop live-take state between tests (the StatusLineCommand resetForTesting law). */
    public static function resetForTesting(): void
    {
        foreach (self::$live as $entry) {
            self::teardown($entry['process']);
        }

        foreach (self::$stopping as $process) {
            self::teardown($process);
        }

        self::disarmCancelTimers();
        self::$live = [];
        self::$stopping = [];
    }

    /** Create one directory at 0700; an existing one is verified by the caller's PrivateDir gate, never re-permissioned. */
    private static function mkdir0700(string $dir): bool
    {
        $previous = \umask(0o077);
        $made = @\mkdir($dir, 0700, true);
        \umask($previous);

        if ($made) {
            // mkdir is umask-filtered; re-assert the mode on the directory
            // this call created (PrivateDir::ensure precedent).
            @\chmod($dir, 0700);
        }

        return \is_dir($dir);
    }

    /**
     * Reserve the take path so its mode is settled before any audio exists:
     * lstat plant refusal first (a pre-existing or symlinked target is
     * never adopted), then fopen('xb') under a narrowed umask, then chmod
     * 0600 on the fresh inode (MediaStore::publish law). The recorder
     * truncates the file it is handed — truncation keeps the inode and its
     * mode — so 0600 is the mode every byte is ever written under.
     *
     * @return string the refusal reason, or '' on success
     */
    private static function reserveTake(string $wav): string
    {
        if (@\lstat($wav) !== false) {
            return "a take file already sits at {$wav}";
        }

        $previous = \umask(0o077);

        try {
            $handle = @\fopen($wav, 'xb');
        } finally {
            \umask($previous);
        }

        if ($handle === false) {
            return \error_get_last()['message'] ?? 'the take file could not be created';
        }

        \fclose($handle);

        if (@\chmod($wav, 0o600) === false) {
            @\unlink($wav);

            return "the take file's mode could not be settled at {$wav}";
        }

        return '';
    }

    /**
     * Spawn the recorder: containment-wrapped argv, stdio pinned to
     * /dev/null, credentials stripped. The take itself is the file on
     * disk; there is nothing to read off the child.
     *
     * @param list<string> $argv
     */
    private static function spawn(array $argv): mixed
    {
        $command = ProcessContainment::spawnSpec($argv);
        $devNull = '/dev/null';
        $descriptors = [
            0 => ['file', $devNull, 'r'],
            1 => ['file', $devNull, 'w'],
            2 => ['file', $devNull, 'w'],
        ];
        $process = @\proc_open($command, $descriptors, $pipes, null, ProcessContainment::scrubbedEnv());

        return \is_resource($process) ? $process : null;
    }

    private static function signal(mixed $process, int $signal): void
    {
        ProcessContainment::terminate($process, $signal);
    }

    /**
     * The stop sequence on loop timers: SIGINT, a poll of up to
     * {@see INTERRUPT_GRACE_SECONDS}, then {@see BoundedShutdown::escalateAsync()}
     * with the same TERM and KILL budgets the synchronous teardown uses.
     * `$then` runs once the child is seen gone, or once the kill budget is
     * spent: a child that outlives signal 9 is in an uninterruptible kernel
     * wait, so its reap is left to {@see BoundedShutdown::reapWhenExited()}
     * rather than holding the take back from the user.
     *
     * @param \Closure(): void $then
     */
    private static function interruptThenEscalate(LoopInterface $loop, mixed $process, \Closure $then): void
    {
        self::signal($process, self::SIGNAL_INTERRUPT);
        $deadline = self::now() + self::INTERRUPT_GRACE_SECONDS;
        $timer = null;
        $timer = $loop->addPeriodicTimer(
            self::POLL_INTERVAL_US / 1_000_000,
            static function () use (&$timer, $loop, $process, $deadline, $then): void {
                if (self::gone($process)) {
                    $loop->cancelTimer($timer);
                    self::reap($process);
                    $then();

                    return;
                }

                if (self::now() < $deadline) {
                    return;
                }

                $loop->cancelTimer($timer);
                BoundedShutdown::escalateAsync(
                    $loop,
                    static function (int $signal) use ($process): void {
                        self::signal($process, $signal);
                    },
                    static fn (): bool => self::gone($process),
                    static function (bool $gone) use ($loop, $process, $then): void {
                        if ($gone) {
                            self::reap($process);
                        } elseif (\is_resource($process)) {
                            BoundedShutdown::reapWhenExited($loop, $process);
                        }

                        $then();
                    },
                    self::TERMINATE_GRACE_SECONDS,
                    self::KILL_GRACE_SECONDS,
                );
            },
        );
    }

    /**
     * A closed handle counts as gone: the shutdown teardown may close a child
     * while its stop Cmd's timers are still armed.
     */
    private static function gone(mixed $process): bool
    {
        if (!\is_resource($process)) {
            return true;
        }

        return (bool) (\proc_get_status($process)['running'] ?? false) === false;
    }

    /** Close a handle the poll already saw exit, so the close cannot wait. */
    private static function reap(mixed $process): void
    {
        if (\is_resource($process)) {
            @\proc_close($process);
        }
    }

    /** Monotonic seconds, the clock {@see BoundedShutdown} runs its deadlines on. */
    private static function now(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /**
     * The cancel's ladder: TERM then KILL on loop timers with the same
     * budgets as {@see teardown()}, the child held in the pending set until
     * it is seen gone (or its kill budget is spent, when the reap is left to
     * {@see BoundedShutdown::reapWhenExited()}).
     */
    private static function teardownAsync(string $path, mixed $process): void
    {
        if (!\is_resource($process)) {
            return;
        }

        if (self::gone($process)) {
            self::reap($process);

            return;
        }

        $loop = Loop::get();
        self::$stopping[$path] = $process;
        self::$cancelTimers[$path] = BoundedShutdown::escalateAsync(
            $loop,
            static function (int $signal) use ($process): void {
                self::signal($process, $signal);
            },
            static fn (): bool => self::gone($process),
            static function (bool $gone) use ($loop, $path, $process): void {
                if ($gone) {
                    self::reap($process);
                } elseif (\is_resource($process)) {
                    BoundedShutdown::reapWhenExited($loop, $process);
                }

                if ((self::$stopping[$path] ?? null) === $process) {
                    unset(self::$stopping[$path], self::$cancelTimers[$path]);
                }
            },
            self::TERMINATE_GRACE_SECONDS,
            self::KILL_GRACE_SECONDS,
        );
    }

    /** Disarm every cancel ladder still on the loop; its child was already torn down. */
    private static function disarmCancelTimers(): void
    {
        foreach (self::$cancelTimers as $timer) {
            Loop::get()->cancelTimer($timer);
        }

        self::$cancelTimers = [];
    }

    /**
     * TERM then KILL through the shared synchronous ladder, then close the
     * handle. Used by the exit path only; {@see stop()} and {@see cancel()}
     * run the same budgets on the loop.
     */
    private static function teardown(mixed $process): void
    {
        if (!\is_resource($process)) {
            return;
        }

        BoundedShutdown::escalate(
            static function (int $signal) use ($process): void {
                self::signal($process, $signal);
            },
            static fn (): bool => self::gone($process),
            self::TERMINATE_GRACE_SECONDS,
            self::KILL_GRACE_SECONDS,
        );

        @\proc_close($process);
    }
}
