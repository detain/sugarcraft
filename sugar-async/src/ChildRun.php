<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * Run one child process with a deadline and a per-stream byte cap, and collect
 * what it wrote: blocking with {@see run()}, or on a loop with {@see launch()}.
 *
 * The argv is executed directly, never through a shell. The defaults are the
 * deadline and cap {@see ChildPump} uses: ten seconds, 8 MiB per stream.
 *
 *     $r = ChildRun::new(['git', 'status', '--porcelain'])
 *         ->withTimeout(5.0)
 *         ->withMaxBytes(1_048_576)
 *         ->run();
 *     if (!$r->succeeded()) { ... }
 *
 * A child past its deadline is killed through the sugar-core ladder (SIGTERM,
 * a grace period, SIGKILL) and reported as TIMED_OUT. A child that closes its
 * output and then lingers past the deadline is treated the same way.
 *
 * Stdin: {@see withStdin()} feeds a payload to the child. The POSIX arm does it
 * through a non-blocking pipe. On Windows a non-empty payload is refused (a
 * LogicException from run(), a rejected promise from launch()), because the
 * Windows runtime cannot give a non-blocking stdin pipe.
 *
 * Mirrors the process runners that grew in candy-git-server (BoundedCommand),
 * candy-top (AsyncCommand) and candy-code (CommandBackend); they are the copies
 * this replaces.
 */
final class ChildRun
{
    /** Deadline when none was set: the ChildPump default. */
    public const DEFAULT_TIMEOUT_SECONDS = ChildPump::DEFAULT_TIMEOUT_SECONDS;

    /** Per-stream cap when none was set: the ChildPump default. */
    public const DEFAULT_MAX_BYTES = ChildPump::DEFAULT_MAX_BYTES;

    private const WINDOWS_STDIN_MESSAGE = 'ChildRun stdin needs non-blocking pipes, which the Windows runtime cannot give; '
        . 'pass the payload to the child as a file instead.';

    /**
     * @param list<string> $argv
     * @param null|string $stdin  Payload for the child's stdin, or null for none
     * @param null|array<string, string> $env  Environment, or null to inherit
     */
    private function __construct(
        private readonly array $argv,
        private readonly float $timeout,
        private readonly int $maxBytes,
        private readonly ?string $stdin,
        private readonly ?string $cwd,
        private readonly ?array $env,
    ) {
    }

    /** @param list<string> $argv The program and its arguments; $argv[0] is the program */
    public static function new(array $argv): self
    {
        if ($argv === []) {
            throw new \InvalidArgumentException('ChildRun needs a non-empty argv');
        }
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                throw new \InvalidArgumentException('ChildRun argv entries must be strings');
            }
        }

        return new self($argv, self::DEFAULT_TIMEOUT_SECONDS, self::DEFAULT_MAX_BYTES, null, null, null);
    }

    /** Seconds from the start of the run before the child is killed. */
    public function withTimeout(float $seconds): self
    {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Timeout must be a positive finite number of seconds');
        }

        return $this->mutate(['timeout' => $seconds]);
    }

    /** Bytes kept per stream (>= 0); output past it is read and counted, not kept. */
    public function withMaxBytes(int $bytes): self
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Max bytes must be >= 0');
        }

        return $this->mutate(['maxBytes' => $bytes]);
    }

    /** Payload for the child's stdin; null or '' gives the child an immediate EOF. */
    public function withStdin(?string $bytes): self
    {
        return $this->mutate(['stdin' => $bytes]);
    }

    public function withCwd(?string $dir): self
    {
        return $this->mutate(['cwd' => $dir]);
    }

    /** @param null|array<string, string> $env The whole environment, or null to inherit */
    public function withEnv(?array $env): self
    {
        return $this->mutate(['env' => $env]);
    }

    /**
     * Run the child to completion, blocking the caller, and return what it wrote.
     *
     * @throws \LogicException for a non-empty stdin payload on the Windows runtime
     * @throws \RuntimeException when the child cannot be started
     */
    public function run(): ChildResult
    {
        $this->guardStdin();
        $clock = Clock::monotonicSeconds(...);
        [$proc, $pipes] = $this->spawn();
        $deadline = $clock() + $this->timeout;

        try {
            $pump = ChildPump::new([1 => $pipes[1], 2 => $pipes[2]])
                ->withDeadline($deadline)
                ->withCap($this->maxBytes)
                ->withClock($clock)
                ->withRunning(WindowsPipes::runningProbe($proc));
            if ($this->stdin !== null && $this->stdin !== '') {
                $pump = $pump->withStdin($pipes[0], $this->stdin);
            } else {
                fclose($pipes[0]);
            }
            $r = $pump->pump();
        } catch (\Throwable $e) {
            self::closeAll($pipes);
            BoundedShutdown::terminateBounded($proc);
            throw $e;
        }

        // Pipes are closed before any proc_close: the reap helpers reap through
        // proc_close, and the pipe handles are ours to close first.
        self::closeAll($pipes);

        if ($r->reason === DrainResult::CLOSED) {
            $code = BoundedShutdown::reapIfExited($proc, max(0.0, $deadline - $clock()));
            if ($code !== null) {
                return new ChildResult($r->buffer(1), $r->buffer(2), $code, ChildResult::EXITED, self::dropped($r));
            }
            // Output closed but the child is still running past the deadline.
            $code = BoundedShutdown::terminateBounded($proc);

            return new ChildResult($r->buffer(1), $r->buffer(2), $code, ChildResult::TIMED_OUT, self::dropped($r));
        }

        $code = BoundedShutdown::terminateBounded($proc);
        $status = $r->reason === DrainResult::EXPIRED ? ChildResult::TIMED_OUT : ChildResult::PIPE_FAILED;

        return new ChildResult($r->buffer(1), $r->buffer(2), $code, $status, self::dropped($r));
    }

    /**
     * Start the child on $loop and return a promise of its result. The loop is
     * never blocked: output is read by stream callbacks, the deadline is a timer,
     * and the reap is a short periodic check for the exit.
     *
     * The promise resolves (never rejects) once the child is done, and rejects
     * only when it cannot be started, or on a stdin payload on Windows.
     *
     * @return PromiseInterface<ChildResult>
     */
    public function launch(LoopInterface $loop): PromiseInterface
    {
        if (WindowsPipes::runtimeNeedsIt() && $this->stdin !== null && $this->stdin !== '') {
            return reject(new \LogicException(self::WINDOWS_STDIN_MESSAGE));
        }

        try {
            [$proc, $pipes] = $this->spawn();
        } catch (\RuntimeException $e) {
            return reject($e);
        }

        $deferred = new Deferred();
        (new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            $this->stdin ?? '',
            $this->timeout,
            $this->maxBytes,
            $deferred,
        ))->start();

        return $deferred->promise();
    }

    private function guardStdin(): void
    {
        if ($this->stdin !== null && $this->stdin !== '' && WindowsPipes::runtimeNeedsIt()) {
            throw new \LogicException(self::WINDOWS_STDIN_MESSAGE);
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}  The process and its pipes: 0 stdin, 1 stdout, 2 stderr
     */
    private function spawn(): array
    {
        $pipes = [];
        $proc = @proc_open(
            $this->argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->cwd,
            $this->env,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('ChildRun: could not start ' . $this->argv[0]);
        }

        return [$proc, $pipes];
    }

    /** @param array<int, resource> $pipes */
    private static function closeAll(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    private static function dropped(DrainResult $r): int
    {
        return $r->droppedBytes(1) + $r->droppedBytes(2);
    }

    /** @param array<string, mixed> $changes Constructor arguments to replace; the rest carry over */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
