<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\UndoManager;

/**
 * Finding 086: pushUndoOn stored the text only, so Ctrl+Z restored words
 * but always parked the caret at end of text. Frames now carry
 * (text, caret) pairs; the UndoManager itself stays value-agnostic and
 * ferries the opaque encoded frame.
 */
final class TextPromptUndoCaretTest extends TestCase
{
    private static function cursorOf(TextPrompt $p): int
    {
        $property = new \ReflectionProperty(TextPrompt::class, 'cursor');
        return $property->getValue($p);
    }

    private static function managerOf(TextPrompt $p): ?UndoManager
    {
        $property = new \ReflectionProperty(TextPrompt::class, 'undoManager');
        return $property->getValue($p);
    }

    public function testMidBufferEditUndoRestoresTheCaretColumn(): void
    {
        $typed = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleKey(Key::Left)->handleKey(Key::Left);
        $before = $typed->handleChar('X');
        $this->assertSame('aXbc', $before->value());
        $this->assertSame(2, self::cursorOf($before));

        $undone = $before->handleKey('ctrl_z');
        $this->assertSame('abc', $undone->value());
        $this->assertSame(1, self::cursorOf($undone), 'caret returns to its recorded column');
        // Byte pin: the restored frame is indistinguishable from never
        // having typed the X — the reverse-video caret block sits at the
        // same byte offset.
        $this->assertSame($typed->view(), $undone->view());
    }

    public function testRedoRestoresTextAndCaretTogether(): void
    {
        $edited = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleKey(Key::Left)->handleKey(Key::Left)
            ->handleChar('X');

        $undone = $edited->handleKey('ctrl_z');
        $redone = $undone->handleKey('ctrl_y');

        $this->assertSame('aXbc', $redone->value());
        $this->assertSame(2, self::cursorOf($redone));
        $this->assertSame($edited->view(), $redone->view());
    }

    public function testMultibyteCaretRoundTrip(): void
    {
        $typed = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar("\u{fc}")->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleKey(Key::Left);
        $edited = $typed->handleChar('X');
        $this->assertSame("\u{fc}abXc", $edited->value());

        $undone = $edited->handleKey('ctrl_z');
        $this->assertSame("\u{fc}abc", $undone->value());
        $this->assertSame(3, self::cursorOf($undone));
        $this->assertSame($typed->view(), $undone->view());
    }

    public function testLegacyRawFramesDecodeToTheOldLaw(): void
    {
        // A host-seeded stack of plain strings (pre-caret frames) keeps
        // working: text restored, caret at end, clamped and sane.
        $p = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager(['plaintext']));

        $undone = $p->handleKey('ctrl_z');
        $this->assertSame('plaintext', $undone->value());
        $this->assertSame(9, self::cursorOf($undone));
    }

    public function testHiddenPromptsStillTakeNoFrames(): void
    {
        $p = TextPrompt::new('PIN: ')
            ->withUndoManager(new UndoManager())
            ->withHidden(true)
            ->handleChar('s')->handleChar('e')->handleChar('c');

        $manager = self::managerOf($p);
        $this->assertNotNull($manager);
        $this->assertFalse($manager->canUndo(), 'secrets (and their carets) never enter undo state');
    }
}
