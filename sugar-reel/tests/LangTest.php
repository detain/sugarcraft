<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Lang;
use ReflectionClass;

/**
 * Unit tests for Lang i18n facade.
 *
 * @covers \SugarCraft\Reel\Lang
 */
final class LangTest extends TestCase
{
    /**
     * @testdox Lang is a final class
     */
    public function testIsFinalClass(): void
    {
        $rc = new ReflectionClass(Lang::class);
        $this->assertTrue($rc->isFinal());
    }

    /**
     * @testdox NAMESPACE constant is 'reel'
     */
    public function testNamespaceConstant(): void
    {
        $rc = new ReflectionClass(Lang::class);
        $ns = $rc->getConstant('NAMESPACE');
        $this->assertSame('reel', $ns);
    }

    /**
     * @testdox DIR constant points to a real lang/ directory within the package
     */
    public function testDirConstant(): void
    {
        $rc = new ReflectionClass(Lang::class);
        $dir = $rc->getConstant('DIR');
        // DIR is __DIR__.'/../lang' from src/Lang.php; the 'lang' leaf must exist
        $this->assertSame('lang', basename($dir));
        $this->assertDirectoryExists(dirname(__DIR__) . '/lang');
    }

    /**
     * @testdox t() method is callable (inherited from BaseLang)
     */
    public function testTMethoIsCallable(): void
    {
        $this->assertTrue(method_exists(Lang::class, 't'));
    }
}
