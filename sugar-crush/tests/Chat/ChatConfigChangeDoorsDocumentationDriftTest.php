<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * `Chat::$onConfigChange` has FOUR doors, and every doc-block that enumerates
 * them must name all four.
 *
 * THE FAMILY, NOT THE SENTENCE, AND THE FAMILY IS FIVE. One recurring defect:
 * a doc-block or page that credits the Ctrl+P palette for a write a slash
 * command also performs. It was found in {@see \SugarCraft\Crush\Config\LayeredSettings}'
 * class doc-block and in `docs/SETTINGS.md` (round 43, E81, pinned by
 * {@see \SugarCraft\Crush\Tests\Config\ConfigWriteProducerDocumentationDriftTest}),
 * in `README.md`'s reversed-ordering counterfactual (same round, same file's
 * `testTheReadmeCounterfactualCreditsTheSlashCommandAndNotThePaletteAlone()`),
 * TWICE in `src/Chat.php` (round 44, E106 — this file), and in
 * `docs/ENVIRONMENT.md`'s precedence paragraph (round 44's review follow-up,
 * pinned by {@see testTheEnvironmentPrecedenceParagraphNamesAllFourDoors()}).
 * The backlog entry for E106 called it "one line"; it was two, in two
 * doc-blocks describing the same property, and finding the second one is why
 * this guard enumerates the documents rather than pinning a string.
 *
 * WHERE THAT ROSTER COMES FROM, because the previous version of this paragraph
 * did not have one. WHAT IT SAID: the four documents above, full stop. WHAT IS
 * TRUE NOW: that list was copied out of E81's write-up and never re-run against
 * the tree, and `docs/ENVIRONMENT.md` was carrying the same omission the whole
 * time — an inherited sentence presented as a census. WHY THE ROSTER STILL
 * EARNS ITS PLACE: it is the only index of the family a reader has, so the fix
 * is to say how it was obtained rather than to drop it. The census is in
 * {@see testTheEnvironmentPrecedenceParagraphNamesAllFourDoors()}'s doc-block,
 * as a command, and it is a text search over `src`, `docs` and `README.md` —
 * so it can only find documents that say "palette" near "persist"; a page that
 * describes the write without either word is still invisible to it.
 *
 * WHY THE DOORS ARE ASSERTED AND NOT JUST THE PROSE. A prose guard alone pins
 * the sentence against a number somebody typed. {@see testEveryDoorReachesTheCallback()}
 * drives all four through a real {@see Chat} and records what the callback
 * received, so the count the prose must match is a MEASUREMENT. Change the
 * routing and the behavioural test moves first; change the prose and the prose
 * test moves. Neither can be satisfied by editing the other.
 *
 * WHAT THIS CANNOT DO, stated so the next reader does not over-trust it, and
 * adapted (not inherited wholesale — see
 * {@see testOneParagraphNamesAllFourDoors()} on what inheriting E81's wording
 * without E81's luck cost this file) from the E81 file's equivalent paragraph:
 * a FIFTH door
 * — a new command routed into `selectPaletteProvider()` or
 * `handleThemeCommand()` — leaves every assertion here green while the
 * enumeration goes stale again. The routes are ordinary private-method calls
 * with no shared marker, so there is no cheap oracle for "every user-facing
 * route into this callback". What IS mechanically derivable is the set of KEYS,
 * and that is already censused from the token stream by
 * `ConfigWriteProducerDocumentationDriftTest::testConfigJsonEverReceivesExactlyTwoKeys()`.
 *
 * @internal
 */
final class ChatConfigChangeDoorsDocumentationDriftTest extends TestCase
{
    /**
     * The four doors, as the prose must name them. `Switch Model` and
     * `Switch Theme` are palette row labels; `/model` and `/theme` are the
     * slash commands that reach the identical write.
     */
    private const DOORS = ['Switch Model', '/model', 'Switch Theme', '/theme'];

    /**
     * The wording that omitted `/model`, verbatim. It may appear only inside a
     * paragraph that retracts it — the rule that survived round 42's mutation C
     * on the E81 file, where a character-window guard was shown to pin the
     * window rather than the claim.
     */
    private const RETRACTED = 'the Switch Model/Switch Theme palette actions (or /theme)';

    // ── mechanism ────────────────────────────────────────────────────────

    /**
     * All four doors, driven, with what each one actually wrote.
     *
     * The palette rows and the slash commands are ONE writer each with TWO
     * doors, which is the claim the doc-blocks make and the reason no separate
     * persistence code exists for the slash-command half.
     */
    public function testEveryDoorReachesTheCallback(): void
    {
        self::assertSame(['provider', 'custom'], self::viaSlashCommand('/model custom'));
        self::assertSame(['theme', 'light'], self::viaSlashCommand('/theme light'));
        self::assertSame(['provider', 'custom'], self::viaPalette('switch model', 'custom'));
        self::assertSame(['theme', 'light'], self::viaPalette('switch theme', 'light'));
    }

    /**
     * The roster the prose enumerates and the roster this file drives are the
     * same roster, and each door writes the key the prose claims.
     *
     * WHAT THIS TEST USED TO ASSERT, and why it was worth rewriting (round 44
     * review, MINOR-7): `assertCount(4, $observed)` over a four-element array
     * literal, and then `assertCount(count(self::DOORS), $observed)` comparing
     * two hand-typed literals. Neither could be failed by ANY change to `src/`
     * — they were arithmetic about this file. The doc-block on top of them
     * claimed the count was "a measurement, not a number somebody typed"; it
     * was exactly a number somebody typed, twice.
     *
     * WHAT IS TRUE NOW: `$observed` is keyed by the door LABEL the prose uses,
     * so the roster this file drives is compared against {@see DOORS} itself
     * rather than against its own length. Add a fifth label to `DOORS` without
     * driving it and this reds; drive a fifth door without listing it and this
     * reds; rename a label in `DOORS` and the prose guard and this both red.
     *
     * WHY IT STILL EARNS ITS PLACE next to {@see testEveryDoorReachesTheCallback()},
     * which asserts something strictly stronger about the same four drives:
     * that test pins the ROUTES, this one pins the ROSTER — the binding between
     * the four words the doc-blocks are searched for and the four things that
     * actually run. The prose guard searches for `DOORS`; without this, `DOORS`
     * is an unverified list and the prose guard is searching for whatever
     * somebody typed into it.
     *
     * What it still cannot do is catch a FIFTH door added to `Chat` and to
     * neither list — see the class doc-block; there is no oracle for that.
     */
    public function testTheDrivenRosterIsTheRosterTheProseEnumerates(): void
    {
        $observed = [
            '/model' => self::viaSlashCommand('/model custom'),
            '/theme' => self::viaSlashCommand('/theme light'),
            'Switch Model' => self::viaPalette('switch model', 'custom'),
            'Switch Theme' => self::viaPalette('switch theme', 'light'),
        ];

        self::assertSame(
            self::sortedUnique(self::DOORS),
            self::sortedUnique(array_keys($observed)),
            'the doors this test drives and the doors self::DOORS enumerates (and the prose guard '
                . 'therefore searches for) are different sets',
        );

        self::assertSame(
            ['/model' => 'provider', '/theme' => 'theme', 'Switch Model' => 'provider', 'Switch Theme' => 'theme'],
            array_map(static fn (array $pair): string => $pair[0], $observed),
            'a door writes a different config key than the one the doc-blocks pair it with',
        );

        self::assertSame(
            ['provider', 'theme'],
            self::sortedUnique(array_column($observed, 0)),
            'the callback writes a key the doc-blocks do not enumerate',
        );
    }

    // ── prose ────────────────────────────────────────────────────────────

    /**
     * Every doc-block describing the callback, and how to read it.
     *
     * Both are reached by REFLECTION rather than by slicing `Chat.php`, so a
     * doc-block that is moved, reindented or line-rewrapped is still found and
     * still checked; only DELETING it makes this go red, which is the correct
     * response to deleting the enumeration.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function enumeratingDocBlocks(): iterable
    {
        yield 'Chat::$onConfigChange constructor param' => ['property'];
        yield 'Chat::withOnConfigChange()' => ['method'];
    }

    /**
     * ONE paragraph must name all four doors. Scattering them across a
     * doc-block is how a reader ends up counting three — which is exactly what
     * happened here, since `/model` DID appear elsewhere in `Chat.php` while
     * the sentence a reader of this property lands on omitted it.
     *
     * THE RETRACTION PARAGRAPH IS NOT AN ANSWER TO THIS QUESTION, and round
     * 44's review is the reason that sentence is here. WHAT THIS GUARD DID
     * WHEN IT WAS WRITTEN: it searched every paragraph. WHAT IS TRUE ABOUT
     * THAT: a retraction of the form "WHAT THIS SAID: <the wording that omitted
     * /model>. WHAT IS TRUE NOW: … the missing one is `/model`" necessarily
     * quotes three doors in the bad sentence and names the fourth in the
     * correction, so it scores four out of four ALL BY ITSELF. Both doc-blocks
     * here had exactly that shape, so the guard was satisfied by its own
     * apology: reverting the live enumeration to the omitting form left all six
     * tests green (round 44 review, mutations D1/D2). WHY THE GUARD STILL
     * EARNS ITS PLACE rather than being replaced by a string pin: what must be
     * true is that a reader who lands on this doc-block meets all four doors in
     * one place, and only a paragraph search can say that. So the retraction is
     * EXCLUDED from the search and the exclusion is itself asserted — excluding
     * zero paragraphs would mean the retraction had been deleted, and excluding
     * two would mean the live enumeration had been swallowed by the exclusion.
     *
     * The sibling guard {@see \SugarCraft\Crush\Tests\Config\ConfigWriteProducerDocumentationDriftTest}
     * does not have this hole, and not by design: its retraction paragraph
     * happens to name only two of the doors it enumerates, so the live
     * paragraph is the only one that can satisfy it. This file inherited that
     * file's wording and not that file's luck.
     *
     * @dataProvider enumeratingDocBlocks
     */
    public function testOneParagraphNamesAllFourDoors(string $which): void
    {
        $paragraphs = self::paragraphs(self::docBlock($which));

        $retracting = array_values(array_filter($paragraphs, self::isRetraction(...)));
        $live = array_values(array_filter(
            $paragraphs,
            static fn (string $p): bool => !self::isRetraction($p),
        ));

        self::assertCount(
            1,
            $retracting,
            "the {$which} doc-block should hold exactly one retraction paragraph, and this search excludes it. "
                . 'Zero means the retraction was deleted and this exclusion is now hiding nothing; two means '
                . 'the exclusion may be swallowing the live enumeration and passing this test on a paragraph '
                . 'no reader is meant to believe.',
        );

        $hit = null;
        foreach ($live as $paragraph) {
            $missing = array_values(array_filter(
                self::DOORS,
                static fn (string $door): bool => !str_contains($paragraph, $door),
            ));

            if ($missing === []) {
                $hit = $paragraph;
                break;
            }
        }

        self::assertNotNull(
            $hit,
            "no single NON-RETRACTION paragraph of the {$which} doc-block names all four doors ("
                . implode(', ', self::DOORS) . '); an enumeration split across paragraphs is how E106 happened, '
                . 'and an enumeration that survives only inside the retraction of the wording it corrects is '
                . 'how round 44 nearly shipped the same defect twice',
        );
    }

    /**
     * Is this paragraph a retraction — i.e. one that exists to quote a wording
     * that was wrong?
     *
     * Two markers, either of which is enough. {@see RETRACTED} is the specific
     * wording E106 corrected. The other is the opening of the repo-wide
     * three-part form — WHAT IT SAID / WHAT IS TRUE NOW / WHY THIS STILL EARNS
     * ITS PLACE — which every corrected justification in this tree is written
     * in; both spellings of its first clause are matched, because this file's
     * two retractions say `WHAT THIS SAID` and
     * {@see \SugarCraft\Crush\Config\LayeredSettings}' says `WHAT IT SAID`
     * (both verified, this commit), and a guard that knows only one spelling
     * would silently stop excluding the day someone matched the other file.
     *
     * A future retraction of some OTHER wording still carries the second
     * marker and is excluded too, which is the behaviour wanted: ANY
     * retraction written in that form quotes the wrong sentence and then
     * corrects it, so it names the full roster by construction while the live
     * prose may still be short by one.
     */
    private static function isRetraction(string $paragraph): bool
    {
        return str_contains($paragraph, self::RETRACTED)
            || preg_match('/\bWHAT (?:THIS|IT) SAID\b/', $paragraph) === 1;
    }

    /**
     * `docs/ENVIRONMENT.md` is the FIFTH instance of this family, and it was
     * found by measuring the family rather than by inheriting a list of it.
     *
     * WHAT THE CLASS DOC-BLOCK ABOVE SAID when this file was written: that the
     * family was LayeredSettings + `docs/SETTINGS.md` + `README.md` + twice in
     * `src/Chat.php`. WHAT IS TRUE NOW: that list was copied from E81's
     * write-up and never re-run against the tree, and one more document was
     * carrying the same omission — `docs/ENVIRONMENT.md`'s precedence
     * paragraph said environment variables win "over a choice persisted to
     * `~/.sugar-crush/config.json` by the Ctrl+P palette", crediting the
     * palette alone for a write two slash commands also perform. WHY THIS
     * DESERVES A CASE OF ITS OWN rather than a mention: it is the one document
     * in the family whose subject is PRECEDENCE, so a reader who believes only
     * the palette persists concludes that `/model` is not overridden by
     * `$SUGARCRUSH_PROVIDER` — a wrong answer about the thing that page exists
     * to answer.
     *
     * The census that found it, re-runnable, from `sugar-crush/`:
     * `grep -rn 'Ctrl+P palette\|command palette' --include=*.php --include=*.md
     * src docs README.md | grep -i 'persist\|config.json\|writes\|saved'`
     * (PHP is irrelevant here; this is a text census, run on this box with GNU
     * grep 3.11). It reports four hits today: `README.md`'s persistence bullet
     * and `docs/SETTINGS.md`'s two, all three of which already name the slash
     * commands, and this one.
     */
    public function testTheEnvironmentPrecedenceParagraphNamesAllFourDoors(): void
    {
        $path = \dirname(__DIR__, 2) . '/docs/ENVIRONMENT.md';
        self::assertFileExists($path, 'the precedence document this guard reads has moved');

        $crediting = array_values(array_filter(
            self::paragraphs((string) file_get_contents($path)),
            static fn (string $p): bool => str_contains($p, 'Ctrl+P palette'),
        ));

        self::assertCount(
            1,
            $crediting,
            'docs/ENVIRONMENT.md should name the Ctrl+P palette in exactly one paragraph — the precedence '
                . 'one. Zero means the phrase was rewritten and this guard is now searching for nothing; '
                . 'more than one means there is a second place to keep in step and this row needs widening.',
        );

        $missing = array_values(array_filter(
            self::DOORS,
            static fn (string $door): bool => !str_contains($crediting[0], $door),
        ));

        self::assertSame(
            [],
            $missing,
            "docs/ENVIRONMENT.md's precedence paragraph does not name " . implode(', ', $missing)
                . '. It is describing what an environment variable OVERRIDES, so a door missing from it '
                . 'reads as a door the variable does not override.',
        );
    }

    /**
     * The retracted wording may appear only in a paragraph that retracts it.
     *
     * THE EXEMPTION IS LOAD-BEARING, not decorative, and the E81 file learned
     * this the expensive way: a guard that forgives a retraction must prove the
     * retraction actually matched, or it is passing because it found nothing
     * and would keep passing after the retraction was deleted. So the count of
     * quoting paragraphs is asserted, not merely the count of offenders.
     *
     * @dataProvider enumeratingDocBlocks
     */
    public function testTheOmittingWordingAppearsOnlyInsideItsRetraction(string $which): void
    {
        $paragraphs = self::paragraphs(self::docBlock($which));

        $quoting = array_values(array_filter(
            $paragraphs,
            static fn (string $p): bool => str_contains($p, self::RETRACTED),
        ));

        self::assertCount(
            1,
            $quoting,
            "the {$which} doc-block should quote the omitting wording exactly once — in its retraction. "
                . 'Zero means the retraction was deleted and this guard has stopped guarding anything; '
                . 'two means the false sentence is back.',
        );

        self::assertStringContainsString(
            'WHAT THIS SAID',
            $quoting[0],
            "the {$which} doc-block carries the /model-omitting wording outside a retraction",
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private static function docBlock(string $which): string
    {
        $doc = $which === 'property'
            ? (new \ReflectionProperty(Chat::class, 'onConfigChange'))->getDocComment()
            : (new \ReflectionMethod(Chat::class, 'withOnConfigChange'))->getDocComment();

        self::assertIsString($doc, "Chat's {$which} lost its doc-block");

        return $doc;
    }

    /**
     * Doc-block paragraphs, leader-stripped and whitespace-collapsed, because
     * every claim here is line-wrapped and a raw `str_contains()` would report
     * a line break as a defect.
     *
     * @return list<string>
     */
    private static function paragraphs(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $lines[] = preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? $line;
        }

        $out = [];
        foreach (preg_split('/\n\s*\n/', implode("\n", $lines)) ?: [] as $paragraph) {
            $normalised = trim((string) preg_replace('/\s+/', ' ', $paragraph));
            if ($normalised !== '') {
                $out[] = $normalised;
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function viaSlashCommand(string $line): array
    {
        $seen = [];
        $chat = (new Chat(inputBuf: $line, backend: new EchoBackend()))
            ->withSize(100, 30)
            ->withOnConfigChange(static function (string $k, string $v) use (&$seen): void {
                $seen[] = [$k, $v];
            });

        $chat->update(new KeyMsg(KeyType::Enter, ''));

        self::assertCount(1, $seen, "`{$line}` must fire the callback exactly once");

        return $seen[0];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function viaPalette(string $row, string $choice): array
    {
        $seen = [];
        $chat = (new Chat(backend: new EchoBackend()))
            ->withSize(100, 30)
            ->withOnConfigChange(static function (string $k, string $v) use (&$seen): void {
                $seen[] = [$k, $v];
            });

        [$current] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        foreach (str_split($row) as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }

        [$current] = $current->update(new KeyMsg(KeyType::Enter, ''));
        foreach (str_split($choice) as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }

        $current->update(new KeyMsg(KeyType::Enter, ''));

        self::assertCount(1, $seen, "the palette row '{$row}' must fire the callback exactly once");

        return $seen[0];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }
}
