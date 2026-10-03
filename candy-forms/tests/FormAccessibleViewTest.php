<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Form;

/**
 * Accessible mode renders "title: value" for the focused field. It used a
 * raw `(string)` cast, so a MultiSelect (array value) raised "Array to
 * string conversion" and printed the literal "Array" — on exactly the
 * field types screen-reader users need. It now shares getString()'s
 * coercion (arrays imploded with ", ").
 */
final class FormAccessibleViewTest extends TestCase
{
    public function testMultiSelectValueIsImplodedNotArray(): void
    {
        $form = Form::new(
            MultiSelect::new('langs')->withTitle('Languages')
                ->withOptions('PHP', 'Go', 'Rust')
                ->withValue(['PHP', 'Rust']),
        )->withAccessible();

        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $out = $form->view();
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings);
        $this->assertSame('Languages: PHP, Rust', $out);
        $this->assertSame($form->getString('langs'), 'PHP, Rust');
    }

    public function testScalarValuesKeepTheirSpelling(): void
    {
        $this->assertSame('Name: bob', Form::new(Input::new('n')->withTitle('Name')->withValue('bob'))->withAccessible()->view());
        $confirm = Form::new(Confirm::new('ok')->withTitle('OK?'))->withAccessible()->view();
        $this->assertMatchesRegularExpression('/^OK\?: (true|false)$/', $confirm);
    }
}
