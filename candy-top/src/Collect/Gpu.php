<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Best-effort NVIDIA GPU stats via an `nvidia-smi` shell-out.
 *
 * Mirrors aristocratos/btop Gpu::collect in spirit only: btop dlopen()s
 * libnvidia-ml / ROCm SMI, which PHP cannot do without FFI (plan §1
 * non-goal). sugar-dash SystemModule's nvidia-smi query is the precedent
 * for the memoized-absent law, refined here:
 *  - before any query has succeeded, a missing binary, a non-zero exit or
 *    unparseable output marks the GPU absent for the collector's lifetime —
 *    a GPU-less host never spawns again;
 *  - a timeout is always transient, and so is any failure once a query has
 *    succeeded (a driver reload or GPU reset on a host that HAS a GPU): it
 *    yields an empty snapshot and the next query is pushed back
 *    exponentially (interval × 2^n, capped at 2^5), so a wedged driver costs
 *    one bounded wait per backoff window, not per tick;
 *  - a fork+exec per frame tick is too expensive to pay at the cpu/mem
 *    cadence, so real queries are spaced at least $interval seconds apart
 *    on the injected clock; samples in between return the last snapshot.
 *
 * The child is spawned with an argv array (no shell), only when the binary
 * is on PATH, bounded by a deadline, SIGKILLed on overrun and reaped with a
 * bounded wait — no descriptor outlives the call. A child that will not die
 * within that wait is abandoned rather than blocking the frame: PHP's
 * resource destructor only polls it with WNOHANG, so if it dies later it
 * stays a zombie until the PHP process exits (one per such timeout, and the
 * backoff above bounds how often that can happen). The runner is injectable
 * so tests never spawn.
 */
final class Gpu
{
    /**
     * `name` is last on purpose: it is the only free-text column and may
     * contain commas ("NVIDIA A100-SXM4-40GB, MIG 1g.5gb"), so the parser
     * splits a fixed number of numeric columns and keeps the tail whole.
     */
    public const array QUERY = ['index', 'utilization.gpu', 'memory.used', 'memory.total', 'temperature.gpu', 'power.draw', 'name'];

    public const float DEFAULT_INTERVAL = 5.0;

    private const float TIMEOUT = 2.0;
    private const float REAP_WAIT = 0.5;
    private const int MAX_BACKOFF_EXPONENT = 5;

    /**
     * @param \Closure(list<string>): array{0: GpuOutcome, 1: string} $runner argv → [outcome, stdout]
     * @param \Closure(): float $clock monotonic seconds
     */
    private function __construct(
        private readonly \Closure $runner,
        private readonly \Closure $clock,
        private readonly float $interval,
        private readonly bool $absent,
        private readonly ?float $nextQueryAt,
        private readonly int $timeouts,
        private readonly GpuSnapshot $last,
        private readonly bool $everSucceeded,
    ) {
    }

    /**
     * @param (\Closure(list<string>): array{0: GpuOutcome, 1: string})|null $runner defaults to a bounded proc_open of nvidia-smi
     * @param (\Closure(): float)|null $clock defaults to hrtime-based monotonic seconds
     */
    public static function new(?\Closure $runner = null, ?\Closure $clock = null, float $interval = self::DEFAULT_INTERVAL): self
    {
        return new self(
            $runner ?? self::nvidiaSmi(...),
            $clock ?? static fn (): float => hrtime(true) / 1e9,
            max(0.0, $interval),
            false,
            null,
            0,
            new GpuSnapshot([]),
            false,
        );
    }

    /**
     * @return array{0: GpuSnapshot, 1: self}
     */
    public function sample(): array
    {
        if ($this->absent) {
            return [$this->last, $this];
        }
        $now = ($this->clock)();
        if ($this->nextQueryAt !== null && $now < $this->nextQueryAt) {
            return [$this->last, $this];
        }

        [$outcome, $output] = ($this->runner)([
            'nvidia-smi',
            '--query-gpu=' . implode(',', self::QUERY),
            '--format=csv,noheader,nounits',
        ]);

        $devices = $outcome === GpuOutcome::Ok ? self::parse($output) : [];
        if ($devices !== []) {
            $snapshot = new GpuSnapshot($devices);

            return [$snapshot, new self($this->runner, $this->clock, $this->interval, false, $now + $this->interval, 0, $snapshot, true)];
        }

        $empty = new GpuSnapshot([]);
        if ($outcome !== GpuOutcome::Timeout && !$this->everSucceeded) {
            return [$empty, new self($this->runner, $this->clock, $this->interval, true, null, 0, $empty, false)];
        }

        $timeouts = $this->timeouts + 1;
        $backoff = $this->interval * (2 ** min($timeouts, self::MAX_BACKOFF_EXPONENT));

        return [$empty, new self($this->runner, $this->clock, $this->interval, false, $now + $backoff, $timeouts, $empty, $this->everSucceeded)];
    }

    public function absent(): bool
    {
        return $this->absent;
    }

    /**
     * @return list<GpuDevice>
     */
    private static function parse(string $output): array
    {
        $devices = [];
        $columns = count(self::QUERY);
        foreach (explode("\n", trim($output)) as $line) {
            $cols = array_map('trim', explode(',', $line, $columns));
            if (count($cols) !== $columns || !ctype_digit($cols[0]) || $cols[$columns - 1] === '') {
                continue;
            }
            $float = static fn (string $v): float => is_numeric($v) ? (float) $v : Sentinel::UNMEASURED;
            // nvidia-smi reports memory in MiB with nounits.
            $mib = static fn (string $v): int => is_numeric($v) ? (int) round((float) $v * 1048576) : Sentinel::UNMEASURED_INT;
            $devices[] = new GpuDevice(
                (int) $cols[0],
                $cols[6],
                $float($cols[1]),
                $mib($cols[2]),
                $mib($cols[3]),
                $float($cols[4]),
                $float($cols[5]),
            );
        }

        return $devices;
    }

    /**
     * @param list<string> $argv
     * @return array{0: GpuOutcome, 1: string}
     */
    private static function nvidiaSmi(array $argv): array
    {
        $binary = self::which($argv[0]);
        if ($binary === null) {
            return [GpuOutcome::Absent, ''];
        }
        $argv[0] = $binary;

        $pipes = [];
        try {
            $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        } catch (\Error) {
            return [GpuOutcome::Absent, '']; // proc_open listed in disable_functions
        }
        if (!is_resource($process)) {
            return [GpuOutcome::Absent, ''];
        }

        stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = microtime(true) + self::TIMEOUT;
        while (!feof($pipes[1]) && ($left = $deadline - microtime(true)) > 0) {
            $read = [$pipes[1]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) min(200000, $left * 1e6)) > 0) {
                $output .= (string) fread($pipes[1], 65536);
            }
        }
        $timedOut = !feof($pipes[1]);
        fclose($pipes[1]);

        if ($timedOut) {
            proc_terminate($process, 9);
            // A child stuck in uninterruptible sleep ignores even SIGKILL;
            // proc_close would then block the frame indefinitely. Poll
            // briefly, and if it is still alive drop the handle: the
            // resource destructor's WNOHANG poll never blocks, but a child
            // that dies after it stays a zombie until PHP exits.
            $reapBy = microtime(true) + self::REAP_WAIT;
            while (proc_get_status($process)['running'] && microtime(true) < $reapBy) {
                usleep(10000);
            }
            if (!proc_get_status($process)['running']) {
                proc_close($process);
            }

            return [GpuOutcome::Timeout, ''];
        }

        return proc_close($process) === 0 ? [GpuOutcome::Ok, $output] : [GpuOutcome::Absent, ''];
    }

    private static function which(string $command): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $command) && is_executable($dir . '/' . $command)) {
                return $dir . '/' . $command;
            }
        }

        return null;
    }
}
