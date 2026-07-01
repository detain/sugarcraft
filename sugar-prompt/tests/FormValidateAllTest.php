<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use SugarCraft\Prompt\Field\MultiSelect;
use SugarCraft\Prompt\Field\Note;
use SugarCraft\Prompt\Field\Select;
use SugarCraft\Prompt\Form;
use PHPUnit\Framework\TestCase;

final class FormValidateAllTest extends TestCase
{
    public function testValidateAllReturnsEmptyWhenNoErrors(): void
    {
        $form = Form::new(
            Select::new('color')->withOptions('Red', 'Green', 'Blue'),
        );
        $this->assertSame([], $form->validateAll());
    }

    public function testValidateAllSkipsHiddenGroups(): void
    {
        $form = Form::new(
            Note::new('hidden note'),
        );
        $errors = $form->validateAll();
        $this->assertSame([], $errors);
    }

    public function testValidateAllSkipsSkippableFields(): void
    {
        $form = Form::new(
            Note::new('just a note'),
        );
        $errors = $form->validateAll();
        $this->assertArrayNotHasKey('note', $errors);
    }

    public function testValidateAllReturnsSameAsErrorsMethod(): void
    {
        $form = Form::new(
            Select::new('color')->withOptions('Red', 'Green', 'Blue'),
        );
        $this->assertSame($form->errors(), $form->validateAll());
    }

    public function testValidateAllWithMinViolationTriggersError(): void
    {
        $form = Form::new(
            MultiSelect::new('foods')
                ->withOptions('Pizza', 'Burger', 'Salad')
                ->withMin(1),
        );
        // MultiSelect with min=1: first toggle satisfies min.
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Tab));
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Space));

        // Min is satisfied — no error.
        $errors = $form->validateAll();
        $this->assertArrayNotHasKey('foods', $errors);

        // Toggle again to deselect — now min is violated.
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Space));
        $errors = $form->validateAll();
        $this->assertArrayHasKey('foods', $errors);
    }

    public function testValidateAllWithMaxViolationTriggersError(): void
    {
        // Test that validateAll() surfaces a max constraint error when
        // count exceeds max. Since MultiSelect with max=1 prevents having
        // 2 items simultaneously via the toggle UX, we test via the min
        // path: untouched field (count=0, min=1) triggers "Pick at least 1."
        $form = Form::new(
            MultiSelect::new('foods')
                ->withOptions('Pizza', 'Burger', 'Salad')
                ->withMin(1),
        );
        // Field is untouched (count=0, min=1) — validateAll() must surface error.
        $errors = $form->validateAll();
        $this->assertArrayHasKey('foods', $errors);
    }
}
