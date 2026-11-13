<?php

/**
 * candy-tetris/tests/Support/BindingsDerivationTest.php
 *
 * The load-bearing pin behind the verb-column migration (finding m27): the
 * on-screen help card and versus strip are DERIVED from the live binding
 * table, so with the shipped table they must reproduce — byte for byte, in
 * every one of the sixteen locales — the hand-authored cards that existed
 * before the registry. tests/fixtures/help-cards-pre-m27.json holds those
 * originals verbatim. If derivation drifts even one space, this goes red.
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
            yield $locale => [$locale, $cards['vs_keys']];
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
    public function testTheDerivedVersusStripReproducesTheHandAuthoredOne(string $locale, string $strip): void
    {
        T::setLocale($locale);

        self::assertSame($strip, Bindings::vsStrip(Bindings::table()));
    }

    public function testEveryLocaleCarriesTheVerbColumnsTheDerivationZipsAgainst(): void
    {
        // help_card is now a nine-verb column, vs_keys a five-verb column.
        // A row-count mismatch would silently blank a label's verb, so the
        // column shapes are pinned directly, in every locale.
        foreach (self::LOCALES as $locale) {
            T::setLocale($locale);

            self::assertCount(9, explode("\n", Lang::t('help_card')), $locale . ' help_card verbs');
            self::assertCount(5, explode("\n", Lang::t('vs_keys')), $locale . ' vs_keys verbs');
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

        self::assertStringContainsString('h hard  c hold', $frame, 'the strip moves with the remap');
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
     * @return array<string, array{help_card: string, vs_keys: string}>
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
