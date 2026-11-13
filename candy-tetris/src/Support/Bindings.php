<?php

/**
 * candy-tetris/src/Support/Bindings.php
 *
 * The app-side half of the live-binding layer: the shipped key table candy-tetris
 * dispatches through, and the two help surfaces that used to be static
 * translated cards ("← →  move" / "spc hard  c hold ..."). Those cards are
 * now DERIVED from the live registry, so a remap moves the on-screen help
 * with it — the frame never advertises a key the game no longer answers.
 *
 * The lang values keep their key names (help_card, vs_keys) but carry the
 * VERB column only; this class zips the verb lines onto the key column,
 * padded exactly as the hand-written cards were (width 5). With the default
 * table the derivation reproduces the original hand-written cards byte for
 * byte — pinned
 * for all sixteen locales by tests/Support/BindingsDerivationTest.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Support;

use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Util\Width;
use SugarCraft\Tetris\Lang;

final class Bindings
{
    /**
     * The shipped gameplay table. The ids are the dispatch vocabulary —
     * Game/VsGame gates name them; the tokens are what a user may remap.
     * 'begin' is the on-ramp's confirm key, not gameplay.
     *
     * @var array<string, list<string>>
     */
    private const MAP = [
        'quit'       => ['q'],
        'pause'      => ['p'],
        'restart'    => ['r'],
        'hold'       => ['c'],
        'move-left'  => ['left'],
        'move-right' => ['right'],
        'soft-drop'  => ['down'],
        'rotate-cw'  => ['up', 'x'],
        'rotate-ccw' => ['z'],
        'hard-drop'  => ['space'],
        'begin'      => ['space', 'enter'],
    ];

    /**
     * Display names for the named key tokens, spelled the way the physical
     * keycaps read them. The frame glyph map (sugar-core KeyBindings) is
     * deliberately compact ('spc'); the on-ramp card speaks the long form
     * so its first sentence never reads as a chord (candy-tetris-053).
     *
     * @var array<string, string>
     */
    public const KEY_NAMES = [
        'space' => 'Space',
        'enter' => 'Enter',
        'escape' => 'Escape',
        'backspace' => 'Backspace',
        'delete' => 'Delete',
        'tab' => 'Tab',
        'up' => 'Up',
        'down' => 'Down',
        'left' => 'Left',
        'right' => 'Right',
    ];

    /**
     * Key-column width of the hand-written help card ('← →' pads to 5).
     */
    private const KEY_COLUMN = 5;

    /**
     * Help-card rows in card order. The move row answers with both arrow
     * labels, exactly as the old static card read '← →'.
     *
     * The restart verb carries its over-screen context ('restart (over)'
     * and per-locale equivalents): stepGame only honours r once the game
     * is over, so a bare verb on an inert-during-play row misleads. The
     * card box is a fixed width(20) sidebar, so each locale's qualified
     * verb must stay within 15 cells (20 - frame - label column).
     *
     * @var list<list<string>>
     */
    private const HELP_ROWS = [
        ['move-left', 'move-right'],
        ['rotate-cw'],
        ['rotate-ccw'],
        ['soft-drop'],
        ['hard-drop'],
        ['hold'],
        ['pause'],
        ['restart'],
        ['quit'],
    ];

    /**
     * Versus strip rows, sharing the same verbs key-for-key.
     *
     * @var list<string>
     */
    private const VS_ROWS = [
        'hard-drop',
        'hold',
        'pause',
        'restart',
        'quit',
    ];

    public static function table(): KeyBindings
    {
        return KeyBindings::fromMap(self::MAP);
    }

    /**
     * The single-player help card, derived from the live bindings.
     */
    public static function helpCard(KeyBindings $bindings): string
    {
        $verbs = explode("\n", Lang::t('help_card'));
        $lines = [];
        foreach (self::HELP_ROWS as $index => $row) {
            $label = self::rowLabel($bindings, $row);
            $lines[] = $label . str_repeat(' ', max(0, self::KEY_COLUMN - Width::of($label))) . ($verbs[$index] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * The versus-mode key strip ("spc hard  c hold  p pause ..."), derived
     * from the live bindings.
     */
    public static function vsStrip(KeyBindings $bindings): string
    {
        $verbs = explode("\n", Lang::t('vs_keys'));
        $parts = [];
        foreach (self::VS_ROWS as $index => $row) {
            $parts[] = $bindings->label($row) . ' ' . ($verbs[$index] ?? '');
        }

        return implode('  ', $parts);
    }

    /**
     * @param list<string> $row
     */
    private static function rowLabel(KeyBindings $bindings, array $row): string
    {
        $labels = [];
        foreach ($row as $id) {
            $labels[] = $bindings->label($id);
        }

        return implode(' ', $labels);
    }
}
