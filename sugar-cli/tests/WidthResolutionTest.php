<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Cli\AutoWidth;
use SugarCraft\Cli\HelpText;
use SugarCraft\Cli\Internal\WidthProbe;
use SugarCraft\Cli\Section;
use SugarCraft\Cli\Tests\Support\SandboxTerminalColumns;
use SugarCraft\Cli\Theme;

/**
 * The width-resolution precedence the presenters promised after the
 * kit#3 TODO was paid down: an explicit caller width wins, `null` keeps
 * each presenter's unbounded meaning, otherwise the exported COLUMNS is
 * used, and 80 remains the last-resort fallback. The probe itself is
 * pinned here too — it must never throw on junk and never touch a device.
 */
final class WidthResolutionTest extends TestCase
{
    use SandboxTerminalColumns;

    protected function tearDown(): void
    {
        $this->restoreTerminalColumns();
    }

    // ------------------------------------------------------------------
    // Probe
    // ------------------------------------------------------------------

    public function testProbeAnswersNullWhenNothingIsAdvertised(): void
    {
        $this->sandboxTerminalColumns();
        $this->assertNull(WidthProbe::columns());
        $this->assertSame(80, WidthProbe::resolve(AutoWidth::Auto));
    }

    public function testProbeReadsExportedColumns(): void
    {
        $this->pinTerminalColumns(120);
        $this->assertSame(120, WidthProbe::columns());
        $this->assertSame(120, WidthProbe::resolve(AutoWidth::Auto));
    }

    /** A numeric string on the environment parses like the int it stands for. */
    public function testProbeAcceptsNumericStringValues(): void
    {
        $this->pinTerminalColumns('97');
        $this->assertSame(97, WidthProbe::columns());
    }

    /** Junk, zero, and negatives are "unknown", not an error and not a width. */
    public function testProbeFailsSoftOnUnusableValues(): void
    {
        foreach (['', '0', '-5', 'abc', '80x', '1e3', '  ', '٣', '999999999', '10001'] as $junk) {
            $this->pinTerminalColumns($junk);
            $this->assertNull(WidthProbe::columns(), "junk value '$junk' must not resolve");
            $this->assertSame(80, WidthProbe::resolve(AutoWidth::Auto));
        }
    }

    /**
     * The credibility cap: an absurd advertised width would otherwise drive
     * Section's filler str_repeat() past the memory limit (env-to-crash), so
     * values above MAX_CREDIBLE fall back like any other junk — while the
     * boundary itself still resolves, and an EXPLICIT huge int from a caller
     * (tier 1) is never capped.
     */
    public function testAdvertisedWidthIsCappedAtTheCredibilityBound(): void
    {
        $this->pinTerminalColumns(WidthProbe::MAX_CREDIBLE);
        $this->assertSame(WidthProbe::MAX_CREDIBLE, WidthProbe::columns());
        $this->pinTerminalColumns(WidthProbe::MAX_CREDIBLE + 1);
        $this->assertNull(WidthProbe::columns());
        $this->assertSame(80, WidthProbe::resolve(AutoWidth::Auto));

        // Tier 1 bypasses the cap: the probe does not second-guess callers.
        $this->assertSame(50_000, WidthProbe::resolve(50_000));
    }

    /** Explicit ints and nulls pass through the resolver untouched. */
    public function testResolvePassesThroughCallerValues(): void
    {
        $this->pinTerminalColumns(120);
        $this->assertSame(40, WidthProbe::resolve(40));
        $this->assertNull(WidthProbe::resolve(null));
    }

    /**
     * Finding 041: the probe's read ladder is getenv → $_ENV → $_SERVER,
     * but every other test drives it through the sandbox trait, which pins
     * all three sources in lockstep — a regression in either superglobal
     * fallback would hide there. These arms light exactly one source at a
     * time (verified locally by commenting out each ladder rung in
     * WidthProbe::columns(); the matching test goes red, then green again).
     */
    public function testColumnsResolvesFromServerSuperglobalAlone(): void
    {
        $this->snapshotTerminalColumns();
        putenv('COLUMNS');                    // getenv: absent
        unset($_ENV['COLUMNS']);              // $_ENV: absent
        $_SERVER['COLUMNS'] = '64';           // only $_SERVER is alive

        $this->assertSame(64, WidthProbe::columns());
        $this->assertSame(64, WidthProbe::resolve(AutoWidth::Auto));
    }

    public function testColumnsResolvesFromEnvSuperglobalAlone(): void
    {
        $this->snapshotTerminalColumns();
        putenv('COLUMNS');
        $_ENV['COLUMNS'] = '72';              // only $_ENV is alive
        unset($_SERVER['COLUMNS']);

        $this->assertSame(72, WidthProbe::columns());
    }

    /** The ladder order is itself a contract: the exported value wins. */
    public function testGetenvOutranksTheSuperglobals(): void
    {
        $this->snapshotTerminalColumns();
        putenv('COLUMNS=55');
        $_ENV['COLUMNS']    = '72';
        $_SERVER['COLUMNS'] = '64';

        $this->assertSame(55, WidthProbe::columns());
    }

    // ------------------------------------------------------------------
    // Precedence across the presenters
    // ------------------------------------------------------------------

    /** Tier 1: an explicit width beats an advertised terminal width. */
    public function testExplicitWidthWinsOverColumns(): void
    {
        $this->pinTerminalColumns(120);
        $this->assertSame(40, Width::string(Section::rule(Theme::plain(), 40)));
        $this->assertSame(40, Width::string(Section::header('S', Theme::plain(), 2, 40)));
    }

    /** Tier 3: an omitted width follows the advertised terminal width. */
    public function testOmittedWidthFollowsColumns(): void
    {
        $this->pinTerminalColumns(120);
        $this->assertSame(120, Width::string(Section::rule(Theme::plain())));
        $this->assertSame(120, Width::string(Section::header('S', Theme::plain())));
        $this->assertSame(120, Width::string(Section::subHeader('S', Theme::plain())));
    }

    /** Passing AutoWidth::Auto explicitly is the same as omitting the argument. */
    public function testAutoSentinelEqualsOmission(): void
    {
        $this->pinTerminalColumns(132);
        $this->assertSame(
            Section::rule(Theme::plain()),
            Section::rule(Theme::plain(), AutoWidth::Auto),
        );
        $this->assertSame(
            HelpText::renderRows(['-x' => 'y'], Theme::plain()),
            HelpText::renderRows(['-x' => 'y'], Theme::plain(), AutoWidth::Auto),
        );
    }

    /** Tier 4: with nothing advertised the historical 80-cell fallback holds. */
    public function testFallbackStandsWhenColumnsUnset(): void
    {
        $this->sandboxTerminalColumns();
        $this->assertSame(80, Width::string(Section::rule(Theme::plain())));
        $this->assertSame(80, Width::string(Section::header('S', Theme::plain())));
    }

    /**
     * `null` is the caller's opt-out, not a request to resolve: a null-width
     * Section rule stays the unbounded two-rune minimum even on a wide
     * terminal, and null-width HelpText rows never wrap.
     */
    public function testNullWidthStillMeansUnboundedUnderWideColumns(): void
    {
        $this->pinTerminalColumns(200);
        $this->assertSame(2, Width::string(Section::rule(Theme::plain(), null)));
        $long = trim(str_repeat('word ', 100));
        $out = HelpText::renderRows(['-x' => $long], Theme::plain(), null);
        $this->assertSame('  -x  ' . $long, $out);
    }

    /** An advertised width below one cell is junk (fallback), while an
     *  explicitly passed 0 still throws — the guard is not bypassed. */
    public function testExplicitZeroStillThrowsDespiteAdvertisedWidth(): void
    {
        $this->pinTerminalColumns(120);
        $this->expectException(\InvalidArgumentException::class);
        Section::rule(Theme::plain(), 0);
    }

    /**
     * Finding 042: an advertised narrow width must flow into the FULL page
     * renderer, not just the probe: every emitted row stays within the cap,
     * over-long rows re-flow (wrap) instead of clipping, `AutoWidth::Auto`
     * equals the omitted argument byte-for-byte, and `null` still opts out
     * of resolution even under a tight terminal.
     */
    public function testRenderedHelpPageRespectsAdvertisedNarrowColumns(): void
    {
        $this->pinTerminalColumns(30);

        $sections = ['flags' => [
            '--enable-verbose-logging' => 'turn on verbose diagnostic output for everything that runs',
            '-v'                       => 'short form of the verbose flag',
        ]];
        $build = function (array $extra = []) use ($sections): string {
            return HelpText::render(...[
                'usage'    => 'myapp [flags] <file>',
                'sections' => $sections,
                'theme'    => Theme::plain(),
            ] + $extra);
        };

        $lines = explode("\n", $omitted = $build());
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(
                30,
                Width::string($line),
                'no row may exceed the advertised width: ' . $line,
            );
        }
        $this->assertGreaterThan(5, count($lines), 'over-long rows must wrap under the 30-cell cap');

        $this->assertSame($omitted, $build(['width' => AutoWidth::Auto]), 'Auto is the same resolution as omission');

        // null is the caller's opt-out: the 30-cell terminal is ignored and
        // at least one row runs wider than the cap would have allowed.
        $unbounded = explode("\n", $build(['width' => null]));
        $widest    = max(array_map(static fn (string $l): int => Width::string($l), $unbounded));
        $this->assertGreaterThan(30, $widest, 'null width must stay unbounded under a narrow advertised cap');
    }

    // ------------------------------------------------------------------
    // The motivating 81-cell help line
    // ------------------------------------------------------------------

    /**
     * candy-code's longest `cli.help.screen` row measures 81 cells; on an
     * 82-column terminal that line must survive an omitted width intact —
     * at the old hard 80 default it wrapped, and narrow terminals must
     * still wrap rather than clip it.
     */
    public function testEightyOneCellHelpRowFitsAdvertisedEightyTwoColumns(): void
    {
        // '  ' + key + '  ' + description: a 6-cell key and a 71-cell
        // description land the row at exactly the motivating 81 cells.
        $description = trim(str_repeat('ab ', 24));  // 24 words → 71 cells
        $rows = ['--flag' => $description];
        $rowWidth = 2 + Width::string('--flag') + 2 + Width::string($description);
        self::assertSame(81, $rowWidth, 'fixture must measure the motivating 81 cells');

        $this->pinTerminalColumns(82);
        $out = HelpText::renderRows($rows, Theme::plain());
        $lines = explode("\n", $out);
        $this->assertCount(1, $lines, 'the 81-cell row must not wrap at 82 columns');
        $this->assertSame(81, Width::string($lines[0]));

        // The same row on the silent fallback still wraps instead of clipping.
        $this->sandboxTerminalColumns();
        $wrapped = explode("\n", HelpText::renderRows($rows, Theme::plain()));
        $this->assertGreaterThan(1, count($wrapped));
        foreach ($wrapped as $line) {
            $this->assertLessThanOrEqual(80, Width::string($line));
        }
        $this->assertSame(
            preg_replace('/\s+/', '', '--flag' . $description),
            preg_replace('/\s+/', '', implode('', $wrapped)),
            'wrapping must re-flow the row, never lose a cell of it',
        );
    }
}
