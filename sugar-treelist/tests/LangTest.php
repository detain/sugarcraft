<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use SugarCraft\TreeList\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    public function testTranslateNoItemsKey(): void
    {
        // 2026-11-03 RE-PIN (named disclosure): the 'NoItems:' Go-sentinel prefix
        // was retired from the English copy — users receive a sentence.
        $result = Lang::t('list.no_items');
        $this->assertSame('The list has no items to display.', $result);
    }

    public function testTranslateZeroViewportKey(): void
    {
        // 2026-11-03 RE-PIN (named disclosure): grammar truth-flip alongside the
        // NoItems sentence; still carries the 'viewport' word render pins rely on.
        $result = Lang::t('list.zero_viewport');
        $this->assertSame('The viewport has zero width or height, so the list cannot be displayed.', $result);
    }

    public function testTranslateWithParams(): void
    {
        // The en.php doesn't have params, but we test the method still works
        $result = Lang::t('list.no_items', []);
        $this->assertIsString($result);
    }

    public function testTranslateKeyWithNonexistentFallback(): void
    {
        // A key that doesn't exist should return the raw key
        $result = Lang::t('nonexistent.key');
        $this->assertSame('treelist.nonexistent.key', $result);
    }

    public function testTranslationNamespaceIsIdempotent(): void
    {
        // Calling t() multiple times should work (registration is idempotent)
        $result1 = Lang::t('list.no_items');
        $result2 = Lang::t('list.no_items');
        $this->assertSame($result1, $result2);
    }
}
