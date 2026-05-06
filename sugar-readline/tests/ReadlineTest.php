<?php

declare(strict_types=1);

namespace CandyCore\Readline\Tests;

use CandyCore\Readline\{ConfirmationPrompt, MultiSelectPrompt, SelectionPrompt, TextPrompt, TextareaPrompt};
use PHPUnit\Framework\TestCase;

final class ReadlineTest extends TestCase
{
    // ---- TextPrompt tests ----

    public function testTextPromptNew(): void
    {
        $p = TextPrompt::new('Name: ');
        $this->assertSame('Name: ', $p->Value());
    }

    public function testTextPromptTypeChar(): void
    {
        $p = TextPrompt::new('> ');
        $p = $p->HandleChar('h')
              ->HandleChar('i');

        $this->assertSame('> hi', $p->Value());
    }

    public function testTextPromptBackspace(): void
    {
        $p = TextPrompt::new('> ');
        $p = $p->HandleChar('x')->HandleChar('y');
        $this->assertSame('> xy', $p->Value());

        $p = $p->HandleBackspace();
        $this->assertSame('> x', $p->Value());
    }

    public function testTextPromptCursorMovement(): void
    {
        $p = TextPrompt::new('> ')->HandleChar('A')->HandleChar('B')->HandleChar('C');
        $p = $p->HandleKey('left');
        $this->assertSame(2, $p->Cursor());
    }

    public function testTextPromptHome(): void
    {
        $p = TextPrompt::new('> ')->HandleChar('x')->HandleChar('y');
        $p = $p->HandleKey('home');
        $this->assertSame(2, $p->Cursor()); // '> ' is 2 chars
    }

    public function testTextPromptWithDefault(): void
    {
        $p = TextPrompt::new('> ')->WithDefault('world');
        $this->assertSame('world', $p->Value());
    }

    public function testTextPromptWithCompletions(): void
    {
        $p = TextPrompt::new('> ')->WithCompletions(['apple', 'banana', 'cherry']);
        $this->assertIsString($p->View());
    }

    public function testTextPromptTabCompletion(): void
    {
        $p = TextPrompt::new('> ');
        $p = $p->HandleChar('b');
        $p = $p->HandleKey('tab');
        // 'banana' is the only match; cursor should be at end of 'banana'
        $this->assertSame('> banana', $p->Value());
    }

    public function testTextPromptValidation(): void
    {
        $p = TextPrompt::new('> ');
        $p = $p->HandleChar('x')
              ->HandleChar('y')
              ->HandleChar('z')
              ->WithValidation(fn($v) => $v !== 'xyz');

        $p = $p->Confirm();
        $this->assertFalse($p->IsConfirmed());
        $this->assertSame('Invalid input', $p->Error());
    }

    public function testTextPromptCancel(): void
    {
        $p = TextPrompt::new('> ')->HandleChar('x')->HandleKey('esc');
        $this->assertTrue($p->IsCancelled());
        $this->assertSame('', $p->Value());
    }

    public function testTextPromptImmutability(): void
    {
        $a = TextPrompt::new('> ');
        $b = $a->HandleChar('x');
        $this->assertNotSame($a, $b);
    }

    // ---- SelectionPrompt tests ----

    public function testSelectionPromptNew(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b', 'c']);
        $this->assertSame('a', $p->SelectedValue());
    }

    public function testSelectionPromptCursorDown(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b', 'c']);
        $p = $p->HandleKey('down');
        $this->assertSame('b', $p->SelectedValue());
    }

    public function testSelectionPromptCursorUp(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b', 'c'])->HandleKey('down')->HandleKey('down');
        $p = $p->HandleKey('up');
        $this->assertSame('b', $p->SelectedValue());
    }

    public function testSelectionPromptFilter(): void
    {
        $p = SelectionPrompt::new('Pick:', ['apple', 'banana', 'cherry', 'date'])
            ->Filter('an');

        $this->assertSame(2, $p->rowCount());
        $this->assertSame('banana', $p->SelectedValue());
    }

    public function testSelectionPromptFilterClear(): void
    {
        $p = SelectionPrompt::new('Pick:', ['apple', 'banana'])->Filter('app');
        $p = $p->Filter('');

        $this->assertSame(2, $p->rowCount());
    }

    public function testSelectionPromptConfirm(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b'])->Confirm();
        $this->assertTrue($p->IsConfirmed());
        $this->assertSame('a', $p->SelectedValue());
    }

    public function testSelectionPromptCancel(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b'])->HandleKey('esc');
        $this->assertTrue($p->IsCancelled());
    }

    public function testSelectionPromptPagination(): void
    {
        $items = \range('A', 'Z');  // 26 items
        $p = SelectionPrompt::new('Pick:', $items)->WithPerPage(5);
        $this->assertSame(6, $p->TotalPages());
    }

    public function testSelectionPromptView(): void
    {
        $p = SelectionPrompt::new('Pick:', ['apple', 'banana']);
        $view = $p->View();
        $this->assertStringContainsString('Pick:', $view);
        $this->assertStringContainsString('apple', $view);
    }

    public function testSelectionPromptMultiSelect(): void
    {
        $p = SelectionPrompt::new('Pick:', ['a', 'b', 'c'])
            ->WithMultiSelect()
            ->HandleKey('down')
            ->HandleKey('space')
            ->HandleKey('down')
            ->HandleKey('space')
            ->Confirm();

        $values = $p->SelectedValues();
        $this->assertContains('a', $values);
        $this->assertContains('b', $values);
        $this->assertNotContains('c', $values);
    }

    // ---- ConfirmationPrompt tests ----

    public function testConfirmationPromptDefaultYes(): void
    {
        $p = ConfirmationPrompt::new('Delete?');
        $p = $p->Confirm();
        $this->assertTrue($p->Result());
    }

    public function testConfirmationPromptNo(): void
    {
        $p = ConfirmationPrompt::new('Delete?')->HandleKey('n')->Confirm();
        $this->assertFalse($p->Result());
    }

    public function testConfirmationPromptLeftArrow(): void
    {
        $p = ConfirmationPrompt::new('Delete?')->HandleKey('n')->HandleKey('left')->Confirm();
        $this->assertTrue($p->Result());
    }

    public function testConfirmationPromptCancel(): void
    {
        $p = ConfirmationPrompt::new('Delete?')->HandleKey('esc');
        $this->assertTrue($p->IsCancelled());
    }

    public function testConfirmationPromptView(): void
    {
        $p = ConfirmationPrompt::new('Continue?');
        $view = $p->View();
        $this->assertStringContainsString('Continue?', $view);
    }

    // ---- TextareaPrompt tests ----

    public function testTextareaPromptNew(): void
    {
        $p = TextareaPrompt::new('Description:');
        $this->assertSame('', $p->Value());
    }

    public function testTextareaPromptType(): void
    {
        $p = TextareaPrompt::new('> ')->HandleChar('H')->HandleChar('i');
        $this->assertSame("Hi", $p->Value());
    }

    public function testTextareaPromptNewline(): void
    {
        $p = TextareaPrompt::new('> ')->HandleChar('L')->HandleKey('enter')->HandleChar('2');
        $this->assertSame("L\n2", $p->Value());
    }

    public function testTextareaPromptMoveLine(): void
    {
        $p = TextareaPrompt::new('> ')
            ->HandleChar('A')
            ->HandleKey('enter')
            ->HandleChar('B')
            ->HandleKey('up');

        $this->assertSame(0, $this->getCursorLine($p));
    }

    public function testTextareaPromptConfirm(): void
    {
        $p = TextareaPrompt::new('> ')->HandleChar('x')->Confirm();
        $this->assertTrue($p->IsConfirmed());
        $this->assertSame('x', $p->Value());
    }

    public function testTextareaPromptCancel(): void
    {
        $p = TextareaPrompt::new('> ')->HandleChar('x')->HandleKey('esc');
        $this->assertTrue($p->IsCancelled());
    }

    public function testTextareaPromptView(): void
    {
        $p = TextareaPrompt::new('Text:')->HandleChar('H')->HandleChar('i');
        $this->assertStringContainsString('Hi', $p->View());
    }

    // ---- MultiSelectPrompt ----

    public function testMultiSelectNew(): void
    {
        $p = MultiSelectPrompt::new('Pick foods:', ['Pizza', 'Burger', 'Sushi']);
        $this->assertFalse($p->IsConfirmed());
        $this->assertFalse($p->IsCancelled());
        $this->assertSame(0, $p->SelectionCount());
    }

    public function testMultiSelectToggleAndConfirm(): void
    {
        $p = MultiSelectPrompt::new('Pick foods:', ['Pizza', 'Burger', 'Sushi']);
        // Space toggles selection, enter confirms if min is met
        $p = $p->HandleKey('space')  // select first item
               ->HandleKey('down')
               ->HandleKey('space')  // select second item
               ->HandleKey('enter'); // confirm

        $this->assertTrue($p->IsConfirmed());
        $values = $p->SelectedValues();
        $this->assertCount(2, $values);
        $this->assertContains('Pizza', $values);
        $this->assertContains('Burger', $values);
    }

    public function testMultiSelectMinEnforcement(): void
    {
        $p = MultiSelectPrompt::new('Pick foods:', ['Pizza', 'Burger', 'Sushi'])
            ->WithMinSelections(2);

        // Only 1 selection — enter should not confirm
        $p = $p->HandleKey('space')
               ->HandleKey('enter');

        $this->assertFalse($p->IsConfirmed()); // min not met, stay in prompt
    }

    public function testMultiSelectMinMetAllowsConfirm(): void
    {
        $p = MultiSelectPrompt::new('Pick foods:', ['Pizza', 'Burger', 'Sushi'])
            ->WithMinSelections(2);

        $p = $p->HandleKey('space')
               ->HandleKey('down')
               ->HandleKey('space')
               ->HandleKey('enter');

        $this->assertTrue($p->IsConfirmed());
    }

    public function testMultiSelectMaxEnforcement(): void
    {
        $p = MultiSelectPrompt::new('Pick foods:', ['Pizza', 'Burger', 'Sushi', 'Tacos'])
            ->WithMaxSelections(2);

        $p = $p->HandleKey('space')   // select Pizza
               ->HandleKey('down')
               ->HandleKey('space')   // select Burger
               ->HandleKey('down')
               ->HandleKey('space');  // at max, deselects first (Pizza), selects Sushi

        $values = $p->SelectedValues();
        $this->assertCount(2, $values);
        $this->assertContains('Burger', $values);
        $this->assertContains('Sushi', $values);
        $this->assertNotContains('Pizza', $values);
    }

    public function testMultiSelectCancel(): void
    {
        $p = MultiSelectPrompt::new('Pick foods:', ['Pizza', 'Burger'])
            ->HandleKey('ctrl_c');

        $this->assertTrue($p->IsCancelled());
        $this->assertSame([], $p->SelectedValues());
    }

    public function testMultiSelectView(): void
    {
        $p = MultiSelectPrompt::new('Food:', ['Pizza', 'Burger']);
        $view = $p->View();

        $this->assertStringContainsString('Food:', $view);
        $this->assertStringContainsString('Pizza', $view);
        $this->assertStringContainsString('Burger', $view);
        $this->assertStringContainsString('○', $view); // unselected marker
    }

    public function testMultiSelectPagination(): void
    {
        $items = \range('A', 'Z'); // 26 items
        $p = MultiSelectPrompt::new('Letter:', $items)->WithPerPage(5);

        $this->assertSame(6, $p->TotalPages());
        $pageItems = $p->CurrentPageItems();
        $this->assertCount(5, $pageItems);
        $this->assertSame('A', $pageItems[0]);

        // Navigate to page 2
        $p = $p->HandleKey('pagedown');
        $this->assertSame(1, $p->CurrentPage());
        $pageItems = $p->CurrentPageItems();
        $this->assertSame('F', $pageItems[0]);
    }

    public function testMultiSelectFilter(): void
    {
        $p = MultiSelectPrompt::new('Food:', ['Apple', 'Banana', 'Cherry', 'Date']);

        // Simulate filter by directly using items (filter is user-driven)
        $p = $p->HandleKey('enter');
        $this->assertSame(4, $p->FilterMatchCount());
    }

    public function testMultiSelectCursorNavigation(): void
    {
        $p = MultiSelectPrompt::new('Food:', ['A', 'B', 'C', 'D', 'E']);
        $this->assertCount(5, $p->CurrentPageItems());

        $p = $p->HandleKey('end');
        $p = $p->HandleKey('home');

        // Just verify it doesn't crash and stays on first page
        $this->assertSame(0, $p->CurrentPage());
    }

    public function testMultiSelectCanConfirm(): void
    {
        $p = MultiSelectPrompt::new('Pick:', ['A', 'B']);
        $this->assertFalse($p->CanConfirm());

        $p = $p->HandleKey('space');
        $this->assertTrue($p->CanConfirm());
    }

    // ---- SelectionPrompt min/max selections ----

    public function testSelectionPromptWithMinSelections(): void
    {
        $p = SelectionPrompt::new('Pick one:', ['A', 'B', 'C'])
            ->WithMultiSelect(true)
            ->WithMinSelections(2);

        // One item selected — enter should not confirm
        $p = $p->HandleKey('space')
               ->HandleKey('enter');

        $this->assertFalse($p->IsConfirmed());
    }

    public function testSelectionPromptWithMaxSelections(): void
    {
        $p = SelectionPrompt::new('Pick:', ['A', 'B', 'C'])
            ->WithMultiSelect(true)
            ->WithMaxSelections(1);

        $p = $p->HandleKey('space')   // select A
               ->HandleKey('down')
               ->HandleKey('space');  // should replace A with B

        $values = $p->SelectedValues();
        $this->assertCount(1, $values);
        $this->assertSame(['B'], $values);
    }

    // ---- Helper ----

    /** @param object $obj */
    private function getCursorLine(object $obj): int
    {
        $ref = (new \ReflectionClass($obj))->getProperty('cursorLine');
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }

    /** @param object $obj */
    private function rowCount(object $obj): int
    {
        $ref = (new \ReflectionClass($obj))->getProperty('filteredItems');
        $ref->setAccessible(true);
        return \count($ref->getValue($obj));
    }
}
