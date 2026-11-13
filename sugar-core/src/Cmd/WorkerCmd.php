<?php

declare(strict_types=1);

namespace SugarCraft\Core\Cmd;

use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg\WorkerResultMsg;
use SugarCraft\Core\WorkerPool;

/**
 * Cmd factory for dispatching CPU-bound tasks to the worker pool.
 *
 * Usage in a model:
 * ```php
 * return [$model, WorkerCmd::run($pool, [Heavy::class, 'compute'])];
 * ```
 *
 * The returned Msg will be a {@see WorkerResultMsg} with the result
 * or error when the worker finishes.
 *
 * @see WorkerPool
 */
final class WorkerCmd
{
    private function __construct(
        private readonly WorkerPool $pool,
    ) {
    }

    /**
     * Create a Cmd that dispatches `$task` to the worker pool.
     *
     * @param WorkerPool $pool
     * @param callable|string $task Serializable callable. Strings are REJECTED
     *                              by the pool with a named
     *                              InvalidArgumentException (the sc-29 string
     *                              channel is removed — this door keeps the
     *                              old signature only so the refusal message
     *                              is the pool's, not a PHP TypeError).
     *                              Closures cannot serialize: they reject the
     *                              promise, named.
     * @return \Closure A closure that, when executed, returns an AsyncCmd whose
     *             promise resolves to WorkerResultMsg
     */
    public static function run(WorkerPool $pool, callable|string $task): \Closure
    {
        $cmd = new self($pool);

        return static fn (): AsyncCmd => $cmd->dispatch($task);
    }

    private function dispatch(callable|string $task): AsyncCmd
    {
        $promise = $this->pool->dispatch($task)->then(
            /** @return \SugarCraft\Core\Msg */
            static function (WorkerResultMsg $msg) {
                return $msg;
            },
            /** @return \SugarCraft\Core\Msg */
            static function (\Throwable $e) {
                return new \SugarCraft\Core\Msg\ExceptionMsg($e);
            },
        );

        return new AsyncCmd($promise);
    }
}
