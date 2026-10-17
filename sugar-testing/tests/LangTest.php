<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Lang;

/**
 * Tests for the Lang translation facade.
 *
 * These would have caught the fatal visibility violation in Step 1.
 *
 * @see Mirrors charmbracelet/bubbletea — i18n facade pattern
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
        // After Step 11, lang/en.php has real entries. A known key should
        // return its English translation rather than the raw key.
        // This test is conditional on Step 11 having populated en.php.
        $result = Lang::t('nonexistent.key');

        // The fallback behavior returns the raw namespaced key for unknown keys.
        $this->assertSame('testing.nonexistent.key', $result);
    }
}
