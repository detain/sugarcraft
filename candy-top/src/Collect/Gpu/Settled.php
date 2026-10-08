<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use React\Promise\PromiseInterface;

/**
 * Reads a react/promise that may already be settled. The GPU collectors
 * are written once, as promise chains: with a blocking runner every link
 * settles synchronously (react/promise runs then() callbacks of a settled
 * promise at once), so {@see value()} unwraps the result on the spot;
 * with the loop runner the same chain resolves later.
 */
final class Settled
{
    private function __construct()
    {
    }

    /**
     * [true, value] when `$promise` has fulfilled; [false, null] while it
     * is pending. A rejection is rethrown.
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function peek(PromiseInterface $promise): array
    {
        $done = false;
        $value = null;
        $error = null;
        $promise->then(
            static function (mixed $v) use (&$done, &$value): void {
                $done = true;
                $value = $v;
            },
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );
        if ($error instanceof \Throwable) {
            throw $error;
        }

        return [$done, $value];
    }

    /**
     * The fulfilled value.
     *
     * @throws \LogicException while `$promise` is still pending (a loop-driven runner read synchronously)
     */
    public static function value(PromiseInterface $promise): mixed
    {
        [$done, $value] = self::peek($promise);
        if (!$done) {
            throw new \LogicException('the promise is still pending: a loop-driven runner was read synchronously');
        }

        return $value;
    }
}
