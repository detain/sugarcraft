<?php

/**
 * sugar-core/tests/Keys/KeyBindingsChordTest.php
 *
 * Pins the P2-1 chord grammar: canonical spelling (case, alias, modifier
 * order), the rejects, exact-modifier matching (a bare key no longer fires
 * under Ctrl or Alt, shift is folded off printable characters), the
 * bind()/withHelp() help layer, actionFor() dispatch and chord labels.
 */

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Keys;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

final class KeyBindingsChordTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function canonicalChords(): iterable
    {
        yield 'ctrl+s'            => ['ctrl+s', 'ctrl+s'];
        yield 'shift+tab'         => ['shift+tab', 'shift+tab'];
        yield 'case folds'        => ['Ctrl+S', 'ctrl+s'];
        yield 'modifier order'    => ['shift+alt+ctrl+up', 'ctrl+alt+shift+up'];
        yield 'alt before ctrl'   => ['alt+ctrl+x', 'ctrl+alt+x'];
        yield 'esc alias'         => ['esc', 'escape'];
        yield 'pgdn alias'        => ['ctrl+pgdn', 'ctrl+pagedown'];
        yield 'lone space'        => [' ', 'space'];
        yield 'plus key'          => ['ctrl++', 'ctrl++'];
        yield 'bare plus'         => ['+', '+'];
        yield 'named key'         => ['f5', 'f5'];
        yield 'uppercase rune'    => ['Q', 'q'];
        yield 'multibyte rune'    => ['é', 'é'];
        yield 'shift named key'   => ['shift+space', 'shift+space'];
    }

    #[DataProvider('canonicalChords')]
    public function testParseChordCanonicalizes(string $input, string $canonical): void
    {
        self::assertSame($canonical, KeyBindings::parseChord($input));
    }

    /** @return iterable<string, array{0: string}> */
    public static function rejectedChords(): iterable
    {
        yield 'empty'              => [''];
        yield 'unknown key name'   => ['spce'];
        yield 'unknown modifier'   => ['meta+x'];
        yield 'super modifier'     => ['super+x'];
        yield 'dangling modifier'  => ['ctrl+'];
        yield 'repeated modifier'  => ['ctrl+ctrl+s'];
        yield 'repeated mixed'     => ['ctrl+alt+ctrl+s'];
        yield 'shift on a rune'    => ['shift+a'];
        yield 'ctrl shift rune'    => ['ctrl+shift+a'];
        yield 'internal char type' => ['char'];
        yield 'two runes'          => ['ab'];
        yield 'inner whitespace'   => ['ctrl+ s'];
        yield 'control byte'       => ["ctrl+\x1b"];
        yield 'newline'            => ["\n"];
        yield 'over long'          => [str_repeat('x', 33)];
        yield 'invalid utf8'       => ["\xff"];
        yield 'modifier name only' => ['ctrl'];
    }

    #[DataProvider('rejectedChords')]
    public function testParseChordRejects(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyBindings::parseChord($input);
    }

    public function testRejectsAreRaisedAtWiringTimeEverywhere(): void
    {
        // A typo must throw where it is written, not bind nothing silently.
        foreach ([
            static fn() => KeyBindings::fromMap(['save' => ['ctrl+spce']]),
            static fn() => KeyBindings::defaults()->withOverride('quit', 'shift+q'),
            static fn() => KeyBindings::new()->bind('save', 'meta+s'),
            static fn() => KeyBindings::new()->withOverrides(['save' => ['ctrl+']]),
        ] as $wire) {
            try {
                $wire();
                self::fail('a bad chord was accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testChordOfSpellsTheMessageCanonically(): void
    {
        self::assertSame('ctrl+s', KeyBindings::chordOf(new KeyMsg(KeyType::Char, 's', ctrl: true)));
        self::assertSame('shift+tab', KeyBindings::chordOf(new KeyMsg(KeyType::Tab, shift: true)));
        self::assertSame('ctrl+alt+shift+up', KeyBindings::chordOf(
            new KeyMsg(KeyType::Up, alt: true, ctrl: true, shift: true),
        ));
        // Shift is folded off a printable rune: the rune carries it.
        self::assertSame('a', KeyBindings::chordOf(new KeyMsg(KeyType::Char, 'A', shift: true)));
        // Space is a named key either wire shape, so shift counts on both.
        self::assertSame('shift+space', KeyBindings::chordOf(new KeyMsg(KeyType::Space, ' ', shift: true)));
        self::assertSame('shift+space', KeyBindings::chordOf(new KeyMsg(KeyType::Char, ' ', shift: true)));
        // tokenOf() stays modifier-free (its documented v1 contract).
        self::assertSame('s', KeyBindings::tokenOf(new KeyMsg(KeyType::Char, 's', ctrl: true)));
    }

    public function testChordBindingsMatchOnlyWithTheirModifiers(): void
    {
        $b = KeyBindings::new()
            ->bind('save', 'ctrl+s')
            ->bind('flag', 's')
            ->bind('next', 'tab')
            ->bind('prev', 'shift+tab');

        $ctrlS = new KeyMsg(KeyType::Char, 's', ctrl: true);
        self::assertTrue($b->matches($ctrlS, 'save'));
        self::assertFalse($b->matches($ctrlS, 'flag'), 'a bare s must not fire on ctrl+s');
        self::assertFalse($b->matches(new KeyMsg(KeyType::Char, 's'), 'save'));
        self::assertFalse($b->matches(new KeyMsg(KeyType::Char, 's', alt: true), 'flag'));
        self::assertTrue($b->matches(new KeyMsg(KeyType::Char, 'S'), 'flag'), 'Caps Lock still folds (m26)');

        $backTab = new KeyMsg(KeyType::Tab, shift: true);
        self::assertTrue($b->matches($backTab, 'prev'));
        self::assertFalse($b->matches($backTab, 'next'));
        self::assertTrue($b->matches(new KeyMsg(KeyType::Tab), 'next'));
        self::assertFalse($b->matches(new KeyMsg(KeyType::Tab), 'prev'));
    }

    public function testMatchesAnswersFalseForNonKeyMessagesAndReleases(): void
    {
        $b = KeyBindings::new()->bind('quit', ['q', 'ctrl+c']);

        self::assertFalse($b->matches(new WindowSizeMsg(80, 24), 'quit'));
        self::assertFalse($b->matches(new KeyReleaseMsg(KeyType::Char, 'c', ctrl: true), 'quit'));
        self::assertTrue($b->matches(new KeyMsg(KeyType::Char, 'c', ctrl: true), 'quit'));
    }

    public function testActionForDispatchesInDeclarationOrder(): void
    {
        $b = KeyBindings::new()
            ->bind('quit', ['q', 'ctrl+c'])
            ->bind('save', 'ctrl+s')
            ->bind('select', 'enter')
            ->bind('confirm', 'enter');

        self::assertSame('quit', $b->actionFor(new KeyMsg(KeyType::Char, 'c', ctrl: true)));
        self::assertSame('save', $b->actionFor(new KeyMsg(KeyType::Char, 's', ctrl: true)));
        self::assertSame('select', $b->actionFor(new KeyMsg(KeyType::Enter)), 'first declared wins a shared key');
        self::assertNull($b->actionFor(new KeyMsg(KeyType::Char, 's')));
        self::assertNull($b->actionFor(new WindowSizeMsg(80, 24)));
    }

    public function testBindDeclaresChordsAndHelpImmutably(): void
    {
        $empty = KeyBindings::new();
        $b = $empty->bind('quit', ['q', 'Ctrl+C', 'q'], 'quit the app');

        self::assertSame([], $empty->actions());
        self::assertSame(['quit'], $b->actions());
        self::assertSame(['q', 'ctrl+c'], $b->tokens('quit'), 'canonical and deduplicated');
        self::assertSame('quit the app', $b->help('quit'));
        self::assertSame('', $empty->help('quit'));
    }

    public function testWithHelpReplacesAndClearsWithoutTouchingChords(): void
    {
        $b = KeyBindings::new()->bind('quit', 'q', 'quit');
        $renamed = $b->withHelp('quit', 'leave');
        $cleared = $renamed->withHelp('quit', '');

        self::assertSame('quit', $b->help('quit'));
        self::assertSame('leave', $renamed->help('quit'));
        self::assertSame('', $cleared->help('quit'));
        self::assertSame(['q'], $cleared->tokens('quit'));
    }

    public function testOverridesKeepHelpAndReplaceChords(): void
    {
        $b = KeyBindings::new()
            ->bind('quit', 'q', 'quit')
            ->bind('save', 'ctrl+s', 'save');
        $user = $b->withOverrides(['quit' => ['ctrl+q'], 'save' => 'f2']);

        self::assertSame(['ctrl+q'], $user->tokens('quit'));
        self::assertSame(['f2'], $user->tokens('save'));
        self::assertSame('quit', $user->help('quit'));
        self::assertSame(['q'], $b->tokens('quit'), 'the original registry is untouched');
    }

    public function testLabelRendersChordsWithGlyphBases(): void
    {
        $b = KeyBindings::new()
            ->bind('quit', ['q', 'ctrl+c'])
            ->bind('prev', 'shift+tab')
            ->bind('word-left', 'ctrl+left')
            ->bind('zoom', 'ctrl++');

        self::assertSame('q ctrl+c', $b->label('quit'));
        self::assertSame('q/ctrl+c', $b->label('quit', '/'));
        self::assertSame('shift+tab', $b->label('prev'));
        self::assertSame('ctrl+←', $b->label('word-left'));
        self::assertSame('ctrl++', $b->label('zoom'));
        self::assertSame('spc', KeyBindings::labelOf('space'));
        self::assertSame('alt+ent', KeyBindings::labelOf('alt+enter'));
    }
}
