<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use SugarCraft\Core\I18n\T;
use SugarCraft\Log\Lang;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \SugarCraft\Log\Lang
 */
final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        T::reset();
    }

    protected function tearDown(): void
    {
        T::reset();
        parent::tearDown();
    }

    /**
     * @covers ::t
     */
    public function testTReturnsTranslation(): void
    {
        $result = Lang::t('logger.fatal');

        $this->assertSame('fatal log: {message}', $result);
    }

    /**
     * @covers ::t
     */
    public function testTInterpolatesParameters(): void
    {
        $result = Lang::t('logger.fatal', ['message' => 'out of memory']);

        $this->assertSame('fatal log: out of memory', $result);
    }

    /**
     * @covers ::t
     */
    public function testTReturnsRawKeyWhenMissing(): void
    {
        // When a key is missing from all locale fallbacks, T::translate()
        // returns the fully-qualified key that was passed to it (including
        // the namespace prefix assembled in Lang::t()).
        $result = Lang::t('nonexistent.key');

        $this->assertSame('log.nonexistent.key', $result);
    }

    /**
     * @covers ::t
     */
    public function testTWithEmptyParamsReturnsTranslationWithoutReplacement(): void
    {
        $result = Lang::t('logger.fatal', []);

        $this->assertSame('fatal log: {message}', $result);
    }

    /**
     * @covers ::t
     */
    public function testTReturnsUnmatchedPlaceholdersIntact(): void
    {
        // When a placeholder is provided in the translation but no value is
        // passed, the placeholder should remain literal
        $result = Lang::t('logger.fatal', ['other' => 'value']);

        $this->assertSame('fatal log: {message}', $result);
    }

    public function testNamespaceIsLog(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('NAMESPACE');

        $this->assertSame('log', $constant);
    }

    public function testDirPointsToLangDirectory(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('DIR');

        // __DIR__ in the test file differs from __DIR__ in the source file,
        // so compare realpath() of both to normalize path resolution.
        $this->assertSame(realpath(__DIR__ . '/../lang'), realpath($constant));
    }
}
