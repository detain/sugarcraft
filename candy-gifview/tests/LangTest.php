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

    /**
     * gifview-025 (keep arm): the key stays in the catalogue precisely
     * because the bin prints this string BEFORE any autoload — translation
     * cannot run there, so the catalogue exists for translators, and this
     * pin makes the fiction impossible: the hardcoded bin literal must stay
     * byte-equal to the en lookup value.
     */
    public function testTheBinShimLiteralIsByteEqualToTheCatalogueEntry(): void
    {
        $bin = (string) \file_get_contents(__DIR__ . '/../bin/candy-gifview');
        $prefix = 'fwrite(STDERR, "';
        $start = \strpos($bin, $prefix);
        $this->assertIsInt($start, 'the bin must still print its refusal through one fwrite');
        $from = $start + \strlen($prefix);
        $end = \strpos($bin, '\n");', $from);
        $this->assertIsInt($end, 'the refusal literal must end on the pinned quote shape');
        $literal = \substr($bin, $from, $end - $from);

        $en = require __DIR__ . '/../lang/en.php';
        $this->assertSame($literal, $en['cli.no_autoload'], 'bin literal and en catalogue entry drifted apart');
        $this->assertSame($literal, Lang::t('cli.no_autoload'), 'the en lookup must answer the same bytes');
    }

    public function testUnknownKeyFallsBackToNamespacedKey(): void
    {
        // T::translate falls back to the namespaced key when no translation exists.
        $result = Lang::t('nonexistent.key');
        $this->assertSame('gifview.nonexistent.key', $result);
    }
}
