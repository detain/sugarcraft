<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Lang;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-library i18n Lang wrapper.
 */
final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure the T registry is fresh for each test so we don't get
        // cross-contamination from a previously-loaded 'flip' namespace.
        \SugarCraft\Core\I18n\T::reset();
    }

    public function testTranslateKnownKey(): void
    {
        $result = Lang::t('decoder.no_file', ['path' => '/tmp/test.gif']);
        $this->assertStringContainsString('candy-flip', $result);
        $this->assertStringContainsString('/tmp/test.gif', $result);
    }

    public function testTranslateAnotherKnownKey(): void
    {
        $result = Lang::t('decoder.no_gd');
        $this->assertSame('candy-flip: ext-gd is required', $result);
    }

    public function testTranslateCliUsage(): void
    {
        $result = Lang::t('cli.usage');
        $this->assertStringContainsString('usage:', $result);
        $this->assertStringContainsString('candy-flip', $result);
    }

    public function testParameterInterpolation(): void
    {
        $result = Lang::t('decoder.grid_too_large', ['max' => '100000']);
        $this->assertStringContainsString('100000', $result);
    }

    public function testUnknownKeyFallsBackToNamespacedKey(): void
    {
        // T::translate falls back to the namespaced key when no translation exists.
        $result = Lang::t('nonexistent.key');
        $this->assertSame('flip.nonexistent.key', $result);
    }

    public function testEmptyParamsDoesNotCrash(): void
    {
        $result = Lang::t('decoder.not_gif');
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function testNamespaceIsFlip(): void
    {
        // The full key seen by T is "flip.decoder.no_gd".
        $result = Lang::t('decoder.no_gd');
        // The underlying T registry stores files under the flip namespace.
        $this->assertStringContainsString('candy-flip', $result);
    }
}
