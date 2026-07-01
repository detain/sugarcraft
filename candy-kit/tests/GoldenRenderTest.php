<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kit\Banner;
use SugarCraft\Kit\Section;
use SugarCraft\Kit\HelpText;
use SugarCraft\Kit\Frame;
use SugarCraft\Kit\Logo;
use SugarCraft\Kit\StatusLine;
use SugarCraft\Kit\Stage;
use SugarCraft\Sprinkles\Style;
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

    public function testBannerTitleRendersAnsi(): void
    {
        $output = Banner::title('MyApp', 'v1.0.0');

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/banner-title.golden',
            $output,
        );
    }

    public function testSectionHeaderRendersAnsi(): void
    {
        $output = Section::header('Features');

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/section-header.golden',
            $output,
        );
    }

    public function testSectionRuleRendersAnsi(): void
    {
        $output = Section::rule();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/section-rule.golden',
            $output,
        );
    }

    public function testSectionSubHeaderRendersAnsi(): void
    {
        $output = Section::subHeader('Sub-section');

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/section-subheader.golden',
            $output,
        );
    }

    public function testHelpTextRendersAnsi(): void
    {
        $output = HelpText::render(
            'myapp [flags] <file>',
            ['flags' => ['-h' => 'show help', '-v' => 'verbose']],
            'A sample application.',
        );

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/helptext.golden',
            $output,
        );
    }

    public function testFrameRendersAnsi(): void
    {
        $output = Frame::new()
            ->withTitle('Welcome')
            ->withStatus(' Ready ')
            ->render("Hello, world!\nThis is a test.", 50, 10);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/frame.golden',
            $output,
        );
    }

    public function testFrameWithTitleTextRendersAnsi(): void
    {
        $output = Frame::new()
            ->withTitleText('Welcome', Style::new()->bold())
            ->withStatus(' Ready ')
            ->render('Hello, world!', 50, 10);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/frame-title-text.golden',
            $output,
        );
    }

    public function testLogoSugarcraftRendersAnsi(): void
    {
        $output = Logo::sugarcraft()->render();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/logo-sugarcraft.golden',
            $output,
        );
    }

    public function testLogoSugarcraftWithColorRendersAnsi(): void
    {
        $output = Logo::sugarcraft()->withColor('#ff5fd2')->render();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/logo-sugarcraft-colored.golden',
            $output,
        );
    }

    public function testStatusLineSuccessRendersAnsi(): void
    {
        $output = StatusLine::success('Deployment complete');

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/statusline-success.golden',
            $output,
        );
    }

    public function testStatusLineErrorRendersAnsi(): void
    {
        $output = StatusLine::error('Something went wrong');

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/statusline-error.golden',
            $output,
        );
    }

    public function testStatusLineWarnRendersAnsi(): void
    {
        $output = StatusLine::warn('Low disk space');

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/statusline-warn.golden',
            $output,
        );
    }

    public function testStageSubStepWithProgressRendersAnsi(): void
    {
        $output = Stage::subStepWithProgress('installing packages', 4, 10);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/stage-substep-progress.golden',
            $output,
        );
    }
}
