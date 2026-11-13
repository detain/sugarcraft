<?php

declare(strict_types=1);

/**
 * candy-tetris/src/Support/Bindings.php
 *
 * The app-side half of the live-binding layer: the shipped key table candy-tetris
 * dispatches through, and the two help surfaces that used to be static
 * translated cards ("← →  move" / "spc hard  c hold ..."). Those cards are
 * now DERIVED from the live registry, so a remap moves the on-screen help
 * with it — the frame never advertises a key the game no longer answers.
 *
 * The lang values carry the VERB column only (help_card); this class zips
 * the verb lines onto the key column, padded exactly as the hand-written
 * cards were (width 5). The versus strip shares the SAME row set and the
 * SAME verb column as the solo card — one teaching surface, one vocabulary,
 * no second five-verb roster that can drift from the live map
 * (candy-tetris-015/-087: the strip used to show 5 of the 11 bindings and
 * name the restart verb differently from the card).
 *
 * Chord tokens: 'ctrl+c' (the interrupt, candy-tetris-034) lives in the
 * token lists next to plain names, but the shared registry is deliberately
 * modifier-blind — matching looks at key identity only, so a ctrl press
 * must NOT answer a plain token. {@see self::actuates()} is the app-side
 * dispatcher that keeps that promise: under ctrl only a 'ctrl+…' token of
 * the same action actuates, and without it only plain tokens do. Every
 * gameplay key gate in Game/VsGame dispatches through it.
 */
namespace SugarCraft\Tetris\Support;

use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\KeyRepeatMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Tetris\Lang;

final class Bindings
{
    /**
     * The shipped gameplay table. The ids are the dispatch vocabulary —
     * Game/VsGame gates name them; the tokens are what a user may remap.
     * 'begin' is the on-ramp's confirm key, not gameplay.
     *
     * 'ctrl+c' (candy-tetris-034) is a CHORD token: it rides the same list
     * as plain names, but only {@see self::actuates()} resolves it — the
     * shared registry is modifier-blind (its own docblock law), so raw
     * matching would fold Ctrl+C into the plain 'c' token and let the
     * interrupt actuate hold instead of quitting. KeyMap's vocabulary
     * door accepts the 'ctrl+…' form explicitly.
     *
     * @var array<string, list<string>>
     */
    private const MAP = [
        'quit'       => ['q', 'ctrl+c'],
        'pause'      => ['p'],
        'restart'    => ['r'],
        'hold'       => ['c'],
        'move-left'  => ['left'],
        'move-right' => ['right'],
        'soft-drop'  => ['down'],
        'rotate-cw'  => ['up', 'x'],
        'rotate-ccw' => ['z'],
        // candy-tetris-016: the guideline's flip key. The SRS tables have
        // always carried the 0↔2 transitions; this id is the control path
        // that made reading them possible.
        'rotate-180' => ['a'],
        'hard-drop'  => ['space'],
        'begin'      => ['space', 'enter'],
        // candy-tetris-006: the meta key of the teaching surface itself —
        // a floor-sized terminal loses the help card to the budget, so
        // the compact key line (and the intro card) point here: pressing
        // this id paints the full derived card over the frame.
        'help'       => ['h'],
    ];

    /**
     * Ids the GAMEPLAY_ROWS card deliberately does not teach: 'begin'
     * belongs to the on-ramp sentence (Game::introBeginLabel) and 'help'
     * is named by the compact key line the card's budgeted absence is
     * replaced with (candy-tetris-006). Both are still dispatch vocabulary
     * and still remappable — the derivation pin reads this list.
     *
     * @var list<string>
     */
    public const META_IDS = ['begin', 'help'];

    /** The dispatch ids (candy-tetris-015's completeness pin reads them). */
    public static function mapIds(): array
    {
        return array_keys(self::MAP);
    }

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
     * Gameplay rows in card order — ONE row set shared by the solo help
     * card and the versus strip (candy-tetris-015/-087: the strip used to
     * carry its own five-row roster, teaching 5 of the 11 bindings under
     * verbs that drifted from the card's). The move row answers with both
     * arrow labels, exactly as the old static card read '← →'. 'begin' is
     * deliberately absent: it belongs to the on-ramp card, which names it
     * in its own sentence (Game::introBeginLabel).
     *
     * The restart verb carries its over-screen context ('restart (over)'
     * and per-locale equivalents): stepGame only honours r once the game
     * is over, so a bare verb on an inert-during-play row misleads. The
     * card box is a fixed width(20) sidebar, so each locale's qualified
     * verb must stay within 15 cells (20 - frame - label column).
     *
     * @var list<list<string>>
     */
    public const GAMEPLAY_ROWS = [
        ['move-left', 'move-right'],
        ['rotate-cw'],
        ['rotate-ccw'],
        ['rotate-180'],
        ['soft-drop'],
        ['hard-drop'],
        ['hold'],
        ['pause'],
        ['restart'],
        ['quit'],
    ];

    /** Rows per versus-strip line (chunks of three keep every line ≤48 cells). */
    private const STRIP_CHUNK = 3;

    /**
     * The one shipped default table (candy-tetris-048/-112): KeyBindings
     * is immutable, so every frame — and every key gate inside it — can
     * share a single instance instead of re-folding the MAP ~11 times per
     * keystroke. Overrides are separate instances and never enter here.
     */
    private static ?KeyBindings $defaultTable = null;

    /**
     * Rendered teaching surfaces keyed by locale + table identity
     * (candy-tetris-048): the card was rebuilt string-by-string on every
     * frame. The value keeps its KeyBindings strong so an spl_object_id
     * recycled onto a new table can never serve stale bytes, and the
     * locale rides the key because a test (or a $LANG export) flipping
     * languages mid-process must see the verb column it asked for.
     *
     * @var array<string, array{0: KeyBindings, 1: string}>
     */
    private static array $SURFACES = [];

    public static function table(): KeyBindings
    {
        return self::$defaultTable ??= KeyBindings::fromMap(self::MAP);
    }

    /**
     * App-side actuation door (candy-tetris-034): under a ctrl chord only
     * 'ctrl+…' tokens of the action actuate; without one only plain
     * tokens do. The shared sugar-core registry folds modifiers away by
     * design ("a legacy 'q' gate fires on Ctrl+Q too"), which for this
     * game meant Ctrl+C answered the plain 'c' token and actuated HOLD
     * mid-drop instead of leaving — while the interrupt itself exited
     * nothing. Releases and repeats never actuate (m11), on either leg.
     */
    public static function actuates(KeyBindings $keys, KeyMsg $msg, string $id): bool
    {
        if ($msg instanceof KeyReleaseMsg || $msg instanceof KeyRepeatMsg) {
            return false;
        }

        if ($msg->ctrl === true) {
            return in_array('ctrl+' . KeyBindings::tokenOf($msg), $keys->tokens($id), true);
        }

        return $keys->matches($msg, $id);
    }

    /**
     * The key-name vocabulary for config validation (candy-tetris-032):
     * a single printable character (the decoder emits runes lowercased,
     * and a space arrives as the 'space' name), any named key the decoder
     * can emit, or a 'ctrl+' chord over either. A typo like 'spce' is NOT
     * a key any stream can actuate — loading it "valid" is how begin got
     * stranded behind undismissable intro cards (UXD-05). Case is folded
     * the way KeyBindings folds override tokens.
     */
    public static function isKnownToken(string $token): bool
    {
        $token = strtolower($token);

        if (str_starts_with($token, 'ctrl+') && $token !== 'ctrl+') {
            return self::isKnownToken(substr($token, 5));
        }

        if ($token === 'char' || $token === '') {
            return false;
        }

        foreach (KeyType::cases() as $case) {
            if ($case->value === $token) {
                return true;
            }
        }

        // Single printable character (runes included — strlen would say no
        // to 'é'): exactly one codepoint, no control byte, not a space
        // (the decoder folds the space rune to the 'space' name).
        return preg_match('/^[\P{C}\P{Z}]/u', $token) === 1 && mb_strlen($token) === 1;
    }

    /**
     * The single-player help card, derived from the live bindings.
     */
    public static function helpCard(KeyBindings $bindings): string
    {
        return self::surface('card', $bindings, static fn(): string => self::buildHelpCard($bindings));
    }

    private static function buildHelpCard(KeyBindings $bindings): string
    {
        $verbs = self::verbColumn();
        $lines = [];
        foreach (self::GAMEPLAY_ROWS as $index => $row) {
            $label = self::rowLabel($bindings, $row);
            $lines[] = $label . str_repeat(' ', max(1, self::KEY_COLUMN - Width::of($label))) . $verbs[$index];
        }

        return implode("\n", $lines);
    }

    /**
     * The versus-mode key strip — the SAME live rows and verbs as the solo
     * card, chunked three to a line so the full table fits the width-50
     * match strip (candy-tetris-015: five of eleven was not teaching).
     */
    public static function vsStrip(KeyBindings $bindings): string
    {
        return self::surface('strip', $bindings, static fn(): string => self::buildVsStrip($bindings));
    }

    private static function buildVsStrip(KeyBindings $bindings): string
    {
        $verbs = self::verbColumn();
        $parts = [];
        foreach (self::GAMEPLAY_ROWS as $index => $row) {
            $verbPart = [];
            foreach ($row as $id) {
                $verbPart[] = $bindings->label($id);
            }
            $parts[] = implode(' ', $verbPart) . ' ' . $verbs[$index];
        }

        $lines = [];
        foreach (array_chunk($parts, self::STRIP_CHUNK) as $chunk) {
            $lines[] = implode('  ', $chunk);
        }

        return implode("\n", $lines);
    }

    /**
     * The translated verb column — fail-loud on shape (candy-tetris-077:
     * the old `?? ''` legs rendered a blank verb when a locale lost a
     * line, so a missing binding name became invisible instead of a bug).
     *
     * @return list<string>
     */
    public static function verbColumn(): array
    {
        return self::verbColumnFrom(Lang::t('help_card'));
    }

    /**
     * The parse door {@see self::verbColumn()} routes the live catalogue
     * through — public as the test seam for the fail-loud arm (a suite
     * cannot fake a broken shipped file, but it can name the contract on
     * a truncated column).
     *
     * @return list<string>
     */
    public static function verbColumnFrom(string $cardText): array
    {
        $verbs = explode("\n", $cardText);
        if (count($verbs) !== count(self::GAMEPLAY_ROWS)) {
            throw new \LogicException(sprintf(
                'help_card carries %d verb lines but the row table has %d rows — a locale lost or gained a line',
                count($verbs),
                count(self::GAMEPLAY_ROWS),
            ));
        }

        return $verbs;
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

    /**
     * The memo door (candy-tetris-048): keyed by locale and table
     * identity, and the identity is RE-CHECKED against the strong
     * reference the entry keeps — an spl_object_id recycled onto a new
     * table can serve the entry the same way a cache never should.
     */
    private static function surface(string $kind, KeyBindings $bindings, \Closure $build): string
    {
        $key = $kind . '|' . \SugarCraft\Core\I18n\T::locale() . '|' . spl_object_id($bindings);
        $cached = self::$SURFACES[$key] ?? null;
        if ($cached !== null && $cached[0] === $bindings) {
            return $cached[1];
        }

        $text = $build();
        self::$SURFACES[$key] = [$bindings, $text];

        return $text;
    }
}
