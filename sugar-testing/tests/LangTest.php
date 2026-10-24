<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Lang;

/**
 * Tests for the Lang translation facade.
 *
 * The load smoke-test guards the class against the visibility mismatch
 * between SugarCraft\Testing\Lang and its core base; the known-key test
 * guards actual translation (with placeholder interpolation) — a facade
 * that only ever fell back to raw keys would pass a load test alone.
 */
final class LangTest extends TestCase
{
    public function testLangClassLoads(): void
    {
        // This call would fatal if the class is unloadable due to the
        // visibility mismatch between SugarCraft\Testing\Lang and its base.
        $result = Lang::t('anything.key');

        $this->assertIsString($result);
    }

    public function testLangFallsBackToRawKeyWhenMissing(): void
    {
        // When a key is not found in any translation file, the T::translate
        // chain falls back to exact → base → en → raw key (dot-joined).
        // For 'testing.nonexistent.key', the raw fallback should return
        // 'testing.nonexistent.key' (the namespaced key itself).
        $result = Lang::t('nonexistent.key');

        $this->assertSame('testing.nonexistent.key', $result);
    }

    public function testLangReturnsKnownKeyValue(): void
    {
        // A key present in lang/en.php must return its translation — not the
        // raw key — with {placeholder} tokens substituted from the params.
        $result = Lang::t('input.invalid_arrow', ['dir' => 'left']);

        $this->assertSame('Invalid arrow direction: left', $result);
    }
}
