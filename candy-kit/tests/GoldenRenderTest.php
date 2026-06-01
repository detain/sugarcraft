<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kit\Stage;
use SugarCraft\Testing\Snapshot\Assertions;

/**
 * Golden-file snapshot tests for candy-kit ANSI presenter output.
 *
 * Captures the byte-exact output of Stage::step() and other renderers
 * to detect regressions in themed CLI output.
 *
 * @see Mirrors charmbracelet/fang output rendering
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    /**
     * Test that Stage::step() emits deterministic ANSI output.
     *
     * Uses Theme::ansi() to produce the default colourful output.
     * Snapshot pins the arrow glyph + count formatting + message + colors.
     */
    public function testStepRendersAnsi(): void
    {
        $output = Stage::step(2, 5, 'building dependencies');

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/stage-step.golden',
            $output,
        );
    }

    /**
     * Test that Stage::subStep() emits deterministic ANSI output.
     */
    public function testSubStepRendersAnsi(): void
    {
        $output = Stage::subStep('installing packages', null, false);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/stage-substep.golden',
            $output,
        );
    }
}
