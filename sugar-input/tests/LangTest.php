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
    /**
     * Fail-closed roster: every key shipped in lang/en.php must resolve to
     * a real translation through Lang::t() — a key falling through to the
     * raw 'input.<key>' sentinel fails the suite, no smoke tier.
     */
    public function testEveryShippedKeyResolvesNonRaw(): void
    {
        $messages = require __DIR__ . '/../lang/en.php';
        $this->assertIsArray($messages);
        $this->assertNotEmpty($messages, 'lang/en.php must ship at least one key');

        foreach ($messages as $key => $template) {
            $resolved = Lang::t($key);

            $this->assertIsString($resolved, $key . ' must resolve to a string');
            $this->assertNotSame('input.' . $key, $resolved, $key . ' fell through to the raw sentinel');
            $this->assertNotSame($key, $resolved, $key . ' came back as its own raw key');
        }
    }

    public function testPasteTruncatedKeyInterpolation(): void
    {
        $result = Lang::t('paste.truncated', ['bytes' => 10, 'mib' => 0]);

        $this->assertSame('Paste truncated at 0 MiB (10 bytes).', $result);
    }

    public function testPasteTruncatedWithLargeBytes(): void
    {
        $result = Lang::t('paste.truncated', ['bytes' => 1048576, 'mib' => 1]);

        $this->assertSame('Paste truncated at 1 MiB (1048576 bytes).', $result);
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
}
