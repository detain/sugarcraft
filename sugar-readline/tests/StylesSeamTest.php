<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\ConfirmationPrompt;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\SelectionPrompt;
use SugarCraft\Readline\Styles;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\TextareaPrompt;
use SugarCraft\Style\Style;

/**
 * The style seam (finding 036): the palette lives only in Styles, every
 * carrier references it, and the Style|string setters paint exactly what
 * the raw code strings painted before.
 */
final class StylesSeamTest extends TestCase
{
    public function testPaletteConstantsMatchTheLegacyLiterals(): void
    {
        self::assertSame('1;36', Styles::LABEL);
        self::assertSame('7', Styles::CURSOR);
        self::assertSame('31', Styles::ERROR);
        self::assertSame('90', Styles::COMPLETION);
        self::assertSame('1;32', Styles::SELECTED);
    }

    public function testNoCarrierKeepsRawSgrLiteralsInStyleSlots(): void
    {
        $files = [
            'TextPrompt' => __DIR__ . '/../src/TextPrompt.php',
            'TextareaPrompt' => __DIR__ . '/../src/TextareaPrompt.php',
            'ConfirmationPrompt' => __DIR__ . '/../src/ConfirmationPrompt.php',
            'SelectionEngine' => __DIR__ . '/../src/Selection/SelectionEngine.php',
        ];
        foreach ($files as $name => $path) {
            $src = (string) \file_get_contents($path);
            $n = \preg_match_all('/private string \$\w*[Ss]tyle\s*=\s*([^\n;]+)/', $src, $m);
            self::assertGreaterThan(0, $n, $name . ' lost its style slots');
            foreach ($m[1] as $default) {
                self::assertStringContainsString('Styles::', $default, $name . ' still inlines ' . $default);
            }
        }
    }

    public function testCodeCoercion(): void
    {
        self::assertSame('1;36', Styles::code('1;36'), 'strings ride verbatim');
        self::assertSame('', Styles::code(Style::new()), 'decoration-free collapses to empty codes');
        self::assertSame('7', Styles::code(Style::new()->reverse()), 'single-prop collapses to its code');
        self::assertSame('1', Styles::code(Style::new()->bold()));
    }

    public function testCodeRejectsLayoutBearingStyle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot collapse');
        Styles::code(Style::new()->padding(1)->reverse());
    }

    public function testStringSettersReproduceDefaultPaintingByteForByte(): void
    {
        // Passing the legacy spelling through the new seam must move zero
        // bytes: this is the goldens-preservation proof in miniature.
        self::assertSame((new TextPrompt('Hello'))->view(), (new TextPrompt('Hello'))->withLabelStyle(Styles::LABEL)->view());
        self::assertSame((new TextareaPrompt('Hello'))->view(), (new TextareaPrompt('Hello'))->withCursorStyle(Styles::CURSOR)->view());
        self::assertSame((new ConfirmationPrompt('Ok?'))->view(), (new ConfirmationPrompt('Ok?'))->withLabelStyle(Styles::LABEL)->view());
        $sel = new SelectionPrompt('Pick', ['a', 'b']);
        self::assertSame($sel->view(), $sel->withCursorStyle(Styles::CURSOR)->view());
        $multi = new MultiSelectPrompt('Pick', ['a', 'b']);
        self::assertSame($multi->view(), $multi->withLabelStyle(Styles::LABEL)->view());
    }

    public function testStyleObjectPaintsIdenticallyToItsCollapsedCode(): void
    {
        $viaObject = (new TextPrompt('Hello'))->withCursorStyle(Style::new()->reverse())->view();
        $viaString = (new TextPrompt('Hello'))->withCursorStyle(Styles::CURSOR)->view();
        self::assertSame($viaString, $viaObject);
        self::assertSame((new TextPrompt('Hello'))->view(), $viaObject, 'reverse cursor is the default slot');
    }

    public function testSettersActuallyChangeThePaintedFrame(): void
    {
        // A seam nothing reads would pass every identity test above; prove
        // each slot is live by repainting it differently.
        $default = (new ConfirmationPrompt('Ok?', true))->view();
        $repainted = (new ConfirmationPrompt('Ok?', true))->withSelectedStyle(Styles::ERROR)->view();
        self::assertNotSame($default, $repainted);
        self::assertStringContainsString("\x1b[31m", $repainted);

        $textDefault = (new TextPrompt('Label'))->view();
        self::assertStringContainsString("\x1b[1;36m", $textDefault);
        $textRepaint = (new TextPrompt('Label'))->withLabelStyle(Styles::ERROR)->view();
        self::assertStringContainsString("\x1b[31m", $textRepaint);
        self::assertStringNotContainsString("\x1b[1;36m", $textRepaint);
    }
}
