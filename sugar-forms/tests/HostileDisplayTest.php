<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * SF-3 (M8): values, validator messages and accessible lines are built from
 * untrusted bytes (pasted content, remote model output) and reach a terminal
 * surface. {@see \SugarCraft\Forms\Util\RenderSafe::clean()} neutralises ESC
 * sequences at the display choke points — the screen-clear CSI 2J, OSC 8
 * hyperlink payloads — while true SGR styling survives and the editing
 * buffers keep their bytes verbatim.
 */
final class HostileDisplayTest extends TestCase
{
    /** A paste carrying a screen clear and an OSC 8 hyperlink. */
    private const HOSTILE = "a\x1b[2Jb\x1b]8;;http://evil\x07c";

    /** What clean() leaves of HOSTILE: ESC bytes dropped, printables kept. */
    private const HOSTILE_INERT = 'a[2Jb]8;;http://evilc';

    public function testInputDisplayValueStripsControlBytesKeepingRemainder(): void
    {
        $input = Input::new('card')->withValue(self::HOSTILE);

        $this->assertSame(self::HOSTILE_INERT, $input->displayValue());
    }

    public function testInputViewCarriesNoLiveScreenClearOrHyperlink(): void
    {
        $view = Input::new('card')->withTitle('Card')->withValue(self::HOSTILE)->view();

        $this->assertStringNotContainsString("\x1b[2J", $view);
        $this->assertStringNotContainsString("\x1b]8;;", $view);
        $this->assertStringContainsString('evilc', $view);
    }

    public function testTrueSgrSurvivesTheSweep(): void
    {
        $input = Input::new('c')->withValue("keep \x1b[1mbold\x1b[0m drop \x1b[2J end");

        $this->assertSame("keep \x1b[1mbold\x1b[0m drop [2J end", $input->displayValue());
    }

    public function testMaskProjectionStripsControlBytesInsideMaskedValue(): void
    {
        $input = Input::new('card')->withPasswordMask('\d+')->withValue("12\x1b[2J34");

        $this->assertStringNotContainsString("\x1b", $input->displayValue());
        $this->assertStringNotContainsString("\x1b", $input->view());
    }

    public function testTextAreaDisplayIsInertWhileBufferStaysVerbatim(): void
    {
        $area = TextArea::new()->insertString("x\x1b[2Jy");

        // The buffer is the editing contract: bytes are kept verbatim.
        $this->assertSame("x\x1b[2Jy", $area->value());
        // Only what reaches the screen is sanitised.
        $this->assertStringNotContainsString("\x1b[2J", $area->view());
        $this->assertStringContainsString('x[2Jy', $area->view());
    }

    public function testErrorSummaryStripsHostileValidatorMessage(): void
    {
        $field = Input::new('k')
            ->withTitle('K')
            ->withValue('x')
            ->withValidator(static fn (string $v): ?string => 'e' . "\x1b[2J" . 'vil')
            ->revalidate();
        $this->assertNotNull($field->error(), 'precondition: the validator error is stored');

        $view = Form::new($field)->withErrorSummary(true)->view();

        $this->assertStringNotContainsString("\x1b[2J", $view);
        $this->assertStringContainsString('e[2Jvil', $view);
    }

    public function testAccessibleLineStripsHostileValueAndError(): void
    {
        $field = Input::new('k')
            ->withTitle('A')
            ->withValue('v')
            ->withValidator(static fn (string $v): ?string => 'e' . "\x1b[2J" . 'vil')
            ->revalidate();

        $this->assertSame("A: v\n! e[2Jvil", Form::new($field)->withAccessible()->view());
    }
}
