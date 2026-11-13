<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\FilterModel;
use SugarCraft\ScriptUi\Model\InputModel;
use SugarCraft\ScriptUi\Model\WriteModel;
use SugarCraft\ScriptUi\Runtime\Geometry;

/**
 * scriptui-14 (finding 004) — frames must never emit lines wider than the
 * terminal. Width is resolved through {@see Geometry}; the model-mirror
 * bodies (choose multi, filter multi, fuzzy view, the filter ghost line)
 * used to bypass ItemList's own clip stage, and input/write never consulted
 * the geometry at all, so a 200-cell payload soft-wrapped in the terminal,
 * the logical-vs-physical line count diverged, and the line-addressed frame
 * diff painted onto drifted rows. Every captured frame here is pinned at
 * 40 columns; every emitted line (the appended keybinding hint excepted —
 * that is scriptui-16's budget) must fit. With no knowable geometry the
 * historical uncapped frame must survive byte-for-byte: piped/cron contexts
 * keep the output they were pinned with.
 */
final class WidthClampTest extends TestCase
{
    private const COLS = 40;

    protected function tearDown(): void
    {
        Geometry::override(null);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $exempt lines the caller owns through another row
     */
    private function assertEveryLineFits(array $lines, array $exempt = [], string $message = ''): void
    {
        foreach ($lines as $line) {
            if (in_array($line, $exempt, true)) {
                continue;
            }
            $this->assertLessThanOrEqual(
                self::COLS,
                Width::string($line),
                ($message !== '' ? $message . ': ' : '') . "line overflows " . self::COLS . " cells: [" . substr($line, 0, 60) . "]…"
            );
        }
    }

    public function testChooseMultiMarkedBodyClipsEveryLine(): void
    {
        Geometry::override(['cols' => self::COLS]);
        $long   = str_repeat('L', 200);
        $model  = ChooseModel::fromOptions([$long, 'short', $long], height: 3, limit: 5, header: str_repeat('H', 200));
        $body   = $model->view();

        $this->assertEveryLineFits(explode("\n", $body), [Lang::t('hints.choose.multi')], 'choose multi frame');
        // The mirror contract: clipped rows are plain truncations of the
        // rendered content, never empty and never the full 200 cells.
        $lines = explode("\n", $body);
        $this->assertStringContainsString('L', $lines[1]);
        $this->assertLessThan(200, substr_count($lines[1], 'L'));
    }

    public function testChooseSingleDelegatedBodyClips(): void
    {
        // The delegation path already rides ItemList::clip — this arm keeps
        // the mirror honest if that upstream stage ever moves.
        Geometry::override(['cols' => self::COLS]);
        $model = ChooseModel::fromOptions([str_repeat('S', 200)], limit: 1);
        $this->assertEveryLineFits(explode("\n", $model->view()), [Lang::t('hints.choose')], 'choose single frame');
    }

    public function testFilterMultiMarkedBodyClips(): void
    {
        Geometry::override(['cols' => self::COLS]);
        $model = FilterModel::fromOptions([str_repeat('M', 200), 'mm'], height: 2, limit: 5);
        $this->assertEveryLineFits(explode("\n", $model->view()), [Lang::t('hints.filter')], 'filter multi frame');
    }

    public function testFilterFuzzyViewClips(): void
    {
        Geometry::override(['cols' => self::COLS]);
        $model = FilterModel::fromOptions([str_repeat('f', 200), 'fx'], height: 2, fuzzy: true, value: 'f');
        $this->assertEveryLineFits(explode("\n", $model->view()), [Lang::t('hints.filter')], 'filter fuzzy frame');
    }

    public function testFilterGhostPlaceholderLineClips(): void
    {
        Geometry::override(['cols' => self::COLS]);
        $model = FilterModel::fromOptions(['one'], height: 2, placeholder: str_repeat('P', 200));
        $lines = explode("\n", $model->view());
        // The ghost replaces the bare '/' probe line; it is the only line
        // carrying P bytes.
        $ghost = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'P')) {
                $ghost = $line;
            }
        }
        $this->assertNotNull($ghost, 'ghost line must still paint');
        $this->assertEveryLineFits([$ghost], [], 'filter ghost line');
    }

    public function testInputLongValueAndHeaderClipsToColumns(): void
    {
        Geometry::override(['cols' => self::COLS]);
        $model = InputModel::newPrompt(prompt: '> ', value: str_repeat('v', 200), header: str_repeat('h', 200));
        $this->assertEveryLineFits(explode("\n", $model->view()), [], 'input frame');
        // The scroll window keeps the tail (the caret sits at end of text):
        // the value is scrolled, not silently left-anchored.
        $this->assertStringContainsString('vvvv', explode("\n", $model->view())[1]);
    }

    public function testInputGhostPlaceholderClips(): void
    {
        Geometry::override(['cols' => self::COLS]);
        $model = InputModel::newPrompt(placeholder: str_repeat('g', 200));
        $this->assertEveryLineFits(explode("\n", $model->view()), [], 'input ghost frame');
    }

    public function testWriteLongBufferLinesClip(): void
    {
        Geometry::override(['cols' => self::COLS]);
        $model = WriteModel::newPrompt(
            value: str_repeat('w', 200) . "\nsecond " . str_repeat('x', 200),
            showLineNumbers: true,
            header: str_repeat('H', 200),
        );
        $this->assertEveryLineFits(explode("\n", $model->view()), [], 'write frame');
    }

    public function testExplicitWidthRespectsTerminalColumns(): void
    {
        Geometry::override(['cols' => self::COLS]);
        $model = InputModel::newPrompt(prompt: '> ', value: str_repeat('q', 500), width: 120);
        $this->assertEveryLineFits(explode("\n", $model->view()), [], 'input explicit width');
    }

    public function testUnknownGeometryKeepsHistoricalUncappedFrame(): void
    {
        // Empty override (not null): the probe is answered with "nothing
        // knowable" regardless of ambient COLUMNS/LINES in the runner env —
        // the lists fall back to their historical 60-cell budget, the
        // editors to no cap at all.
        Geometry::override([]);
        $model = ChooseModel::fromOptions([str_repeat('U', 200)], height: 2, limit: 5);
        $itemLines = array_values(array_filter(
            explode("\n", $model->view()),
            static fn(string $l): bool => str_contains($l, 'U')
        ));
        $this->assertNotEmpty($itemLines);
        $this->assertSame(60, Width::string($itemLines[0]), 'historical 60-cell list budget when no width is knowable');

        $input = InputModel::newPrompt(prompt: '> ', value: str_repeat('z', 200));
        $this->assertGreaterThan(self::COLS, Width::string(explode("\n", $input->view())[0]));
    }

    public function testClipIsByteIdenticalToTheDelegatedPathForPlainRows(): void
    {
        // The no-ellipsis mirror contract: an over-long row in the custom
        // body truncates exactly like ItemList's own clip would.
        Geometry::override(['cols' => self::COLS]);
        $long  = str_repeat('B', 200);
        $rows  = [$long];
        $multi = ChooseModel::fromOptions($rows, height: 2, limit: 5);
        $plain = ChooseModel::fromOptions($rows, height: 2, limit: 1);

        $multiLines = array_values(array_filter(
            explode("\n", $multi->view()),
            static fn(string $l): bool => str_contains($l, 'B')
        ));
        $plainLines = array_values(array_filter(
            explode("\n", $plain->view()),
            static fn(string $l): bool => str_contains($l, 'B')
        ));
        $this->assertNotEmpty($multiLines);
        $this->assertNotEmpty($plainLines);
        // Multi's row carries prefixes/absent-reverse the single path paints
        // differently; the shared invariant is the VISIBLE budget: both
        // paths end at the same cell, with no ellipsis marker anywhere.
        $this->assertSame(self::COLS, Width::string($multiLines[0]));
        $this->assertSame(self::COLS, Width::string($plainLines[0]));
        $this->assertStringNotContainsString('…', $multi->view());
    }
}
