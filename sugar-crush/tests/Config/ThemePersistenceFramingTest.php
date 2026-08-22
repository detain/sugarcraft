<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * THREE DOCUMENTS DESCRIBED THE SAME COUNTERFACTUAL AND ONLY ONE OF THEM WAS
 * RIGHT. `config.json` outranks `settings.json`; the question every settings
 * page has to answer is what would break if it did not.
 *
 *  - {@see LayeredSettings}' class doc-block said every `/theme` would
 *    "appear to do nothing". Coarser, and wrong.
 *  - `docs/SETTINGS.md` said the theme repaints immediately and then silently
 *    reverts on the next launch — what breaks is PERSISTENCE, not the visible
 *    command. Right.
 *  - `README.md` was aligned to the persistence framing in round 42.
 *
 * The distinction is not pedantry, it is the diagnosis a reader leaves with.
 * "The command does nothing" sends someone to `Chat`; "it does not stick"
 * sends them to a settings file, which is where the problem is.
 *
 * DRIVEN, NOT QUOTED. `SETTINGS.md` being the surviving document is not why it
 * is believed here — three documents disagreeing is exactly the situation in
 * which the third one is also wrong. The mechanism tests below reproduce both
 * halves separately: that `/theme` mutates the live `Chat` (so the repaint is
 * unconditional), and that only the MERGE — read at the next launch — is where
 * an ordering could take the choice away.
 *
 * MEASURED ON PHP 8.3.6, 2026-08-22. PHP 8.4 was NOT exercised: this box has
 * only 8.3.6 while CI runs 8.3 and 8.4. Nothing on this path is believed
 * version-sensitive — it is `array_merge()` and a plain property read — and
 * the stamp is provenance rather than a caveat.
 *
 * @internal
 */
final class ThemePersistenceFramingTest extends TestCase
{
    private const SETTINGS_DOC = __DIR__ . '/../../docs/SETTINGS.md';

    /**
     * Paragraphs, whitespace-normalised, of a doc-block or markdown page — the
     * same normalisation {@see ConfigWriteProducerDocumentationDriftTest} uses
     * and for the same reason: the claims being checked are line-wrapped, so a
     * raw `str_contains()` reports a line break as a defect.
     *
     * @return list<string>
     */
    private function paragraphs(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $lines[] = preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? $line;
        }

        $out = [];
        foreach (preg_split('/\n\s*\n/', implode("\n", $lines)) ?: [] as $para) {
            $normalised = trim((string) preg_replace('/\s+/', ' ', $para));
            if ($normalised !== '') {
                $out[] = $normalised;
            }
        }

        return $out;
    }

    private function docBlock(): string
    {
        $doc = (new \ReflectionClass(LayeredSettings::class))->getDocComment();
        self::assertIsString($doc, 'LayeredSettings lost its class doc-block');

        return $doc;
    }

    // ── mechanism: the repaint half ──────────────────────────────────────

    /**
     * `/theme` MUTATES THE LIVE CHAT, so the visible command works no matter
     * what any settings file says. This is the half the old doc-block denied,
     * and it is why "appear to do nothing" was the wrong sentence.
     *
     * Both effects are asserted in one test on purpose: the framing is that
     * the repaint and the persistence are SEPARATE, and a test that checked
     * only one of them would leave the other free to disappear.
     */
    public function testSlashThemeRepaintsImmediatelyAndSeparatelyAsksToPersist(): void
    {
        $written = [];
        $chat = (new Chat(inputBuf: '/theme dracula', themeName: 'dark'))
            ->withOnConfigChange(function (string $k, string $v) use (&$written): void {
                $written[$k] = $v;
            });

        $this->assertSame('dark', $chat->theme()->name);

        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(
            'dracula',
            $next->theme()->name,
            '/theme must repaint the live session, independent of every settings file',
        );
        $this->assertSame(
            ['theme' => 'dracula'],
            $written,
            'and must separately ask for the choice to be persisted',
        );
    }

    /**
     * The repaint does not depend on a persistence callback existing at all —
     * every embedder builds a `Chat` without one. If it did, "persistence is
     * the only thing at risk" would be false.
     */
    public function testTheRepaintSurvivesAChatThatPersistsNothing(): void
    {
        [$next] = (new Chat(inputBuf: '/theme dracula', themeName: 'dark'))
            ->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('dracula', $next->theme()->name);
    }

    // ── mechanism: the persistence half ──────────────────────────────────

    /**
     * And the ordering is where a `settings.json` `theme` could take it away —
     * at the NEXT launch's merge, which is the only place the files are
     * consulted. `Bootstrap::chat()` reads `themeName` out of exactly this
     * merged array.
     */
    public function testTheMergeIsWhereTheOrderingDecidesTheNextLaunchesTheme(): void
    {
        $merged = LayeredSettings::merge(
            ['theme' => 'dracula'],    // layer 4, what /theme just persisted
            ['theme' => 'settingsy'],  // layer 3
            ['theme' => 'projecty'],   // layers 1+2
        );

        $this->assertSame('dracula', $merged['theme'], 'config.json must win, or a persisted /theme silently reverts');

        // The counterfactual, spelled out rather than asserted about prose: if
        // `config.json` ranked BELOW `settings.json`, the same three files hand
        // the next launch the hand-authored value instead, and nothing about
        // the SESSION changes. Modelled by demoting what `/theme` persisted
        // into the second slot — THROUGH `merge()`, so the thing being measured
        // is the ranking this class implements.
        //
        // THIS BLOCK USED TO BE `array_merge(['theme' => 'projecty'], …)` with
        // the layers hand-shuffled. WHAT WAS WRONG WITH IT: it invoked no code
        // under test. Both operands were literals and the function was the PHP
        // builtin, so it could not fail for any change to `LayeredSettings` —
        // the same shape {@see ReadmeSettingsTierClaimTest} retracted from its
        // own file ("It was 10 of this file's 25 assertions and pinned
        // nothing"). WHY THE COUNTERFACTUAL STILL EARNS AN ASSERTION: it is the
        // half of the framing that says the damage is deferred to the next
        // launch, and a reader who is told that and shown nothing goes looking
        // for it in `Chat`.
        $asIfConfigRankedBelowSettings = LayeredSettings::merge(
            ['theme' => 'settingsy'],  // the hand-authored file, in the slot that wins
            ['theme' => 'dracula'],    // what /theme persisted, demoted
            ['theme' => 'projecty'],
        );
        $this->assertSame(
            'settingsy',
            $asIfConfigRankedBelowSettings['theme'],
            'merge() no longer ranks its first argument highest, so the counterfactual below it is not the '
            . 'counterfactual the doc-block describes',
        );
    }

    // ── prose ────────────────────────────────────────────────────────────

    /**
     * The doc-block's counterfactual paragraph must state the persistence
     * framing. Located by the phrase that makes it that paragraph rather than
     * by a character offset.
     */
    public function testTheDocBlockCounterfactualNamesPersistenceNotADeadCommand(): void
    {
        $hit = null;
        foreach ($this->paragraphs($this->docBlock()) as $para) {
            if (str_contains($para, 'Ranked the other way round')) {
                $hit = $para;

                break;
            }
        }

        $this->assertNotNull($hit, 'the reversed-ordering counterfactual is gone from the LayeredSettings doc-block');
        $this->assertStringContainsString('PERSISTENCE', $hit, 'the counterfactual no longer names what actually breaks');
        $this->assertMatchesRegularExpression(
            '/repaint/i',
            $hit,
            'the counterfactual no longer says the theme repaints anyway — which is the half that was wrong',
        );
    }

    /**
     * And the retracted wording may appear only in the paragraph that retracts
     * it. Paragraph-scoped, not a character window, for the reason round 42
     * measured: a window wide enough to reach a retraction is wide enough for
     * the restored sentence to sit inside it and inherit it.
     */
    public function testTheRetractedDeadCommandClaimAppearsOnlyInsideItsRetraction(): void
    {
        $needle = 'appear to do nothing';

        $offenders = [];
        foreach ($this->paragraphs($this->docBlock()) as $para) {
            if (!str_contains($para, $needle)) {
                continue;
            }
            if (!str_contains($para, 'AN EARLIER VERSION OF THIS PARAGRAPH')) {
                $offenders[] = mb_substr($para, 0, 90);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'the retracted "every /theme would appear to do nothing" claim is back outside its retraction',
        );
    }

    /**
     * `docs/SETTINGS.md` is the document that was right; pin it so the
     * agreement is not one-sided. If it ever drifts back to the coarse
     * framing, the doc-block would be left as the only correct copy — the same
     * failure with the documents swapped, which is precisely how this pair got
     * out of step the first time.
     */
    public function testTheSettingsPageStillCarriesThePersistenceFraming(): void
    {
        $doc = (string) file_get_contents(self::SETTINGS_DOC);

        $hit = null;
        foreach ($this->paragraphs($doc) as $para) {
            if (str_contains($para, 'a `settings.json` naming `theme` would outrank')) {
                $hit = $para;

                break;
            }
        }

        $this->assertNotNull($hit, 'docs/SETTINGS.md lost its reversed-ordering counterexample');
        $this->assertStringContainsString('repaint', $hit);
        $this->assertStringContainsString('persistence', $hit);
    }

    /**
     * EVERY MEASURED FIGURE ON THIS PATH CARRIES A PHP VERSION. E68 was a
     * defect recorded as unconditional that turned out to be PHP-8.3-only, and
     * the thing that would have caught it is a version beside the measurement.
     * Neither of the two passages below is BELIEVED version-sensitive; the
     * stamp is provenance, and the cost of carrying it is one clause.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function stampedMeasurements(): iterable
    {
        // LOCATORS ARE THE DISTINCTIVE PHRASE OF THE PASSAGE, not the figure it
        // reports, and the first cut of this provider got that wrong in a way
        // worth recording: it located the negated-class measurement by `[!B]*`
        // plus the word "measured", and in `docs/SETTINGS.md` that matched the
        // "Two things narrow this" paragraph — a DIFFERENT measurement, several
        // paragraphs later, which quotes the same glob and legitimately carries
        // no version. The test failed against correct prose. When an assertion
        // like this reds, suspect its window before its subject.
        yield 'LayeredSettings: negated-class tool glob' => ['source', 'MEASURED end-to-end'];
        yield 'LayeredSettings: reversed theme ordering' => ['source', 'Ranked the other way round'];
        yield 'SETTINGS.md: negated-class tool glob' => ['settings', 'Measured end-to-end'];
    }

    /** @dataProvider stampedMeasurements */
    public function testEveryMeasuredPassageCarriesAPhpVersion(string $which, string $locator): void
    {
        // The whole SOURCE FILE, not just the class doc-block: the
        // negated-class measurement lives on {@see LayeredSettings::PROJECT_TIER_KEYS},
        // which a `getDocComment()` on the class cannot see.
        $text = $which === 'source'
            ? (string) file_get_contents((string) (new \ReflectionClass(LayeredSettings::class))->getFileName())
            : (string) file_get_contents(self::SETTINGS_DOC);

        $hits = [];
        foreach ($this->paragraphs($text) as $para) {
            if (stripos($para, $locator) !== false) {
                $hits[] = $para;
            }
        }

        $this->assertCount(
            1,
            $hits,
            $which . ': the locator "' . $locator . '" no longer identifies exactly one paragraph, '
            . 'so this assertion can no longer be trusted to be looking at the right one',
        );
        $hit = $hits[0];

        $this->assertNotNull($hit, $which . ': no measured paragraph found for ' . $locator);
        $this->assertStringContainsString(
            '8.3.6',
            $hit,
            $which . ': this measurement has no PHP version on it — an undated figure is how E68 happened',
        );
        $this->assertStringContainsString(
            '8.4',
            $hit,
            $which . ': say explicitly that 8.4 was not exercised on this box, or a reader assumes both were',
        );
    }
}
