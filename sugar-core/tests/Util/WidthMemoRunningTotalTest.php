<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

/**
 * Regression pin for the memo's running byte total (rank 52). Eviction used to
 * re-sum every surviving key with array_map('strlen'); it now subtracts the
 * evicted keys. The invariant must hold through count-cap and byte-budget
 * eviction, including integer-like keys, which PHP stores as int array keys.
 */
final class WidthMemoRunningTotalTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetMemo();
    }

    protected function tearDown(): void
    {
        $this->resetMemo();
    }

    public function testRunningTotalMatchesKeyBytesAfterCountCapEviction(): void
    {
        // Well past MEMO_MAX (2048) so the count cap evicts several times.
        for ($i = 0; $i < 2600; $i++) {
            Width::string('k' . $i);
        }
        // Integer-like keys: stored as int, so their byte length needs the string form.
        for ($i = 100; $i < 2300; $i += 7) {
            Width::string((string) $i);
        }

        $this->assertSame($this->actualKeyBytes(), $this->trackedBytes());
        $this->assertLessThanOrEqual(2048, \count($this->memo()));
    }

    public function testRunningTotalMatchesKeyBytesAfterByteBudgetEviction(): void
    {
        $budget = (new \ReflectionClassConstant(Width::class, 'MEMO_MAX_BYTES'))->getValue();
        $chunk = str_repeat('y', 300_000);
        for ($i = 0; $i < 9; $i++) {
            Width::string('B' . $i . $chunk);
        }
        $this->assertLessThanOrEqual($budget, $this->trackedBytes());
        $this->assertSame($this->actualKeyBytes(), $this->trackedBytes());
    }

    public function testEvictedKeysRecomputeToTheSameWidth(): void
    {
        $first = 'first-' . str_repeat('z', 10);
        $expected = Width::string($first);
        for ($i = 0; $i < 2200; $i++) {
            Width::string('filler' . $i);
        }
        $this->assertSame($expected, Width::string($first));
    }

    /** @return array<int|string, mixed> */
    private function memo(): array
    {
        $p = new \ReflectionProperty(Width::class, 'memo');
        $p->setAccessible(true);
        return $p->getValue();
    }

    private function trackedBytes(): int
    {
        $p = new \ReflectionProperty(Width::class, 'memoBytes');
        $p->setAccessible(true);
        return $p->getValue();
    }

    private function actualKeyBytes(): int
    {
        $total = 0;
        foreach (array_keys($this->memo()) as $key) {
            $total += \strlen((string) $key);
        }
        return $total;
    }

    private function resetMemo(): void
    {
        foreach (['memo' => [], 'memoBytes' => 0, 'extendKey' => null, 'extendWidth' => 0, 'extendKeyHasEsc' => false] as $name => $value) {
            $p = new \ReflectionProperty(Width::class, $name);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }
}
