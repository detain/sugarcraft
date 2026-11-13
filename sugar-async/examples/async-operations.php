<?php

declare(strict_types=1);

/**
 * Timer-backed helpers on a private loop: debounce and throttle return
 * invokable Subscription handles whose calls answer as promises, plus the
 * all()/race()/onPending() fan-in combinators.
 */

namespace SugarCraft\Async\Examples;

use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use SugarCraft\Async\AsyncOps;

use function React\Promise\resolve;

require __DIR__ . '/../vendor/autoload.php';

$loop = new StreamSelectLoop();
$guard = $loop->addTimer(2.0, static function () use ($loop): void {
    $loop->stop();
});

$seen = [];

// Debounce: one burst, one promise per call — superseded calls answer null.
$debounced = AsyncOps::debounce(
    static function (string $input) use (&$seen): string {
        $seen[] = "debounced:$input";
        return $input;
    },
    0.02,
    $loop,
);
$debounced('a')->then(static function (mixed $v): void {
    print('call a answered: ' . var_export($v, true) . "\n");
});
$debounced('b')->then(static function (mixed $v): void {
    print('call b answered: ' . var_export($v, true) . "\n");
});

// Throttle with a trailing edge: leading fires, one suppressed answers null,
// the last suppressed call runs at window end.
$throttled = AsyncOps::throttle(
    static function (string $input) use (&$seen): string {
        $seen[] = "throttled:$input";
        return $input;
    },
    0.02,
    $loop,
    trailing: true,
);
$throttled('lead');
$throttled('skip')->then(static function (mixed $v): void {
    print('skip answered: ' . var_export($v, true) . "\n");
});

$loop->addTimer(0.06, static function () use ($loop, $guard, &$seen): void {
    // Fan-in on already-settled promises: no waiting, ordering guaranteed.
    AsyncOps::all(['one' => resolve(1), 'two' => resolve(2)])
        ->then(static function (array $values) use (&$seen): void {
            $seen[] = 'all:' . implode(',', $values);
        });
    AsyncOps::race([resolve('winner'), resolve('loser')])
        ->then(static function (string $first) use (&$seen): void {
            $seen[] = "race:$first";
        });
    // A still-pending promise under a bounded wait rejects with a named TimeoutException.
    AsyncOps::boundWait((new Deferred())->promise(), 0.01, $loop)
        ->then(null, static function (\Throwable $e) use (&$seen, $loop, $guard): void {
            $seen[] = 'pending:' . $e::class;
            $loop->cancelTimer($guard);
            $loop->stop();
        });
});

$loop->run();
foreach ($seen as $line) {
    echo "observed: $line\n";
}
