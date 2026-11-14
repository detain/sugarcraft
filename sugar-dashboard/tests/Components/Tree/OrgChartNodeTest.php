<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Components\Tree\OrgChartNode;

/**
 * D-143: withReportByName is the one constructor-shaped door in the VO —
 * it synthesises the child node AND inherits the parent's color into it.
 * Both halves are real behavior with no direct pin before this file.
 */
final class OrgChartNodeTest extends TestCase
{
    public function testWithReportAppendsToACloneAndLeavesTheSourceClean(): void
    {
        $boss = new OrgChartNode('Ada', 'CTO');
        $engineer = new OrgChartNode('Bob', 'SWE');

        $withOne = $boss->withReport($engineer);

        self::assertSame([$engineer], $withOne->reports);
        self::assertSame([], $boss->reports, 'the source node must stay childless');

        // Append order preserved through a second, chained door.
        $designer = new OrgChartNode('Cy', 'Design');
        $withTwo = $withOne->withReport($designer);
        self::assertSame([$engineer, $designer], $withTwo->reports);
        self::assertCount(1, $withOne->reports, 'the intermediate clone must not grow either');
    }

    public function testWithReportByNameConstructsCarryingSlotsAndParentColor(): void
    {
        $color = Color::hex('#89B4FA');
        $boss = new OrgChartNode('Ada', 'CTO', 'Engineering', $color);

        $withDirect = $boss->withReportByName('Bob', 'SWE', 'Platform');

        $report = $withDirect->reports[0];
        self::assertInstanceOf(OrgChartNode::class, $report);
        self::assertSame('Bob', $report->name);
        self::assertSame('SWE', $report->title);
        self::assertSame('Platform', $report->department);
        // Color inheritance: reports painted in the parent's branch color.
        self::assertSame($color, $report->color);
        self::assertNull($report->avatar);
    }

    public function testWithReportByNameDefaultsAreAllNull(): void
    {
        $boss = new OrgChartNode('Ada');

        $report = $boss->withReportByName('Bob')->reports[0];

        self::assertNull($report->title);
        self::assertNull($report->department);
        self::assertNull($report->color, 'a colorless parent inherits nothing');
    }
}
