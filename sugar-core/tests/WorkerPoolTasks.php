<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\Exception\ProgramException;

/**
 * Static task surface for the WorkerPool suites (sc-29).
 *
 * These methods exist to be dispatched as `[self::class, 'name']`
 * callables: the array form serializes to pure strings, and the child
 * resolves the class through the autoloader the parent now hands it —
 * which is exactly the contract this fixture proves. A Closure task is
 * impossible by design (PHP cannot serialize one); the global-function
 * string channel was removed by sc-29.
 */
final class WorkerPoolTasks
{
    public static function pi(): float
    {
        return 3.141592653589793;
    }

    public static function sapiName(): string
    {
        return PHP_SAPI;
    }

    public static function pid(): int
    {
        return getmypid();
    }

    /**
     * Proves the env-merge leg: the task returns the child's PATH and
     * the parent compares it to its own (the old env array replaced the
     * whole block, so PATH was simply gone in the child).
     */
    public static function childPath(): string
    {
        return (string) (getenv('PATH') ?: '');
    }

    public static function home(): string
    {
        return (string) (getenv('HOME') ?: '');
    }

    /** Error round-trip arm. */
    public static function boom(): void
    {
        throw new \RuntimeException('task exploded');
    }

    /**
     * REAL-CLASS round trip (sc-29 pin): returns a library throwable
     * VALUE — it must re-materialise in the parent as ProgramException,
     * not as __PHP_Incomplete_Class.
     */
    public static function programException(): ProgramException
    {
        return new ProgramException('worker-side program failure');
    }

    /** Same real-class proof on the ERROR arm: thrown in-child, rejected in-parent. */
    public static function throwProgramException(): void
    {
        throw new ProgramException('worker-side program failure');
    }

    /** Autoloader leg: naming a core class inside the task body. */
    public static function coreClassSeen(): string
    {
        return ProgramException::class;
    }

    public function __invoke(): string
    {
        return 'invoked';
    }
}
