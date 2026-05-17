<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Pty\Contract\Termios;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Pty\TermiosFactory;
use SugarCraft\Vcr\Recorder;

/**
 * `candy-vcr record [--output PATH] [--cols N] [--rows N] [--no-ctty] -- <cmd> [args...]`
 *
 * Records a command's PTY session into a candy-vcr cassette: spawns the
 * command under a fresh master/slave PTY pair, drops the host TTY into
 * raw mode, runs the byte pump with a {@see Recorder} tee'd onto every
 * stdin / master-output chunk, then restores the host termios on exit
 * — even when the pump throws.
 *
 * Pairs with {@see ReplayCommand} for round-trip workflows
 * (`record … | replay`), and the existing API-mode capture via
 * `\SugarCraft\Core\Program::withRecorder()` for in-program recording.
 *
 * Wired in plan step P6.5.1; depends on candy-pty's P6.1 recorder tap.
 */
final class RecordCommand implements Command
{
    /** Default cassette path when `--output` is omitted. */
    public const DEFAULT_OUTPUT_PATTERN = 'session-%s.cas';

    /** Default PTY size when --cols / --rows are omitted. */
    public const DEFAULT_COLS = 80;
    public const DEFAULT_ROWS = 24;

    /**
     * Conservative secret-name regex used by `--env` to strip likely
     * credential vars before they reach the cassette. Matches anywhere
     * in the key, case-insensitive — `MYSQL_PASSWORD`, `GITHUB_TOKEN`,
     * `STRIPE_API_KEY` are all stripped. Per the plan's review focus,
     * we'd rather over-strip than leak; callers needing finer control
     * can pass `--env-regex=` to override.
     */
    public const SECRET_KEY_REGEX = '/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i';

    /** Fallback shell when `$SHELL` is empty / unset during `--shell`. */
    public const FALLBACK_SHELL = '/bin/sh';

    /**
     * Termios snapshot held in static scope so the shutdown-function +
     * signal handlers from {@see installRescueHandlers()} can reach it
     * without closures. Wired by P6.5.4 (host termios safety net).
     */
    private static ?Termios $rescueSnapshot = null;

    /**
     * One-shot guard so multiple `record` invocations in the same
     * process do not stack duplicate `register_shutdown_function` /
     * `pcntl_signal` registrations.
     */
    private static bool $rescueInstalled = false;

    /**
     * Path under `sys_get_temp_dir()` where the host TTY's device path
     * is dumped while recording is in flight. If a SIGKILL-class exit
     * leaves the host termios stuck in raw mode, a separate process
     * (or the user's next shell) can `stty sane < $tty` against the
     * device named in this marker file. Cleared by
     * {@see rescueRestore()} on every clean exit path.
     */
    private static string $rescueMarkerPath = '';

    /**
     * @param resource|null $stdin  Host stdin stream — defaults to the
     *                              STDIN constant so the CLI works
     *                              against the real TTY. Tests inject
     *                              `fopen('/dev/null', 'r')` so the
     *                              recorder can run headless.
     */
    public function __construct(
        private $stdin = null,
    ) {}

    public function summary(): string
    {
        return 'Record a command\'s PTY session into a cassette';
    }

    public function run(array $args, $stdout, $stderr): int
    {
        try {
            $opts = $this->parseArgs($args, $stderr);
        } catch (\InvalidArgumentException $e) {
            \fwrite($stderr, "candy-vcr record: {$e->getMessage()}\n");
            return 2;
        }
        if ($opts === null) {
            return 2;
        }

        $outputPath = $opts['output'] ?? \sprintf(self::DEFAULT_OUTPUT_PATTERN, \date('Ymd-His'));
        $cols = $opts['cols'];
        $rows = $opts['rows'];
        $ctty = $opts['ctty'];
        $cmd  = $opts['cmd'];
        $captureEnv = $opts['captureEnv'];
        $envRegex   = $opts['envRegex'];
        $idleTrim   = $opts['idleTrim'];

        $stdin = $this->stdin ?? \STDIN;

        $savedTermios = null;
        $pair = null;
        $recorder = null;
        $exitCode = 1;

        try {
            $savedTermios = $this->captureHostTermios($stderr);
            if ($savedTermios !== null) {
                self::installRescueHandlers($savedTermios);
            }

            $system = PtySystemFactory::default();
            $pair = $system->open($cols, $rows);

            $env = $this->captureEnv();
            $child = $pair->slave()->spawn(
                $cmd,
                $env,
                $cols,
                $rows,
                controllingTerminal: $ctty,
            );

            $headerEnv = $captureEnv ? self::filteredHostEnv($envRegex) : [];
            $recorder = Recorder::open(
                $outputPath,
                new \SugarCraft\Vcr\CassetteHeader(
                    version: \SugarCraft\Vcr\CassetteHeader::CURRENT_VERSION,
                    createdAt: \gmdate('Y-m-d\TH:i:s\Z'),
                    cols: $cols,
                    rows: $rows,
                    runtime: 'sugarcraft/candy-vcr@record',
                    env: $headerEnv,
                ),
            );
            if ($idleTrim !== null) {
                $recorder->withIdleTrim($idleTrim);
            }
            $recorder->recordResize($cols, $rows);

            $pumpOpts = (new PumpOptions())->withRecorder($recorder);
            $exitCode = (new PosixPump())->run(
                $pair->master(),
                $stdin,
                $stdout,
                $child,
                $pumpOpts,
            );
            if ($exitCode === -1) {
                $exitCode = $child->wait();
            }

            $recorder->recordQuit();
        } catch (\Throwable $e) {
            \fwrite($stderr, "candy-vcr record: {$e->getMessage()}\n");
            $exitCode = 1;
        } finally {
            if ($recorder !== null) {
                $recorder->close();
            }
            if ($pair !== null && !$pair->master()->isClosed()) {
                $pair->master()->close();
            }
            if ($savedTermios !== null) {
                try {
                    $savedTermios->restore();
                } catch (\Throwable) {
                    // Best-effort restore; the P6.5.4 shutdown_function +
                    // signal handlers cover the still-armed paths.
                }
            }
            // Clean up the rescue state — the in-band finally took care
            // of restoration on the happy / throw paths.
            self::rescueClear();
        }

        \fwrite($stderr, \sprintf(
            "candy-vcr: recorded %s (exit %d)\n",
            $outputPath,
            $exitCode,
        ));
        return $exitCode;
    }

    /**
     * Parse argv into `{output, cols, rows, ctty, cmd}` — returns null
     * when usage was printed and the caller should exit 2.
     *
     * @param list<string> $args
     * @return array{output: ?string, cols: int, rows: int, ctty: bool, cmd: list<string>, captureEnv: bool, envRegex: string, idleTrim: ?float}|null
     * @param resource $stderr
     */
    private function parseArgs(array $args, $stderr): ?array
    {
        $output = null;
        $cols = self::DEFAULT_COLS;
        $rows = self::DEFAULT_ROWS;
        $ctty = true;
        $shell = false;
        $captureEnv = false;
        $envRegex = self::SECRET_KEY_REGEX;
        $idleTrim = null;
        $cmd = [];
        $inOpts = true;

        $i = 0;
        while ($i < \count($args)) {
            $a = $args[$i];
            if ($inOpts) {
                if ($a === '--') {
                    $inOpts = false;
                    $i++;
                    continue;
                }
                if ($a === '-h' || $a === '--help' || $a === 'help') {
                    $this->printUsage($stderr);
                    return null;
                }
                if (\str_starts_with($a, '--output=')) {
                    $output = \substr($a, 9);
                } elseif ($a === '--output') {
                    $output = $args[++$i] ?? null;
                    if ($output === null) {
                        throw new \InvalidArgumentException('--output requires a path');
                    }
                } elseif (\str_starts_with($a, '--cols=')) {
                    $cols = (int) \substr($a, 7);
                } elseif ($a === '--cols') {
                    $cols = (int) ($args[++$i] ?? 0);
                } elseif (\str_starts_with($a, '--rows=')) {
                    $rows = (int) \substr($a, 7);
                } elseif ($a === '--rows') {
                    $rows = (int) ($args[++$i] ?? 0);
                } elseif ($a === '--no-ctty') {
                    $ctty = false;
                } elseif ($a === '--shell') {
                    $shell = true;
                } elseif ($a === '--env') {
                    $captureEnv = true;
                } elseif (\str_starts_with($a, '--env-regex=')) {
                    $captureEnv = true;
                    $envRegex = \substr($a, 12);
                    if (@\preg_match($envRegex, '') === false) {
                        throw new \InvalidArgumentException("--env-regex='{$envRegex}' is not a valid PCRE pattern");
                    }
                } elseif (\str_starts_with($a, '--idle-trim=')) {
                    $idleTrim = (float) \substr($a, 12);
                    if ($idleTrim <= 0.0) {
                        throw new \InvalidArgumentException('--idle-trim must be > 0 seconds');
                    }
                } elseif ($a === '--idle-trim') {
                    $next = $args[++$i] ?? null;
                    if ($next === null) {
                        throw new \InvalidArgumentException('--idle-trim requires a positive seconds argument');
                    }
                    $idleTrim = (float) $next;
                    if ($idleTrim <= 0.0) {
                        throw new \InvalidArgumentException('--idle-trim must be > 0 seconds');
                    }
                } elseif (\str_starts_with($a, '--')) {
                    throw new \InvalidArgumentException("unknown option {$a}");
                } else {
                    $inOpts = false;
                    $cmd[] = $a;
                }
            } else {
                $cmd[] = $a;
            }
            $i++;
        }

        if ($shell) {
            if ($cmd !== []) {
                throw new \InvalidArgumentException('--shell may not be combined with a positional <cmd>');
            }
            $cmd = self::shellCommand();
        }

        if ($cmd === []) {
            $this->printUsage($stderr);
            return null;
        }
        if ($cols <= 0 || $rows <= 0) {
            throw new \InvalidArgumentException("--cols and --rows must be positive integers (cols={$cols} rows={$rows})");
        }

        return [
            'output' => $output,
            'cols'   => $cols,
            'rows'   => $rows,
            'ctty'   => $ctty,
            'cmd'    => $cmd,
            'captureEnv' => $captureEnv,
            'envRegex'   => $envRegex,
            'idleTrim'   => $idleTrim,
        ];
    }

    /**
     * Resolve the shell to invoke for `--shell`: honour `$SHELL`,
     * fall back to {@see FALLBACK_SHELL}. Always passes `-l` so the
     * recorded session runs as a login shell (sources the user's
     * profile and reads `$HOME/.profile` chain just like a fresh
     * terminal login).
     *
     * @return list<string>
     */
    private static function shellCommand(): array
    {
        $shell = (string) (\getenv('SHELL') ?: '');
        if ($shell === '' || !\is_executable($shell)) {
            $shell = self::FALLBACK_SHELL;
        }
        return [$shell, '-l'];
    }

    /**
     * Install the safety-net handlers so a SIGTERM, SIGHUP, fatal
     * error, or unclean exit still restores the host termios. The
     * snapshot is held statically because shutdown_function and
     * pcntl_signal cannot capture closures with arbitrary instance
     * state (in particular, the recorder's own snapshot disappears as
     * soon as the run() frame unwinds).
     *
     * Handlers are signal-safe: no allocation, no logging, just a
     * direct `restore()` then a marker-file unlink. SIGKILL still
     * can't be intercepted — that's documented; the marker file at
     * {@see $rescueMarkerPath} points at the host's tty path so an
     * external recovery (`stty sane < $tty`) is at least possible.
     */
    private static function installRescueHandlers(Termios $snapshot): void
    {
        self::$rescueSnapshot = $snapshot;
        self::writeRescueMarker();

        if (self::$rescueInstalled) {
            return;
        }
        self::$rescueInstalled = true;

        \register_shutdown_function([self::class, 'rescueRestore']);

        if (\function_exists('pcntl_signal') && \function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            \pcntl_signal(\SIGTERM, [self::class, 'handleRescueSignal']);
            \pcntl_signal(\SIGHUP,  [self::class, 'handleRescueSignal']);
        }
    }

    /**
     * Signal-safe restore. Called both from the shutdown function and
     * from the SIGTERM / SIGHUP handlers — idempotent so it's safe to
     * fire from either entry point.
     *
     * @internal
     */
    public static function rescueRestore(): void
    {
        $snapshot = self::$rescueSnapshot;
        if ($snapshot === null) {
            return;
        }
        self::$rescueSnapshot = null;
        try {
            $snapshot->restore();
        } catch (\Throwable) {
            // Best-effort: the handler runs after run()'s finally on
            // every other path. Swallow any late-restore failure.
        }
        if (self::$rescueMarkerPath !== '' && \file_exists(self::$rescueMarkerPath)) {
            @\unlink(self::$rescueMarkerPath);
        }
        self::$rescueMarkerPath = '';
    }

    /**
     * SIGTERM / SIGHUP handler. Restores then re-raises with the
     * default action so the process actually dies with the right
     * status code instead of being trapped indefinitely.
     *
     * @internal
     */
    public static function handleRescueSignal(int $signo): void
    {
        self::rescueRestore();
        if (\function_exists('pcntl_signal') && \function_exists('posix_kill') && \function_exists('posix_getpid')) {
            \pcntl_signal($signo, \SIG_DFL);
            @\posix_kill(\posix_getpid(), $signo);
            return;
        }
        // Fallback if pcntl/posix are unavailable — exit with the
        // conventional 128 + signal status so callers can still see
        // what killed us.
        exit(128 + $signo);
    }

    /**
     * Internal: clear the rescue state without firing restore. Called
     * from `run()`'s finally after the in-band restore has already
     * succeeded — we don't want the shutdown_function to run a second
     * restore against a freed Termios.
     *
     * @internal
     */
    private static function rescueClear(): void
    {
        self::$rescueSnapshot = null;
        if (self::$rescueMarkerPath !== '' && \file_exists(self::$rescueMarkerPath)) {
            @\unlink(self::$rescueMarkerPath);
        }
        self::$rescueMarkerPath = '';
    }

    /**
     * Drop a tiny marker file under sys_get_temp_dir() pointing at
     * the host's tty path (looked up via posix_ttyname when available).
     * If a SIGKILL-class exit leaves the terminal in raw mode, the
     * user can `stty sane < $tty` against the recorded device.
     */
    private static function writeRescueMarker(): void
    {
        $ttyPath = null;
        if (\function_exists('posix_ttyname')) {
            $maybe = @\posix_ttyname(\STDIN);
            if (\is_string($maybe) && $maybe !== '') {
                $ttyPath = $maybe;
            }
        }
        if ($ttyPath === null) {
            return;
        }
        $path = \sys_get_temp_dir() . '/candy-vcr-rescue.' . \getmypid();
        $payload = "tty={$ttyPath}\npid=" . \getmypid() . "\nstarted=" . \date('c') . "\n";
        if (@\file_put_contents($path, $payload) !== false) {
            self::$rescueMarkerPath = $path;
        }
    }

    /**
     * Snapshot the current process env, dropping any key that matches
     * the configured secret regex. Keys are sorted alphabetically so
     * the cassette diff stays stable across runs.
     *
     * @return array<string, string>
     */
    public static function filteredHostEnv(string $regex = self::SECRET_KEY_REGEX): array
    {
        // `getenv()` (no args) returns the full process env on PHP 8.3.
        // Fall back to `$_SERVER` if for some reason it's disabled.
        $env = \getenv();
        if (!\is_array($env) || $env === []) {
            $env = [];
            foreach ($_SERVER as $k => $v) {
                if (\is_string($k) && \is_string($v)) {
                    $env[$k] = $v;
                }
            }
        }

        $kept = [];
        foreach ($env as $k => $v) {
            if (!\is_string($k) || $k === '' || !\is_string($v)) {
                continue;
            }
            if (@\preg_match($regex, $k) === 1) {
                continue;
            }
            $kept[$k] = $v;
        }
        \ksort($kept);
        return $kept;
    }

    /**
     * @param resource $stream
     */
    private function printUsage($stream): void
    {
        \fwrite(
            $stream,
            "usage: candy-vcr record [--output PATH] [--cols N] [--rows N] [--no-ctty]\n"
            . "                        [--shell] [--env] [--env-regex=PATTERN] -- <cmd> [args...]\n"
            . "\n"
            . "  --output PATH    Cassette file to write (default: session-<timestamp>.cas)\n"
            . "  --cols N         Initial terminal columns (default: 80)\n"
            . "  --rows N         Initial terminal rows (default: 24)\n"
            . "  --no-ctty        Spawn without a controlling terminal (Ctrl+C will not\n"
            . "                   reach the recorded program; default: ctty enabled)\n"
            . "  --shell          Spawn \$SHELL -l (or /bin/sh -l) instead of an explicit\n"
            . "                   <cmd>. Mutually exclusive with positional <cmd>.\n"
            . "  --env            Capture the host environment into the cassette header.\n"
            . "                   Off by default — env capture is opt-in to avoid leaking\n"
            . "                   the caller's full shell environment. Keys matching the\n"
            . "                   secret regex (see --env-regex) are stripped.\n"
            . "  --env-regex=RE   Override the secret-stripping regex (default conservative\n"
            . "                   /(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i).\n"
            . "                   Implies --env.\n"
            . "  --idle-trim N    Compress inter-event gaps longer than N seconds. Trimmed\n"
            . "                   events carry both `t` (compressed) and `tRaw` (original)\n"
            . "                   so `replay --no-trim` can opt back into the real cadence.\n",
        );
    }

    /**
     * Snapshot the host stdin termios and flip it into raw mode so the
     * recorded program sees every keystroke unbuffered. Returns the
     * saved snapshot so {@see run()} can restore on exit. Returns null
     * when stdin is not a tty (e.g. piped input in tests) — restore is
     * then a no-op.
     *
     * @param resource $stderr
     */
    private function captureHostTermios($stderr): ?Termios
    {
        try {
            $termios = TermiosFactory::open(0);
        } catch (\Throwable $e) {
            \fwrite($stderr, "candy-vcr record: termios snapshot skipped ({$e->getMessage()})\n");
            return null;
        }

        if (!$termios->isAtty()) {
            return null;
        }

        try {
            $raw = $termios->makeRaw();
            $raw->apply();
        } catch (\Throwable $e) {
            \fwrite($stderr, "candy-vcr record: raw-mode apply failed ({$e->getMessage()})\n");
            return null;
        }
        return $termios;
    }

    /**
     * Capture a minimal env for the recorded child. Full env capture
     * (with secret stripping) lands in P6.5.2 behind --env.
     *
     * @return array<string, string>
     */
    private function captureEnv(): array
    {
        return [
            'TERM' => \getenv('TERM') !== false ? (string) \getenv('TERM') : 'xterm-256color',
            'PATH' => \getenv('PATH') !== false ? (string) \getenv('PATH') : '/usr/bin:/bin',
            'HOME' => \getenv('HOME') !== false ? (string) \getenv('HOME') : '/tmp',
            'LANG' => \getenv('LANG') !== false ? (string) \getenv('LANG') : 'C.UTF-8',
        ];
    }
}
