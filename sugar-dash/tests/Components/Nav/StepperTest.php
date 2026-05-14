<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Nav;

use SugarCraft\Dash\Components\Nav\Stepper;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class StepperTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testStepperImplementsSizer(): void
    {
        $stepper = Stepper::new([]);
        $this->assertInstanceOf(Sizer::class, $stepper);
    }

    public function testStepperImplementsItem(): void
    {
        $stepper = Stepper::new([]);
        $this->assertInstanceOf(Item::class, $stepper);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Step 1', 'status' => 'pending'],
        ]);
        $rendered = $stepper->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsStepLabel(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Step One', 'status' => 'pending'],
        ]);
        $rendered = $stepper->render();

        $this->assertStringContainsString('Step One', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testFromLabelsCreatesCorrectSteps(): void
    {
        $stepper = Stepper::fromLabels(['First', 'Second', 'Third'], 1);
        $rendered = $stepper->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
        $this->assertStringContainsString('Third', $rendered);
    }

    public function testFromLabelsSetsActiveStep(): void
    {
        $stepper = Stepper::fromLabels(['A', 'B', 'C'], 1);
        $rendered = $stepper->render();

        // Step 2 (index 1) should be active
        $this->assertStringContainsString('[2]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Step states
    // ═══════════════════════════════════════════════════════════════

    public function testCompletedStepShowsCheckmark(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Done', 'status' => 'completed'],
        ]);
        $rendered = $stepper->render();

        $this->assertStringContainsString('✓', $rendered);
    }

    public function testActiveStepShowsNumber(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Active', 'status' => 'active'],
        ]);
        $rendered = $stepper->render();

        $this->assertStringContainsString('[1]', $rendered);
    }

    public function testPendingStepShowsCircle(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Later', 'status' => 'pending'],
        ]);
        $rendered = $stepper->render();

        // Pending shows ○ by default when showNumbers is true
        $this->assertStringContainsString('○', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Show/hide numbers
    // ═══════════════════════════════════════════════════════════════

    public function testWithShowNumbersFalse(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Test', 'status' => 'pending'],
        ])->withShowNumbers(false);
        $rendered = $stepper->render();

        // When numbers hidden, pending shows ○ without brackets
        $this->assertStringContainsString('○', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testCompletedColorAddsAnsiCodes(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Done', 'status' => 'completed'],
        ])->withCompletedColor(Color::ansi(10));
        $rendered = $stepper->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testActiveColorAddsAnsiCodes(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Active', 'status' => 'active'],
        ])->withActiveColor(Color::ansi(13));
        $rendered = $stepper->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Test', 'status' => 'completed'],
        ])->withCompletedColor(Color::ansi(10));
        $rendered = $stepper->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Stepper::new([['label' => 'Test', 'status' => 'pending']]);
        $resized = $original->setSize(50, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithStepsReturnsNewInstance(): void
    {
        $original = Stepper::new([['label' => 'Old', 'status' => 'pending']]);
        $updated = $original->withSteps([['label' => 'New', 'status' => 'active']]);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New', $updated->render());
    }

    public function testWithActiveStep(): void
    {
        $stepper = Stepper::fromLabels(['A', 'B', 'C'], 0);
        $updated = $stepper->withActiveStep(2);
        $rendered = $updated->render();

        // Now step 3 should be active
        $this->assertStringContainsString('✓', $rendered); // A is completed
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Test', 'status' => 'pending'],
        ]);
        [$w, $h] = $stepper->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h); // Single line
    }

    public function testGetInnerSizeWithMultipleSteps(): void
    {
        $stepper = Stepper::new([
            ['label' => 'One', 'status' => 'completed'],
            ['label' => 'Two', 'status' => 'active'],
            ['label' => 'Three', 'status' => 'pending'],
        ]);
        [$w, $h] = $stepper->getInnerSize();

        $this->assertGreaterThan(10, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptySteps(): void
    {
        $stepper = Stepper::new([]);
        $rendered = $stepper->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSingleStep(): void
    {
        $stepper = Stepper::new([
            ['label' => 'Only', 'status' => 'active'],
        ]);
        $rendered = $stepper->render();

        $this->assertStringContainsString('Only', $rendered);
    }

    public function testUnicodeLabels(): void
    {
        $stepper = Stepper::fromLabels(['一', '二', '三'], 0);
        $rendered = $stepper->render();

        $this->assertStringContainsString('一', $rendered);
    }
}
