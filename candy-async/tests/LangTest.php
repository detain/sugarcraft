<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Lang;

/**
 * @covers \SugarCraft\Async\Lang
 */
final class LangTest extends TestCase
{
    public function testLangTReturnsString(): void
    {
        // When no translation exists, t() returns the key itself with namespace
        // prefix (fallback chain: exact → base → en → raw key). Since lang/en.php
        // is empty, the key falls through with namespace prepended.
        $result = Lang::t('some.key');

        $this->assertIsString($result);
        $this->assertSame('async.some.key', $result);
    }

    public function testLangTWithParams(): void
    {
        // Even with params, the fallback returns the raw key with namespace when no translation exists
        $result = Lang::t('another.key', ['name' => 'test']);

        $this->assertIsString($result);
        $this->assertSame('async.another.key', $result);
    }
}
