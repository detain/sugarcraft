<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Wizard;
use SugarCraft\Dash\Grid\WizardStep;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class WizardTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testWizardImplementsSizer(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2']);
        $this->assertInstanceOf(Sizer::class, $wizard);
    }

    public function testWizardImplementsItem(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2']);
        $this->assertInstanceOf(Item::class, $wizard);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyWizardRendersEmpty(): void
    {
        $wizard = Wizard::withSteps([]);
        $rendered = $wizard->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderWithSingleStep(): void
    {
        $wizard = Wizard::withSteps(['Step 1']);
        $rendered = $wizard->render();

        $this->assertStringContainsString('Step 1', $rendered);
        $this->assertStringContainsString('●', $rendered); // Current step indicator
    }

    public function testRenderWithMultipleSteps(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2', 'Step 3']);
        $rendered = $wizard->render();

        $this->assertStringContainsString('Step 1', $rendered);
        $this->assertStringContainsString('Step 2', $rendered);
        $this->assertStringContainsString('Step 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Step states
    // ═══════════════════════════════════════════════════════════════

    public function testFirstStepIsCurrent(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2']);
        $rendered = $wizard->render();

        // First step should be current (●)
        $this->assertStringContainsString('●', $rendered);
    }

    public function testCompletedStepsShowCheckmark(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2', 'Step 3'])
            ->withCurrentStep(1); // Move to step 2
        $rendered = $wizard->render();

        // First step should be completed (✓)
        $this->assertStringContainsString('✓', $rendered);
    }

    public function testUpcomingStepsShowCircle(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withCurrentStep(0);
        $rendered = $wizard->render();

        // Future steps should show ○
        $this->assertStringContainsString('○', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Current step change
    // ═══════════════════════════════════════════════════════════════

    public function testWithCurrentStep(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2', 'Step 3'])
            ->withCurrentStep(2);
        $rendered = $wizard->render();

        $this->assertStringContainsString('✓', $rendered); // Completed steps
        $this->assertStringContainsString('●', $rendered); // Current step
    }

    public function testCurrentStepClampedToValidRange(): void
    {
        // Step beyond last should be clamped
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withCurrentStep(10);
        $rendered = $wizard->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testNegativeCurrentStepClamped(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withCurrentStep(-5);
        $rendered = $wizard->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Step descriptions
    // ═══════════════════════════════════════════════════════════════

    public function testStepWithDescription(): void
    {
        $wizard = Wizard::withSteps(['Step 1'])
            ->withSteps([
                WizardStep::create('Step 1', 'This is a description'),
            ]);
        $rendered = $wizard->render();

        $this->assertStringContainsString('This is a description', $rendered);
    }

    public function testStepWithNullDescription(): void
    {
        $wizard = Wizard::withSteps(['Step 1'])
            ->withSteps([
                WizardStep::create('Step 1', null),
            ]);
        $rendered = $wizard->render();

        // Should render without description
        $this->assertStringContainsString('Step 1', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testCustomCompletedChar(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withCurrentStep(1)
            ->withCompletedChar('✓');
        $rendered = $wizard->render();

        $this->assertStringContainsString('✓', $rendered);
    }

    public function testCustomCurrentChar(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withCurrentChar('◆');
        $rendered = $wizard->render();

        $this->assertStringContainsString('◆', $rendered);
    }

    public function testCustomUpcomingChar(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withUpcomingChar('◇');
        $rendered = $wizard->render();

        $this->assertStringContainsString('◇', $rendered);
    }

    public function testCustomConnectorChar(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withConnectorChar('=');
        $rendered = $wizard->render();

        // Should have connector characters
        $this->assertMatchesRegularExpression('/[=]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testCompletedColorAddsAnsiCodes(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withCurrentStep(1)
            ->withCompletedColor(Color::ansi(9));
        $rendered = $wizard->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testCurrentColorAddsAnsiCodes(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withCurrentColor(Color::ansi(9));
        $rendered = $wizard->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testUpcomingColorAddsAnsiCodes(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withUpcomingColor(Color::ansi(9));
        $rendered = $wizard->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Wizard::withSteps(['Step 1']);
        $resized = $original->setSize(80, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocationAffectsOutput(): void
    {
        $narrow = Wizard::withSteps(['Step 1', 'Step 2', 'Step 3'])->setSize(30, 5);
        $wide = Wizard::withSteps(['Step 1', 'Step 2', 'Step 3'])->setSize(100, 5);

        $narrowRendered = $narrow->render();
        $wideRendered = $wide->render();

        // Wider should produce wider output
        $this->assertLessThan(
            mb_strlen($wideRendered, 'UTF-8'),
            mb_strlen($narrowRendered, 'UTF-8')
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Fluent API / withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithStepsReturnsNewInstance(): void
    {
        $original = Wizard::withSteps(['Step 1']);
        $updated = $original->withSteps([
            WizardStep::create('New Step'),
        ]);

        $this->assertNotSame($original, $updated);
    }

    public function testAddStepReturnsNewInstance(): void
    {
        $original = Wizard::withSteps(['Step 1']);
        $updated = $original->addStep(WizardStep::create('Step 2'));

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Step 2', $updated->render());
    }

    public function testWithCurrentStepReturnsNewInstance(): void
    {
        $original = Wizard::withSteps(['Step 1', 'Step 2']);
        $updated = $original->withCurrentStep(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithCompletedColorReturnsNewInstance(): void
    {
        $original = Wizard::withSteps(['Step 1']);
        $updated = $original->withCompletedColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithCurrentStep(): void
    {
        $original = Wizard::withSteps(['Step 1', 'Step 2']);
        $original->withCurrentStep(1);
        $rendered = $original->render();

        // Original should still be at step 0
        $this->assertStringContainsString('●', $rendered);
        $this->assertStringNotContainsString('✓', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeEmptyWizard(): void
    {
        $wizard = Wizard::withSteps([]);
        [$w, $h] = $wizard->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeBasicWizard(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2']);
        [$w, $h] = $wizard->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(2, $h); // Progress + labels
    }

    public function testGetInnerSizeWithDescriptions(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])
            ->withSteps([
                WizardStep::create('Step 1', 'Description 1'),
                WizardStep::create('Step 2', 'Description 2'),
            ]);
        [, $h] = $wizard->getInnerSize();

        $this->assertSame(4, $h); // Progress + labels + 2 descriptions
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $wizard = Wizard::withSteps(['Step 1', 'Step 2'])->setSize(100, 5);
        [$w, ] = $wizard->getInnerSize();

        $this->assertSame(100, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // WizardStep tests
    // ═══════════════════════════════════════════════════════════════

    public function testWizardStepCreate(): void
    {
        $step = WizardStep::create('Title', 'Description');

        $this->assertSame('Title', $step->title);
        $this->assertSame('Description', $step->description);
    }

    public function testWizardStepCreateWithNullDescription(): void
    {
        $step = WizardStep::create('Title');

        $this->assertSame('Title', $step->title);
        $this->assertNull($step->description);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongStepTitle(): void
    {
        $wizard = Wizard::withSteps([str_repeat('x', 100)]);
        $rendered = $wizard->render();

        // Should truncate
        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeStepTitle(): void
    {
        $wizard = Wizard::withSteps(['日本語タイトル']);
        $rendered = $wizard->render();

        $this->assertStringContainsString('日本語タイトル', $rendered);
    }

    public function testManySteps(): void
    {
        $stepTitles = [];
        for ($i = 0; $i < 10; $i++) {
            $stepTitles[] = "Step $i";
        }
        $wizard = Wizard::withSteps($stepTitles);
        $rendered = $wizard->render();

        $this->assertNotSame('', $rendered);
    }
}
