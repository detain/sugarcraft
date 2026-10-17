<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Lang;

/**
 * Tests for Lang i18n wrapper (Step 10).
 */
final class LangTest extends TestCase
{
    public function testLangClassExists(): void
    {
        $this->assertTrue(class_exists(Lang::class));
    }

    public function testPasteTruncatedKeyInterpolation(): void
    {
        $result = Lang::t('paste.truncated', ['bytes' => 10]);

        $this->assertSame('Paste truncated at 10 bytes.', $result);
    }

    public function testPasteTruncatedWithLargeBytes(): void
    {
        $result = Lang::t('paste.truncated', ['bytes' => 1048576]);

        $this->assertSame('Paste truncated at 1048576 bytes.', $result);
    }

    public function testUnknownKeyReturnsNamespacedKey(): void
    {
        // Unknown keys fall through the lookup chain and get the namespace prepended
        $result = Lang::t('nonexistent.key');

        // The base implementation prepends the library namespace 'input.'
        $this->assertSame('input.nonexistent.key', $result);
    }

    public function testUnknownKeyWithExplicitNamespace(): void
    {
        // When explicitly including the namespace, it gets prepended again
        $result = Lang::t('input.missing');

        $this->assertSame('input.input.missing', $result);
    }

    public function testLangTReturnsString(): void
    {
        $result = Lang::t('paste.truncated', ['bytes' => 1]);

        $this->assertIsString($result);
    }
}
