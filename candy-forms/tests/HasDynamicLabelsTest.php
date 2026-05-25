<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Text;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the {@see \SugarCraft\Forms\HasDynamicLabels} trait —
 * each field type that opts in must surface withTitleFunc /
 * withDescriptionFunc setters and resolve them at view / getTitle /
 * getDescription time.
 */
final class HasDynamicLabelsTest extends TestCase
{
    public function testInputDynamicTitle(): void
    {
        $count = 0;
        $field = Input::new('q')
            ->withTitle('static')
            ->withTitleFunc(static function () use (&$count): string {
                return 'dynamic-' . (++$count);
            });
        $this->assertSame('dynamic-1', $field->getTitle());
        $this->assertSame('dynamic-2', $field->getTitle());
        $this->assertStringContainsString('dynamic-3', $field->view());
    }

    public function testInputDynamicDescription(): void
    {
        $field = Input::new('q')
            ->withDescription('orig')
            ->withDescriptionFunc(static fn (): string => 'live');
        $this->assertSame('live', $field->getDescription());
    }

    public function testConfirmDynamicLabels(): void
    {
        $field = Confirm::new('ok')
            ->withTitleFunc(static fn (): string => 'go?')
            ->withDescriptionFunc(static fn (): string => 'final answer');
        $this->assertSame('go?',          $field->getTitle());
        $this->assertSame('final answer', $field->getDescription());
        $view = $field->view();
        $this->assertStringContainsString('go?',          $view);
        $this->assertStringContainsString('final answer', $view);
    }

    public function testNoteDynamicLabels(): void
    {
        $field = Note::new('intro')
            ->withTitle('static title')
            ->withTitleFunc(static fn (): string => 'live title');
        $this->assertSame('live title', $field->getTitle());
    }

    public function testSelectDynamicTitle(): void
    {
        $field = Select::new('lang')
            ->withOptions('PHP', 'Go')
            ->withTitleFunc(static fn (): string => 'Pick one');
        $this->assertStringContainsString('Pick one', $field->view());
    }

    public function testMultiSelectDynamicTitle(): void
    {
        $field = MultiSelect::new('langs')
            ->withOptions('PHP', 'Go')
            ->withTitleFunc(static fn (): string => 'Pick many');
        $this->assertStringContainsString('Pick many', $field->view());
    }

    public function testTextDynamicTitle(): void
    {
        $field = Text::new('bio')
            ->withTitleFunc(static fn (): string => 'About you');
        $this->assertStringContainsString('About you', $field->view());
    }

    public function testFilePickerDynamicTitle(): void
    {
        $tmp = sys_get_temp_dir();
        $field = FilePicker::new('path', $tmp)
            ->withTitleFunc(static fn (): string => 'Pick a file');
        $this->assertStringContainsString('Pick a file', $field->view());
    }

    public function testNullClearsFunc(): void
    {
        $field = Input::new('q')
            ->withTitle('static')
            ->withTitleFunc(static fn (): string => 'live')
            ->withTitleFunc(null);
        $this->assertSame('static', $field->getTitle());
        $this->assertFalse($field->hasTitleFunc());
    }

    public function testHasFlags(): void
    {
        $a = Input::new('q');
        $this->assertFalse($a->hasTitleFunc());
        $this->assertFalse($a->hasDescriptionFunc());
        $b = $a->withTitleFunc(static fn (): string => 'x');
        $this->assertTrue($b->hasTitleFunc());
        $this->assertFalse($b->hasDescriptionFunc());
        $c = $b->withDescriptionFunc(static fn (): string => 'y');
        $this->assertTrue($c->hasDescriptionFunc());
    }
}
