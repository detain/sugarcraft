<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Lang;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\SelectionPrompt;
use SugarCraft\Readline\TextPrompt;

/**
 * S-10 (findings 040, 113-117): the catalog speaks in whole sentences with
 * named {placeholders}. No English morphology may be spliced at a call site
 * (no 'match' + 'es' ternary), no fragment may be concatenated onto a
 * translated sentence (min/max arms are whole keys), and no positional
 * %d/%s display formats remain — LOCALES.md is the law, en bytes prove it.
 */
final class PluralTokenTest extends TestCase
{
    protected function tearDown(): void
    {
        T::setLocale('en');
    }

    public static function matchArmsProvider(): array
    {
        return [
            'one match rides _one' => ['aa', ' (1 match)'],
            'two ride _other' => ['a', ' (2 matches)'],
            'zero rides _other (English prose: zero is plural)' => ['qq', ' (0 matches)'],
        ];
    }

    /** @dataProvider matchArmsProvider */
    public function testMatchCountIsAWholeSentencePerPluralClass(string $filter, string $expected): void
    {
        $prompt = (new SelectionPrompt('Pick: ', ['aa', 'ab', 'zz']))->withFilter($filter);
        $this->assertStringContainsString($expected, $prompt->view());
    }

    public function testMinAndMaxArmsAreWholeSentencesNotFragments(): void
    {
        $plain = static fn (string $s): string => (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);

        $base = new MultiSelectPrompt('Pick: ', ['a', 'b', 'c']);
        $rows = explode("\n", $plain($base->view()));
        $this->assertSame('[multi-select] selected: 0', $rows[1], 'bare arm: no clause spliced on');

        $minOnly = $base->withMinSelections(2);
        $rows = explode("\n", $plain($minOnly->view()));
        $this->assertSame('[multi-select] selected: 0 (min 2)', $rows[1]);

        $maxOnly = $base->withMaxSelections(3);
        $rows = explode("\n", $plain($maxOnly->view()));
        $this->assertSame('[multi-select] selected: 0 (max 3)', $rows[1]);

        $both = $minOnly->withMaxSelections(3);
        $rows = explode("\n", $plain($both->view()));
        $this->assertSame('[multi-select] selected: 0 (min 2) (max 3)', $rows[1], 'combo key, not two fragments');
    }

    public function testSearchLineIsTheWholeUnitAndKeepsTheNeedle(): void
    {
        $history = new InMemoryHistory();
        $history->push('keep this entry');
        $p = TextPrompt::new('> ')->withHistory($history)
            ->handleKey(Key::CtrlR);
        foreach (['k', 'e', 'e', 'p'] as $ch) {
            $p = $p->handleChar($ch);
        }
        $this->assertStringContainsString(
            "(reverse-i-search)`keep': ",
            $p->view(),
            'the echo line rides the full-sentence key with the needle intact'
        );

        // Now push the query past any match: the failed variant is a whole
        // line of its own, not a 'failed ' fragment glued in place.
        $failed = $p;
        foreach (['x', 'y', 'z'] as $ch) {
            $failed = $failed->handleChar($ch);
        }
        $this->assertStringContainsString(
            "(failed reverse-i-search)`keepxyz': ",
            $failed->view()
        );
    }

    public function testNeedleSpecialBytesSurviveSanitizeIntoTheLine(): void
    {
        $history = new InMemoryHistory();
        $history->push("git status `it's a \\trap");
        $p = TextPrompt::new('> ')->withHistory($history)->handleKey(Key::CtrlR);
        // A needle carrying the line's own quoting characters and an
        // interpolation token must land VERBATIM — sanitize strips escape
        // bytes only, and a MATCHING needle keeps the line un-failed.
        $needle = "`it's a \\trap";
        foreach (str_split($needle) as $ch) {
            $p = $p->handleChar($ch);
        }
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $p->view());
        $this->assertStringContainsString('(reverse-i-search)`' . $needle . "': ", $plain);
    }

    public function testQueryBracesAreNotReInterpolated(): void
    {
        $history = new InMemoryHistory();
        $history->push('whatever');
        $p = TextPrompt::new('> ')->withHistory($history)->handleKey(Key::CtrlR);
        foreach (str_split('{label}') as $ch) {
            $p = $p->handleChar($ch);
        }
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $p->view());
        // strtr is single-pass: the query's literal {label} rides through as
        // text; it must NOT swallow/duplicate the real label slot.
        $this->assertSame(1, substr_count($plain, '> '), "label appears once, brace payload is inert: {$plain}");
        $this->assertStringContainsString("`{label}': ", $plain);
    }

    public function testGermanLocaleRendersNamedTokensFromItsOwnFile(): void
    {
        T::setLocale('de');
        $this->assertSame('Seite 1/2', Lang::t('selection.page', ['page' => 1, 'of' => 2]));
        $this->assertSame(' ({count} Übereinstimmungen)', Lang::t('selection.matches_other'));
        $this->assertSame(
            '[Mehrfachauswahl] ausgewählt: 1 (min 2)',
            Lang::t('multiselect.hint_min', ['count' => 1, 'min' => 2])
        );
    }

    /**
     * The -116 source law: sugar-readline displays nothing through
     * sprintf-positioned formats anymore — chrome render sites are all
     * named-token interpolations.
     */
    public function testNoPositionalSprintfRemainsInSrc(): void
    {
        $files = array_merge(
            (array) glob(__DIR__ . '/../src/*.php'),
            (array) glob(__DIR__ . '/../src/*/*.php'),
            (array) glob(__DIR__ . '/../src/*/*/*.php')
        );
        $this->assertGreaterThan(10, count($files), 'src sweep must see the tree');
        foreach ($files as $file) {
            $this->assertSame(
                0,
                substr_count((string) file_get_contents($file), 'sprintf('),
                basename($file) . ' must not positionally format display strings'
            );
        }
    }
}
