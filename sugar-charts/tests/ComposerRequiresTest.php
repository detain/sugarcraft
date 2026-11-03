<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;

/**
 * lib-reviews-1103 batch 2, M15 + M1: ext-mbstring is called unconditionally
 * by the render paths, so it belongs in `require`, not `suggest`; and the
 * dashboard braille canvas is a null-check discriminant only — the runtime
 * dependency on sugar-dashboard moved to require-dev once the property and
 * setter signatures widened to ?object.
 */
final class ComposerRequiresTest extends TestCase
{
    /** @return array{require?: array<string,string>, 'require-dev'?: array<string,string>, suggest?: array<string,string>} */
    private static function manifest(): array
    {
        $path = dirname(__DIR__) . '/composer.json';
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{require?: array<string,string>, 'require-dev'?: array<string,string>, suggest?: array<string,string>} $json */
        return $json;
    }

    public function testMbstringIsARuntimeRequirement(): void
    {
        $m = self::manifest();
        $this->assertArrayHasKey('ext-mbstring', $m['require'] ?? []);
        $this->assertArrayNotHasKey('ext-mbstring', $m['suggest'] ?? []);
    }

    public function testDashboardIsDevOnlyAfterTheBrailleDiscriminantWidening(): void
    {
        $m = self::manifest();
        $this->assertArrayNotHasKey('sugarcraft/sugar-dashboard', $m['require'] ?? []);
        $this->assertArrayHasKey('sugarcraft/sugar-dashboard', $m['require-dev'] ?? []);
    }

    public function testSrcCarriesNoDashboardNamespaceReference(): void
    {
        $needle = 'SugarCraft\\Dashboard';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $this->assertStringNotContainsString(
                $needle,
                (string) file_get_contents($file->getPathname()),
                $file->getPathname() . ' still references the dashboard namespace',
            );
        }
    }
}
