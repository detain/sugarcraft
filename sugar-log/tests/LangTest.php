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

    /**
     * [SL-27] Every routed error-path key must resolve to its English
     * template; a renamed or dropped key surfaces here as the fully
     * qualified raw fallback rather than a near-miss message.
     *
     * @covers ::t
     */
    public function testRoutedErrorPathKeysResolveToTheirTemplates(): void
    {
        $roster = [
            'logger.fatal'             => 'fatal log: {message}',
            'logger.invalid_stream'    => 'stream must be a valid resource',
            'psrbridge.not_string'     => 'Log level must be one of the PSR-3 LogLevel constants; got {got}',
            'psrbridge.unknown_level'  => "Unknown log level '{level}'; expected one of the PSR-3 LogLevel constants",
            'parts.unknown'            => 'unknown log line part(s): {parts}',
            'parts.duplicate'          => 'duplicate log line part(s): {parts}',
            'log.fatal_error'          => 'Fatal error',
            'log.fatal_parse'          => 'Parse error',
            'log.fatal_core'           => 'Core error',
            'log.fatal_compile'        => 'Compile error',
            'log.fatal_user'           => 'User error',
            'log.fatal_shutdown'       => 'Shutdown error',
        ];

        foreach ($roster as $key => $template) {
            $this->assertSame($template, Lang::t($key), "routed key {$key} must resolve to its template");
        }
    }

    /**
     * The 15 non-English locales all carry the same key set; a German
     * sample proves selection actually re-resolves (not just the file
     * sitting next to en.php).
     *
     * @covers ::t
     */
    public function testRoutedMessagesFollowTheSelectedLocale(): void
    {
        T::setLocale('de');

        $this->assertSame('Schwerwiegender Fehler', Lang::t('log.fatal_error'));
        $this->assertSame('Fatales Protokoll: {message}', Lang::t('logger.fatal'));
        $this->assertSame(
            'Die Protokollstufe muss eine der PSR-3-LogLevel-Konstanten sein; erhalten int',
            Lang::t('psrbridge.not_string', ['got' => 'int']),
        );
    }
}
