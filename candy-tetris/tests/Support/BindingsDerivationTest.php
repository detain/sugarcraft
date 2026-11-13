<?php

/**
 * candy-tetris/tests/Support/BindingsDerivationTest.php
 *
 * The load-bearing pin behind the verb-column migration (finding m27): the
 * on-screen help card and versus strip are DERIVED from the live binding
 * table, so with the shipped table they must reproduce the recorded bytes
 * exactly. tests/fixtures/help-cards-pre-m27.json holds those recorded
 * cards verbatim. If derivation drifts even one space, this goes red.
 *
 * tetris-08 (candy-tetris-015/-087) re-baselined BOTH columns: the versus
 * strip now carries the full live map (it used to be a five-binding
 * subset), the two surfaces share ONE verb column, and the interrupt
 * (candy-tetris-034) joins the quit row. The help_card legs still pin the
 * pre-m27 hand-authored card for every row except the newly-widened quit
 * binding; the vs_strip legs pin the full-map derivation at these bytes.
 *
 * MAJOR-1 (TET-FEATURES review) added the other half of the promise: the
 * override-seeded pins proving ONE folded keys.json lands in dispatch AND
 * in every derived surface. Each goes red if the app hardcodes the shipped
 * table at its seam (Renderer.php:328, VsRenderer.php:291, Game::keys()).
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Lang;
use SugarCraft\Tetris\Support\Bindings;
use SugarCraft\Tetris\Support\KeyMap;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;

final class BindingsDerivationTest extends TestCase
{
    /** @var list<string> */
    private const LOCALES = [
        'ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja',
        'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh-cn',
    ];

    protected function tearDown(): void
    {
        T::setLocale('en');
    }

    /**
     * @return \Generator<array{0:string,1:string}>
     */
    public static function helpCardFixtures(): \Generator
    {
        foreach (self::cards() as $locale => $cards) {
            yield $locale => [$locale, $cards['help_card']];
        }
    }

    /**
     * @return \Generator<array{0:string,1:string}>
     */
    public static function vsStripFixtures(): \Generator
    {
        foreach (self::cards() as $locale => $cards) {
            yield $locale => [$locale, $cards['vs_strip']];
        }
    }

    /**
     * @dataProvider helpCardFixtures
     */
    public function testTheDerivedHelpCardReproducesTheHandAuthoredOne(string $locale, string $card): void
    {
        T::setLocale($locale);

        self::assertSame($card, Bindings::helpCard(Bindings::table()));
    }

    /**
     * @dataProvider vsStripFixtures
     */
    public function testTheDerivedVersusStripReproducesTheBaselinedOne(string $locale, string $strip): void
    {
        T::setLocale($locale);

        self::assertSame($strip, Bindings::vsStrip(Bindings::table()));
    }

    public function testEveryLocaleCarriesTheVerbColumnsTheDerivationZipsAgainst(): void
    {
        // help_card is a ten-verb column shared by BOTH derived surfaces
        // since candy-tetris-015 (the versus strip used to zip its own
        // five-verb roster, which drifted; candy-tetris-016's flip row is
        // the tenth). A row-count mismatch would blank a label's verb —
        // verbColumn() throws instead, so the column shape is pinned
        // directly, in every locale.
        foreach (self::LOCALES as $locale) {
            T::setLocale($locale);

            self::assertCount(10, explode("\n", Lang::t('help_card')), $locale . ' help_card verbs');
        }
    }

    public function testEveryVerbColumnRowIsTaughtOnBothDerivedSurfaces(): void
    {
        // candy-tetris-015's completeness contract: the row table covers
        // every dispatched gameplay id — Bindings::META_IDS are excluded
        // on purpose: 'begin' belongs to the on-ramp sentence, and 'help'
        // (candy-tetris-006) is taught by the compact key line that
        // replaces the card where the budget drops it. A MAP entry nobody
        // teaches is how the strip drifted to 5-of-11.
        $taught = array_merge(...Bindings::GAMEPLAY_ROWS);
        sort($taught);
        $gameplay = array_values(array_filter(
            Bindings::mapIds(),
            static fn(string $id): bool => !in_array($id, Bindings::META_IDS, true),
        ));
        sort($gameplay);

        self::assertSame($gameplay, $taught, 'every gameplay id must reach the teaching surfaces');
    }

    public function testTheVerbColumnFailsLoudWhenARowIsMissing(): void
    {
        // candy-tetris-077: the old `?? ''` legs rendered a silent blank
        // verb column. The roster is now a hard contract — a catalogue
        // that lost a line throws, naming both counts. Derived from the
        // row table so candy-tetris-016-style growth cannot stale-pin it.
        $missing = 2;
        $kept = count(Bindings::GAMEPLAY_ROWS) - $missing;
        $short = implode("\n", array_slice(explode("\n", Lang::t('help_card')), 0, $kept));

        try {
            Bindings::verbColumnFrom($short);
            self::fail('a truncated verb column must throw, not blank the card');
        } catch (\LogicException $problem) {
            self::assertMatchesRegularExpression(
                sprintf('/help_card carries %d verb lines but the row table has %d rows/', $kept, $kept + $missing),
                $problem->getMessage(),
            );
        }
    }

    public function testTheHelpCardSpeaksTheOverriddenTable(): void
    {
        // MAJOR-1 pin (a): the Renderer help-card seam must read the game's
        // table, not the shipped one. 'h' pads into the width-5 key column
        // exactly the way the old 'spc' row did; the old row must be gone.
        $game = Game::start(new Bag(static fn (int $max): int => 0), bindings: $this->remappedTable());
        $frame = $game->view();

        self::assertStringContainsString('h    hard drop', $frame, 'the remapped glyph lands on the card');
        self::assertStringNotContainsString('spc  hard drop', $frame, 'the shipped glyph must not be advertised');
    }

    public function testTheOverriddenKeyActuatesAndTheShippedOneGoesInert(): void
    {
        // MAJOR-1 pin (b): the dispatch seam (Game::keys()) consults the
        // same folded table. An override REPLACES the token list, so the
        // remapped key locks the piece (a hard drop scores 2 points per
        // fallen cell) while the shipped space token actuates nothing.
        $game = Game::start(new Bag(static fn (int $max): int => 0), bindings: $this->remappedTable());

        [$dropped] = $game->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertGreaterThan(0, $dropped->score->points, "'h' hard-drops under the override");

        [$untouched] = $game->update(new KeyMsg(KeyType::Space, ' '));
        self::assertSame($game, $untouched, 'space must no longer actuate hard-drop');
    }

    public function testTheVersusStripSpeaksTheOverriddenTable(): void
    {
        // MAJOR-1 pin (c) first leg: the VsRenderer strip seam reads the
        // player's table, so the versus frame remaps with the same file.
        $frame = VsRenderer::render(VsGame::start(bindings: $this->remappedTable()));

        self::assertStringContainsString('h hard drop', $frame, 'the strip moves with the remap');
        self::assertStringContainsString('a rotate 180  ↓ soft drop  h hard drop', $frame, 'the re-chunked flip row leads its chunk');
        self::assertStringNotContainsString('spc hard', $frame, 'the strip must not keep advertising the old key');
    }

    public function testTheIntroCardAndBeginLegFollowAnOverriddenBegin(): void
    {
        // MAJOR-1 pin (c) second leg: the on-ramp card prints the LIVE begin
        // label, and the intro gate dispatches through the same table — the
        // card can never say "press X" while X is inert.
        $game = Game::start(
            new Bag(static fn (int $max): int => 0),
            bindings: $this->remappedTable(),
            introPending: true,
        );
        $frame = $game->view();

        self::assertStringContainsString('press Enter to play', $frame, 'the card names the remapped begin key (spelled like the keycap since candy-tetris-053)');
        self::assertStringNotContainsString('press Space or Enter to play', $frame, 'the two-token default label must be gone');

        [$held] = $game->update(new KeyMsg(KeyType::Space, ' '));
        self::assertTrue($held->introPending, 'the shipped begin token is inert behind the card');

        [$dismissed, $cmd] = $game->update(new KeyMsg(KeyType::Enter, ''));
        self::assertFalse($dismissed->introPending, 'the remapped begin key dismisses the card');
        self::assertNull($cmd, 'begin is a transition, not an exit');
    }

    /**
     * The fold bin/candy-tetris performs at boot (m27's user path): a
     * permissive keys.json — hard-drop moves to 'h', begin to 'enter' —
     * loaded through the real KeyMap loader so the pins ride the shipped
     * shape, not a hand-built registry.
     */
    private function remappedTable(): KeyBindings
    {
        $path = sys_get_temp_dir() . '/candy-tetris-remap-' . uniqid('', true) . '.json';
        file_put_contents($path, '{"hard-drop": ["h"], "begin": ["enter"]}');
        $table = KeyMap::load($path, Bindings::table());
        unlink($path);

        return $table;
    }

    /**
     * @return array<string, array{help_card: string, vs_strip: string}>
     */
    private static function cards(): array
    {
        $path = \dirname(__DIR__, 2) . '/tests/fixtures/help-cards-pre-m27.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'fixture ' . $path . ' must exist');

        $cards = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($cards);
        self::assertSame(self::LOCALES, array_keys($cards), 'fixture covers exactly the shipped locales');

        return $cards;
    }
}
