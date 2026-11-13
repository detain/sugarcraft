<?php

declare(strict_types=1);

/**
 * Cancellation vocabulary: sources, tokens, disposable registrations, and the
 * "cancellation is not an error" catch arm. Runs without an event loop — every
 * promise here settles synchronously.
 */

namespace SugarCraft\Async\Examples;

use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\OperationCancelledException;

use function React\Promise\resolve;

require __DIR__ . '/../vendor/autoload.php';

$source = CancellationSource::new();
$token = $source->token();

// Registrations are Subscriptions: unsubscribe() drops the callback — it never fires.
$fireCount = 0;
$dropped = $token->onCancel(static function () use (&$fireCount): void {
    $fireCount++;
    echo "never printed\n";
});
$dropped->unsubscribe();

// Hooks fire in registration order, exactly once; a throwing hook is logged,
// never propagated into the canceller.
$token->onCancel(static function () use (&$fireCount): void {
    $fireCount++;
    echo "cleanup ran\n";
});

$guarded = AsyncOps::cancellable(resolve('late'), $token);
$guarded->then(
    static function (mixed $value): void {
        echo "resolved (token never fired): ", var_export($value, true), "\n";
    },
    static function (\Throwable $e): void {
        echo $e instanceof OperationCancelledException
            ? "cancelled cleanly\n"
            : "real failure: {$e->getMessage()}\n";
    },
);

$source->cancel();
// register → use → unsubscribe → count: one registration was dropped and one
// kept, so the cancel ran exactly one of the two hooks.
echo "hooks fired: {$fireCount} of 2 registrations\n";
echo $token->isCancelled() ? "token is cancelled\n" : "unreachable\n";
