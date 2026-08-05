<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\WorkflowStatus;

final class WorkflowTest extends TestCase
{
    public function testConstructionWithAllArguments(): void
    {
        $stages = [
            ['name' => 'build', 'tasks' => []],
            ['name' => 'test', 'tasks' => []],
        ];

        $wf = new Workflow(
            name: 'CI Pipeline',
            description: 'Builds and tests the project.',
            stages: $stages,
            maxConcurrent: 3,
            timeout: 7200,
            workflowStatus: WorkflowStatus::Running,
        );

        $this->assertSame('CI Pipeline', $wf->name);
        $this->assertSame('Builds and tests the project.', $wf->description);
        $this->assertSame($stages, $wf->stages);
        $this->assertSame(3, $wf->maxConcurrent);
        $this->assertSame(7200, $wf->timeout);
        $this->assertSame(WorkflowStatus::Running, $wf->workflowStatus);
    }

    public function testDefaultValues(): void
    {
        $wf = new Workflow(
            name: 'Simple Workflow',
            description: 'A minimal workflow.',
        );

        $this->assertSame('Simple Workflow', $wf->name);
        $this->assertSame('A minimal workflow.', $wf->description);
        $this->assertSame([], $wf->stages);
        $this->assertSame(5, $wf->maxConcurrent);
        $this->assertSame(3600, $wf->timeout);
        $this->assertSame(WorkflowStatus::Draft, $wf->workflowStatus);
    }

    public function testWithStatusReturnsNewInstance(): void
    {
        $original = new Workflow(
            name: 'Test Workflow',
            description: 'Testing status transitions.',
            workflowStatus: WorkflowStatus::Draft,
        );

        $updated = $original->withStatus(WorkflowStatus::Pending);

        // Original is unchanged
        $this->assertSame(WorkflowStatus::Draft, $original->workflowStatus);

        // New instance has new status
        $this->assertSame(WorkflowStatus::Pending, $updated->workflowStatus);

        // Other properties preserved
        $this->assertSame($original->name, $updated->name);
        $this->assertSame($original->description, $updated->description);
        $this->assertSame($original->stages, $updated->stages);
        $this->assertSame($original->maxConcurrent, $updated->maxConcurrent);
        $this->assertSame($original->timeout, $updated->timeout);
    }

    public function testWithStatusSupportsAllStatuses(): void
    {
        $wf = new Workflow(name: 'Test', description: 'Test');

        foreach (WorkflowStatus::cases() as $status) {
            $next = $wf->withStatus($status);
            $this->assertSame($status, $next->workflowStatus);
        }
    }

    public function testStagedArrayIsPreservedVerbatim(): void
    {
        $stages = [
            [
                'name' => 'lint',
                'tasks' => [
                    ['agent' => 'code-review', 'prompt' => 'lint the code'],
                ],
            ],
            [
                'name' => 'build',
                'tasks' => [
                    ['agent' => 'builder', 'prompt' => 'compile'],
                ],
            ],
        ];

        $wf = new Workflow(name: 'Full Pipeline', description: 'Full', stages: $stages);

        $this->assertCount(2, $wf->stages);
        $this->assertSame('lint', $wf->stages[0]['name']);
        $this->assertSame('build', $wf->stages[1]['name']);
    }

    public function testImmutabilityOfOtherProperties(): void
    {
        $original = new Workflow(
            name: 'Original',
            description: 'Original desc',
            stages: [['name' => 's1']],
            maxConcurrent: 2,
            timeout: 500,
            workflowStatus: WorkflowStatus::Draft,
        );

        $updated = $original->withStatus(WorkflowStatus::Completed);

        $this->assertSame('Original', $updated->name);
        $this->assertSame('Original desc', $updated->description);
        $this->assertSame([['name' => 's1']], $updated->stages);
        $this->assertSame(2, $updated->maxConcurrent);
        $this->assertSame(500, $updated->timeout);
    }
}
