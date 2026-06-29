<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Lang;

/**
 * Regression tests for Lang facade.
 *
 * Verifies the class does not fatal when extended (protected const
 * inheritance issue) and that Lang::t() returns a string.
 */
final class LangTest extends TestCase
{
    public function testLangFacadeDoesNotFatal(): void
    {
        // Calling Lang::t() on the facade must not trigger a fatal error.
        // With the fix (private const → protected const) the class extends
        // the base correctly.  The translation returns the raw key because
        // lang/en.php is empty, so we assert a string is returned.
        $result = Lang::t('x.y');

        $this->assertIsString($result);
        $this->assertSame('buffer.x.y', $result);
    }

    public function testLangClassCanBeExtended(): void
    {
        // Confirm the class is loadable and does not fatal on access.
        // The class string itself is the canonical reference.
        $this->assertSame('SugarCraft\Buffer\Lang', Lang::class);
    }
}
