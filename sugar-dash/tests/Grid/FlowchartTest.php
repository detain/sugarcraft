<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Flowchart;
use SugarCraft\Dash\Grid\FlowchartNode;
use SugarCraft\Dash\Grid\FlowchartNodeType;

final class FlowchartTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $flowchart = Flowchart::new();
        $this->assertInstanceOf(Flowchart::class, $flowchart);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->setSize(65, 18);
        $this->assertInstanceOf(\SugarCraft\Dash\Grid\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $flowchart = Flowchart::new()->setSize(65, 18);
        $rendered = $flowchart->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $flowchart = Flowchart::new()->setSize(65, 18);
        $rendered = $flowchart->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithNode(): void
    {
        $flowchart = Flowchart::new();
        $node = FlowchartNode::process('1', 'Start Process');
        $result = $flowchart->withNode($node);
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testAddNode(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->addNode('1', 'Process', FlowchartNodeType::Process);
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithNodes(): void
    {
        $flowchart = Flowchart::new();
        $nodes = [
            '1' => FlowchartNode::process('1', 'Start'),
            '2' => FlowchartNode::decision('2', 'Is Valid?'),
        ];
        $result = $flowchart->withNodes($nodes);
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithConnection(): void
    {
        $flowchart = Flowchart::new();
        $flowchart = $flowchart->addNode('1', 'Start', FlowchartNodeType::StartEnd);
        $flowchart = $flowchart->addNode('2', 'Process', FlowchartNodeType::Process);
        $result = $flowchart->withConnection('1', '2');
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithStartNode(): void
    {
        $flowchart = Flowchart::new();
        $flowchart = $flowchart->addNode('start', 'Start', FlowchartNodeType::StartEnd);
        $result = $flowchart->withStartNode('start');
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithShowArrows(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withShowArrows(false);
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithShowLabels(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withShowLabels(false);
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithFlowDirection(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withFlowDirection('left-right');
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testFlowchartNodeHelpers(): void
    {
        $process = FlowchartNode::process('1', 'Process');
        $this->assertEquals(FlowchartNodeType::Process, $process->type);
        $this->assertEquals('Process', $process->label);

        $decision = FlowchartNode::decision('2', 'Decision?');
        $this->assertEquals(FlowchartNodeType::Decision, $decision->type);

        $startEnd = FlowchartNode::startEnd('3', 'Start');
        $this->assertEquals(FlowchartNodeType::StartEnd, $startEnd->type);

        $inputOutput = FlowchartNode::inputOutput('4', 'Input');
        $this->assertEquals(FlowchartNodeType::InputOutput, $inputOutput->type);
    }

    public function testFlowchartNodeWithNext(): void
    {
        $node = FlowchartNode::process('1', 'Step 1');
        $nodeWithNext = $node->withNext('2');
        $this->assertContains('2', $nodeWithNext->nextIds);
    }

    public function testGetInnerSize(): void
    {
        $flowchart = Flowchart::new()->setSize(65, 18);
        $size = $flowchart->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(65, $size[0]);
        $this->assertEquals(18, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $flowchart = Flowchart::new()->setSize(10, 5);
        $rendered = $flowchart->render();
        $this->assertSame('', $rendered);
    }

    public function testWithStyle(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withStyle('bold');
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithProcessColor(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withProcessColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithDecisionColor(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withDecisionColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithStartEndColor(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withStartEndColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithIoColor(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withIoColor(\SugarCraft\Core\Util\Color::hex('#FFFF00'));
        $this->assertInstanceOf(Flowchart::class, $result);
    }

    public function testWithArrowColor(): void
    {
        $flowchart = Flowchart::new();
        $result = $flowchart->withArrowColor(\SugarCraft\Core\Util\Color::hex('#FF00FF'));
        $this->assertInstanceOf(Flowchart::class, $result);
    }
}
