<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Prompt\Field\Confirm;
use SugarCraft\Prompt\Field\FilePicker;
use SugarCraft\Prompt\Field\Input;
use SugarCraft\Prompt\Field\MultiSelect;
use SugarCraft\Prompt\Field\Note;
use SugarCraft\Prompt\Field\Select;
use SugarCraft\Prompt\Field\Text;

/**
 * Verifies sugar-prompt concrete fields satisfy the SugarCraft\Forms\Field interface.
 *
 * Note: SugarCraft\Prompt\Field class_alias re-exports were removed because
 * SugarCraft\Forms\Field is an interface (not a class), and class_alias cannot
 * alias an interface to a class name in PHP. The concrete field classes
 * (Input, Text, Confirm, etc.) implement SugarCraft\Forms\Field directly.
 */
final class FieldAliasTest extends TestCase
{
    public function testPromptFieldClassDoesNotExist(): void
    {
        // The class_alias approach for SugarCraft\Prompt\Field was removed
        // because SugarCraft\Forms\Field is an interface (class_alias cannot alias an interface)
        $this->assertFalse(
            class_exists(\SugarCraft\Prompt\Field::class),
            'SugarCraft\Prompt\Field should not exist after alias removal'
        );
    }

    /**
     * @dataProvider concreteFieldProvider
     * @template T of object
     * @param class-string<T> $fieldClass
     */
    public function testConcreteFieldsSatisfyFormsFieldInterface(string $fieldClass): void
    {
        $field = $fieldClass::new('test_key');
        $this->assertInstanceOf(\SugarCraft\Forms\Field::class, $field);
    }

    /**
     * @return iterable<array{class-string}>
     */
    public static function concreteFieldProvider(): iterable
    {
        yield 'Input' => [Input::class];
        yield 'Text' => [Text::class];
        yield 'Confirm' => [Confirm::class];
        yield 'Select' => [Select::class];
        yield 'MultiSelect' => [MultiSelect::class];
        yield 'Note' => [Note::class];
        yield 'FilePicker' => [FilePicker::class];
    }
}
