<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use SugarCraft\Prompt\Field\Confirm;
use SugarCraft\Prompt\Field\Text;
use SugarCraft\Prompt\Form;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testConfirmAndTextFormRendersWithAnsi(): void
    {
        $form = Form::new(
            Confirm::new('agree')->withTitle('Terms'),
            Text::new('bio')->withTitle('Biography'),
        );

        $output = $form->view();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Terms', $output);
        $this->assertStringContainsString('Biography', $output);

        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/confirm-text-form.golden',
            $output,
        );
    }
}
