<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Network;
use SugarCraft\Dash\Grid\NetworkNode;
use SugarCraft\Dash\Grid\NetworkShape;

final class NetworkTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $network = Network::new();
        $this->assertInstanceOf(Network::class, $network);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $network = Network::new();
        $result = $network->setSize(70, 18);
        $this->assertInstanceOf(\SugarCraft\Dash\Grid\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $network = Network::new()->setSize(70, 18);
        $rendered = $network->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $network = Network::new()->setSize(70, 18);
        $rendered = $network->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithNode(): void
    {
        $network = Network::new();
        $node = new NetworkNode('1', 'Node 1', NetworkShape::Circle);
        $result = $network->withNode($node);
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testAddNode(): void
    {
        $network = Network::new();
        $result = $network->addNode('1', 'Server', NetworkShape::Square);
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithNodes(): void
    {
        $network = Network::new();
        $nodes = [
            '1' => new NetworkNode('1', 'Node 1', NetworkShape::Circle),
            '2' => new NetworkNode('2', 'Node 2', NetworkShape::Square),
        ];
        $result = $network->withNodes($nodes);
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithEdge(): void
    {
        $network = Network::new();
        $result = $network->withEdge(['from' => '1', 'to' => '2', 'label' => null]);
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testAddEdge(): void
    {
        $network = Network::new();
        $result = $network->addEdge('1', '2', 'connection');
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithEdges(): void
    {
        $network = Network::new();
        $edges = [
            ['from' => '1', 'to' => '2', 'label' => null],
            ['from' => '2', 'to' => '3', 'label' => 'data'],
        ];
        $result = $network->withEdges($edges);
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithShowLabels(): void
    {
        $network = Network::new();
        $result = $network->withShowLabels(false);
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithShowWeights(): void
    {
        $network = Network::new();
        $result = $network->withShowWeights(true);
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithDirected(): void
    {
        $network = Network::new();
        $result = $network->withDirected(false);
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithLayoutAlgorithm(): void
    {
        $network = Network::new();
        $result = $network->withLayoutAlgorithm('circular');
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testNetworkNodeWithConnection(): void
    {
        $node = new NetworkNode('1', 'Node 1', NetworkShape::Circle);
        $nodeWithConnection = $node->withConnection('2');
        $this->assertContains('2', $nodeWithConnection->connections);
    }

    public function testNetworkNodeShapes(): void
    {
        $circle = new NetworkNode('1', 'Circle', NetworkShape::Circle);
        $this->assertEquals(NetworkShape::Circle, $circle->shape);

        $square = new NetworkNode('2', 'Square', NetworkShape::Square);
        $this->assertEquals(NetworkShape::Square, $square->shape);

        $diamond = new NetworkNode('3', 'Diamond', NetworkShape::Diamond);
        $this->assertEquals(NetworkShape::Diamond, $diamond->shape);

        $hexagon = new NetworkNode('4', 'Hexagon', NetworkShape::Hexagon);
        $this->assertEquals(NetworkShape::Hexagon, $hexagon->shape);

        $star = new NetworkNode('5', 'Star', NetworkShape::Star);
        $this->assertEquals(NetworkShape::Star, $star->shape);
    }

    public function testGetInnerSize(): void
    {
        $network = Network::new()->setSize(70, 18);
        $size = $network->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(70, $size[0]);
        $this->assertEquals(18, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $network = Network::new()->setSize(10, 5);
        $rendered = $network->render();
        $this->assertSame('', $rendered);
    }

    public function testWithStyle(): void
    {
        $network = Network::new();
        $result = $network->withStyle('bold');
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithNodeColor(): void
    {
        $network = Network::new();
        $result = $network->withNodeColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithEdgeColor(): void
    {
        $network = Network::new();
        $result = $network->withEdgeColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(Network::class, $result);
    }

    public function testWithLabelColor(): void
    {
        $network = Network::new();
        $result = $network->withLabelColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(Network::class, $result);
    }
}
