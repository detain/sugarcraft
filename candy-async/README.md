# candy-async

Shared async utilities for SugarCraft — cancellation tokens, subscriptions, and AsyncOps helpers built on ReactPHP.

## Overview

`candy-async` provides the foundational async vocabulary used across the SugarCraft TUI ecosystem:

- **Cancellation tokens** — `CancellationSource` / `CancellationToken` / `Cancellable` for coordinated cancellation across async operations
- **Subscriptions** — `Subscription` interface and `Subscriptions::compose()` for managing TEA-style subscription lifecycles
- **AsyncOps** — static helpers for `withTimeout`, `retry`, `debounce`, and `throttle` operations

## Quickstart

```php
use SugarCraft\Async\{AsyncOps, CancellationSource, Subscriptions};

$source = CancellationSource::new();

// Attach a cancellation callback
$source->token()->onCancel(fn() => echo "Cancelled!\n");

$source->cancel(); // prints "Cancelled!"

// Timeout wrapper
$loop = \React\EventLoop\Loop::get();
$promise = AsyncOps::withTimeout($loop, $somePromise, 5.0);

// Retry with backoff
$promise = AsyncOps::retry(
    fn() => $httpClient->request('GET', 'https://example.com'),
    attempts: 3,
    baseBackoffSeconds: 0.5,
);

// Debounce rapid calls
$debounced = AsyncOps::debounce(fn($input) => process($input), 0.15);
$debounced('a');
$debounced('b');
$debounced('c'); // only this fires, 150ms after last call
```

## Requirements

- PHP 8.3+
- `react/event-loop: ^1.6`
- `react/promise: ^3.3`

## Installation

```bash
composer require sugarcraft/candy-async
```

## Architecture

### Cancellation

`CancellationSource` owns the mutable cancellation flag. It exposes a read-only `CancellationToken` to consumers. When `cancel()` is called:

1. The flag is flipped (idempotent)
2. All callbacks registered via `onCancel()` fire in registration order, exactly once

This pattern allows cancellation to propagate without the consumer being able to trigger it themselves.

### Subscriptions

`Subscription` is the disposal handle returned by subscribe-style APIs. `Subscriptions::compose()` lets multiple subscriptions be disposed atomically:

```php
$composite = Subscriptions::compose($sub1, $sub2, $sub3);
$composite->unsubscribe(); // disposes all three
```

### AsyncOps

withTimeout and retry are stateless helpers. debounce and throttle return stateful closures that retain mutable timer/cooldown state. All helpers work via Promise plumbing and `LoopInterface` timers:

- `withTimeout` — wraps a promise; rejects with `TimeoutException` after N seconds. The inner operation is NOT cancelled and keeps running to completion.
- `retry` — retries a failed operation up to N times with exponential backoff (no per-attempt timeout; wrap with withTimeout for a deadline)
- `debounce` — only the last call within the window fires, after silence
- `throttle` — fires at most once per interval, ignoring excess calls

## License

MIT
