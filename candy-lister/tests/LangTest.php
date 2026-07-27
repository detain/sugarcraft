<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Lister\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    public function testTranslateNoItemsKey(): void
    {
        $result = Lang::t('list.no_items');
        $this->assertSame('NoItems: list has no items', $result);
    }

    public function testTranslateZeroViewportKey(): void
    {
        $result = Lang::t('list.zero_viewport');
        $this->assertSame("Can't display with zero width or height of viewport", $result);
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
        $this->assertSame('lister.nonexistent.key', $result);
    }

    public function testTranslationNamespaceIsIdempotent(): void
    {
        // Calling t() multiple times should work (registration is idempotent)
        $result1 = Lang::t('list.no_items');
        $result2 = Lang::t('list.no_items');
        $this->assertSame($result1, $result2);
    }
}
