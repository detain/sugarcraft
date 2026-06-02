<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\SetupThreads;

final class SetupThreadsTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $thread = SetupThreads::new(
            threadId: 42,
            name: 'thread/sql/main',
            type: 'FOREGROUND',
            processlistId: 42,
            processlistUser: 'root',
            processlistCommand: 'Query',
            processlistInfo: 'SELECT * FROM users',
        );

        $this->assertSame(42, $thread->threadId);
        $this->assertSame('thread/sql/main', $thread->name);
        $this->assertSame('FOREGROUND', $thread->type);
        $this->assertSame(42, $thread->processlistId);
        $this->assertSame('root', $thread->processlistUser);
        $this->assertSame('Query', $thread->processlistCommand);
        $this->assertSame('SELECT * FROM users', $thread->processlistInfo);
    }

    public function testIsForeground(): void
    {
        $foreground = SetupThreads::new(threadId: 1, name: 'test', type: 'FOREGROUND');
        $background = SetupThreads::new(threadId: 2, name: 'test', type: 'BACKGROUND');

        $this->assertTrue($foreground->isForeground());
        $this->assertFalse($foreground->isBackground());
        $this->assertFalse($background->isForeground());
        $this->assertTrue($background->isBackground());
    }

    public function testIsBackground(): void
    {
        $background = SetupThreads::new(threadId: 1, name: 'test', type: 'BACKGROUND');

        $this->assertTrue($background->isBackground());
    }

    public function testHasProcesslist(): void
    {
        $withProcesslist = SetupThreads::new(
            threadId: 1,
            name: 'test',
            type: 'FOREGROUND',
            processlistId: 42,
        );
        $withoutProcesslist = SetupThreads::new(
            threadId: 1,
            name: 'test',
            type: 'FOREGROUND',
        );

        $this->assertTrue($withProcesslist->hasProcesslist());
        $this->assertFalse($withoutProcesslist->hasProcesslist());
    }

    public function testNullableFields(): void
    {
        $thread = SetupThreads::new(
            threadId: 1,
            name: 'thread/sql/main',
            type: 'BACKGROUND',
        );

        $this->assertNull($thread->processlistId);
        $this->assertNull($thread->processlistUser);
        $this->assertNull($thread->processlistCommand);
        $this->assertNull($thread->processlistInfo);
    }
}
