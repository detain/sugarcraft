<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Text;

/**
 * Terminal-injection regressions: titles, descriptions, option labels and
 * button labels are attacker-influenced display strings. Each render path
 * must strip C0/DEL and bare ESC (screen clears, cursor moves) via
 * RenderSafe::clean() while preserving SGR — and Select must keep its
 * VALUE raw even though its DISPLAY is cleaned.
 *
 * `\x1b[2J` = ESC + CSI erase-display; cleaning strips the ESC, leaving the
 * harmless printable remainder `[2J`.
 */
final class RenderSafeInjectionTest extends TestCase
{
    private const ESC = "\x1b";

    public function testInputTitleStripsEsc(): void
    {
        $view = Input::new('q')->withTitle("hi\x1b[2Jevil")->view();
        $this->assertStringNotContainsString(self::ESC, $view);
        $this->assertStringContainsString('hi', $view);
        $this->assertStringContainsString('evil', $view);
    }

    public function testInputDescriptionStripsEsc(): void
    {
        $view = Input::new('q')->withDescription("desc\x1b[2Jhack")->view();
        $this->assertStringNotContainsString(self::ESC, $view);
        $this->assertStringContainsString('desc', $view);
        $this->assertStringContainsString('hack', $view);
    }

    public function testTextTitleAndDescriptionStripEsc(): void
    {
        $view = Text::new('bio')
            ->withTitle("t\x1b[2Jinj")
            ->withDescription("d\x1b[2Jinj")
            ->view();
        $this->assertStringNotContainsString(self::ESC, $view);
        $this->assertStringContainsString('t', $view);
        $this->assertStringContainsString('d', $view);
        $this->assertStringContainsString('inj', $view);
    }

    public function testSelectOptionLabelDisplayCleanedButValueStaysRaw(): void
    {
        $raw   = "bad\x1b[2Jinject";
        $field = Select::new('lang')
            ->withOptions('ok', $raw)
            ->withSelectedIndex(1);

        // DISPLAY: the option's injected CSI must not survive rendering.
        // (The selected row carries a legitimate REVERSE SGR wrapper, so
        // we can't assert "no ESC at all" — assert the injection is gone.)
        $view = $field->view();
        $this->assertStringNotContainsString(self::ESC . '[2J', $view);
        $this->assertStringContainsString('inject', $view);

        // VALUE: the field value stays byte-for-byte raw (value semantics
        // preserved — cleaning happens at render, not at value).
        $this->assertSame($raw, $field->value());
        $this->assertStringContainsString(self::ESC, $field->value());
    }

    public function testNoteTitleDescriptionAndButtonLabelStripEsc(): void
    {
        $view = Note::new('intro')
            ->withTitle("title\x1b[2Jx")
            ->withDescription("body\x1b[2Jx")
            ->withNext()
            ->withNextLabel("go\x1b[2J")
            ->view();
        $this->assertStringNotContainsString(self::ESC, $view);
        $this->assertStringContainsString('title', $view);
        $this->assertStringContainsString('body', $view);
        $this->assertStringContainsString('[ go', $view);
    }

    public function testDynamicTitleFuncIsCleaned(): void
    {
        // Proves the *Func closure path routes through resolveTitle's clean.
        $field = Input::new('q')->withTitleFunc(static fn (): string => "x\x1by");
        $this->assertSame('xy', $field->getTitle());
        $this->assertStringNotContainsString(self::ESC, $field->view());
    }

    public function testSgrInTitleIsPreserved(): void
    {
        // RenderSafe keeps SGR — the fix must not over-strip styling.
        $sgrTitle = "\x1b[1mBold\x1b[0m";
        $view = Input::new('q')->withTitle($sgrTitle)->view();
        $this->assertStringContainsString($sgrTitle, $view);
    }

    /**
     * The `\x1b[2Jm` bypass, exercised through real render paths.
     *
     * `\x1b[2J` (Erase-in-Display) followed by a literal 'm' used to survive
     * cleaning because the old regex accepted any byte up to the next 'm' as
     * SGR params. These assert the ESC is gone from the rendered view.
     */
    public function testInputTitleClosesCsiMBypass(): void
    {
        $view = Input::new('q')->withTitle("hi\x1b[2Jmevil")->view();
        $this->assertStringNotContainsString(self::ESC, $view);
        $this->assertStringContainsString('hi', $view);
        $this->assertStringContainsString('evil', $view);
    }

    public function testSelectOptionLabelClosesCsiMBypass(): void
    {
        $raw   = "bad\x1b[2Jminject";
        $field = Select::new('lang')->withOptions('ok', $raw)->withSelectedIndex(1);

        // The injected erase-display CSI must not survive rendering.
        $this->assertStringNotContainsString(self::ESC . '[2J', $field->view());
        $this->assertStringContainsString('inject', $field->view());
        // Value stays byte-for-byte raw.
        $this->assertSame($raw, $field->value());
    }

    public function testNoteButtonLabelClosesCsiMBypass(): void
    {
        $view = Note::new('intro')
            ->withNext()
            ->withNextLabel("go\x1b[2Jm")
            ->view();
        $this->assertStringNotContainsString(self::ESC, $view);
        $this->assertStringContainsString('[ go', $view);
    }

    /**
     * Validation-error messages are attacker-influenceable display strings
     * (a validator can echo user input). Their render site must clean too,
     * while the stored error / getError() stay raw.
     */
    public function testInputErrorMessageStripsEsc(): void
    {
        $field = Input::new('q')
            ->withValidation(static fn (string $v): bool => false, "bad\x1b[2Jminput")
            ->revalidate();

        $view = $field->view();
        $this->assertStringNotContainsString(self::ESC, $view);
        $this->assertStringContainsString('bad', $view);
        $this->assertStringContainsString('input', $view);
        // Stored error keeps the raw bytes — cleaning is display-only.
        $this->assertStringContainsString(self::ESC, (string) $field->getError());
    }

    public function testTextErrorMessageStripsEsc(): void
    {
        $field = Text::new('bio')
            ->withValidation(static fn (string $v): bool => false, "bad\x1b[2Jmbody")
            ->revalidate();

        $view = $field->view();
        $this->assertStringNotContainsString(self::ESC, $view);
        $this->assertStringContainsString('bad', $view);
        $this->assertStringContainsString('body', $view);
        $this->assertStringContainsString(self::ESC, (string) $field->getError());
    }
}
