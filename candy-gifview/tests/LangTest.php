<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GifView\Lang;

/**
 * Tests for the candy-gifview i18n Lang wrapper. The `cli.*` keys moved here
 * from sugar-animation when the viewer CLI split out (rename campaign step 1.3).
 */
final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure the T registry is fresh for each test so we don't get
        // cross-contamination from a previously-loaded 'gifview' namespace.
        \SugarCraft\Core\I18n\T::reset();
    }

    public function testTranslateCliUsage(): void
    {
        $result = Lang::t('cli.usage');
        $this->assertStringContainsString('usage:', $result);
        $this->assertStringContainsString('candy-gifview', $result);
    }

    public function testTranslateCliNoAutoload(): void
    {
        $result = Lang::t('cli.no_autoload');
        $this->assertSame('candy-gifview: cannot find composer autoload.php', $result);
    }

    public function testUnknownKeyFallsBackToNamespacedKey(): void
    {
        // T::translate falls back to the namespaced key when no translation exists.
        $result = Lang::t('nonexistent.key');
        $this->assertSame('gifview.nonexistent.key', $result);
    }
}
