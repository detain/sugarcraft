<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\PerfSchema\EasySetup;

/**
 * The four default-profile constants are fed verbatim into SQL LIKE
 * patterns and '%s' interpolations (their values are trusted allowlists,
 * not user data). This guard keeps that trust structural: if a future
 * edit ever lands a quote, backslash, or control byte inside a default,
 * the interpolation sites turn from allowlist-fed into injection-shaped
 * and this test says which list rotted (pin owed with D-C9, 2026-11-03).
 */
final class EasySetupAllowlistTest extends TestCase
{
    /**
     * @return array<string, array{list<string>}>
     */
    public static function defaultProfiles(): array
    {
        return [
            'instruments 5.6' => [EasySetup::DEFAULT_INSTRUMENTS_56],
            'instruments 5.7' => [EasySetup::DEFAULT_INSTRUMENTS_57],
            'consumers 5.6' => [EasySetup::DEFAULT_CONSUMERS_56],
            'consumers 5.7' => [EasySetup::DEFAULT_CONSUMERS_57],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('defaultProfiles')]
    public function testEveryDefaultCarriesOnlyStructuralCharacters(array $patterns): void
    {
        $this->assertNotEmpty($patterns);
        foreach ($patterns as $pattern) {
            $this->assertMatchesRegularExpression(
                '/^[\w\/.%:-]+$/',
                $pattern,
                'defaults are allowlist material for SQL interpolation - only word chars, slash, dot, percent, colon, hyphen',
            );
            $this->assertStringNotContainsString(chr(0), $pattern);
            $this->assertStringNotContainsString(chr(27), $pattern);
        }
    }
}
