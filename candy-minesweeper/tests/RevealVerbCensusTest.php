<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One verb per action: the footer's rules, keyboard and mouse lines must all
 * say "reveal" in the locale's own word, so a player never wonders whether
 * "open" and "reveal" are two different moves. A locale drifting back to the
 * old split verb fails here.
 */
final class RevealVerbCensusTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> locale => [code, reveal stem, retired stem or ''] */
    public static function verbs(): array
    {
        return [
            'ar' => ['ar', 'كشف', 'فتح'],
            'cs' => ['cs', 'odkr', ''],
            'de' => ['de', 'deck', 'öffne'],
            'en' => ['en', 'reveal', 'open'],
            'es' => ['es', 'revel', 'abr'],
            'fr' => ['fr', 'révél', 'ouvr'],
            'it' => ['it', 'rivela', 'apri'],
            'ja' => ['ja', '開', ''],
            'ko' => ['ko', '열', ''],
            'nl' => ['nl', 'onthul', 'open'],
            'pl' => ['pl', 'odkryj', 'otwórz'],
            'pt' => ['pt', 'revel', 'abr'],
            'pt-br' => ['pt-br', 'revel', 'abr'],
            'ru' => ['ru', 'откр', ''],
            'tr' => ['tr', 'aç', ''],
            'zh-cn' => ['zh-cn', '翻开', '打开'],
        ];
    }

    #[DataProvider('verbs')]
    public function testHelpLinesShareOneRevealVerb(string $code, string $stem, string $retired): void
    {
        $t = require __DIR__ . "/../lang/$code.php";
        $line = $t['ui.help_rules'] . ' | ' . $t['ui.help_moves'] . ' | ' . $t['ui.help_board'];
        $this->assertGreaterThanOrEqual(3, mb_substr_count(mb_strtolower($line), $stem), "$code: reveal verb missing from a help line");
        foreach (['ui.help_rules', 'ui.help_moves', 'ui.help_board'] as $k) {
            $this->assertStringContainsString($stem, mb_strtolower($t[$k]), "$code: $k");
            if ($retired !== '') {
                $this->assertStringNotContainsString($retired, mb_strtolower($t[$k]), "$code: $k kept the retired verb");
            }
        }
    }

    public function testEveryLocaleIsCovered(): void
    {
        $files = array_map(static fn ($p) => basename($p, '.php'), glob(__DIR__ . '/../lang/*.php') ?: []);
        sort($files);
        $keys = array_keys(self::verbs());
        sort($keys);
        $this->assertSame($files, $keys);
    }
}
