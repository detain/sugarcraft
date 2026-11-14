<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\StateNode;

/**
 * D-143: three action channels (entry/exit/internal) that must never
 * cross-contaminate — the StateMachine dispatches each list at a
 * different moment, so a withEntry leaking into exitActions would fire
 * cleanup on entry and the suite would never notice.
 */
final class StateNodeTest extends TestCase
{
    public function testDefaultsAreNonTerminalIdleWithEmptyChannels(): void
    {
        $node = new StateNode('idle', 'Idle');

        self::assertFalse($node->isInitial);
        self::assertFalse($node->isFinal);
        self::assertSame([], $node->entryActions);
        self::assertSame([], $node->exitActions);
        self::assertSame([], $node->internalActions);
    }

    public function testEachWitherGrowsOnlyItsOwnChannel(): void
    {
        $node = new StateNode('idle', 'Idle');

        $entry = $node->withEntry('log');
        self::assertSame(['log'], $entry->entryActions);
        self::assertSame([], $entry->exitActions);
        self::assertSame([], $entry->internalActions);

        $exit = $node->withExit('cleanup');
        self::assertSame([], $exit->entryActions);
        self::assertSame(['cleanup'], $exit->exitActions);
        self::assertSame([], $exit->internalActions);

        $internal = $node->withInternal('tick');
        self::assertSame([], $internal->entryActions);
        self::assertSame([], $internal->exitActions);
        self::assertSame(['tick'], $internal->internalActions);

        // All three sources stayed pristine.
        self::assertSame([], $node->entryActions);
        self::assertSame([], $node->exitActions);
        self::assertSame([], $node->internalActions);
    }

    public function testChannelsComposeWithAppendOrderPerChannel(): void
    {
        $node = (new StateNode('s', 'S'))
            ->withEntry('a')
            ->withExit('x')
            ->withEntry('b')
            ->withInternal('i');

        self::assertSame(['a', 'b'], $node->entryActions, 'append order per channel');
        self::assertSame(['x'], $node->exitActions);
        self::assertSame(['i'], $node->internalActions);
        self::assertSame('s', $node->id);
        self::assertSame('S', $node->label);
    }

    public function testInitialAndFinalFlagsTravelThroughActionDoors(): void
    {
        $final = new StateNode('end', 'End', false, true);

        $marked = $final->withEntry('bye');

        self::assertTrue($marked->isFinal);
        self::assertFalse($marked->isInitial);
    }
}
