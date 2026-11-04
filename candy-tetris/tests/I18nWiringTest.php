<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;

/**
 * i18n wiring: the catalogue only earns its sixteen files if the frames ask
 * Lang for their strings and the shipped binary arms the detected locale
 * before the Program starts. Each leg here names a red for the mutation that
 * would make the locales dead weight.
 */
final class I18nWiringTest extends TestCase
{
    private string $priorLocale = 'en';

    protected function setUp(): void
    {
        $this->priorLocale = T::locale();
    }

    protected function tearDown(): void
    {
        T::setLocale($this->priorLocale);
    }

    private static function freshGame(): Game
    {
        return Game::start(new Bag(static fn(int $max): int => 0));
    }

    public function testEnglishFrameCarriesTheSourceOfTruthStrings(): void
    {
        T::setLocale('en');
        $view = Renderer::render(self::freshGame());

        $this->assertStringContainsString('next:', $view);
        $this->assertStringContainsString('hold:', $view);
        $this->assertStringContainsString('score:', $view);
    }

    public function testSinglePlayerFrameHonoursTheActiveLocale(): void
    {
        T::setLocale('de');
        $view = Renderer::render(self::freshGame());

        $this->assertStringContainsString('nächste:', $view, 'sidebar labels must translate (de)');
        $this->assertStringContainsString('Lager:', $view);
        $this->assertStringContainsString('Punkte:', $view, 'the score card must translate (de)');
        $this->assertStringNotContainsString('next:', $view, 'English must not bleed through');
        $this->assertStringNotContainsString('hold:', $view);

        T::setLocale('ja');
        $view = Renderer::render(self::freshGame());
        $this->assertStringContainsString('ホールド:', $view, 'sidebar labels must translate (ja)');
        $this->assertStringContainsString('スコア:', $view);
    }

    public function testVersusFrameHonoursTheActiveLocale(): void
    {
        T::setLocale('fr');
        $view = VsRenderer::render(VsGame::start());

        $this->assertStringContainsString('JOUEUR', $view, 'versus labels must translate (fr)');
        $this->assertStringContainsString('ORDINATEUR', $view);
        $this->assertStringNotContainsString('COMPUTER', $view, 'English must not bleed through');

        T::setLocale('ru');
        $view = VsRenderer::render(VsGame::start());
        $this->assertStringContainsString('КОМПЬЮТЕР', $view, 'versus labels must translate (ru)');
        $this->assertStringContainsString('ИГРОК', $view);
    }

    public function testTheBinaryArmsTheDetectedLocale(): void
    {
        // Source-scan pin: the runtime locale of a TUI child cannot be read
        // back cheaply, so the call itself is what reddens when removed.
        $bin = file_get_contents(__DIR__ . '/../bin/candy-tetris');
        $this->assertIsString($bin);
        // A real statement line — not a prose mention, not a commented-out call.
        $statement = '/^\\\\SugarCraft\\\\Core\\\\I18n\\\\T::setLocale\\(\\\\SugarCraft\\\\Core\\\\I18n\\\\T::detect\\(\\)\\);$/m';
        $matched = preg_match($statement, $bin, $m, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $matched, 'binary must arm the detected locale with a live statement');
        // …BEFORE the Program starts, or the first frame renders untranslated.
        // 'new Program(' and not 'Program(': the use-statement import sits above.
        $this->assertLessThan(
            (int) strpos($bin, 'new Program('),
            (int) $m[0][1],
            'setLocale must precede the Program construction',
        );
    }
}
