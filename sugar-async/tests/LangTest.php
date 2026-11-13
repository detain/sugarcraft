<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Lang;

/**
 * @covers \SugarCraft\Async\Lang
 *
 * These are the English-fallthrough contract pins, not translation coverage:
 * the catalogue (lang/en.php) is empty BY DESIGN — sugar-async's messages are
 * English literals at their sites — and the base chain (exact → base → en →
 * raw key) makes that dormant scaffolding observable by returning the
 * namespaced key itself. Should the catalogue ever be wired, these pins are
 * the first rows to replace.
 */
final class LangTest extends TestCase
{
    public function testLangTReturnsString(): void
    {
        // No translation exists, so t() falls through to the raw key with the
        // 'async' namespace prepended — the by-design contract above.
        $result = Lang::t('some.key');

        $this->assertIsString($result);
        $this->assertSame('async.some.key', $result);
    }

    public function testLangTWithParams(): void
    {
        // Params cannot rescue a missing key: the fallthrough still returns
        // the raw namespaced key.
        $result = Lang::t('another.key', ['name' => 'test']);

        $this->assertIsString($result);
        $this->assertSame('async.another.key', $result);
    }
}
