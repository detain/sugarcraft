<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;
use PHPUnit\Framework\TestCase;

final class SelectTest extends TestCase
{
    public function testInitialValueIsFirstOption(): void
    {
        $f = Select::new('lang')->withOptions('PHP', 'Go', 'Rust');
        $this->assertSame('PHP', $f->value());
    }

    public function testArrowsChangeSelection(): void
    {
        [$f, ] = Select::new('lang')->withOptions('PHP', 'Go', 'Rust')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        $this->assertSame('Go', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        $this->assertSame('Rust', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Up));
        $this->assertSame('Go', $f->value());
    }

    public function testTitleInView(): void
    {
        $f = Select::new('lang')
            ->withTitle('Pick a language')
            ->withOptions('A', 'B');
        $this->assertStringContainsString('Pick a language', $f->view());
    }

    public function testEmptyOptions(): void
    {
        $f = Select::new('x');
        $this->assertNull($f->value());
    }

    public function testWithFuzzySuggestions(): void
    {
        $candidates = ['alpha', 'ablation', 'label'];
        $f = Select::new('test')->withFuzzySuggestions($candidates);
        $this->assertNotNull($f);
        $view = $f->view();
        $this->assertStringContainsString('alpha', $view);
        $this->assertStringContainsString('ablation', $view);
        $this->assertStringContainsString('label', $view);
    }

    public function testFuzzySuggestionsShortAlias(): void
    {
        $candidates = ['beta', 'beta decay', 'alphabet'];
        $f = Select::new('test')->fuzzy($candidates);
        $this->assertNotNull($f);
        $view = $f->view();
        $this->assertStringContainsString('beta', $view);
        $this->assertStringContainsString('beta decay', $view);
        $this->assertStringContainsString('alphabet', $view);
    }

    public function testBlurResetsList(): void
    {
        $f = Select::new('lang')->withOptions('A', 'B', 'C');
        [$focused, ] = $f->focus();
        $this->assertTrue($focused->isFocused());
        $blurred = $focused->blur();
        $this->assertFalse($blurred->isFocused());
    }

    public function testWithAsyncSuggestions(): void
    {
        $fetcher = static fn(string $filter): array => ['result1', 'result2'];
        $f = Select::new('test')
            ->withOptions('opt1', 'opt2')
            ->withAsyncSuggestions($fetcher, 200);
        $this->assertNotNull($f);
    }

    public function testAsyncShortAlias(): void
    {
        $fetcher = static fn(string $filter): array => [];
        $f = Select::new('test')->async($fetcher);
        $this->assertNotNull($f);
    }

    public function testUpdateWithSuggestionsReadyMsg(): void
    {
        $f = Select::new('test')
            ->withOptions('old1', 'old2');
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new SuggestionsReadyMsg('test', ['new1', 'new2', 'new3']));
        $view = $f->view();
        $this->assertStringContainsString('new1', $view);
        $this->assertStringContainsString('new2', $view);
        $this->assertStringContainsString('new3', $view);
        $this->assertStringNotContainsString('old1', $view);
    }

    public function testFocusAndBlurImmutability(): void
    {
        $f = Select::new('test')->withOptions('A', 'B');
        [$focused, ] = $f->focus();
        $this->assertNotSame($f, $focused);
        $blurred = $focused->blur();
        $this->assertNotSame($focused, $blurred);
        $this->assertFalse($blurred->isFocused());
    }

    public function testEnumModeReturnsBackedEnum(): void
    {
        $f = Select::new('test')
            ->withOptions('foo', 'bar')
            ->withEnum('SugarCraft\Forms\Tests\Field\DummyBackedEnum');
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        $this->assertInstanceOf(DummyBackedEnum::class, $f->value());
    }

    public function testHeightSetter(): void
    {
        $f = Select::new('test')
            ->withOptions('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J')
            ->withHeight(3);
        $this->assertNotNull($f);
        $view = $f->view();
        $this->assertNotEmpty($view);
    }

    public function testShortFormMethodsReturnNewInstance(): void
    {
        $f = Select::new('test')->withOptions('A', 'B');
        $title = $f->title('My Title');
        $desc = $f->desc('My Desc');
        $height = $f->height(5);
        $enum = $f->enum('SugarCraft\Forms\Tests\Field\DummyBackedEnum');
        $this->assertNotSame($f, $title);
        $this->assertNotSame($f, $desc);
        $this->assertNotSame($f, $height);
        $this->assertNotSame($f, $enum);
    }

    public function testFuzzyFilterIntegrationWithAmbiguousQuery(): void
    {
        $f = Select::new('test')
            ->withFuzzySuggestions(['alpha', 'ablation', 'label']);
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertNotNull($f);
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertNotNull($f);
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertNotNull($f);
        $view = $f->view();
        $this->assertNotEmpty($view);
    }

    public function testFuzzyFilterClearsToOriginalOrder(): void
    {
        $f = Select::new('test')
            ->withFuzzySuggestions(['alpha', 'ablation', 'label']);
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '/'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'b'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Escape));
        $view = $f->view();
        $this->assertStringContainsString('alpha', $view);
        $this->assertStringContainsString('ablation', $view);
        $this->assertStringContainsString('label', $view);
    }

    public function testWithSelectedIndexPreSelects(): void
    {
        $f = Select::new('k')->withOptions('A', 'B', 'C')->withSelectedIndex(2);
        $this->assertSame('C', $f->value());
    }

    public function testWithSelectedIndexClampsNegativeToFirst(): void
    {
        $f = Select::new('k')->withOptions('A', 'B', 'C')->withSelectedIndex(-1);
        $this->assertSame('A', $f->value());
    }

    public function testWithSelectedByValue(): void
    {
        $f = Select::new('k')->withOptions('A', 'B', 'C')->withSelected('B');
        $this->assertSame('B', $f->value());
    }

    public function testWithSelectedUnknownValueLeavesSelectionUnchanged(): void
    {
        $f = Select::new('k')->withOptions('A', 'B', 'C')->withSelected('Nope');
        // Unknown value: selection stays on the prior (first) option.
        $this->assertSame('A', $f->value());
    }

    public function testWithSelectedAfterReSettingOptions(): void
    {
        $f = Select::new('k')
            ->withOptions('A', 'B', 'C')
            ->withOptions('X', 'Y', 'Z')
            ->withSelected('Z');
        $this->assertSame('Z', $f->value());
    }

    public function testPreSelectedValueIsSubmittedWhenUntouched(): void
    {
        $form = Form::new(
            Select::new('k')->withOptions('A', 'B', 'C')->withSelected('C'),
        );
        $this->assertSame('C', $form->getString('k'));
    }
}

enum DummyBackedEnum: string
{
    case foo = 'foo';
    case bar = 'bar';
}
