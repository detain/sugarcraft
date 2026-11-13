<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use PHPUnit\Framework\TestCase;

/**
 * SF-8 (sugar-forms-006 / sugar-forms-028): Form::withWidth was a stored
 * promise the renderer never kept. The budget now (a) threads into each
 * width-aware field's native knob as a ceiling — a field that set a
 * narrower width keeps it, the form never widens; (b) clips every line of
 * a threaded field block plus the form's own chrome rows; (c) leaves the
 * documented exemptions (Confirm, Note, Date, Color, FilePicker) exactly
 * as the field painted them. Select and MultiSelect gained real
 * withWidth() setters; width 0 keeps the historical unbounded shape
 * byte-identical (the whole existing suite pins that).
 */
final class FormWidthThreadTest extends TestCase
{
    private const WIDE = '选项标题很长很长的选项标题很长很长的选项标题很长很长的'; // ~40 cells of wide text

    /** @return list<int> */
    private function lineCells(string $view): array
    {
        return array_map(
            static fn (string $line): int => Width::of(Ansi::strip($line)),
            explode("\n", $view),
        );
    }

    public function testFormWidthBoundsEveryLineOfWidthAwareBlock(): void
    {
        $select = Select::new('pick')->withTitle(self::WIDE . ' 标题')
            ->withOptions(str_repeat('選', 60), str_repeat('択', 50));
        $view = Form::new($select)->withWidth(40)->view();
        foreach ($this->lineCells($view) as $cells) {
            $this->assertLessThanOrEqual(40, $cells, 'no line exceeds the 40-cell probe');
        }
    }

    public function testGroupChromeClipsToBudget(): void
    {
        $form = Form::groups(Group::new(Input::new('a'))->withTitle(self::WIDE . self::WIDE));
        foreach ($this->lineCells($form->withWidth(30)->view()) as $cells) {
            $this->assertLessThanOrEqual(30, $cells);
        }
    }

    public function testFieldWidthWinsBelowTheFormCeiling(): void
    {
        $field = Input::new('q')->withWidth(10)->withValue(str_repeat('a', 50));
        [$field, ] = $field->focus();
        $form = Form::new($field)->withWidth(40);
        $widened = Form::new($field->withWidth(40))->withWidth(40);
        foreach ($this->lineCells($form->view()) as $cells) {
            $this->assertLessThanOrEqual(12, $cells, 'a 10-wide field keeps its window (+2 prompt) under a 40 ceiling');
        }
        $this->assertGreaterThan(30, max($this->lineCells($widened->view())));
    }

    public function testFormAdoptsUnboundedTextIntoBudget(): void
    {
        $field = Input::new('q')->withTitle(self::WIDE);
        $form = Form::new($field)->withWidth(30);
        $this->assertLessThanOrEqual(30, max($this->lineCells($form->view())));
        // Same field standalone keeps its unbounded shape (no silent shrink).
        $this->assertGreaterThan(30, max($this->lineCells($field->view())));
    }

    public function testSelectWithWidthClipsAndKeepsHeight(): void
    {
        $select = Select::new('pick')->withOptions(str_repeat('x', 100))->withHeight(4);
        $this->assertGreaterThan(20, max($this->lineCells($select->view()))); // ItemList default 60 cap
        $clipped = $select->withWidth(20);
        $this->assertLessThanOrEqual(20, max($this->lineCells($clipped->view())));
        $this->assertSame($select->list->height, $clipped->list->height, 'width must not move the height slot');
    }

    public function testMultiSelectWithWidthClipsOptionLines(): void
    {
        $ms = MultiSelect::new('pick')
            ->withOptions(str_repeat('o', 90), str_repeat('p', 80))
            ->withTitle(self::WIDE);
        $this->assertGreaterThan(50, max($this->lineCells($ms->view())));
        $clipped = $ms->withWidth(24);
        $this->assertLessThanOrEqual(24, max($this->lineCells($clipped->view())));
        // Option rows specifically: the budget is honoured by the lines, not
        // just smuggled by the composer.
        $optionRows = array_slice(explode("\n", $clipped->withTitle('')->withDescription('')->view()), 0, 2);
        foreach ($optionRows as $row) {
            $this->assertLessThanOrEqual(24, Width::of(Ansi::strip($row)));
        }
    }

    public function testRadioPillsAdoptsCeilingWhenUnset(): void
    {
        $pills = RadioPills::new('pick')->withOptions(...array_map(
            static fn (int $i): string => "option-$i",
            range(0, 30),
        ));
        $form = Form::new($pills)->withWidth(25);
        $this->assertLessThanOrEqual(25, max($this->lineCells($form->view())));
    }

    public function testExemptFieldsRenderNaturalWidth(): void
    {
        $wideTitle = self::WIDE . self::WIDE;
        $confirm = Confirm::new('go')->withTitle($wideTitle);
        $form = Form::new($confirm)->withWidth(10);
        $this->assertGreaterThan(10, max($this->lineCells($form->view())), 'exemptions stay natural — no surprise clipping');
        $this->assertSame($confirm->view(), $form->view(), 'the composer never rewrites an exempt block');
    }

    public function testZeroBudgetIsByteIdenticalToUnthreadedRender(): void
    {
        $select = Select::new('pick')->withOptions(str_repeat('x', 100));
        $form = Form::new($select)->withWidth(0);
        $this->assertSame($select->view(), $form->view());
    }

    public function testTextScrollWindowHonoursThreadedBudget(): void
    {
        $field = Input::new('q')->withValue(str_repeat('あ', 30)); // 60 cells
        $view = Form::new($field)->withWidth(20)->view();
        foreach ($this->lineCells($view) as $cells) {
            $this->assertLessThanOrEqual(20, $cells, 'the CJK scroll window fits the budget');
        }
    }

    public function testScrollWindowShowsTextAtCursorNotJustLineStart(): void
    {
        // The thread is not cosmetic: the budget must reach TextInput so it
        // SCROLLS to the caret. A clip-only "fix" would keep just the line
        // head and the tail char (where the cursor sits) would vanish.
        $field = Input::new('q')->withValue(str_repeat('a', 50) . 'Z');
        [$field, ] = $field->focus();
        $view = Form::new($field)->withWidth(20)->view();
        $this->assertStringContainsString('Z', $view);
    }
}
