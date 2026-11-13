<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Support;

use SugarCraft\Pty\Child;
use SugarCraft\Pty\Pty;

/**
 * bin/candy-top running on a REAL pty, driven without script(1) or sleeps.
 *
 * Every wait is condition-based and bounded: {@see readUntil()} loops on
 * the pty until a needle shows up or its deadline passes, {@see reap()}
 * polls the child's exit under a hard deadline and SIGKILLs an overrun,
 * so a regression fails the test instead of hanging the runner.
 */
final class PtyRun
{
    private string $output = '';

    private function __construct(private readonly Pty $pty, private readonly Child $child)
    {
    }

    /** null when this host cannot do pty tests (no FFI / pcntl / ptmx / the pty refuses to open). */
    public static function unavailable(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'POSIX ptys only';
        }
        if (!class_exists(Pty::class)) {
            return 'sugar-pty is not installed (php scripts/refresh-deps.php --mode=linked --libs=candy-top)';
        }
        if (!extension_loaded('ffi') || !extension_loaded('pcntl') || !extension_loaded('posix')) {
            return 'needs ext-ffi, ext-pcntl and ext-posix';
        }
        if (!file_exists('/dev/ptmx')) {
            return '/dev/ptmx is missing';
        }
        try {
            Pty::open()->close();
        } catch (\Throwable $e) {
            return 'pty cannot open: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * @param list<string>          $args candy-top arguments
     * @param array<string, string> $env  overrides on a minimal UTF-8 base environment
     */
    public static function start(array $args, array $env = [], int $cols = 120, int $rows = 40): self
    {
        $base = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => sys_get_temp_dir(),
            'TERM' => 'xterm-256color',
            'COLORTERM' => 'truecolor',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'CANDY_TOP_LOGO' => 'off',
        ];
        $pty = Pty::open($cols, $rows);
        $child = $pty->spawn(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/candy-top', ...$args],
            [...$base, ...$env],
            $cols,
            $rows,
            controllingTerminal: true,
        );

        return new self($pty, $child);
    }

    public function pid(): int
    {
        return $this->child->pid();
    }

    public function output(): string
    {
        return $this->output;
    }

    /** Read until $needle appears in the accumulated output; false on the deadline or EOF. */
    public function readUntil(string $needle, float $seconds = 15.0): bool
    {
        $until = microtime(true) + $seconds;
        while (!str_contains($this->output, $needle)) {
            if (microtime(true) >= $until || $this->child->exited()) {
                $this->drain();

                return str_contains($this->output, $needle);
            }
            $this->drain();
        }

        return true;
    }

    /** Read until $needle has appeared at least $times times. */
    public function readUntilCount(string $needle, int $times, float $seconds = 15.0): bool
    {
        $until = microtime(true) + $seconds;
        while (substr_count($this->output, $needle) < $times) {
            if (microtime(true) >= $until || $this->child->exited()) {
                $this->drain();

                return substr_count($this->output, $needle) >= $times;
            }
            $this->drain();
        }

        return true;
    }

    public function write(string $bytes): void
    {
        $this->pty->write($bytes);
    }

    public function signal(int $signal): void
    {
        posix_kill($this->child->pid(), $signal);
    }

    /** Wait for the child to exit; null (after SIGKILL) when it outlives the deadline. */
    public function reap(float $seconds = 10.0): ?int
    {
        $until = microtime(true) + $seconds;
        while (!$this->child->exited()) {
            if (microtime(true) >= $until) {
                posix_kill($this->child->pid(), SIGKILL);
                $this->child->wait();
                $this->drain();
                $this->pty->close();

                return null;
            }
            $this->drain();
        }
        $this->drain();
        $code = $this->child->exitCode();
        $this->pty->close();

        return $code;
    }

    private function drain(): void
    {
        $chunk = $this->pty->read(65536, 0.05);
        if (is_string($chunk)) {
            $this->output .= $chunk;
        }
    }
}
