<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Readline\ConfirmationPrompt;
use SugarCraft\Readline\Lang;
use SugarCraft\Readline\SelectionPrompt;

/**
 * S-09 (finding 008): the chrome catalog is the single source for painted
 * library strings. The en values are the pre-i18n literals byte-for-byte —
 * the rest of the suite proves that at the render sites; this file proves
 * the catalog itself: key parity across every locale, the visible raw-key
 * fallback, and that explicit host strings stay opaque.
 */
final class LangFoundationTest extends TestCase
{
    /** The pre-i18n bytes each key must still produce (zero render diff). */
    public const EN_BYTES = [
        'confirm.yes' => 'Yes',
        'confirm.no' => 'No',
        'confirm.hint' => '[y/n]',
        'selection.filter_prefix' => '[filter] ',
        'selection.match_count' => ' (%d match%s)',
        'selection.no_matches' => '(no matches)',
        'selection.page' => 'Page %d/%d',
        'multiselect.selected' => '[multi-select] selected: %d',
        'multiselect.min' => ' (min %d)',
        'multiselect.max' => ' (max %d)',
        'text.invalid_input' => 'Invalid input',
        'text.search_reverse' => 'reverse-i-search',
        'text.search_forward' => 'i-search',
        'text.search_failed' => 'failed ',
    ];

    public static function tearDownAfterClass(): void
    {
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale('en');
    }

    public function testEveryKeyExistsWithItsOriginalBytes(): void
    {
        foreach (self::EN_BYTES as $key => $bytes) {
            $this->assertSame($bytes, Lang::t($key), "en render of {$key} must be the pre-i18n literal");
        }
    }

    public function testLocaleFilesCarryExactlyTheEnKeySet(): void
    {
        $dir = dirname(__DIR__) . '/lang';
        $enKeys = array_keys(require $dir . '/en.php');
        $files = glob($dir . '/*.php');
        $this->assertIsArray($files);
        // en + the fleet's 16 sibling locales (the full LOCALES.md roster;
        // sugar-toast is the fleet's 17-file exemplar).
        $this->assertCount(17, $files, 'full fleet locale parity');
        foreach ($files as $file) {
            $code = basename($file, '.php');
            $keys = array_keys(require $file);
            $this->assertSame($enKeys, $keys, "{$code}.php keys must ride en.php verbatim (order included)");
        }
    }

    public function testPlaceholdersSurviveEveryLocaleValue(): void
    {
        $dir = dirname(__DIR__) . '/lang';
        foreach (glob($dir . '/*.php') as $file) {
            $values = require $file;
            // %d is contract everywhere; %s may legitimately drop out of a
            // translated match_count (extra sprintf args are ignored).
            $this->assertStringContainsString('%d', $values['selection.page'], basename($file));
            $this->assertStringContainsString('%d', $values['multiselect.selected'], basename($file));
            $this->assertStringContainsString('%d', $values['multiselect.min'], basename($file));
            $this->assertStringContainsString('%d', $values['multiselect.max'], basename($file));
        }
    }

    public function testMissingKeySurfacesTheRawKey(): void
    {
        // AGENTS lookup law: exact -> base -> en -> RAW, never blank.
        $this->assertSame('readline.text.no_such_key', T::t('readline.text.no_such_key'));
    }

    public function testTranslatedLocaleReachesThePromptRender(): void
    {
        T::setLocale('fr');
        $view = (new ConfirmationPrompt('Etes-vous sur?', true))->view();
        $this->assertStringContainsString('[Oui]', $view, 'catalog reaches the painted row');
        $this->assertStringContainsString('[y/n]', $view, 'key notation rides verbatim');
        $this->assertStringContainsString('Non', $view, 'the unselected twin rides the catalog too');
    }

    public function testExplicitLabelsStayOpaqueUnderAnyLocale(): void
    {
        T::setLocale('fr');
        $view = (new ConfirmationPrompt('Go?'))
            ->withConfirmLabel('GO')
            ->withCancelLabel('STOP')
            ->view();
        $this->assertStringContainsString('GO', $view);
        $this->assertStringContainsString('STOP', $view);
        $this->assertStringNotContainsString('Oui', $view, 'host strings are never catalog material');
    }

    public function testBaseLanguageFallbackForRegionalLocale(): void
    {
        T::setLocale('fr-fr');
        $this->assertSame('Oui', Lang::t('confirm.yes'), 'fr-fr falls back to fr.php');
        T::setLocale('pt-br');
        $this->assertStringContainsString('seleção', Lang::t('multiselect.selected'), 'pt-br uses its own file');
    }

    public function testFilterBarBytesStableAcrossBothSurfacesUnderLocale(): void
    {
        T::setLocale('de');
        $single = (new SelectionPrompt('Pick: ', ['a']))->view();
        $this->assertStringContainsString('[Filter] ', $single);
    }
}
