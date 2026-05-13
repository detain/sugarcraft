<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\State;
use SugarCraft\Dash\Grid\StateNode;
use SugarCraft\Dash\Grid\StateTransition;
use SugarCraft\Dash\Grid\TransitionType;

final class StateTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $state = State::new();
        $this->assertInstanceOf(State::class, $state);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $state = State::new();
        $result = $state->setSize(60, 20);
        $this->assertInstanceOf(\SugarCraft\Dash\Grid\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $state = State::new()->setSize(60, 20);
        $rendered = $state->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $state = State::new()->setSize(60, 20);
        $rendered = $state->render();
        $this->assertStringContainsString('─', $rendered);
    }

    public function testWithState(): void
    {
        $state = State::new();
        $node = StateNode::state('idle', 'Idle');
        $result = $state->withState($node);
        $this->assertInstanceOf(State::class, $result);
    }

    public function testAddState(): void
    {
        $state = State::new();
        $result = $state->addState('active', 'Active');
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithStates(): void
    {
        $state = State::new();
        $states = [
            'idle' => StateNode::state('idle', 'Idle'),
            'active' => StateNode::state('active', 'Active'),
        ];
        $result = $state->withStates($states);
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithTransition(): void
    {
        $state = State::new();
        $transition = new StateTransition('t1', 'idle', 'active', 'start');
        $result = $state->withTransition($transition);
        $this->assertInstanceOf(State::class, $result);
    }

    public function testAddTransition(): void
    {
        $state = State::new();
        $result = $state->addTransition('idle', 'active', 'start');
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithTransitions(): void
    {
        $state = State::new();
        $transitions = [
            new StateTransition('t1', 'idle', 'active', 'start'),
            new StateTransition('t2', 'active', 'idle', 'stop'),
        ];
        $result = $state->withTransitions($transitions);
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithInitialState(): void
    {
        $state = State::new();
        $result = $state->withInitialState('idle');
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithShowActions(): void
    {
        $state = State::new();
        $result = $state->withShowActions(false);
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithShowLabels(): void
    {
        $state = State::new();
        $result = $state->withShowLabels(false);
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithStyle(): void
    {
        $state = State::new();
        $result = $state->withStyle('bold');
        $this->assertInstanceOf(State::class, $result);
    }

    public function testGetInnerSize(): void
    {
        $state = State::new()->setSize(60, 20);
        $size = $state->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(60, $size[0]);
        $this->assertEquals(20, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $state = State::new()->setSize(10, 5);
        $rendered = $state->render();
        $this->assertSame('', $rendered);
    }

    public function testWithStateColor(): void
    {
        $state = State::new();
        $result = $state->withStateColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithInitialColor(): void
    {
        $state = State::new();
        $result = $state->withInitialColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithFinalColor(): void
    {
        $state = State::new();
        $result = $state->withFinalColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(State::class, $result);
    }

    public function testWithTransitionColor(): void
    {
        $state = State::new();
        $result = $state->withTransitionColor(\SugarCraft\Core\Util\Color::hex('#FFFF00'));
        $this->assertInstanceOf(State::class, $result);
    }

    public function testStateNodeHelpers(): void
    {
        $initial = StateNode::initial('s1', 'Start');
        $this->assertTrue($initial->isInitial);
        $this->assertFalse($initial->isFinal);
        $this->assertEquals('Start', $initial->label);

        $final = StateNode::final('s2', 'End');
        $this->assertFalse($final->isInitial);
        $this->assertTrue($final->isFinal);
        $this->assertEquals('End', $final->label);

        $normal = StateNode::state('s3', 'Running');
        $this->assertFalse($normal->isInitial);
        $this->assertFalse($normal->isFinal);
        $this->assertEquals('Running', $normal->label);
    }

    public function testStateNodeWithEntry(): void
    {
        $node = StateNode::state('s1', 'Active');
        $nodeWithEntry = $node->withEntry('initialize()');
        $this->assertContains('initialize()', $nodeWithEntry->entryActions);
    }

    public function testStateNodeWithExit(): void
    {
        $node = StateNode::state('s1', 'Active');
        $nodeWithExit = $node->withExit('cleanup()');
        $this->assertContains('cleanup()', $nodeWithExit->exitActions);
    }

    public function testStateNodeWithInternal(): void
    {
        $node = StateNode::state('s1', 'Active');
        $nodeWithInternal = $node->withInternal('process()');
        $this->assertContains('process()', $nodeWithInternal->internalActions);
    }

    public function testStateTransitionGuard(): void
    {
        $guard = StateTransition::guard('g1', 'idle', 'active', 'isReady');
        $this->assertEquals(TransitionType::Guard, $guard->type);
        $this->assertEquals('idle', $guard->from);
        $this->assertEquals('active', $guard->to);
    }
}
