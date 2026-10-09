<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\GpuOutcome;
use SugarCraft\Top\Collect\Process\AsyncCommand;
use SugarCraft\Top\Collect\Process\CommandOutcome;
use SugarCraft\Top\Collect\Process\CommandResult;

/**
 * One bounded `nvidia-smi` child: the GPU collectors' view of
 * {@see AsyncCommand}, which owns the spawn, the timeout, the reaping, the
 * registry and the shutdown hook (shared with every other helper child,
 * ipmitool included). This adapter only maps a {@see CommandResult} onto
 * the GPU memoisation vocabulary: Ok keeps stdout, any other exit or no
 * spawn is Absent (permanent), an overrun is Timeout (retried).
 *
 * {@see run()} blocks (startup probe, scripted tests); {@see launch()}
 * resolves on the loop, so a 0.25-1 s `nvidia-smi pmon` never stalls
 * input or rendering (measured on a 4-GPU host: the blocking form froze
 * the UI for ~0.7 s every query cycle).
 */
final class SmiProcess
{
    /** Seconds a query may run before it is killed (a hung driver call). */
    public const float TIMEOUT = 2.0;

    /** Seconds {@see run()} waits for a killed child before leaving it to the registry. */
    public const float REAP_WAIT = AsyncCommand::REAP_WAIT;

    /** Seconds the loop keeps polling a killed / finishing child before leaving it to the registry. */
    public const float REAP_WINDOW = AsyncCommand::REAP_WINDOW;

    private function __construct()
    {
    }

    /**
     * Blocking: [Ok, stdout] on exit 0, [Absent, ''] on any other exit or
     * when nothing could be spawned, [Timeout, ''] past `$timeout`.
     *
     * @param list<string> $argv argv[0] must be an executable path
     * @return array{0: GpuOutcome, 1: string}
     */
    public static function run(array $argv, float $timeout = self::TIMEOUT): array
    {
        return self::outcome(AsyncCommand::run($argv, $timeout));
    }

    /**
     * Non-blocking: the same outcomes as {@see run()}, delivered by the
     * loop. Resolves on the loop thread; never rejects.
     *
     * @param list<string> $argv argv[0] must be an executable path
     * @return PromiseInterface<array{0: GpuOutcome, 1: string}>
     */
    public static function launch(array $argv, ?LoopInterface $loop = null, float $timeout = self::TIMEOUT): PromiseInterface
    {
        return AsyncCommand::launch($argv, $loop, $timeout)->then(self::outcome(...));
    }

    /** Children spawned and not yet closed (every helper child, not only nvidia-smi). */
    public static function liveCount(): int
    {
        return AsyncCommand::liveCount();
    }

    /** SIGKILL and reap every live helper child, waiting at most `$wait` seconds. */
    public static function shutdown(float $wait = self::REAP_WAIT): void
    {
        AsyncCommand::shutdown($wait);
    }

    /** @return array{0: GpuOutcome, 1: string} */
    private static function outcome(CommandResult $result): array
    {
        return match ($result->outcome) {
            CommandOutcome::Ok => [GpuOutcome::Ok, $result->stdout],
            // A runaway output is a misbehaving driver call, not "no nvidia-smi": retried with backoff.
            CommandOutcome::Timeout, CommandOutcome::TooLarge => [GpuOutcome::Timeout, ''],
            default => [GpuOutcome::Absent, ''],
        };
    }
}
