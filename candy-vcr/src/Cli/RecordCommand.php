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

        $stdin = $this->stdin ?? \STDIN;

        $savedTermios = null;
        $pair = null;
        $recorder = null;
        $exitCode = 1;

        try {
            $savedTermios = $this->captureHostTermios($stderr);

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

            $recorder = Recorder::open(
                $outputPath,
                Recorder::defaultHeader($cols, $rows, 'sugarcraft/candy-vcr@record'),
            );
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
                    // Best-effort restore; the safety-net in P6.5.4
                    // will additionally cover signal-driven exits.
                }
            }
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
     * @return array{output: ?string, cols: int, rows: int, ctty: bool, cmd: list<string>}|null
     * @param resource $stderr
     */
    private function parseArgs(array $args, $stderr): ?array
    {
        $output = null;
        $cols = self::DEFAULT_COLS;
        $rows = self::DEFAULT_ROWS;
        $ctty = true;
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
        ];
    }

    /**
     * @param resource $stream
     */
    private function printUsage($stream): void
    {
        \fwrite(
            $stream,
            "usage: candy-vcr record [--output PATH] [--cols N] [--rows N] [--no-ctty] -- <cmd> [args...]\n"
            . "\n"
            . "  --output PATH    Cassette file to write (default: session-<timestamp>.cas)\n"
            . "  --cols N         Initial terminal columns (default: 80)\n"
            . "  --rows N         Initial terminal rows (default: 24)\n"
            . "  --no-ctty        Spawn without a controlling terminal (Ctrl+C will not\n"
            . "                   reach the recorded program; default: ctty enabled)\n",
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
