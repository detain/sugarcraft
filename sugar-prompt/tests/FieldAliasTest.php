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
 * Verifies SugarCraft\Prompt\Field correctly aliases SugarCraft\Forms\Field
 * and that concrete fields satisfy the interface.
 */
final class FieldAliasTest extends TestCase
{
    public function testPromptFieldAliasesFormsField(): void
    {
        $this->assertTrue(class_exists(\SugarCraft\Prompt\Field::class));
        $this->assertSame(
            \SugarCraft\Forms\Field::class,
            (new \ReflectionClass(\SugarCraft\Prompt\Field::class))->getName()
        );
    }

    /**
     * @dataProvider concreteFieldProvider
     * @template T of object
     * @param class-string<T> $fieldClass
     */
    public function testConcreteFieldsSatisfyPromptFieldInterface(string $fieldClass): void
    {
        $field = $fieldClass::new('test_key');
        $this->assertInstanceOf(\SugarCraft\Prompt\Field::class, $field);
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

    public function testPromptFieldFieldIsDistinct(): void
    {
        // SugarCraft\Prompt\Field (root alias) and SugarCraft\Prompt\Field\Field (subnamespace alias)
        // are distinct symbols; the latter points to the value-object form.
        $this->assertNotSame(
            \SugarCraft\Prompt\Field::class,
            \SugarCraft\Prompt\Field\Field::class
        );
        $this->assertSame(
            \SugarCraft\Forms\Field\Field::class,
            (new \ReflectionClass(\SugarCraft\Prompt\Field\Field::class))->getName()
        );
    }
}
