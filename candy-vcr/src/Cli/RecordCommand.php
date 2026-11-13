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
 * `\SugarCraft\Core\Program::setRecorder()` for in-program recording.
 *
 * Wired behind the record verb; depends on sugar-pty's recorder tap.
 */
final class RecordCommand implements DocumentedCommand
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
     * without closures. Wired as the host termios safety net.
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
    ) {
    }

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
        // Recorder::open() writes plain JSONL bytes; naming that file .gz
        // produced a lying artifact — a "gzip cassette" gunzip refuses.
        // Compressed recording is API-level (CompressedJsonlFormat); the
        // CLI declines the mismatch instead of shipping it.
        if (\str_ends_with(\strtolower($outputPath), '.gz')) {
            \fwrite($stderr, "candy-vcr record: --output cannot be a .gz path; record writes plain JSONL — use a .cas/.jsonl name (gzip via CompressedJsonlFormat in the API)\n");
            return 2;
        }
        // M10: recording is destructive (fopen 'wb' truncates). Silently
        // annihilating an existing cassette — possibly the ONLY copy of a
        // session — was the default behavior; it now requires --force.
        if (!$opts['force'] && \file_exists($outputPath)) {
            \fwrite($stderr, "candy-vcr record: refusing to overwrite existing cassette {$outputPath} — pass --force to replace\n");
            return 2;
        }
        $cols = $opts['cols'];
        $rows = $opts['rows'];
        $ctty = $opts['ctty'];
        $cmd  = $opts['cmd'];
        // Finding 002: the shim's pcntl_exec carries execve semantics — a
        // bare argv[0] like `vim` (every README example) never gets a PATH
        // search and dies errno-2 *inside* the pty, recording the warning
        // text as if it were program output. Resolve through PATH here, at
        // argv-parse time, so the documented shape works and an unknown
        // name fails loud before any terminal is opened.
        $resolved = self::resolveExecutable($cmd[0]);
        if ($resolved === null) {
            \fwrite($stderr, "candy-vcr record: command not found: {$cmd[0]}\n");
            return 2;
        }
        $cmd[0] = $resolved;
        $captureEnv = $opts['captureEnv'];
        $envRegex   = $opts['envRegex'];
        $captureAll = $opts['captureAll'];
        $idleTrim   = $opts['idleTrim'];

        $stdin = $this->stdin ?? \STDIN;

        $savedTermios = null;
        $pair = null;
        $child = null;
        $recorder = null;
        $exitCode = 1;
        $frameworkFailed = false;

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

            $headerEnv = $captureEnv ? self::filteredHostEnv($envRegex, $captureAll) : [];
            if ($captureAll) {
                \fwrite($stderr, self::envAllWarning($headerEnv));
            }
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
            $frameworkFailed = true;
        } finally {
            // M11: the child was spawned BEFORE the recorder opened, so any
            // failure between spawn and the pump (bad output path, header
            // rejection, pump construction) used to leave the session
            // running orphaned. Reap it here with the house bounded ladder
            // — happy path sees exited() already true and no-ops.
            if ($child !== null) {
                self::reapBounded($child);
            }
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
                    // Best-effort restore; the shutdown_function +
                    // signal handlers cover the still-armed paths.
                }
            }
            // Clean up the rescue state — the in-band finally took care
            // of restoration on the happy / throw paths.
            self::rescueClear();
        }

        // mi8 + C3: the unconditional "recorded ... (exit N)" line used to
        // fire even when the recorder never opened (an error message plus a
        // fake success notice) or when the tape itself lost writes. Only
        // announce success when neither happened; force a non-zero status
        // over an incomplete capture.
        $captureErrors = $recorder?->captureWriteErrors() ?? 0;
        if ($captureErrors > 0) {
            \fwrite($stderr, \sprintf(
                "candy-vcr record: cassette capture INCOMPLETE (%d failed write(s)) at %s\n",
                $captureErrors,
                $outputPath,
            ));
        }
        if (!$frameworkFailed && $captureErrors === 0) {
            \fwrite($stderr, \sprintf(
                "candy-vcr: recorded %s (exit %d)\n",
                $outputPath,
                $exitCode,
            ));
            return $exitCode;
        }
        return $exitCode === 0 ? 1 : $exitCode;
    }

    /**
     * Bounded reap of the recorded child (M11). If it is still alive —
     * the failure paths that skip PosixPump entirely, or a pump that died
     * mid-run — send SIGTERM, poll in 10 ms ticks for up to 2 s, escalate
     * to SIGKILL, then reap. Never blocks longer than the grace window:
     * a wedged child must not wedge the recorder too.
     *
     * @param \SugarCraft\Pty\Contract\Child $child
     */
    private static function reapBounded($child): void
    {
        if ($child->exited()) {
            return;
        }
        if (\defined('SIGTERM')) {
            $child->kill(\SIGTERM);
            $deadline = \microtime(true) + 2.0;
            while (!$child->exited() && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            if (!$child->exited() && \defined('SIGKILL')) {
                $child->kill(\SIGKILL);
            }
        }
        try {
            $child->wait();
        } catch (\Throwable) {
            // Reap is best-effort; the OS collects once the pty master
            // closed below. A throw here must not mask the original error
            // message already printed from catch/finally.
        }
    }

    /**
     * Parse argv into `{output, cols, rows, ctty, cmd}` — returns null
     * when usage was printed and the caller should exit 2.
     *
     * @param list<string> $args
     * @return array{output: ?string, cols: int, rows: int, ctty: bool, cmd: list<string>, captureEnv: bool, envRegex: ?string, captureAll: bool, idleTrim: ?float, force: bool}|null
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
        $envRegex = null;   // null means "use default SECRET_KEY_REGEX"
        $captureAll = false; // --env-all / --env-allow-secrets: capture full env incl. secrets
        $idleTrim = null;
        $force = false;
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
                } elseif ($a === '--env-all' || $a === '--env-allow-secrets') {
                    // FOOTGUN: capture the ENTIRE host environment with NO
                    // secret-key filtering — the cassette will contain
                    // credential values verbatim. Gated behind this explicit
                    // opt-in (never reachable via an empty --env-regex) and
                    // paired with a stderr warning in run() that names every
                    // captured var. Only use in a trusted, isolated environment
                    // where the cassette will never be shared or stored
                    // untrusted. `--env-allow-secrets` is a legacy alias.
                    $captureEnv = true;
                    $captureAll = true;
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
                } elseif ($a === '--force') {
                    // Overwrite an existing --output target (M10); without
                    // it the run() gate refuses and exits 2.
                    $force = true;
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
            'captureAll' => $captureAll,
            'idleTrim'   => $idleTrim,
            'force'      => $force,
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
     * Turn a command name into a spawnable absolute path (finding 002).
     *
     * An argv[0] that names a path (contains a separator, or a Windows
     * drive prefix) must simply exist and be executable; a bare name is
     * searched across `PATH` exactly the way a shell would, honouring
     * `PATHEXT` on Windows. Returns null when nothing resolves, so the
     * caller can fail loud at argv-parse time instead of letting
     * `pcntl_exec` return errno-2 down inside the pty.
     */
    private static function resolveExecutable(string $bin): ?string
    {
        if ($bin === '') {
            return null;
        }
        if (\str_contains($bin, '/') || \str_contains($bin, '\\') || \preg_match('/^[A-Za-z]:/', $bin) === 1) {
            return \is_file($bin) && \is_executable($bin) ? $bin : null;
        }

        $path = (string) (\getenv('PATH') ?: '');
        if ($path === '') {
            return null;
        }

        $extensions = [''];
        if (\DIRECTORY_SEPARATOR === '\\') {
            $pathExt = (string) (\getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD');
            foreach (\explode(PATH_SEPARATOR, $pathExt) as $ext) {
                if ($ext !== '') {
                    $extensions[] = $ext;
                }
            }
        }

        foreach (\explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($extensions as $ext) {
                $candidate = $dir . \DIRECTORY_SEPARATOR . $bin . $ext;
                if (\is_file($candidate) && \is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Install the safety-net handlers so a SIGTERM, SIGHUP, SIGINT,
     * fatal error, or unclean exit still restores the host termios.
     * The snapshot is held statically because shutdown_function and
     * pcntl_signal cannot capture closures with arbitrary instance
     * state (in particular, the recorder's own snapshot disappears as
     * soon as the run() frame unwinds).
     *
     * Handlers are signal-safe: no allocation, no logging, just a
     * direct `restore()` then a marker-file unlink. SIGKILL still
     * can't be intercepted — that's documented; the marker file at
     * {@see $rescueMarkerPath} points at the host's tty path so an
     * external recovery (`stty sane < $tty`) is at least possible.
     *
     * SIGINT is included because Ctrl-C may reach the recorder
     * process on macOS signal-quirk cases (controlling terminal is
     * on the child by design, but signals do leak).
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
            \pcntl_signal(\SIGHUP, [self::class, 'handleRescueSignal']);
            \pcntl_signal(\SIGINT, [self::class, 'handleRescueSignal']);
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
            // Keep: the file_exists guard is advisory only — handler,
            // shutdown-function and in-band finally race each other on this
            // marker, so a vanished-but-for-this-path unlink is a normal
            // outcome; a raw warning would print into a just-restored
            // terminal.
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
            // Keep: self-kill while mid signal-dispatch; the return
            // value is meaningless (the death IS the success path) and an
            // EPERM/ESRCH warning here would smear onto the raw-mode host
            // terminal this handler just cleaned up.
            @\posix_kill(\posix_getpid(), $signo);
            return;
        }
        // Justified exit-in-src (the ONLY one — census-pinned by
        // tests/Cli/SrcExitCensusTest.php): async pcntl fallback for
        // pcntl-present / ext-posix-absent hosts. The process must die NOW
        // with the conventional 128 + signo status. This frame is not on
        // the bin-side return-int channel: a throw would unwind into
        // PosixPump::run()'s caller — RecordCommand::run()'s
        // catch(\Throwable) turns it into exit 1 (losing the signum), and
        // a signal landing after run() returned would go uncaught (exit
        // 255). exit() is the only exit channel out of a signal handler;
        // the sibling normal path above expresses the same death without
        // exit() only because SIG_DFL re-raise lets the kernel do it.
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
            // Keep: the file_exists guard is advisory only — handler,
            // shutdown-function and in-band finally race each other on this
            // marker, so a vanished-but-for-this-path unlink is a normal
            // outcome; a raw warning would print into a just-restored
            // terminal.
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
        $path = \sys_get_temp_dir() . '/candy-vcr-rescue.' . \getmypid() . '.' . \bin2hex(\random_bytes(8));
        $payload = "tty={$ttyPath}\npid=" . \getmypid() . "\nstarted=" . \date('c') . "\n";
        // 'xb' (O_CREAT|O_EXCL, no truncate): a pre-existing path — including
        // a symlink planted on a shared /tmp, the old pid-only name invited —
        // is refused instead of followed/overwritten. The random suffix makes
        // the name unguessable; two recorders never collide on one marker.
        $fh = @\fopen($path, 'xb');
        if ($fh === false) {
            return;
        }
        if (@\fwrite($fh, $payload) === false) {
            @\fclose($fh);
            @\unlink($path);
            return;
        }
        @\fclose($fh);
        self::$rescueMarkerPath = $path;
    }

    /**
     * Snapshot the current process env, dropping any key that matches
     * the configured secret regex. Keys are sorted alphabetically so
     * the cassette diff stays stable across runs.
     *
     * Safe-by-default: an empty (or null) `$regex` falls back to the
     * conservative {@see SECRET_KEY_REGEX} rather than meaning "capture
     * everything" — the previous empty-string-skips-all behavior silently
     * recorded secrets. The full unfiltered env is captured ONLY when the
     * caller explicitly passes `$captureAll = true` (the CLI's `--env-all`
     * / `--env-allow-secrets` flag), which run() pairs with a stderr
     * warning naming every captured var (see {@see envAllWarning()}).
     *
     * @param string|null $regex  PCRE regex checked against env-var names.
     *                            Null/empty (default): use SECRET_KEY_REGEX.
     *                            Non-empty string: apply that regex.
     * @param bool $captureAll    When true, skip ALL filtering and capture the
     *                            full host environment (secrets included).
     * @return array<string, string>
     * @throws \InvalidArgumentException when $regex is a non-empty invalid PCRE.
     */
    public static function filteredHostEnv(?string $regex = null, bool $captureAll = false): array
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

        // Empty/null regex is NOT a "skip all" signal anymore — that was the
        // secret-leak footgun. It falls back to the conservative default so a
        // caller can never accidentally slurp secrets by passing ''.
        $effectiveRegex = ($regex === null || $regex === '') ? self::SECRET_KEY_REGEX : $regex;

        // Fix (was a masked suppression): an invalid PCRE made every
        // per-key preg_match() return false, `=== 1` never fired, and a
        // mistyped pattern SILENTLY DISABLED secret stripping — the exact
        // footgun this function exists to close. The CLI validates
        // --env-regex at parse time; this public static is the API door,
        // so the pattern probe lands here too and fails loud.
        if (@\preg_match($effectiveRegex, '') === false) {
            throw new \InvalidArgumentException(
                "env-filter pattern is not a valid PCRE pattern: {$effectiveRegex}",
            );
        }

        $kept = [];
        // Both feeding branches above yield array<string,string>, so only the
        // empty-name edge can skip an entry here.
        foreach ($env as $k => $v) {
            if ($k === '') {
                continue;
            }
            // @ kept: the pattern is validated above, so a false here would
            // be a PCRE runtime-limit event on a key name — degrading to
            // "not a secret" (keep the var) beats aborting the whole
            // capture; the probe suppression only silences that noise.
            if (!$captureAll && @\preg_match($effectiveRegex, $k) === 1) {
                continue;
            }
            $kept[$k] = $v;
        }
        \ksort($kept);
        return $kept;
    }

    /**
     * Build the stderr warning emitted whenever `--env-all` captures the full
     * host environment without secret filtering. Names every captured var so
     * the operator can see exactly which (potentially sensitive) keys landed in
     * the cassette. Pure/static so it can be unit-tested without a live PTY.
     *
     * @param array<string, string> $capturedEnv
     */
    public static function envAllWarning(array $capturedEnv): string
    {
        $names = \array_keys($capturedEnv);
        \sort($names);
        return \sprintf(
            "candy-vcr record: WARNING --env-all captured the full host environment "
            . "WITHOUT secret filtering (%d vars): %s\n",
            \count($names),
            $names === [] ? '(none)' : \implode(', ', $names),
        );
    }

    /**
     * The verb's own help text — also what `-h`/`--help` answers with.
     */
    public function usage(): string
    {
        return "usage: candy-vcr record [--output PATH] [--force] [--cols N] [--rows N] [--no-ctty]\n"
            . "                        [--shell] [--env] [--env-regex=PATTERN]\n"
            . "                        [--env-all] [--idle-trim N] -- <cmd> [args...]\n"
            . "\n"
            . "  --output PATH    Cassette file to write (default: session-<timestamp>.cas)\n"
            . "  --force          Replace an existing --output target. Without it, record\n"
            . "                   refuses (exit 2) rather than silently truncating a\n"
            . "                   cassette that is already on disk.\n"
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
            . "                   An empty pattern is rejected — it can no longer be used\n"
            . "                   to disable filtering. Implies --env.\n"
            . "  --env-all        DANGEROUS: capture the FULL host environment with secret\n"
            . "                   filtering disabled — the cassette will contain credential\n"
            . "                   values verbatim, and every captured var name is printed to\n"
            . "                   stderr as a warning. Only use in trusted, isolated\n"
            . "                   environments. Implies --env. (--env-allow-secrets is a\n"
            . "                   legacy alias.)\n"
            . "  --idle-trim N    Compress inter-event gaps longer than N seconds. Trimmed\n"
            . "                   events carry both `t` (compressed) and `tRaw` (original)\n"
            . "                   so `replay --no-trim` can opt back into the real cadence.\n";
    }

    /**
     * @param resource $stream
     */
    private function printUsage($stream): void
    {
        \fwrite($stream, $this->usage());
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
     * (with secret stripping) lands behind --env.
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
