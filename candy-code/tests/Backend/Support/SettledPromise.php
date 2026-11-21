<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend\Support;

use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Backend\ChildRequestCancelled;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Reads the promise {@see ChildChannel::ask()} or {@see ChildChannel::callClient()}
 * returns from a test body that is NOT on a loop-driven turn's fiber.
 *
 * Outside that fiber the promise is always settled before the call returns
 * (the blocking wait happened inside the call), so reading it needs no loop.
 * A cancelled question maps to the same outcome the production approver and
 * client caller give it, so each test asserts what the turn would see.
 */
final class SettledPromise
{
    /**
     * The resolution of a settled ask. A cancellation reads as the cancelled
     * resolution the turn's approver builds from the rejection.
     */
    public static function askAnswer(PromiseInterface $promise): PermissionResolved
    {
        return ChildChannel::awaitTurn(
            $promise,
            static fn (ChildRequestCancelled $cancelled): PermissionResolved => PermissionResolved::cancelled($cancelled->requestId, $cancelled->getMessage()),
        );
    }

    /** The result of a settled client call. A cancellation reads as the error result the turn's client caller gives. */
    public static function callAnswer(PromiseInterface $promise, string $toolCallId): ToolResult
    {
        return ChildChannel::awaitTurn(
            $promise,
            static fn (ChildRequestCancelled $cancelled): ToolResult => new ToolResult(
                toolCallId: $toolCallId,
                content: 'the turn ended before the client answered',
                isError: true,
            ),
        );
    }

    /**
     * The rejection the promise settled with, or null when it resolved.
     * Fails when it is still pending, since a blocking call never returns pending.
     */
    public static function rejection(PromiseInterface $promise): ?\Throwable
    {
        $state = null;
        $promise->then(
            static function (mixed $value) use (&$state): void {
                $state = [true, null];
            },
            static function (\Throwable $error) use (&$state): void {
                $state = [false, $error];
            },
        );
        if ($state === null) {
            throw new \LogicException('the promise is still pending: a blocking call settles before it returns');
        }

        return $state[1];
    }
}
