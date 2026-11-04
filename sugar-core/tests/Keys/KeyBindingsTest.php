<?php

/**
 * sugar-core/tests/Keys/KeyBindingsTest.php
 *
 * Pins the binding-registry contract introduced for finding m27: the shipped
 * defaults table, case-folded wire tokenization in BOTH space shapes (the
 * InputReader wire decodes a bare space as KeyType::Space, hand-built
 * fixtures as KeyType::Char with rune ' ' — one token either way), press-only
 * matching (m11), immutable withOverride replacement, shared-token legality,
 * and the derived display labels.
 */

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Msg\KeyPressMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\KeyRepeatMsg;
use SugarCraft\Core\KeyType;

final class KeyBindingsTest extends TestCase
{
    public function testDefaultsCarryTheSharedVocabulary(): void
    {
        // The whole table byte-pinned: m27's promise is that dispatch and
        // derived help text read ONE map, so the map itself is the contract.
        self::assertSame([
            'cursor-left'     => ['left'],
            'cursor-right'    => ['right'],
            'delete-backward' => ['backspace'],
            'delete-forward'  => ['delete'],
            'quit'            => ['q'],
            'pause'           => ['p'],
            'restart'         => ['r'],
            'begin'           => ['space', 'enter'],
            'move-left'       => ['left'],
            'move-right'      => ['right'],
            'move-up'         => ['up'],
            'move-down'       => ['down'],
            'soft-drop'       => ['down'],
            'hard-drop'       => ['space'],
            'rotate-cw'       => ['x'],
            'rotate-ccw'      => ['z'],
            'hold'            => ['c'],
            'action-a'        => ['z'],
            'action-b'        => ['x'],
            'action-c'        => ['c'],
            'flap'            => ['space'],
            'defuse'          => ['f'],
            'flag'            => ['s'],
        ], KeyBindings::defaults()->map());
    }

    public function testCharPressesMatchCaseInsensitively(): void
    {
        // m26 carried over: Caps Lock must not strand a binding.
        $b = KeyBindings::defaults();

        self::assertTrue($b->matches(self::char('q'), 'quit'));
        self::assertTrue($b->matches(self::char('Q'), 'quit'));
        self::assertTrue($b->matches(self::char('p'), 'pause'));
        self::assertFalse($b->matches(self::char('x'), 'quit'));
    }

    public function testNamedKeysMatchByTypeValue(): void
    {
        $b = KeyBindings::defaults();

        self::assertTrue($b->matches(new KeyMsg(KeyType::Left, rune: ''), 'move-left'));
        self::assertTrue($b->matches(new KeyMsg(KeyType::Up, rune: ''), 'move-up'));
        self::assertFalse($b->matches(new KeyMsg(KeyType::Down, rune: ''), 'move-up'));
    }

    public function testBothSpaceWireShapesActuateOneToken(): void
    {
        // The finding's ambiguity, pinned: wire and fixture agree.
        $b = KeyBindings::defaults();

        $wire = new KeyMsg(KeyType::Space, rune: ' ');
        $fixture = self::char(' ');

        self::assertSame('space', KeyBindings::tokenOf($wire));
        self::assertSame('space', KeyBindings::tokenOf($fixture));
        self::assertTrue($b->matches($wire, 'begin'));
        self::assertTrue($b->matches($fixture, 'begin'));
        self::assertTrue($b->matches($wire, 'hard-drop'));
        self::assertTrue($b->matches($fixture, 'hard-drop'));
    }

    public function testReleasesAndRepeatsNeverActuateAndPressesDo(): void
    {
        // m11: releases and auto-repeats carry the same key identity.
        $b = KeyBindings::defaults();

        self::assertTrue($b->matches(new KeyPressMsg(KeyType::Char, 'p'), 'pause'));
        self::assertTrue($b->matches(new KeyMsg(KeyType::Char, 'p'), 'pause'));
        self::assertFalse($b->matches(new KeyReleaseMsg(KeyType::Char, 'p'), 'pause'));
        self::assertFalse($b->matches(new KeyRepeatMsg(KeyType::Char, 'p'), 'pause'));
    }

    public function testWithOverrideReplacesAndLeavesTheOriginalIntact(): void
    {
        $b = KeyBindings::defaults();
        $remapped = $b->withOverride('quit', 'escape');

        self::assertNotSame($b, $remapped);
        self::assertFalse($remapped->matches(self::char('q'), 'quit'));
        self::assertTrue($remapped->matches(new KeyMsg(KeyType::Escape, rune: ''), 'quit'));
        // The original answers unchanged — value object, not a live map.
        self::assertTrue($b->matches(self::char('q'), 'quit'));
        self::assertSame(['q'], $b->tokens('quit'));
        self::assertSame(['escape'], $remapped->tokens('quit'));
    }

    public function testOverrideTokensAreCaseFolded(): void
    {
        $b = KeyBindings::defaults()->withOverride('quit', 'Q');

        self::assertSame(['q'], $b->tokens('quit'));
        self::assertTrue($b->matches(self::char('q'), 'quit'));
    }

    public function testFromMapLowercasesEveryTokenAndKeepsOrder(): void
    {
        $b = KeyBindings::fromMap(['strike' => ['A', 'Ctrl+J']]);

        self::assertSame(['a', 'ctrl+j'], $b->tokens('strike'));
    }

    public function testUnknownActionIsInert(): void
    {
        $b = KeyBindings::defaults();

        self::assertSame([], $b->tokens('no-such-action'));
        self::assertSame('', $b->label('no-such-action'));
        self::assertFalse($b->matches(self::char('q'), 'no-such-action'));
    }

    public function testOneTokenMayServeTwoActionsBecauseContextDecides(): void
    {
        // 'space' is begin AND hard-drop in the defaults table; each screen
        // dispatches only one of them, so both match — the registry stays
        // context-free instead of pretending a collision is an error.
        $b = KeyBindings::defaults();
        $space = new KeyMsg(KeyType::Space, rune: ' ');

        self::assertTrue($b->matches($space, 'begin'));
        self::assertTrue($b->matches($space, 'hard-drop'));
    }

    public function testLabelsRenderGlyphsForNamedKeysAndVerbatimForChars(): void
    {
        $b = KeyBindings::defaults();

        self::assertSame('←', $b->label('move-left'));
        self::assertSame('→', $b->label('move-right'));
        self::assertSame('↓', $b->label('soft-drop'));
        self::assertSame('spc', $b->label('hard-drop'));
        self::assertSame('q', $b->label('quit'));
        self::assertSame('spc ent', $b->label('begin'));
    }

    public function testRemappedLabelsLandInTheDisplaySurface(): void
    {
        // The m27 point: help text derived from the registry follows an
        // override without the app editing one string.
        $b = KeyBindings::defaults()->withOverride('hard-drop', 'j');

        self::assertSame('j', $b->label('hard-drop'));
    }

    private static function char(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune);
    }
}
