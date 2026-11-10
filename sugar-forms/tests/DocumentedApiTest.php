<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Fleet guard for the prose-column-phantom-API pattern (docs-review 2026-11-03;
 * the sugar-widgets DocumentedApiTest is the sibling precedent, aimed at the
 * site body — here the README's Field-types table was the lying surface):
 * the 2026-11 fix round found Date advertising a `withWidth` that never
 * existed, Color advertising `withValidator` it does not have, Confirm
 * naming `withAffirmative`/`withNegative` where the real API is
 * `withLabels`, and FilePicker listing `withCwd`/`withShowSize`/
 * `withAllowDirs`/`withAllowFiles` while the field exposes
 * `withDirAllowed`/`withFileAllowed`/`withAllowedExtensions`. Every one of
 * those copies an undefined-method Error onto any reader who trusts the
 * table.
 *
 * This guard censuses every row of the Field-types table against
 * method_exists through a fail-closed roster: an unresolvable class column,
 * a stale or collapsed roster, or a knob token that does not exist on the
 * class all redden. Hermetic: reads only this lib's README (skip-guarded in
 * split clones) and reflection — no rendering, no timers.
 */
final class DocumentedApiTest extends TestCase
{
    /**
     * The complete field inventory, in README table order. Adding a field
     * class means adding its table row; dropping a row without dropping the
     * class (or vice versa) reddens the roster arm.
     */
    private const FIELD_ROSTER = [
        'Input',
        'Text',
        'Confirm',
        'Select',
        'MultiSelect',
        'Note',
        'FilePicker',
        'Date',
        'Slider',
        'Color',
        'NumberField',
        'RadioPills',
    ];

    /** Lowercase-initial identifier at the head of a backticked span. */
    private const KNOB_PATTERN = '/^([a-z][A-Za-z0-9_]*)/';

    public function testEveryFieldTableRowResolvesAndEveryKnobExists(): void
    {
        $readme = $this->readme();

        $problems = [];
        foreach ($this->scanTable($readme) as $class => $knobs) {
            $fqcn = 'SugarCraft\\Forms\\Field\\' . $class;
            if (!class_exists($fqcn)) {
                $problems[] = "{$class}: row names no such class";
                continue;
            }
            foreach ($knobs as $knob) {
                if (!method_exists($fqcn, $knob)) {
                    $problems[] = "{$class}::{$knob}()";
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            'every method named in a README Field-types row must exist on that class '
            . '(phantom API in docs is an undefined-method Error for the reader)',
        );
    }

    public function testTheFieldTableRosterIsExactlyTheTwelveDocumentedFields(): void
    {
        $classes = array_keys($this->scanTable($this->readme()));

        $this->assertSame(
            self::FIELD_ROSTER,
            $classes,
            'the README Field-types table and the src/Field inventory are one census: '
            . 'a new or removed field class must move the table (and this roster) in the same commit',
        );
    }

    public function testTheScannerDetectsAPlantedPhantomAndStaysQuietOnRealKnobs(): void
    {
        // Non-vacuity leg: the scanner must actually be able to fail. The
        // planted `withPhantomKnobZzz` row is a stand-in for exactly the
        // Date/withWidth and Color/withValidator shapes this guard exists
        // to catch; `withCharLimit` is Input's real API.
        $fixture = "
### Field types
"
            . "| Field   | Description | Notable knobs |
"
            . "| ------- | ----------- | ------------- |
"
            . "| `Input` | real row    | `withCharLimit`, `withPhantomKnobZzz` |
";

        $rows = $this->scanTable($fixture);
        $this->assertSame(['Input'], array_keys($rows));

        $missing = [];
        foreach ($rows as $class => $knobs) {
            $fqcn = 'SugarCraft\\Forms\\Field\\' . $class;
            foreach ($knobs as $knob) {
                if (!method_exists($fqcn, $knob)) {
                    $missing[] = $knob;
                }
            }
        }

        $this->assertSame(
            ['withPhantomKnobZzz'],
            $missing,
            'the scanner must report the planted phantom and nothing else',
        );
    }

    /**
     * Parse the "### Field types" markdown table into class => knob-name
     * lists. The class is the row's first backticked token; every later
     * backticked span contributes its leading lowercase identifier
     * (uppercase-initial spans like `FilePicker::new()` or `\Closure` prose
     * are call-shape hints, not method rows). Whole-row span extraction is
     * deliberate: it is immune to the unescaped `int|float` pipes that
     * naive cell-splitting would mis-tokenize.
     *
     * @return array<string, list<string>>
     */
    private function scanTable(string $markdown): array
    {
        // Parse the document, not the checkout encoding: with core.autocrlf
        // (Windows CI) the README arrives CRLF, and so do the raw newlines
        // inside the in-memory fixtures below — the LF needle would miss on
        // both legs.
        $markdown = str_replace("\r\n", "\n", $markdown);

        $start = strpos($markdown, "\n### Field types\n");
        if ($start === false) {
            $this->fail('README lost its "### Field types" section');
        }

        $tail = substr($markdown, (int) $start + 1);
        $lines = explode("\n", $tail);

        $rows = [];
        $inTable = false;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (!$inTable) {
                $inTable = str_starts_with($line, '| Field');
                continue;
            }
            if (!str_starts_with($line, '|')) {
                break;
            }
            if (preg_match('/^\|\s*-{3,}/', $line)) {
                continue; // column-rule row
            }

            if (preg_match_all('/`([^`]+)`/', $line, $spans) < 2) {
                continue; // header-adjacent prose row without a class token
            }
            if (!preg_match('/^\|\s*`([A-Za-z0-9_]+)`/', $line, $m)) {
                continue;
            }
            $class = $m[1];

            $knobs = [];
            foreach ($spans[1] as $index => $span) {
                if ($index === 0) {
                    continue; // the class token itself
                }
                if (preg_match(self::KNOB_PATTERN, $span, $k)) {
                    $knobs[] = $k[1];
                }
            }
            $rows[$class] = $knobs;
        }

        $this->assertNotEmpty($rows, 'the Field-types table parsed to zero rows');

        return $rows;
    }

    private function readme(): string
    {
        $path = dirname(__DIR__) . '/README.md';
        if (!is_file($path)) {
            $this->markTestSkipped('lib README not present in this clone');
        }

        return (string) file_get_contents($path);
    }
}
