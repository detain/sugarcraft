<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Workflows\WorkflowStatus;

final class WorkflowStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $statuses = WorkflowStatus::cases();
        $this->assertCount(8, $statuses);

        $expectedNames = ['Draft', 'Pending', 'Running', 'Paused', 'Resuming', 'Completed', 'Failed', 'Cancelled'];
        $actualNames = array_map(fn($s) => $s->name, $statuses);
        $this->assertSame($expectedNames, $actualNames);
    }

    public function testStringValues(): void
    {
        $this->assertSame('draft', WorkflowStatus::Draft->value);
        $this->assertSame('pending', WorkflowStatus::Pending->value);
        $this->assertSame('running', WorkflowStatus::Running->value);
        $this->assertSame('paused', WorkflowStatus::Paused->value);
        $this->assertSame('resuming', WorkflowStatus::Resuming->value);
        $this->assertSame('completed', WorkflowStatus::Completed->value);
        $this->assertSame('failed', WorkflowStatus::Failed->value);
        $this->assertSame('cancelled', WorkflowStatus::Cancelled->value);
    }

    public function testIsTerminal(): void
    {
        $terminalCases = [WorkflowStatus::Completed, WorkflowStatus::Failed, WorkflowStatus::Cancelled];
        $nonTerminalCases = [
            WorkflowStatus::Draft,
            WorkflowStatus::Pending,
            WorkflowStatus::Running,
            WorkflowStatus::Paused,
            WorkflowStatus::Resuming,
        ];

        foreach ($terminalCases as $status) {
            $this->assertTrue($status->isTerminal());
        }

        foreach ($nonTerminalCases as $status) {
            $this->assertFalse($status->isTerminal());
        }
    }

    public function testIsActive(): void
    {
        $activeCases = [WorkflowStatus::Running, WorkflowStatus::Paused];
        $inactiveCases = [
            WorkflowStatus::Draft,
            WorkflowStatus::Pending,
            WorkflowStatus::Resuming,
            WorkflowStatus::Completed,
            WorkflowStatus::Failed,
            WorkflowStatus::Cancelled,
        ];

        foreach ($activeCases as $status) {
            $this->assertTrue($status->isActive());
        }

        foreach ($inactiveCases as $status) {
            $this->assertFalse($status->isActive());
        }
    }
}
