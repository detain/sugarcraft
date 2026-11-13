<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Lang;
use SugarCraft\Minesweeper\Renderer;

/**
 * M13 teach-to-play pin (review R1 §7): the help footer must state the
 * winning rule — "a number counts nearby mines" — not just the key legend.
 * These pins prove the copy reaches the rendered output through the
 * production render path (Renderer::render), so deleting the
 * Lang::t('ui.help_rules') call site in Renderer::frame() goes red here.
 */
final class TeachToPlayTest extends TestCase
{
    private string $priorLocale = 'en';

    protected function setUp(): void
    {
        $this->priorLocale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->priorLocale);
    }

    public function testTheEnglishSourceCarriesTheRulesSentence(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        // The key must sit with its help_* siblings, before the key legend,
        // so the parity guard's order assertion pins the footer sequence too.
        $keys = array_keys($en);
        $rulesAt = array_search('ui.help_rules', $keys, true);
        $movesAt = array_search('ui.help_moves', $keys, true);
        $this->assertIsInt($rulesAt, 'ui.help_rules missing from en.php');
        $this->assertLessThan($movesAt, $rulesAt, 'rules must precede the key legend');
        $this->assertStringContainsString('number', $en['ui.help_rules']);
        $this->assertStringContainsString('mine', $en['ui.help_rules']);
    }

    public function testTheFreshBoardFrameCarriesTheRulesLine(): void
    {
        $frame = Renderer::render(Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0));
        $rules = Lang::t('ui.help_rules');
        $this->assertNotSame('ui.help_rules', $rules, 'facade fell through to the raw key');
        $this->assertStringContainsString($rules, $frame);
    }

    public function testTheRulesLineLeadsTheKeyLegendInTheFooter(): void
    {
        $frame = Renderer::render(Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0));
        $rulesPos = strpos($frame, Lang::t('ui.help_rules'));
        $movesPos = strpos($frame, Lang::t('ui.help_moves'));
        $this->assertNotFalse($rulesPos);
        $this->assertNotFalse($movesPos);
        $this->assertLessThan($movesPos, $rulesPos, 'a first-timer must read the rule before the keys');
    }

    public function testTheRulesLineIsTranslatedNotHardcoded(): void
    {
        T::setLocale('de');
        $frame = Renderer::render(Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0));
        $de = require __DIR__ . '/../lang/de.php';
        $en = require __DIR__ . '/../lang/en.php';
        $this->assertStringContainsString($de['ui.help_rules'], $frame);
        $this->assertStringNotContainsString($en['ui.help_rules'], $frame);
    }
}
