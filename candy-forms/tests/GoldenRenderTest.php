<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for ANSI rendering output.
 *
 * These tests capture the byte-exact output of render() methods
 * to detect unintended changes to terminal output.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testConfirmFieldRendersWithAnsi(): void
    {
        $f = Confirm::new('agree')
            ->withTitle('Do you agree?')
            ->withDescription('Please confirm your choice')
            ->withDefault(true);

        $output = $f->view();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Do you agree?', $output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/confirm-field.golden',
            $output,
        );
    }

    public function testFormWithConfirmAndTextInputRendersWithAnsi(): void
    {
        $form = Form::new(
            Confirm::new('agree')->withTitle('Terms of Service'),
            Text::new('name')->withTitle('Your Name'),
        );

        $output = $form->view();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Terms of Service', $output);
        $this->assertStringContainsString('Your Name', $output);

        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/form-confirm-text.golden',
            $output,
        );
    }
}
