<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\TermDb;
use SugarCraft\Image\TermInfo;

/**
 * F-06: the safe-symbol bitmask carried by the terminal table.
 *
 * Chicle curates a per-row `safe_symbol_tags` mask (chafa-term-db.c:86-88,
 * :156, :1071): the Linux console gets ASCII only, the Windows console and
 * mintty get the block/border families, everyone else rides the default.
 * SugarCraft ports the vocabulary onto {@see TermInfo::$safeSymbols} and the
 * consumption-side question {@see TermInfo::symbolIsSafe()}; this file is the
 * evidence that the table and the chain math agree.
 *
 * Hermetic law unchanged (TermDbTest::testTermDbCarriesNoExecSurface): every
 * case here is string-in / TermInfo-out — no getenv, no exec, no tty.
 */
final class TermSymbolSafetyTest extends TestCase
{
    /**
     * The golden set: every TERM-row name mapped to the symbol mask the table
     * must ship. Adding or flipping a row mask without curating it here (and
     * citing chicle's line) reddens this pin first.
     *
     * @var array<string, int>
     */
    private const GOLDEN_ROW_MASKS = [
        // chicle LINUX_CONSOLE_SYMS (chafa-term-db.c:86, row :725-729)
        'linux' => TermInfo::ASCII_ONLY,
        // chicle WIN_TERMINAL_SYMS (chafa-term-db.c:88, rows :731-738 and :857-860)
        'mintty' => TermInfo::BLOCK_BORDER_ONLY,
        'windows-console' => TermInfo::BLOCK_BORDER_ONLY,
        // every other TERM row: the untagged default is the full set — chicle's
        // LINUX_DESKTOP_SYMS narrowing (BLOCK|BORDER, no braille) is
        // deliberately NOT mirrored for SC (S6.4 product decision: the braille
        // canvases in sugar-charts ride the full default; see lane report).
        'kitty' => TermInfo::ALL_SYMBOLS,
        'ghostty' => TermInfo::ALL_SYMBOLS,
        'wezterm' => TermInfo::ALL_SYMBOLS,
        'warp' => TermInfo::ALL_SYMBOLS,
        'iterm' => TermInfo::ALL_SYMBOLS,
        'rio' => TermInfo::ALL_SYMBOLS,
        'mlterm' => TermInfo::ALL_SYMBOLS,
        'foot' => TermInfo::ALL_SYMBOLS,
        'contour' => TermInfo::ALL_SYMBOLS,
        'yaft' => TermInfo::ALL_SYMBOLS,
        'konsole' => TermInfo::ALL_SYMBOLS,
        'xterm' => TermInfo::ALL_SYMBOLS,
        'xterm-256color' => TermInfo::ALL_SYMBOLS,
        'xterm-direct' => TermInfo::ALL_SYMBOLS,
        'alacritty' => TermInfo::ALL_SYMBOLS,
        'st' => TermInfo::ALL_SYMBOLS,
        'apple' => TermInfo::ALL_SYMBOLS,
        'vte' => TermInfo::ALL_SYMBOLS,
        'vt220' => TermInfo::ALL_SYMBOLS,
    ];

    /**
     * The table itself, row by row: each TERM row's `symbols` key (or its
     * absence = the full-set default) must equal the golden set.
     */
    public function testGoldenSetOfRowSymbolMasks(): void
    {
        $seen = [];
        foreach (TermDb::ROWS as $row) {
            if ($row['type'] !== TermDb::TYPE_TERM) {
                continue;
            }
            $seen[$row['name']] = true;
            self::assertArrayHasKey($row['name'], self::GOLDEN_ROW_MASKS, "un-golden TERM row: {$row['name']}");
            self::assertSame(
                self::GOLDEN_ROW_MASKS[$row['name']],
                $row['symbols'] ?? TermInfo::ALL_SYMBOLS,
                "F-06 row mask moved on {$row['name']}",
            );
        }

        // Bidirectional: a golden entry whose row was deleted is stale.
        foreach (array_keys(self::GOLDEN_ROW_MASKS) as $name) {
            self::assertArrayHasKey($name, $seen, "golden entry without a live TERM row: {$name}");
        }
    }

    /**
     * Behavior: TERM=linux resolves to the ASCII-only set and the drawn-class
     * questions answer like the Linux console actually behaves.
     */
    public function testLinuxConsoleResolvesAsciiOnly(): void
    {
        $info = TermDb::matchChain(['TERM' => 'linux']);

        self::assertSame('linux', $info->chain);
        self::assertSame(TermInfo::ASCII_ONLY, $info->safeSymbols);
        self::assertTrue($info->symbolIsSafe(TermInfo::SYMBOL_ASCII));
        self::assertFalse($info->symbolIsSafe(TermInfo::SYMBOL_BLOCK), '▀ does not draw on the Linux console');
        self::assertFalse($info->symbolIsSafe(TermInfo::SYMBOL_BORDER), '─ does not draw on the Linux console');
        self::assertFalse($info->symbolIsSafe(TermInfo::SYMBOL_BRAILLE));
        self::assertFalse($info->symbolIsSafe(TermInfo::SYMBOL_BLOCK | TermInfo::SYMBOL_BORDER), 'composite requires all bits');
    }

    /**
     * Behavior: the Windows console family (ComSpec suffix) and mintty resolve
     * to the block/border set — lines and blocks draw, braille dots do not.
     */
    public function testWindowsFamilyResolvesBlockBorderOnly(): void
    {
        $console = TermDb::matchChain(['ComSpec' => 'C:\\Windows\\System32\\cmd.exe']);
        self::assertSame(TermInfo::BLOCK_BORDER_ONLY, $console->safeSymbols);
        self::assertTrue($console->symbolIsSafe(TermInfo::SYMBOL_BLOCK));
        self::assertTrue($console->symbolIsSafe(TermInfo::SYMBOL_BORDER));
        self::assertFalse($console->symbolIsSafe(TermInfo::SYMBOL_BRAILLE));

        $mintty = TermDb::matchChain(['TERM' => 'mintty']);
        self::assertSame(TermInfo::BLOCK_BORDER_ONLY, $mintty->safeSymbols);
    }

    /**
     * Behavior: the default is the full symbol set — both for a $TERM that
     * matched nothing but the vt220 catch-all, and for a clueless environment
     * (SC divergence from chicle's "dumb" ASCII fallback, named in TermInfo's
     * ctor docblock, pinned here so a silent narrowing would go red).
     */
    public function testUnknownTermsDefaultToTheFullSet(): void
    {
        $unrecognized = TermDb::matchChain(['TERM' => 'term-not-in-the-table']);
        self::assertSame(TermInfo::ALL_SYMBOLS, $unrecognized->safeSymbols);
        self::assertTrue($unrecognized->symbolIsSafe(TermInfo::SYMBOL_BRAILLE));

        $modern = TermDb::matchChain(['TERM' => 'xterm-kitty']);
        self::assertSame(TermInfo::ALL_SYMBOLS, $modern->safeSymbols);

        self::assertSame(TermInfo::ALL_SYMBOLS, TermInfo::unknown()->safeSymbols);
    }

    /**
     * Behavior: nesting never widens. The chain mask intersects (chicle's
     * chain reading, chafa-term-info.c:1691), so a Linux console inside tmux
     * stays ASCII-only, and a modern terminal inside tmux stays full.
     */
    public function testMuxChainNeverWidensSymbolSafety(): void
    {
        $nestedConsole = TermDb::matchChain(['TMUX' => '/tmp/tmux-1000/default,123,0', 'TERM_PROGRAM' => 'tmux', 'TERM' => 'linux']);
        self::assertSame(TermInfo::ASCII_ONLY, $nestedConsole->safeSymbols);

        $nestedModern = TermDb::matchChain(['TMUX' => '/tmp/tmux-1000/default,123,0', 'TERM_PROGRAM' => 'tmux', 'TERM' => 'xterm-256color']);
        self::assertSame(TermInfo::ALL_SYMBOLS, $nestedModern->safeSymbols);
    }

    /**
     * Behavior: the ASCII class answers safe on every row — the fallback tier
     * is unconditional by contract (LumaRamp-style ramps must always draw).
     */
    public function testAsciiClassIsSafeOnEveryChainEvenUnderRestriction(): void
    {
        foreach ([['TERM' => 'linux'], ['TERM' => 'mintty'], ['TERM' => 'xterm-kitty']] as $env) {
            self::assertTrue(TermDb::matchChain($env)->symbolIsSafe(TermInfo::SYMBOL_ASCII), json_encode($env));
        }

        // A hypothetical zero-mask row would still answer ASCII safe — the
        // always-fallback guarantee lives in symbolIsSafe, not the table.
        $starved = new TermInfo('probe', [], [], null, 0);
        self::assertTrue($starved->symbolIsSafe(TermInfo::SYMBOL_ASCII));
        self::assertFalse($starved->symbolIsSafe(TermInfo::SYMBOL_BLOCK));
    }
}
