<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncCache;

use function React\Promise\resolve;

/**
 * Regression: PHP stores a numeric-string array key as an int, and keys()
 * used to hand that int back, breaking the declared string contract. A
 * strict in_array('123', ...) missed the key, and a string-typed consumer
 * under strict_types=1 threw a TypeError.
 */
final class AsyncCacheNumericKeyTest extends TestCase
{
    public function test_keys_returns_numeric_string_keys_as_strings(): void
    {
        $cache = AsyncCache::new();
        $this->store($cache, '123', 'numeric');
        $this->store($cache, 'name', 'word');

        $keys = $cache->keys();

        self::assertSame(['123', 'name'], $keys);
        self::assertContainsOnly('string', $keys, true);
        self::assertTrue(in_array('123', $keys, true), 'a strict lookup by the string key must find it');
    }

    public function test_numeric_key_round_trips_into_a_string_typed_consumer(): void
    {
        $cache = AsyncCache::new();
        $this->store($cache, '42', 'answer');

        foreach ($cache->keys() as $key) {
            self::assertSame('answer', self::describe($key));
        }
    }

    private static function describe(string $key): string
    {
        return $key === '42' ? 'answer' : 'other';
    }

    private function store(AsyncCache $cache, string $key, string $value): void
    {
        $cache->fetch($key, static fn (): PromiseInterface => resolve($value));
    }
}
