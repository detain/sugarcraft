<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * S-N02: candy-top is analysed at level 5 over src with NO baseline (the 73
 * findings were fixed, not recorded), and CI's phpstan job can only run if the
 * manifest requires phpstan itself.
 */
final class PhpstanConfigTest extends TestCase
{
    public function testLevelFiveOverSrcWithoutABaseline(): void
    {
        $neon = (string) file_get_contents(__DIR__ . '/../../phpstan.neon');
        $this->assertMatchesRegularExpression('/^\s*level:\s*5\s*$/m', $neon);
        $this->assertMatchesRegularExpression('/^\s*paths:\s*\n\s*-\s*src\s*$/m', $neon);
        $this->assertStringNotContainsString('baseline', $neon, 'fix-all: no baseline file');
        $this->assertFileDoesNotExist(__DIR__ . '/../../phpstan-baseline.neon');
    }

    public function testTheManifestRequiresPhpstanForTheCiJob(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('phpstan/phpstan', $composer['require-dev']);
    }
}
