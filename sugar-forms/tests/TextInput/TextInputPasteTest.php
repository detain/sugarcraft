<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\TextInput\EchoMode;
use SugarCraft\Forms\TextInput\TextInput;
use PHPUnit\Framework\TestCase;

/**
 * SF-25 (sugar-forms-027): TextInput consumes PasteMsg as typed text.
 * CR/LF are stripped (the widget is single-line) and the payload splices
 * through the same per-rune insert() seam keystrokes use — restrict,
 * charLimit, mask/echo and cursor semantics are inherited, not forked.
 */
final class TextInputPasteTest extends TestCase
{
    private function focused(TextInput $t = null): TextInput
    {
        [$f, ] = ($t ?? TextInput::new())->focus();

        return $f;
    }

    /** @return TextInput */
    private function paste(TextInput $t, string $content): TextInput
    {
        [$next, ] = $t->update(new PasteMsg($content));

        return $next;
    }

    public function testCanonicalTaskPasteLandsWholeWithControlBytesStripped(): void
    {
        // The finding's canonical pain: pasting a path. Only CR/LF are
        // stripped (the single-line contract); every other byte rides in
        // VERBATIM per the E704 paste law, and the display-side RenderSafe
        // pass keeps a stray BEL out of the frame.
        $t = $this->paste($this->focused(), "C:\\docs\x07readme.txt\r\n");
        $this->assertSame("C:\\docs\x07readme.txt", $t->value);
        $this->assertSame(18, $t->cursorPos, 'cursor lands after the inserted text');
        $this->assertStringContainsString('C:\docsreadme.txt', $t->view());
        $this->assertStringNotContainsString("\x07", $t->view(), 'no BEL byte on screen');
    }

    public function testEmptyAndPureNewlinePastesCostNothing(): void
    {
        $t = $this->focused();
        $this->assertSame('', $this->paste($t, '')->value);
        $this->assertSame('', $this->paste($t, "\r\n\n")->value);
    }

    public function testRestrictGateFacesEveryPastedRune(): void
    {
        // E736 F2 parity: restrict is per codepoint — a paste must not
        // smuggle through a whole-payload match the typed path refuses.
        $t = $this->paste($this->focused(TextInput::new()->withRestrict('\d')), 'a1b2c3');
        $this->assertSame('123', $t->value);
    }

    public function testCharLimitClipsPasteToTheRoomLeft(): void
    {
        $t = $this->focused(TextInput::new()->withCharLimit(5));
        $t = $this->paste($t, '0123456789');
        $this->assertSame('01234', $t->value);
        $t = $this->paste($t, 'ab');
        $this->assertSame('01234', $t->value, 'at the cap a paste costs nothing');
    }

    public function testMaskedFieldNeverLeaksPastedBytes(): void
    {
        $t = $this->paste(
            $this->focused(TextInput::new()->withEchoMode(EchoMode::Password)->withEchoChar('*')),
            "secret\tpath\nvalue"
        );
        $this->assertSame("secret\tpathvalue", $t->value, 'TAB keeps its documented exemption and rides in');
        $view = $t->view();
        $this->assertStringNotContainsString('secret', $view);
        $this->assertStringContainsString(str_repeat('*', 16), $view);
    }

    public function testUnfocusedWidgetDropsPaste(): void
    {
        [$next, ] = TextInput::new()->update(new PasteMsg('late-paste'));
        $this->assertSame('', $next->value);
    }

    public function testFieldLevelInputForwardsPasteToTheWidget(): void
    {
        $field = Input::new('path');
        [$focused, ] = $field->focus();
        [$next, ] = $focused->update(new PasteMsg("/etc/hosts\n"));
        $this->assertSame('/etc/hosts', $next->value());
    }

    public function testReadonlyFieldRefusesPasteAtTheDoor(): void
    {
        $field = Input::new('locked')->withValue('keep-me')->withReadonly(true);
        [$focused, ] = $field->focus();
        [$next, ] = $focused->update(new PasteMsg('sneak'));
        $this->assertSame('keep-me', $next->value());
    }
}
