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
 * THE FAMILY, NOT THE SENTENCE. This is the fourth instance of one recurring
 * defect: a doc-block that credits the Ctrl+P palette for a write a slash
 * command also performs. It was found in {@see \SugarCraft\Crush\Config\LayeredSettings}'
 * class doc-block and in `docs/SETTINGS.md` (round 43, E81, pinned by
 * {@see \SugarCraft\Crush\Tests\Config\ConfigWriteProducerDocumentationDriftTest}),
 * in `README.md`'s reversed-ordering counterfactual (same round, same file's
 * `testTheReadmeCounterfactualCreditsTheSlashCommandAndNotThePaletteAlone()`),
 * and TWICE in `src/Chat.php` (round 44, E106 — this file). The backlog entry
 * for E106 called it "one line"; it was two, in two doc-blocks describing the
 * same property, and finding the second one is why this guard enumerates the
 * documents rather than pinning a string.
 *
 * WHY THE DOORS ARE ASSERTED AND NOT JUST THE PROSE. A prose guard alone pins
 * the sentence against a number somebody typed. {@see testEveryDoorReachesTheCallback()}
 * drives all four through a real {@see Chat} and records what the callback
 * received, so the count the prose must match is a MEASUREMENT. Change the
 * routing and the behavioural test moves first; change the prose and the prose
 * test moves. Neither can be satisfied by editing the other.
 *
 * WHAT THIS CANNOT DO, stated so the next reader does not over-trust it, and
 * inherited deliberately from the E81 file's equivalent paragraph: a FIFTH door
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
     * The count in the prose is a measurement, not a number somebody typed.
     *
     * Distinct (key, door) pairs: two keys, two doors each. If a fifth door is
     * added this stays green — see the class doc-block on what this cannot do —
     * but if one is REMOVED or re-routed, the prose enumerating four goes from
     * merely stale to actively wrong, and this is what catches that direction.
     */
    public function testThereAreExactlyFourDoorsToCount(): void
    {
        $observed = [
            self::viaSlashCommand('/model custom'),
            self::viaSlashCommand('/theme light'),
            self::viaPalette('switch model', 'custom'),
            self::viaPalette('switch theme', 'light'),
        ];

        self::assertCount(4, $observed, 'the door roster this test drives has changed shape');
        self::assertCount(
            \count(self::DOORS),
            $observed,
            'the prose enumerates a different number of doors than this test drives',
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
     * @dataProvider enumeratingDocBlocks
     */
    public function testOneParagraphNamesAllFourDoors(string $which): void
    {
        $paragraphs = self::paragraphs(self::docBlock($which));

        $hit = null;
        foreach ($paragraphs as $paragraph) {
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
            "no single paragraph of the {$which} doc-block names all four doors ("
                . implode(', ', self::DOORS) . '); an enumeration split across paragraphs is how E106 happened',
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
