<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\AnsiParser\Parser\CsiHandler;
use SugarCraft\AnsiParser\Support\RecordingCsiHandler;

/**
 * Pins the shipped recording double (finding sugar-ansi-parser-006): the
 * DebugHandler log vocabulary, the configured-grid answers, the filter
 * helper, and the maxRows cap whose drops are signalable through the
 * public $dropped counter.
 */
final class RecordingCsiHandlerTest extends TestCase
{
    public function testRecordsTypedRowsInDebugHandlerVocabulary(): void
    {
        $rec = new RecordingCsiHandler();

        $rec->printable('x');
        $rec->cuu(3);
        $rec->cup(2, 10);
        $rec->decset(1000, 63);
        $rec->cr();

        $this->assertSame([
            ['type' => 'printable', 'detail' => 'x'],
            ['type' => 'cuu', 'detail' => 3],
            ['type' => 'cup', 'detail' => ['row' => 2, 'col' => 10]],
            ['type' => 'decset', 'detail' => ['mode' => 1000, 'prefix' => 63]],
            ['type' => 'cr', 'detail' => null],
        ], $rec->log);
    }

    public function testGridQueriesAnswerConfiguredGeometryAndAreRecorded(): void
    {
        // Defaults mirror the 25x80 dev grid every consumer double assumed;
        // a spy records its own queries too — "which geometry the adapter
        // asked for" is part of the observable truth.
        $default = new RecordingCsiHandler();
        $this->assertSame(25, $default->gridRows());
        $this->assertSame(80, $default->gridCols());

        $custom = new RecordingCsiHandler(10, 40);
        $this->assertSame(10, $custom->gridRows());
        $this->assertSame(40, $custom->gridCols());
        $this->assertSame([
            ['type' => 'gridRows', 'detail' => null],
            ['type' => 'gridCols', 'detail' => null],
        ], $custom->log);
    }

    public function testFilterReturnsOnlyMatchingRows(): void
    {
        $rec = new RecordingCsiHandler();
        $rec->cuu(3);
        $rec->cud(1);
        $rec->cuu(5);

        $this->assertSame([
            ['type' => 'cuu', 'detail' => 3],
            ['type' => 'cuu', 'detail' => 5],
        ], $rec->filter('cuu'));
        $this->assertSame([], $rec->filter('rep'));
    }

    public function testCapKeepsTheMostRecentRowsAndCountsEveryDrop(): void
    {
        $rec = new RecordingCsiHandler(maxRows: 3);
        foreach ([1, 2, 3, 4, 5] as $count) {
            $rec->cuu($count);
        }

        $this->assertSame([3, 4, 5], array_column($rec->log, 'detail'));
        // maxRows=3 trims as it goes: 5 rows arrived, 3 remain, 2 dropped.
        $this->assertSame(3, count($rec->log));
        $this->assertSame(2, $rec->dropped);

        $rec->resetLog();
        $this->assertSame([], $rec->log);
        $this->assertSame(0, $rec->dropped);
    }

    public function testRosterCoversEveryInterfaceMethod(): void
    {
        // The compile-time guarantee (final class implements CsiHandler)
        // breaks THIS roster pin too, so a future interface addition forces
        // a deliberate update here rather than silent default-return drift.
        $interface = array_map(
            static fn (object $m): string => $m->getName(),
            (new ReflectionClass(CsiHandler::class))->getMethods()
        );
        sort($interface);

        $expected = [
            'cbt', 'cht', 'cr', 'cub', 'cud', 'cuf', 'cup', 'cuu', 'dch',
            'decrst', 'decset', 'decstbm', 'dl', 'ed', 'el', 'gridCols', 'gridRows',
            'hvp', 'ich', 'il', 'lf', 'printable', 'rep', 'scorc', 'scosc', 'sd',
            'sgr', 'su', 'tbc',
        ];
        $this->assertSame($expected, $interface);

        $classMethods = array_map(
            static fn (object $m): string => $m->getName(),
            (new ReflectionClass(RecordingCsiHandler::class))->getMethods()
        );
        foreach ($interface as $method) {
            $this->assertContains($method, $classMethods, "recorder must define {$method}");
        }
    }
}
