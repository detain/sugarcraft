<?php

/**
 * candy-tetris/tests/KeysVocabReferenceTest.php
 *
 * candy-tetris-040/-042/-043 (tetris-31 code legs): the key vocabulary the
 * docs teach and the vocabulary the game actuates are pinned to ONE source
 * — Bindings' shipped table. The in-game surfaces (help card, versus
 * strip, --help) already derive from it; this closes the paper half of the
 * law: README's Controls and VS Mode Controls tables and
 * docs/ACCESSIBILITY.md's remap-form table must say what the code does.
 * A token added, renamed or dropped without a matching doc edit goes red
 * here (the docs-pinned-by-tests doctrine, AGENTS.md "Docs are pinned by
 * tests").
 *
 * Arms:
 *  - ACCESSIBILITY.md's table IS the map: same ids, same order, same
 *    token lists in config spelling (the only table allowed to name
 *    begin/ctrl+c verbatim — it documents the remap vocabulary itself);
 *  - the solo README table teaches every actuated default except the
 *    on-ramp key, per id in one row, and teaches nothing the game lacks;
 *  - the versus table teaches the shared vocabulary minus the solo-only
 *    surfaces ('begin' on-ramp, 'help' card — the strip IS the table, so
 *    h is inert there by design);
 *  - Ctrl+C, the chord that rides the q row's prose, is taught on both
 *    README surfaces (it actuates quit in VsGame too);
 *  - every named token the map carries has a README display spelling, and
 *    no display spelling outlives its token.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\Support\Bindings;

final class KeysVocabReferenceTest extends TestCase
{
    /**
     * How the README's Key columns spell the named tokens. Single
     * printable characters pass through as themselves; anything else in a
     * Key cell is an unknown spelling and fails the parse.
     */
    private const DISPLAY = [
        '←' => 'left',
        '→' => 'right',
        '↑' => 'up',
        '↓' => 'down',
        'Space' => 'space',
        'Enter' => 'enter',
    ];

    public function test_accessibility_vocabulary_table_is_the_shipped_map(): void
    {
        $rows = self::configTableOf(self::read('docs/ACCESSIBILITY.md'), '## Key vocabulary');
        $expected = [];
        foreach (Bindings::mapIds() as $id) {
            $expected[$id] = self::tokensOf($id);
        }

        $actual = [];
        foreach ($rows as [$id, $tokens]) {
            $actual[$id] = $tokens;
        }

        self::assertSame(
            $expected,
            $actual,
            'docs/ACCESSIBILITY.md drifted from the shipped binding map — the remap-form vocabulary table must list exactly the ids and tokens in Bindings (config spelling, in map order).'
        );
    }

    public function test_solo_controls_table_teaches_every_actuated_default_except_the_onramp_key(): void
    {
        $rows = self::tableOf(self::read('README.md'), '## Controls');

        self::assertContains('begin', Bindings::mapIds(), 'the on-ramp id is expected to stay out of the gameplay tables by name');

        foreach (Bindings::mapIds() as $id) {
            if ($id === 'begin') {
                continue;
            }
            self::assertRowsTeach($id, $rows, 'the solo Controls table');
        }

        self::assertSame(
            self::plainUnion(array_diff(Bindings::mapIds(), ['begin'])),
            self::cellUnion($rows),
            'the solo Controls table must teach exactly the plain shipped tokens of every id it covers — no phantom key, no untaught one'
        );

        self::assertStringContainsString('Ctrl+C', self::actionText($rows, 'q'), 'the chord that actuates quit must be named on the q row (candy-tetris-034)');
    }

    public function test_vs_controls_table_teaches_the_shared_vocabulary_without_solo_only_surfaces(): void
    {
        $rows = self::tableOf(self::read('README.md'), '### VS Mode Controls');

        // 'begin' is the on-ramp card's key and 'help' is the solo overlay's
        // meta key — the versus strip is already the full table, so neither
        // belongs in the duel's paper table (the strip prose says so).
        foreach (array_diff(Bindings::mapIds(), Bindings::META_IDS) as $id) {
            self::assertRowsTeach($id, $rows, 'the VS Mode Controls table');
        }

        self::assertSame(
            self::plainUnion(array_diff(Bindings::mapIds(), Bindings::META_IDS)),
            self::cellUnion($rows),
            'the VS Mode Controls table must mirror the shared vocabulary minus the solo-only surfaces'
        );

        // Ctrl+C leaves a duel in one press too (VsGame dispatches quit).
        self::assertStringContainsString('Ctrl+C', self::actionText($rows, 'q'), 'the duel answers the interrupt chord — its table must say so');
    }

    public function test_every_named_token_the_map_carries_has_a_display_spelling(): void
    {
        $allTokens = [];
        foreach (Bindings::mapIds() as $id) {
            foreach (self::tokensOf($id) as $token) {
                $allTokens[$token] = true;
            }
        }

        foreach (self::DISPLAY as $spelling => $token) {
            self::assertArrayHasKey($token, $allTokens, "the README spells '$spelling' but no shipped binding uses the '$token' token any more — retire the display spelling or rebind it");
        }

        foreach (array_keys($allTokens) as $token) {
            if (str_starts_with($token, 'ctrl+')) {
                continue; // chords ride prose, not a Key cell
            }
            if (preg_match('/^[a-z]$/', $token) === 1) {
                continue; // single characters display as themselves
            }
            self::assertContains($token, array_values(self::DISPLAY), "named token '$token' has no README display spelling — teach one or the tables cannot say it");
        }
    }

    public function test_readme_points_at_the_accessibility_surface(): void
    {
        $readme = self::read('README.md');
        self::assertStringContainsString('docs/ACCESSIBILITY.md', $readme, 'README must route players to the a11y surface (candy-tetris-040)');
        self::assertStringContainsString('tests/KeysVocabReferenceTest.php', $readme, 'README must name the guard that pins the vocabulary, so the law is discoverable (candy-tetris-043)');
    }

    /**
     * Rows of a two-column markdown table under $heading, as raw trimmed
     * cell strings: [cell0, cell1] per row.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function rowsUnder(string $markdown, string $heading): array
    {
        $start = strpos($markdown, "\n$heading\n");
        self::assertIsInt($start, "missing heading '$heading' — the drift guard cannot parse the docs");

        $lines = explode("\n", substr($markdown, $start));
        $rows = [];
        $seenSeparator = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($seenSeparator === false) {
                if (preg_match('/^\|[-\s|]+\|$/', $trimmed) === 1) {
                    $seenSeparator = true;
                }
                continue;
            }
            if (preg_match('/^\|(.+)\|$/', $trimmed, $m) !== 1) {
                break;
            }
            $cells = array_map('trim', explode('|', trim($m[1])));
            self::assertCount(2, $cells, "unexpected column count under '$heading'");
            $rows[] = [$cells[0], $cells[1]];
        }

        self::assertGreaterThan(0, count($rows), "no table parsed under '$heading'");

        return $rows;
    }

    /** @return list<array{0: list<string>, 1: string}> rows of Key-cell tokens + Action text */
    private static function tableOf(string $markdown, string $heading): array
    {
        return array_map(
            static fn(array $cells): array => [self::cellTokens($cells[0]), $cells[1]],
            self::rowsUnder($markdown, $heading)
        );
    }

    /**
     * The ACCESSIBILITY.md form: backticked config spellings, ids in cell
     * zero and comma-separated tokens in cell one — the exact vocabulary
     * keys.json takes, no display glyphs involved.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function configTableOf(string $markdown, string $heading): array
    {
        $rows = [];
        foreach (self::rowsUnder($markdown, $heading) as [$idCell, $tokenCell]) {
            $id = trim($idCell, ' `');
            $tokens = array_values(array_filter(array_map(
                static fn(string $t): string => trim($t, ' `'),
                explode(',', $tokenCell)
            ), static fn(string $t): bool => $t !== ''));
            $rows[] = [$id, $tokens];
        }

        return $rows;
    }

    /** @return list<string> */
    private static function cellTokens(string $cell): array
    {
        $tokens = [];
        foreach (explode('/', $cell) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (isset(self::DISPLAY[$part])) {
                $tokens[] = self::DISPLAY[$part];
                continue;
            }
            if (preg_match('/^[a-z]$/', $part) === 1) {
                $tokens[] = $part;
                continue;
            }
            self::fail("unknown Key-cell spelling '$part' — add it to DISPLAY or fix the table");
        }

        return $tokens;
    }

    /** @param list<array{0: list<string>, 1: string}> $rows */
    private static function assertRowsTeach(string $id, array $rows, string $surface): void
    {
        $plain = array_values(array_filter(
            self::tokensOf($id),
            static fn(string $t): bool => !str_starts_with($t, 'ctrl+')
        ));

        foreach ($rows as [$tokens, $_]) {
            if (array_diff($plain, $tokens) === []) {
                return;
            }
        }

        self::fail(sprintf(
            '%s never teaches all of %s (%s) in a single row',
            $surface,
            $id,
            implode(', ', $plain)
        ));
    }

    /** @param list<string> $ids */
    private static function plainUnion(array $ids): array
    {
        $union = [];
        foreach ($ids as $id) {
            foreach (self::tokensOf($id) as $token) {
                if (!str_starts_with($token, 'ctrl+')) {
                    $union[$token] = true;
                }
            }
        }
        ksort($union);

        return array_keys($union);
    }

    /** @param list<array{0: list<string>, 1: string}> $rows */
    private static function cellUnion(array $rows): array
    {
        $union = [];
        foreach ($rows as [$tokens, $_]) {
            foreach ($tokens as $token) {
                $union[$token] = true;
            }
        }
        ksort($union);

        return array_keys($union);
    }

    /** @param list<array{0: list<string>, 1: string}> $rows */
    private static function actionText(array $rows, string $token): string
    {
        foreach ($rows as [$tokens, $action]) {
            if (in_array($token, $tokens, true)) {
                return $action;
            }
        }

        self::fail("no row teaches '$token' to read prose from");
    }

    /** @return list<string> */
    private static function tokensOf(string $id): array
    {
        return array_values(Bindings::table()->tokens($id));
    }

    private static function read(string $relative): string
    {
        $path = dirname(__DIR__) . '/' . $relative;
        $text = is_file($path) ? file_get_contents($path) : false;
        self::assertIsString($text, "missing doc surface $relative");

        return $text;
    }
}
