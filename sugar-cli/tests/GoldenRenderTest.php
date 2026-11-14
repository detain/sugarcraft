<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\Banner;
use SugarCraft\Cli\Section;
use SugarCraft\Cli\HelpText;
use SugarCraft\Cli\Frame;
use SugarCraft\Cli\Logo;
use SugarCraft\Cli\StatusLine;
use SugarCraft\Cli\Stage;
use SugarCraft\Cli\Theme;
use SugarCraft\Cli\Tests\Support\SandboxTerminalColumns;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use SugarCraft\Testing\Snapshot\Assertions;

/**
 * Golden-file snapshot tests for sugar-cli ANSI presenter output.
 *
 * Captures the byte-exact output of Stage::step() and other renderers
 * to detect regressions in themed CLI output.
 *
 * Output styling mirrors the spirit of charmbracelet/fang's help pages.
 */
final class GoldenRenderTest extends TestCase
{
    use SandboxTerminalColumns;

    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
        // The goldens were captured at the 80-cell fallback; a runner that
        // advertises a different COLUMNS would re-flow the default-width
        // renders below, so hide it before every byte comparison.
        $this->sandboxTerminalColumns();
    }

    protected function tearDown(): void
    {
        $this->restoreTerminalColumns();
    }

    /**
     * Test that Stage::step() emits deterministic ANSI output.
     *
     * Passes Theme::ansi() explicitly: the no-theme fallback is
     * Theme::detect(), whose output depends on the runner's tty/env, while
     * a golden must pin one byte sequence.
     * Snapshot pins the arrow glyph + count formatting + message + colors.
     */
    public function testStepRendersAnsi(): void
    {
        $output = Stage::step('building dependencies', 2, 5, Theme::ansi());

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
        $output = Stage::subStep('installing packages', Theme::ansi(), false);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/stage-substep.golden',
            $output,
        );
    }

    public function testBannerTitleRendersAnsi(): void
    {
        $output = Banner::title('MyApp', 'v1.0.0', Theme::ansi());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/banner-title.golden',
            $output,
        );
    }

    /**
     * Finding 044 arm 1 — plain composite: one golden carrying a page built
     * from several presenters under Theme::plain(), so the zero-escape
     * contract is pinned end-to-end, not only per-presenter.
     */
    public function testPlainCompositeGolden(): void
    {
        $output = Section::header('Flags', Theme::plain(), 1, 70) . "\n"
            . HelpText::render('myapp [flags]', ['flags' => [
                '--verbose' => 'enable verbose logging',
                '--out <p>' => 'write the report to path p',
            ]], theme: Theme::plain(), width: 70) . "\n"
            . StatusLine::success('all done', Theme::plain()) . "\n"
            . Stage::step('running', 3, 3, Theme::plain(), width: 70);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/composite-plain.golden', $output);
        self::assertStringNotContainsString("\x1b", $output, 'the plain composite must be escape-free');
    }

    /**
     * Finding 044 arm 2 — pinned-60 Auto reflow: the same page measured at
     * the DEFAULT 80 would capture nothing about re-flow; captured with
     * COLUMNS pinned to 60 and the width left omitted (the Auto leg), this
     * golden pins exactly how the default page reflows to a narrow terminal.
     */
    public function testPinnedSixtyAutoReflowGolden(): void
    {
        $this->pinTerminalColumns(60);

        $output = HelpText::render('myapp [flags]', ['flags' => [
            '--enable-verbose-logging' => 'turn on verbose diagnostic output for every stage of the run',
        ]], theme: Theme::plain());

        Assertions::assertGoldenAnsi($this->fixturesDir . '/helptext-pin60.golden', $output);

        // The bytes alone could be any width's output; pin what this case
        // exists to capture: the Auto leg resolved to 60, so rows stay
        // within 60 cells and the over-long description re-flowed.
        $lines = explode("\n", $output);
        self::assertGreaterThan(3, count($lines), 'the long description must wrap at 60');
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(60, Width::string($line));
        }
    }

    /**
     * Finding 044 arm 3 — dracula accent path: the preset actually used by
     * themed apps; its accent colour runs were never golden-pinned, so a
     * palette regression could only ever be caught by eye.
     */
    public function testDraculaAccentGolden(): void
    {
        $output = Section::header('Commands', Theme::dracula(), 1, 70) . "\n"
            . HelpText::render('myapp', ['commands' => [
                'build' => 'compile the project',
            ]], theme: Theme::dracula(), width: 70) . "\n"
            . StatusLine::error('failed', Theme::dracula());

        Assertions::assertGoldenAnsi($this->fixturesDir . '/dracula-accent.golden', $output);
        self::assertStringContainsString("\x1b[", $output, 'the dracula path must carry colour runs');
    }

    public function testSectionHeaderRendersAnsi(): void
    {
        $output = Section::header('Features', Theme::ansi());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/section-header.golden',
            $output,
        );
    }

    public function testSectionRuleRendersAnsi(): void
    {
        $output = Section::rule(Theme::ansi());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/section-rule.golden',
            $output,
        );
    }

    public function testSectionSubHeaderRendersAnsi(): void
    {
        $output = Section::subHeader('Sub-section', Theme::ansi());

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
            Theme::ansi(),
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
        $output = StatusLine::success('Deployment complete', Theme::ansi());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/statusline-success.golden',
            $output,
        );
    }

    public function testStatusLineErrorRendersAnsi(): void
    {
        $output = StatusLine::error('Something went wrong', Theme::ansi());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/statusline-error.golden',
            $output,
        );
    }

    public function testStatusLineWarnRendersAnsi(): void
    {
        $output = StatusLine::warn('Low disk space', Theme::ansi());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/statusline-warn.golden',
            $output,
        );
    }

    public function testStageSubStepWithProgressRendersAnsi(): void
    {
        $output = Stage::subStepWithProgress('installing packages', 4, 10, Theme::ansi());

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/stage-substep-progress.golden',
            $output,
        );
    }
}
