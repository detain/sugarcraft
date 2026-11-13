<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Forms\Lang;

/**
 * Shared execution guard for the async-suggestions fetcher (SF-004/SF-019).
 *
 * Input and Select each schedule a debounced fetch through a byte-twin
 * callback; both twins used to call the host fetcher bare, so a throwing
 * fetcher — or one returning a non-promise — detonated INSIDE the live
 * event loop (an unwinding RuntimeException out of `Loop::run()`, or an
 * unconsumed TypeError in the fulfillment arm that left suggestions
 * silently never-arriving). This class collapses both twins into one
 * definition shaped exactly like the guard {@see \SugarCraft\Forms\HasAsyncValidation::validateAsync()}
 * already runs for async validators: a host failure surfaces as a
 * REJECTED promise the consumer can see, never as a loop crash.
 *
 * SugarCraft-native (no upstream symbol: huh has no async suggestion
 * surface); the guard mirrors the in-lib HasAsyncValidation shape.
 */
final class AsyncFetcher
{
    /**
     * Invoke the fetcher inside the loop, then settle $deferred with its
     * outcome. Any throw (including a non-promise return) rejects the
     * deferred; a fulfilled non-array payload rejects with a typed message
     * naming the key. The opt-in wall-clock ceiling is applied here too,
     * belt-braced, because AsyncOps::withTimeout() raises synchronously on
     * a non-positive/non-finite ceiling — a value that could reach this
     * path only through a host that bypassed the setter validation.
     *
     * @param callable(mixed):mixed   $fetcher       host fetcher
     * @param mixed                   $input         value/filter text to fetch for
     * @param string                  $fieldKey      owning field key (error naming)
     * @param float|null              $timeoutSeconds per-fetch ceiling, null = raw await
     * @param Deferred                $deferred      settles with SuggestionsReadyMsg | null-reject
     */
    public static function runAndSettle(
        callable $fetcher,
        mixed $input,
        string $fieldKey,
        ?float $timeoutSeconds,
        Deferred $deferred
    ): void {
        try {
            $promise = $fetcher($input);
            if (!$promise instanceof PromiseInterface) {
                throw new \InvalidArgumentException(Lang::t('suggestions.async_return_type', [
                    'key'  => $fieldKey,
                    'type' => get_debug_type($promise),
                ]));
            }

            // E736-F2/6.5: opt-in wall-clock ceiling per fetch. Null
            // (default) skips the wrapper entirely — byte-identical await
            // of the raw fetcher promise.
            if ($timeoutSeconds !== null) {
                $promise = AsyncOps::withTimeout(Loop::get(), $promise, $timeoutSeconds);
            }
        } catch (\Throwable $e) {
            $deferred->reject($e);
            return;
        }

        $promise->then(
            static function (mixed $suggestions) use ($deferred, $fieldKey): void {
                // The fetcher contract is PromiseInterface<list<string>>;
                // a non-array fulfillment used to detonate an unconsumed
                // TypeError (suggestions never appeared, no diagnosis).
                // Reject loudly instead.
                if (!\is_array($suggestions)) {
                    $deferred->reject(new \InvalidArgumentException(Lang::t('suggestions.async_result_type', [
                        'key'  => $fieldKey,
                        'type' => get_debug_type($suggestions),
                    ])));
                    return;
                }
                $deferred->resolve(new SuggestionsReadyMsg($fieldKey, $suggestions));
            },
            static function (\Throwable $e) use ($deferred): void {
                $deferred->reject($e);
            }
        );
    }

    /**
     * Config-time validation shared by both withAsyncSuggestions doors
     * (SF-019: the same blast radius as a throwing fetcher, detonating at
     * keystroke time instead of at the call site that made the mistake).
     *
     * @throws \InvalidArgumentException on a negative debounce or a
     *                                   non-positive/non-finite timeout
     */
    public static function validateConfig(string $fieldKey, int $debounceMs, ?float $fetchTimeoutSeconds): void
    {
        if ($debounceMs < 0) {
            throw new \InvalidArgumentException(Lang::t('suggestions.bad_debounce', [
                'key' => $fieldKey,
                'ms'  => (string) $debounceMs,
            ]));
        }

        if ($fetchTimeoutSeconds !== null
            && (!\is_finite($fetchTimeoutSeconds) || $fetchTimeoutSeconds <= 0.0)) {
            throw new \InvalidArgumentException(Lang::t('suggestions.bad_timeout', [
                'key'     => $fieldKey,
                'seconds' => var_export($fetchTimeoutSeconds, true),
            ]));
        }
    }
}
