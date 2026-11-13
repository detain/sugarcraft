<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;

/**
 * sc-38 (finding sugar-core-064): the executable-lookup census must have
 * exactly ONE home, Locator. Editor and Open used to carry private
 * shell-probe copies (`command -v x`, `where x`) — one fork per candidate,
 * per-OS behaviour that no unit test could walk, and the reason the same
 * quoting bug was fixed twice. This guard reddens the moment a
 * fork-per-candidate probe returns to either file; the behavioural side of
 * the fold is covered by LocatorTest (the single census) plus the existing
 * Editor/Open suites, which now walk Locator without knowing it.
 */
final class LocatorConsolidationTest extends TestCase
{
    public function testEditorCarriesNoForkProbeAndUsesLocator(): void
    {
        $source = $this->source('Editor.php');

        $this->assertStringNotContainsString('command -v', $source);
        $this->assertStringNotContainsString('where ', $source);
        $this->assertStringNotContainsString('2>/dev/null', $source);
        $this->assertStringNotContainsString('self::which', $source);
        $this->assertStringContainsString('Locator::locate', $source);
    }

    public function testOpenCarriesNoForkProbeAndUsesLocator(): void
    {
        $source = $this->source('Open.php');

        $this->assertStringNotContainsString('command -v', $source);
        $this->assertStringNotContainsString('where ', $source);
        $this->assertStringNotContainsString('2>/dev/null', $source);
        $this->assertStringNotContainsString('commandExists', $source);
        $this->assertStringContainsString('Locator::exists', $source);
    }

    private function source(string $file): string
    {
        $path = \dirname(__DIR__, 2) . '/src/Util/' . $file;
        $source = \file_get_contents($path);
        if ($source === false) {
            $this->fail("missing source under guard: {$path}");
        }

        return $source;
    }
}
