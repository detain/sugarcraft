<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Kit\AutoWidth;
use SugarCraft\Kit\HelpText;
use SugarCraft\Kit\Internal\WidthProbe;
use SugarCraft\Kit\Section;
use SugarCraft\Kit\Tests\Support\SandboxTerminalColumns;
use SugarCraft\Kit\Theme;

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
        foreach (['', '0', '-5', 'abc', '80x', '1e3', '  ', '٣'] as $junk) {
            $this->pinTerminalColumns($junk);
            $this->assertNull(WidthProbe::columns(), "junk value '$junk' must not resolve");
            $this->assertSame(80, WidthProbe::resolve(AutoWidth::Auto));
        }
    }

    /** Explicit ints and nulls pass through the resolver untouched. */
    public function testResolvePassesThroughCallerValues(): void
    {
        $this->pinTerminalColumns(120);
        $this->assertSame(40, WidthProbe::resolve(40));
        $this->assertNull(WidthProbe::resolve(null));
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

    // ------------------------------------------------------------------
    // The motivating 81-cell help line
    // ------------------------------------------------------------------

    /**
     * sugar-crush's longest `cli.help.screen` row measures 81 cells; on an
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
