<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, Overflow, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class ToastMaxConcurrentTest extends TestCase
{
    public function testMaxConcurrentDropsOldest(): void
    {
        $t = Toast::new(50)
            ->withMaxConcurrent(2)
            ->withOverflow(Overflow::DropOldest)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second')
            ->alert(ToastType::Error, 'third');  // should drop 'first'

        $queue = $this->getQueue($t);
        $this->assertCount(2, $queue);
        $this->assertSame('second', $queue[0]->message);
        $this->assertSame('third', $queue[1]->message);
    }

    public function testMaxConcurrentDropsNewest(): void
    {
        $t = Toast::new(50)
            ->withMaxConcurrent(2)
            ->withOverflow(Overflow::DropNewest)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second')
            ->alert(ToastType::Error, 'third');  // should be discarded

        $queue = $this->getQueue($t);
        $this->assertCount(2, $queue);
        $this->assertSame('first', $queue[0]->message);
        $this->assertSame('second', $queue[1]->message);
    }

    public function testMaxConcurrentEnqueueAllowsOverflow(): void
    {
        $t = Toast::new(50)
            ->withMaxConcurrent(2)
            ->withOverflow(Overflow::Enqueue)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second')
            ->alert(ToastType::Error, 'third');  // allowed to exceed

        $queue = $this->getQueue($t);
        $this->assertCount(3, $queue);
    }

    public function testMaxConcurrentNullMeansUnlimited(): void
    {
        $t = Toast::new(50)
            ->withMaxConcurrent(null);

        // Add many alerts - all should be kept
        $t = $t
            ->alert(ToastType::Info, 'a')
            ->alert(ToastType::Info, 'b')
            ->alert(ToastType::Info, 'c')
            ->alert(ToastType::Info, 'd')
            ->alert(ToastType::Info, 'e');

        $this->assertCount(5, $this->getQueue($t));
    }

    public function testMaxConcurrentExactlyAtLimit(): void
    {
        $t = Toast::new(50)
            ->withMaxConcurrent(2)
            ->withOverflow(Overflow::DropOldest)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second');

        // Exactly at limit, no overflow
        $queue = $this->getQueue($t);
        $this->assertCount(2, $queue);
    }

    public function testMaxConcurrentOverflowAtExactLimit(): void
    {
        // At exact limit, adding one more triggers overflow
        $t = Toast::new(50)
            ->withMaxConcurrent(2)
            ->withOverflow(Overflow::DropOldest)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second');

        $t = $t->alert(ToastType::Error, 'third');

        $queue = $this->getQueue($t);
        $this->assertCount(2, $queue);
        $this->assertSame('second', $queue[0]->message);
        $this->assertSame('third', $queue[1]->message);
    }

    public function testWithMaxConcurrentReturnsNewInstance(): void
    {
        $a = Toast::new(50);
        $b = $a->withMaxConcurrent(3);
        $this->assertNotSame($a, $b);
    }

    public function testWithOverflowReturnsNewInstance(): void
    {
        $a = Toast::new(50);
        $b = $a->withOverflow(Overflow::DropNewest);
        $this->assertNotSame($a, $b);
    }

    public function testDropOldestDefaultOverflow(): void
    {
        // Default overflow is DropOldest
        $t = Toast::new(50)
            ->withMaxConcurrent(2)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second')
            ->alert(ToastType::Error, 'third');

        $queue = $this->getQueue($t);
        $this->assertCount(2, $queue);
        $this->assertSame('second', $queue[0]->message);
    }

    public function testDropOldestAtZeroCapDiscardsIncomingInsteadOfExceedingCap(): void
    {
        // A zero cap has no oldest alert to evict; the queue must stay empty
        // rather than letting one alert slip past the cap every push.
        $t = Toast::new(50)
            ->withMaxConcurrent(0)
            ->withOverflow(Overflow::DropOldest)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second');

        $this->assertCount(0, $this->getQueue($t));
    }

    public function testDropNewestAtZeroCapDiscardsIncoming(): void
    {
        $t = Toast::new(50)
            ->withMaxConcurrent(0)
            ->withOverflow(Overflow::DropNewest)
            ->alert(ToastType::Info, 'first');

        $this->assertCount(0, $this->getQueue($t));
    }

    public function testZeroCapAppliesToProgressToastAndPushToo(): void
    {
        $toast = Toast::new(50)
            ->withMaxConcurrent(0)
            ->withOverflow(Overflow::DropOldest);

        $viaProgress = $toast->progressToast(ToastType::Info, 'loading', 0.5);
        $this->assertCount(0, $this->getQueue($viaProgress));

        $viaPush = $toast->push(new Alert(ToastType::Info, 'prebuilt'));
        $this->assertCount(0, $this->getQueue($viaPush));
    }

    // Helper to access private queue
    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
