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
 *
 * RULE (finding 046): an indeterminate render (total = 0) draws its spinner
 * from wall-clock time unless the caller passes an explicit `frame:`; a
 * golden captured that way is a different byte string on every re-mint, so
 * it silently rots the snapshot corpus. {@see
 * testIndeterminateGoldenCallsPinExplicitFrame()} enforces the rule
 * structurally over this file's own source — adding a total=0 golden without
 * a pinned frame goes red on sight. (StageTest carries the same rule and
 * covers the behaviour: its frame-pinned determinism pins were the guard's
 * seed cases.)
 */
final class GoldenRenderTest extends TestCase
{
    use SandboxTerminalColumns;

    private string $fixturesDir;

    /**
     * Finding 046: structural guard. Every literal-argument render call in
     * this file that reaches the indeterminate spinner path (total = 0) must
     * carry an explicit frame. Pinned on the source (the same house technique
     * SafeTextTest uses for untriggerable runtime arms) because the failure
     * it prevents is a FUTURE dataset addition, not a callable bug.
     */
    public function testIndeterminateGoldenCallsPinExplicitFrame(): void
    {
        $src = (string) file_get_contents(__FILE__);
        $violations = [];

        foreach (['step' => 2, 'subStepWithProgress' => 2] as $method => $totalPos) {
            // Walk every call site; a total=0 render without frame: is a coin
            // flip turned into a snapshot.
            $offset = 0;
            while (($at = strpos($src, "Stage::{$method}(", $offset)) !== false) {
                $offset = $at + 1;
                $open = $at + \strlen("Stage::{$method}");
                $depth = 0;
                $end = null;
                $inString = false;
                $stringChar = '';
                for ($i = $open, $n = \strlen($src); $i < $n; $i++) {
                    $c = $src[$i];
                    if ($inString) {
                        if ($c === '\\') {
                            $i++;
                        } elseif ($c === $stringChar) {
                            $inString = false;
                        }
                        continue;
                    }
                    if ($c === "'" || $c === '"') {
                        $inString = true;
                        $stringChar = $c;
                    } elseif ($c === '(') {
                        $depth++;
                    } elseif ($c === ')') {
                        if (--$depth === 0) {
                            $end = $i;
                            break;
                        }
                    }
                }
                self::assertNotNull($end, "unbalanced call text at Stage::{$method} — parser guard");

                $callText = \substr($src, $open + 1, $end - $open - 1);

                // Split top-level args.
                $args = [];
                $depth = 0;
                $current = '';
                $inString = false;
                $stringChar = '';
                for ($i = 0, $n = \strlen($callText); $i < $n; $i++) {
                    $c = $callText[$i];
                    if ($inString) {
                        $current .= $c;
                        if ($c === '\\') {
                            $current .= $callText[++$i];
                        } elseif ($c === $stringChar) {
                            $inString = false;
                        }
                        continue;
                    }
                    if ($c === "'" || $c === '"') {
                        $inString = true;
                        $stringChar = $c;
                        $current .= $c;
                    } elseif ($c === '(' || $c === '[') {
                        $depth++;
                        $current .= $c;
                    } elseif ($c === ')' || $c === ']') {
                        $depth--;
                        $current .= $c;
                    } elseif ($c === ',' && $depth === 0) {
                        $args[] = trim($current);
                        $current = '';
                    } else {
                        $current .= $c;
                    }
                }
                $args[] = trim($current);

                // Named arguments (width:, frame:, ...) ride past the
                // positional block; only literal positional totals are static.
                $positional = [];
                $hasFrame = false;
                foreach ($args as $arg) {
                    if (preg_match('/^(\w+)\s*:/', $arg)) {
                        if (preg_match('/^frame\s*:/', $arg)) {
                            $hasFrame = true;
                        }
                        continue;
                    }
                    $positional[] = $arg;
                }

                $total = $positional[$totalPos] ?? null;
                if ($total === '0' && !$hasFrame) {
                    $line = substr_count($src, "\n", 0, $at) + 1;
                    $violations[] = "Stage::{$method}() at line {$line} renders the indeterminate path without frame:";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

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
