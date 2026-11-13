<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Theme;
use PHPUnit\Framework\TestCase;

/**
 * SF-9 (sugar-forms-007/-029/-094): the theme reaches the FIELD bodies.
 *
 * Contract pinned here:
 *  - standalone `view()` (null theme) paints the legacy plain bytes —
 *    a field rendered outside a Form never moves;
 *  - the shipped default preset keeps the field BODY byte-identical to
 *    the legacy render (form fixture vs golden, pill-for-pixel);
 *  - non-default presets visibly repaint the body (titles, cursor
 *    surface, checked boxes, inline errors) — the "seven presets, zero
 *    difference" claim is dead;
 *  - focus is visible: a blurred list dims its highlight, a focused
 *    Select carries the prompt caret;
 *  - the error summary bullets ride the split errorSummaryItem slot.
 */
final class ThemeRenderTest extends TestCase
{
    private const LEGACY_REVERSE = "\x1b[7m";

    private function erroredInput(): Input
    {
        $field = Input::new('name')->withTitle('Name')->withValidator(static fn (): string => 'bad');
        /** @var Input $validated */
        $validated = $field->revalidate();
        return $validated;
    }

    public function testStandaloneViewKeepsLegacyPlainBytes(): void
    {
        $field = $this->erroredInput();
        $out   = $field->view();
        self::assertStringContainsString('! bad', $out);
        // null theme: the inline error row carries no palette SGR at all.
        self::assertStringNotContainsString("\x1b[38", $out);
        self::assertStringNotContainsString("\x1b[91", $out);
    }

    public function testInlineErrorRowRidesTheThemeErrorSlot(): void
    {
        $field = $this->erroredInput();
        $theme = Theme::ansi();
        $out   = $field->view(true, $theme);
        self::assertStringContainsString($theme->error->render('! bad'), $out);
        $dracula = Theme::dracula();
        self::assertStringContainsString($dracula->error->render('! bad'), $field->view(true, $dracula));
        // The FORM plumbs the theme: the same row inside a composed form
        // reaches the field already themed (this is the withTheme leg of the
        // finding — a bypassed pass-through fails right here).
        $form = Form::new($field);
        self::assertStringContainsString($theme->error->render('! bad'), $form->view());
    }

    public function testDefaultPresetFormBodyIsByteIdenticalToLegacy(): void
    {
        // The golden fixture was cut pre-SF-9 from the default-theme form
        // render; the shipped ansi() preset must reproduce it byte-for-byte.
        $fixture = \file_get_contents(__DIR__ . '/fixtures/form-confirm-text.golden');
        self::assertIsString($fixture);
        $form   = Form::new(
            Confirm::new('agree')->withTitle('Terms of Service'),
            Text::new('name')->withTitle('Your Name'),
        );
        self::assertSame($fixture, $form->view());
    }

    public function testPlainPresetStripsEveryEscape(): void
    {
        // plain() is the no-colour promise: the themed render carries zero
        // SGR — the only escape-free field-body preset in the catalog.
        $form = Form::new(
            Confirm::new('agree')->withTitle('Terms of Service'),
            Text::new('name')->withTitle('Your Name'),
        )->withTheme(Theme::plain());
        self::assertStringNotContainsString("\x1b", $form->view());
    }

    public function testConfirmPillByteIdenticalAtDefaultPreset(): void
    {
        $field = Confirm::new('agree')->withTitle('Agree?')->withDefault(true);
        $legacy = $field->view();
        $themed = $field->view(true, Theme::ansi());
        // ansi() cursor is Style::reverse() — the same pixels the legacy
        // hard-coded pair painted.
        self::assertSame($legacy, $themed);
        self::assertStringContainsString(self::LEGACY_REVERSE, $themed);
        // A preset with a coloured cursor repaints the pill (a legacy-
        // hard-coded pill would render identically under Dracula — dead slot).
        $dracula = Theme::dracula();
        $off = Confirm::new('agree')->withTitle('Agree?');
        self::assertStringContainsString($dracula->cursor->render(' No '), $off->view(true, $dracula));
        self::assertStringNotContainsString($dracula->cursor->render(' No '), $off->view(true, Theme::ansi()));
    }

    public function testDraculaRepaintsTheBodyWhereAnsiStaysPlain(): void
    {
        $select = Select::new('pick')->withTitle('Pick')->withOptions('One', 'Two');
        /** @var Select $focused */
        $focused = $select->focus()[0];
        $ansi     = $focused->view(true, Theme::ansi());
        $dracula  = $focused->view(true, Theme::dracula());
        self::assertNotSame($ansi, $dracula, 'presets must differ inside the field body');
        // Dracula's focused-title slot paints the title row; ansi() does not.
        self::assertStringContainsString(Theme::dracula()->focusedTitle->render('Pick'), $dracula);
        self::assertStringNotContainsString(Theme::dracula()->focusedTitle->render('Pick'), $ansi);
        // Its cursor surface carries the palette pink on top of the reverse.
        self::assertStringContainsString("\x1b[7m", $dracula);
        self::assertStringContainsString('❯ ', $dracula);
        self::assertStringContainsString('❯ ', $ansi);
    }

    public function testBlurredListDimsItsHighlightAndFocusedKeepsIt(): void
    {
        $select = Select::new('pick')->withOptions('One', 'Two');
        /** @var Select $focused */
        $focused = $select->focus()[0];
        $theme   = Theme::ansi();
        self::assertStringContainsString(self::LEGACY_REVERSE, $focused->view(true, $theme));
        /** @var Select $blurred */
        $blurred = $focused->blur();
        self::assertStringNotContainsString(self::LEGACY_REVERSE, $blurred->view(true, $theme));
        // Standalone (null theme) keeps the legacy always-reverse bytes.
        self::assertStringContainsString(self::LEGACY_REVERSE, $blurred->view());
    }

    public function testFocusedSelectCarriesThePromptCaret(): void
    {
        $select = Select::new('pick')->withTitle('Pick')->withOptions('One');
        /** @var Select $focused */
        $focused = $select->focus()[0];
        /** @var Select $blurred */
        $blurred = $focused->blur();
        $theme   = Theme::ansi();
        self::assertStringContainsString($theme->prompt->render('❯ '), $focused->view(true, $theme));
        self::assertStringNotContainsString('❯ ', $blurred->view(true, $theme));
        self::assertStringNotContainsString('❯ ', $focused->view());
    }

    public function testMultiSelectCheckedBoxRidesTheSelectedOptionSlot(): void
    {
        $field = MultiSelect::new('tags')->withOptions('a', 'b')->withValue(['b']);
        $theme = Theme::ansi();
        self::assertStringContainsString($theme->selectedOption->render('[x]'), $field->view(true, $theme));
        // Standalone keeps the plain box.
        self::assertStringContainsString('[x]', $field->view());
        self::assertStringNotContainsString($theme->selectedOption->render('[x]'), $field->view());
    }

    public function testErrorSummaryItemsRideTheSplitSlotAndDefaultBytesHold(): void
    {
        $form = Form::new($this->erroredInput())->withErrorSummary(true);
        self::assertTrue($form->hasErrors());
        // ansi(): the item slot reproduces the pre-split error-slot bytes
        // exactly — the summary moved slots without moving pixels.
        self::assertStringContainsString(
            Theme::ansi()->error->render('Name: bad'),
            $form->view(),
        );
        // Dracula: items take the non-bold item slot, visibly distinct
        // from the bold field-error slot.
        $dracula = Theme::dracula();
        self::assertStringContainsString(
            $dracula->errorSummaryItem->render('Name: bad'),
            $form->withTheme($dracula)->view(),
        );
        self::assertNotSame(
            $dracula->error->render('x'),
            $dracula->errorSummaryItem->render('x'),
            'the split must actually differ at the palette',
        );
    }

    public function testErrorSummaryItemDefaultsToPlainWhenOmitted(): void
    {
        $theme = new Theme(
            title:          Theme::ansi()->title,
            description:    Theme::ansi()->description,
            focusedTitle:   Theme::ansi()->focusedTitle,
            blurredTitle:   Theme::ansi()->blurredTitle,
            error:          Theme::ansi()->error,
            errorSummary:   Theme::ansi()->errorSummary,
            cursor:         Theme::ansi()->cursor,
            option:         Theme::ansi()->option,
            selectedOption: Theme::ansi()->selectedOption,
            help:           Theme::ansi()->help,
            prompt:         Theme::ansi()->prompt,
        );
        self::assertSame('x', $theme->errorSummaryItem->render('x'));
    }
}
