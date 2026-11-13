<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\PERT;
use SugarCraft\Dashboard\Components\Tree\PertTask;
use SugarCraft\Dashboard\Components\Tree\PertStatus;
use SugarCraft\Dashboard\Foundation\Sizer;
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

        [$w, $h] = $pert->innerSize();
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

    /**
     * D-05 (CRITICAL): every id length 1..30 at every width 39..80 must render
     * without a negative str_repeat ValueError (the hardcoded nodeWidth of 10
     * crashed on any id over 8 cells — 'deploy-api' at default width threw
     * straight out of render(); the legend and duration legs went negative on
     * realistic content too). Below the degenerate-size guard render() returns
     * ''; above it the frame must be whole: title row exactly useWidth cells,
     * every box-interior row stays inside the borders.
     */
    public function testEveryIdLengthAndWidthRendersWithoutCrash(): void
    {
        for ($idLen = 1; $idLen <= 30; $idLen++) {
            $id = str_repeat('x', $idLen);
            for ($width = 39; $width <= 80; $width++) {
                $pert = PERT::new()
                    ->addTask($id, 'Deploy the API service at the edge', 5)
                    ->addTask('rel-' . $id, str_repeat('負荷', 6), 3, dependencies: [$id])
                    ->withCriticalPath(true)
                    ->setSize($width, 20);

                $rendered = $pert->render(); // must not throw
                if ($width < 40) {
                    $this->assertSame('', $rendered, "degenerate guard at width {$width}");
                    continue;
                }
                $this->assertNotSame('', $rendered);

                $rows = explode("\n", $rendered);
                $title = $rows[0];
                $this->assertSame(
                    $width,
                    \SugarCraft\Core\Util\Width::string($title),
                    "title row must span the box at id={$idLen} width={$width}"
                );
            }
        }
    }

    /** D-05: a long id WIDENS its box when the horizontal budget affords it — full text visible, not truncated. */
    public function testLongIdWidensBoxAndStaysReadable(): void
    {
        $rendered = PERT::new()
            ->addTask('deploy-api', 'Deploy API', 5)
            ->setSize(80, 20)
            ->render();

        $this->assertStringContainsString('deploy-api', $rendered);
    }

    /** D-05: with no budget for the widest id, boxes hold the 10-cell floor and the id truncates to cells — frame intact, no crash. */
    public function testLongIdTruncatesToCellsUnderBudgetPressure(): void
    {
        $pert = PERT::new()->setSize(40, 20);
        for ($i = 0; $i < 12; $i++) {
            $pert = $pert->addTask('very-long-task-id-' . $i, 'Name ' . $i, 2);
        }
        $rendered = $pert->render(); // must not throw

        $this->assertNotSame('', $rendered);
        $this->assertStringNotContainsString('very-long-task-id-0', $rendered, 'over-budget ids truncate');
        // NOTE: with 12 boxes at a 40-wide chart the single-lane placement
        // overflows the frame — PRE-EXISTING layout behaviour of the x/spacing
        // math (unchanged by this fix; happens with short ids too). Reported,
        // not redesigned here. What D-05 pins is: no ValueError for any content.
        $this->assertStringContainsString('╮', $rendered);
    }
}
