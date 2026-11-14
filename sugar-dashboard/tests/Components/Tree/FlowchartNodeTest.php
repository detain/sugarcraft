<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\FlowchartNode;
use SugarCraft\Dashboard\Components\Tree\FlowchartNodeType;

/**
 * D-143: flow-edge carrier — clone-append nextIds, the Process default
 * type, and the graphviz-shape vocabulary the renderer branches on.
 */
final class FlowchartNodeTest extends TestCase
{
    public function testDefaultTypeIsProcessWithNoSuccessors(): void
    {
        $node = new FlowchartNode('s1', 'Step');

        self::assertSame(FlowchartNodeType::Process, $node->type);
        self::assertSame([], $node->nextIds);
        self::assertNull($node->color);
    }

    public function testWithNextAppendsInOrderWithoutTouchingTheSource(): void
    {
        $node = (new FlowchartNode('s1', 'Step'))
            ->withNext('a')
            ->withNext('b');

        self::assertSame(['a', 'b'], $node->nextIds);
        self::assertSame('s1', $node->id);
        self::assertSame(FlowchartNodeType::Process, $node->type);
    }

    public function testDecisionTypeSurvivesTheAppendDoor(): void
    {
        $gate = new FlowchartNode('g', 'Gate', FlowchartNodeType::Decision);

        $grown = $gate->withNext('yes')->withNext('no');

        self::assertSame(FlowchartNodeType::Decision, $grown->type);
        self::assertSame(['yes', 'no'], $grown->nextIds);
    }

    public function testFlowchartNodeTypeWireRoster(): void
    {
        self::assertSame(
            ['Process', 'Decision', 'StartEnd', 'InputOutput', 'Connector', 'Data'],
            array_map(static fn(FlowchartNodeType $t): string => $t->name, FlowchartNodeType::cases()),
        );
        self::assertSame(
            ['process', 'decision', 'startend', 'inputoutput', 'connector', 'data'],
            array_map(static fn(FlowchartNodeType $t): string => $t->value, FlowchartNodeType::cases()),
        );
    }
}
