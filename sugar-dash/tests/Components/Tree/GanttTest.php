<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Tree\Gantt;
use SugarCraft\Dash\Components\Tree\GanttTask;
use SugarCraft\Dash\Components\Tree\TimeScale;
use SugarCraft\Core\Util\Color;

final class GanttTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $gantt = Gantt::new();
        $this->assertInstanceOf(Gantt::class, $gantt);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $gantt = Gantt::new();
        $result = $gantt->setSize(70, 12);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $gantt = Gantt::new()->setSize(70, 12);
        $rendered = $gantt->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $gantt = Gantt::new()->setSize(70, 12);
        $rendered = $gantt->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithTask(): void
    {
        $gantt = Gantt::new();
        $task = new GanttTask('Task 1', 0, 5);
        $result = $gantt->withTask($task);
        $this->assertInstanceOf(Gantt::class, $result);
    }

    public function testAddTask(): void
    {
        $gantt = Gantt::new();
        $result = $gantt->addTask('Task 1', 0, 5);
        $this->assertInstanceOf(Gantt::class, $result);
    }

    public function testWithTasks(): void
    {
        $gantt = Gantt::new();
        $tasks = [
            new GanttTask('Task 1', 0, 5),
            new GanttTask('Task 2', 3, 7),
        ];
        $result = $gantt->withTasks($tasks);
        $this->assertInstanceOf(Gantt::class, $result);
    }

    public function testWithTimeRange(): void
    {
        $gantt = Gantt::new();
        $result = $gantt->withTimeRange(0, 60);
        $this->assertInstanceOf(Gantt::class, $result);
    }

    public function testWithTimeScale(): void
    {
        $gantt = Gantt::new();
        $result = $gantt->withTimeScale(TimeScale::Weeks);
        $this->assertInstanceOf(Gantt::class, $result);
    }

    public function testWithShowProgress(): void
    {
        $gantt = Gantt::new();
        $result = $gantt->withShowProgress(false);
        $this->assertInstanceOf(Gantt::class, $result);
    }

    public function testWithShowToday(): void
    {
        $gantt = Gantt::new();
        $result = $gantt->withShowToday(false);
        $this->assertInstanceOf(Gantt::class, $result);
    }

    public function testWithStyle(): void
    {
        $gantt = Gantt::new();
        $result = $gantt->withStyle('bold');
        $this->assertInstanceOf(Gantt::class, $result);
    }

    public function testGanttTaskWithProgress(): void
    {
        $task = new GanttTask('Task 1', 0, 5);
        $result = $task->withProgress(0.5);
        $this->assertEquals(0.5, $result->progress);
    }

    public function testGanttTaskAsMilestone(): void
    {
        $task = new GanttTask('Milestone', 5, 0);
        $result = $task->asMilestone();
        $this->assertTrue($result->milestone);
    }

    public function testGetInnerSize(): void
    {
        $gantt = Gantt::new()->setSize(70, 12);
        $size = $gantt->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(70, $size[0]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $gantt = Gantt::new()->setSize(5, 5);
        $rendered = $gantt->render();
        $this->assertSame('', $rendered);
    }
}