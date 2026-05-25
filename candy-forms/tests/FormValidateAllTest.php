<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;
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
        $form = Form::new(
            MultiSelect::new('foods')
                ->withOptions('Pizza', 'Burger', 'Salad')
                ->withMax(1),
        );
        // Tab to focus, select first.
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Tab));
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Space));
        // Down to second, select — exceeds max.
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Down));
        [$form, ] = $form->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Space));

        $errors = $form->validateAll();
        $this->assertArrayHasKey('foods', $errors);
    }
}
