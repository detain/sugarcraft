<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

/**
 * Integration test for the deep-research workflow definition.
 *
 * Verifies:
 * - Workflow loads successfully via WorkflowRegistry
 * - Workflow has 3+ stages
 * - Required stage names 'plan', 'investigate', 'synthesize' are present
 * - Workflow name is 'deep-research'
 */
final class DeepResearchWorkflowTest extends TestCase
{
    private WorkflowRegistry $registry;
    private string $workflowsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflowsPath = __DIR__ . '/../../workflows/';
        $this->registry = new WorkflowRegistry($this->workflowsPath);
    }

    public function testDeepResearchWorkflowLoadsSuccessfully(): void
    {
        $workflow = $this->registry->load('deep-research');

        $this->assertSame('deep-research', $workflow->name);
    }

    public function testDeepResearchWorkflowHasRequiredStages(): void
    {
        $workflow = $this->registry->load('deep-research');

        $stageNames = array_column($workflow->stages, 'name');

        $this->assertContains('plan', $stageNames, 'Workflow must have a "plan" stage');
        $this->assertContains('investigate', $stageNames, 'Workflow must have an "investigate" stage');
        $this->assertContains('synthesize', $stageNames, 'Workflow must have a "synthesize" stage');
    }

    public function testDeepResearchWorkflowHasThreeOrMoreStages(): void
    {
        $workflow = $this->registry->load('deep-research');

        $this->assertGreaterThanOrEqual(3, count($workflow->stages));
    }
}
