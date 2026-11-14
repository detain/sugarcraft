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
        'selection.matches_one' => ' ({count} match)',
        'selection.matches_other' => ' ({count} matches)',
        'selection.no_matches' => '(no matches)',
        'selection.page' => 'Page {page}/{of}',
        'multiselect.hint' => '[multi-select] selected: {count}',
        'multiselect.hint_min' => '[multi-select] selected: {count} (min {min})',
        'multiselect.hint_max' => '[multi-select] selected: {count} (max {max})',
        'multiselect.hint_min_max' => '[multi-select] selected: {count} (min {min}) (max {max})',
        'text.invalid_input' => 'Invalid input',
        'text.search_line_reverse' => '{label}(reverse-i-search)`{query}\': ',
        'text.search_line_forward' => '{label}(i-search)`{query}\': ',
        'text.search_line_reverse_failed' => '{label}(failed reverse-i-search)`{query}\': ',
        'text.search_line_forward_failed' => '{label}(failed i-search)`{query}\': ',
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
        // Named-token contract (LOCALES.md law): a translation may REORDER
        // {tokens}, never rename or drop them — every locale must carry the
        // exact token set its en template uses, key by key.
        $dir = dirname(__DIR__) . '/lang';
        $en = require $dir . '/en.php';
        foreach (glob($dir . '/*.php') as $file) {
            $values = require $file;
            foreach ($en as $key => $enValue) {
                preg_match_all('/\{(\w+)\}/', $enValue, $m);
                $expected = $m[1];
                sort($expected);
                preg_match_all('/\{(\w+)\}/', $values[$key], $ml);
                $actual = $ml[1];
                sort($actual);
                $this->assertSame(
                    $expected,
                    $actual,
                    basename($file) . ":{$key} token set must ride en.php verbatim"
                );
            }
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
        $this->assertStringContainsString('seleção', Lang::t('multiselect.hint'), 'pt-br uses its own file');
    }

    public function testFilterBarBytesStableAcrossBothSurfacesUnderLocale(): void
    {
        T::setLocale('de');
        $single = (new SelectionPrompt('Pick: ', ['a']))->view();
        $this->assertStringContainsString('[Filter] ', $single);
    }
}
