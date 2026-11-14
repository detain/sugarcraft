<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use SugarCraft\Animation\Lang;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-library i18n Lang wrapper.
 */
final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure the T registry is fresh for each test so we don't get
        // cross-contamination from a previously-loaded 'animation' namespace.
        \SugarCraft\Core\I18n\T::reset();
    }

    public function testTranslateKnownKey(): void
    {
        $result = Lang::t('decoder.no_file', ['path' => '/tmp/test.gif']);
        $this->assertStringContainsString('sugar-animation', $result);
        $this->assertStringContainsString('/tmp/test.gif', $result);
    }

    public function testTranslateAnotherKnownKey(): void
    {
        $result = Lang::t('decoder.no_gd');
        $this->assertSame('sugar-animation: install the GD image extension (ext-gd) to decode GIFs - .ansi art playback does not need it', $result);
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
        $this->assertSame('animation.nonexistent.key', $result);
    }

    public function testEmptyParamsDoesNotCrash(): void
    {
        $result = Lang::t('decoder.not_gif');
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function testNamespaceIsAnimation(): void
    {
        // The full key seen by T is "animation.decoder.no_gd".
        $result = Lang::t('decoder.no_gd');
        // The underlying T registry stores files under the flip namespace.
        $this->assertStringContainsString('sugar-animation', $result);
    }

    /**
     * SA-L2 (audit 025): locale key parity, fail-closed by construction.
     * Every lang/*.php catalog must carry EXACTLY the key set of en.php —
     * both directions. A new locale file joins the census automatically,
     * and a key added to en alone goes red here instead of silently
     * falling back to English at runtime. No count is asserted: the
     * census on disk is the definition of the roster.
     */
    public function testAllLocalesCarryExactlyTheEnglishKeySet(): void
    {
        $files = glob(__DIR__ . '/../lang/*.php');
        $this->assertNotEmpty($files, 'the lang/ census found no catalogs');
        $english = require __DIR__ . '/../lang/en.php';
        $this->assertIsArray($english);
        $this->assertNotEmpty($english);

        $drift = [];
        foreach ($files as $file) {
            if (basename($file) === 'en.php') {
                continue;
            }
            $catalog = require $file;
            $this->assertIsArray($catalog, basename($file) . ' must return an array');
            $missing = array_diff(array_keys($english), array_keys($catalog));
            $extra = array_diff(array_keys($catalog), array_keys($english));
            if ($missing !== [] || $extra !== []) {
                $drift[] = sprintf(
                    '%s: missing [%s] extra [%s]',
                    basename($file),
                    implode(',', $missing),
                    implode(',', $extra),
                );
            }
        }

        $this->assertSame([], $drift, 'locale key drift from en.php:');
    }
}
