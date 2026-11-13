<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, Overflow, Toast};
use PHPUnit\Framework\TestCase;

final class OverflowTest extends TestCase
{
    public function testCases(): void
    {
        $cases = Overflow::cases();
        $this->assertCount(3, $cases);
        $this->assertSame('DropOldest', $cases[0]->name);
        $this->assertSame('DropNewest', $cases[1]->name);
        $this->assertSame('Enqueue', $cases[2]->name);
    }

    public function testDropOldest(): void
    {
        $this->assertSame(Overflow::DropOldest, Overflow::DropOldest);
    }

    public function testDropNewest(): void
    {
        $this->assertSame(Overflow::DropNewest, Overflow::DropNewest);
    }

    public function testEnqueue(): void
    {
        $this->assertSame(Overflow::Enqueue, Overflow::Enqueue);
    }

    public function testDropNewestFilesEveryOverCapArrivalInOrder(): void
    {
        $toast = Toast::new(50)
            ->withMaxConcurrent(2)
            ->withOverflow(Overflow::DropNewest)
            ->info('one')
            ->info('two')
            ->error('three')
            ->warning('four');

        $this->assertSame(['one', 'two'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->activeAlerts()
        ));
        $this->assertSame(['three', 'four'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->history()
        ));
    }

    public function testDropOldestEvictionLegIsUnmoved(): void
    {
        // Positive control across the policy split (TST-DROPINFO): DropOldest
        // still evicts the OLDEST into history and admits the newcomer.
        $toast = Toast::new(50)
            ->withMaxConcurrent(2)
            ->withOverflow(Overflow::DropOldest)
            ->info('one')
            ->info('two')
            ->error('three');

        $this->assertSame(['two', 'three'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->activeAlerts()
        ));
        $this->assertSame(['one'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->history()
        ));
    }
}
