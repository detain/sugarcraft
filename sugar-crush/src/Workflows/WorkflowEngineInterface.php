<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Interface for workflow engine implementations.
 *
 * Mirrors the 5 public methods that Chat's /workflow command handlers call:
 * run, pause, resume, getStatus, listWorkflows.
 *
 * This interface exists to allow test doubles (fakes/mocks) to be passed to
 * Chat without requiring WorkflowEngine to be non-final.
 */
interface WorkflowEngineInterface
{
    public function run(string $workflowPath, array $context = []): WorkflowResult;

    public function pause(string $workflowId): void;

    public function resume(string $workflowId): WorkflowResult;

    public function getStatus(string $workflowId): WorkflowStatus;

    public function listWorkflows(): array;
}
