<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Tree\PERT;
use SugarCraft\Dash\Components\Tree\PertTask;
use SugarCraft\Dash\Components\Tree\PertStatus;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;

final class PERTTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testPERTImplementsSizer(): void
    {
        $pert = PERT::new();
        $this->assertInstanceOf(Sizer::class, $pert);
    }

    // ═══════════════════════════════════════════════════════════════
    // PertTask
    // ═══════════════════════════════════════════════════════════════

    public function testTaskCreation(): void
    {
        $task = new PertTask('A', 'Planning', 3);

        $this->assertSame('A', $task->id);
        $this->assertSame('Planning', $task->name);
        $this->assertSame(3, $task->duration);
        $this->assertNull($task->color);
        $this->assertSame(PertStatus::Pending, $task->status);
        $this->assertCount(0, $task->dependencies);
    }

    public function testTaskWithColor(): void
    {
        $color = Color::hex('#89B4FA');
        $task = new PertTask('A', 'Planning', 3, $color);

        $this->assertSame($color, $task->color);
    }

    public function testTaskWithDependencies(): void
    {
        $task = (new PertTask('B', 'Design', 5))->withDependencies(['A']);

        $this->assertCount(1, $task->dependencies);
        $this->assertSame('A', $task->dependencies[0]);
    }

    public function testTaskWithStatus(): void
    {
        $task = (new PertTask('A', 'Planning', 3))->withStatus(PertStatus::InProgress);

        $this->assertSame(PertStatus::InProgress, $task->status);
    }

    public function testTaskWithDuration(): void
    {
        $task = (new PertTask('A', 'Planning', 3))->withDuration(5);

        $this->assertSame(5, $task->duration);
    }

    public function testTaskWithColorReturnsNewInstance(): void
    {
        $task = new PertTask('A', 'Planning', 3);
        $color = Color::hex('#89B4FA');
        $withColor = $task->withColor($color);

        $this->assertSame($color, $withColor->color);
        $this->assertNull($task->color);
    }

    public function testTaskWithDependenciesReturnsNewInstance(): void
    {
        $task = new PertTask('B', 'Design', 5);
        $withDeps = $task->withDependencies(['A']);

        $this->assertCount(1, $withDeps->dependencies);
        $this->assertCount(0, $task->dependencies);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesDefaultInstance(): void
    {
        $pert = PERT::new();
        $this->assertInstanceOf(PERT::class, $pert);
    }

    public function testRenderReturnsEmptyWithNoTasks(): void
    {
        $pert = PERT::new();
        $this->assertSame('', $pert->render());
    }

    public function testRenderReturnsNonEmptyWithTasks(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->setSize(70, 20);

        $rendered = $pert->render();
        $this->assertMatchesRegularExpression('/[╭╮╰╯│─►]/', $rendered);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->setSize(30, 8);

        $this->assertSame('', $pert->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Sample creation
    // ═══════════════════════════════════════════════════════════════

    public function testSampleCreatesValidChart(): void
    {
        $pert = PERT::sample();

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Task operations
    // ═══════════════════════════════════════════════════════════════

    public function testWithTasksReplacesTasks(): void
    {
        $tasks = [
            new PertTask('A', 'Planning', 3),
            new PertTask('B', 'Design', 5),
        ];
        $pert = PERT::new()->withTasks($tasks);

        $rendered = $pert->render();
        $this->assertStringContainsString('Planning', $rendered);
        $this->assertStringContainsString('Design', $rendered);
    }

    public function testWithTaskAddsTask(): void
    {
        $pert = PERT::new()
            ->withTask(new PertTask('A', 'Planning', 3))
            ->withTask(new PertTask('B', 'Design', 5));

        $rendered = $pert->render();
        $this->assertStringContainsString('Planning', $rendered);
        $this->assertStringContainsString('Design', $rendered);
    }

    public function testAddTaskByParams(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->addTask('B', 'Design', 5, ['A']);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Display options
    // ═══════════════════════════════════════════════════════════════

    public function testWithShowDuration(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3);

        $result = $pert->withShowDuration(false);
        $this->assertInstanceOf(PERT::class, $result);
    }

    public function testWithShowStatus(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3);

        $result = $pert->withShowStatus(false);
        $this->assertInstanceOf(PERT::class, $result);
    }

    public function testWithCriticalPath(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3);

        $result = $pert->withCriticalPath(false);
        $this->assertInstanceOf(PERT::class, $result);
    }

    public function testWithShowGrid(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3);

        $result = $pert->withShowGrid(false);
        $this->assertInstanceOf(PERT::class, $result);
    }

    public function testWithStyle(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3);

        $result = $pert->withStyle('bold');
        $this->assertInstanceOf(PERT::class, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testBorderStyles(): void
    {
        $styles = ['rounded', 'single', 'double', 'bold', 'empty'];

        foreach ($styles as $style) {
            $pert = PERT::new()
                ->addTask('A', 'Planning', 3)
                ->withStyle($style)
                ->setSize(70, 20);

            $rendered = $pert->render();
            $this->assertNotSame('', $rendered, "Style '$style' should render");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizer(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3);

        $result = $pert->setSize(70, 20);
        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->setSize(70, 20);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSize(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->setSize(70, 20);

        [$w, $h] = $pert->getInnerSize();
        $this->assertSame(70, $w);
        $this->assertSame(20, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithNodeColor(): void
    {
        $pert = PERT::new();
        $result = $pert->withNodeColor(Color::hex('#FF0000'));
        $this->assertInstanceOf(PERT::class, $result);
    }

    public function testWithArrowColor(): void
    {
        $pert = PERT::new();
        $result = $pert->withArrowColor(Color::hex('#00FF00'));
        $this->assertInstanceOf(PERT::class, $result);
    }

    public function testWithTextColor(): void
    {
        $pert = PERT::new();
        $result = $pert->withTextColor(Color::hex('#0000FF'));
        $this->assertInstanceOf(PERT::class, $result);
    }

    public function testWithCriticalColor(): void
    {
        $pert = PERT::new();
        $result = $pert->withCriticalColor(Color::hex('#FFFF00'));
        $this->assertInstanceOf(PERT::class, $result);
    }

    public function testWithersReturnNewInstance(): void
    {
        $original = PERT::new()->addTask('A', 'Planning', 3);
        $updated = $original->withNodeColor(Color::hex('#FF0000'));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Task dependencies
    // ═══════════════════════════════════════════════════════════════

    public function testTaskWithDependency(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->addTask('B', 'Design', 5, ['A']);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    public function testMultipleDependencies(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->addTask('B', 'Design', 5)
            ->addTask('C', 'Implement', 8, ['A', 'B']);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Task status
    // ═══════════════════════════════════════════════════════════════

    public function testTaskStatusInProgress(): void
    {
        $task = (new PertTask('A', 'Planning', 3))->withStatus(PertStatus::InProgress);
        $pert = PERT::new()->withTask($task);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    public function testTaskStatusCompleted(): void
    {
        $task = (new PertTask('A', 'Planning', 3))->withStatus(PertStatus::Completed);
        $pert = PERT::new()->withTask($task);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    public function testTaskStatusDelayed(): void
    {
        $task = (new PertTask('A', 'Planning', 3))->withStatus(PertStatus::Delayed);
        $pert = PERT::new()->withTask($task);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleTask(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->setSize(70, 20);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    public function testMultipleTasks(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->addTask('B', 'Design', 5, ['A'])
            ->addTask('C', 'Implement', 8, ['B'])
            ->addTask('D', 'Testing', 3, ['C'])
            ->addTask('E', 'Deploy', 2, ['D'])
            ->setSize(70, 20);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    public function testColorAddsAnsiCodes(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->withNodeColor(Color::ansi(12));

        $rendered = $pert->render();
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testHideDurationRendersOk(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->withShowDuration(false)
            ->setSize(70, 20);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }

    public function testHideStatusRendersOk(): void
    {
        $pert = PERT::new()
            ->addTask('A', 'Planning', 3)
            ->withShowStatus(false)
            ->setSize(70, 20);

        $rendered = $pert->render();
        $this->assertNotSame('', $rendered);
    }
}
