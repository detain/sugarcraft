<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use SugarCraft\Crush\Backend\CancellationToken;

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
    /**
     * @param CancellationToken|null $cancellation Chat's double-Escape flips it
     *        mid-run. An engine that honours it stops the run (killing the
     *        stage's agents) and returns a {@see WorkflowStatus::Cancelled}
     *        result; one that ignores it simply finishes, and Chat still shows
     *        whatever it returns.
     */
    public function run(string $workflowPath, array $context = [], ?CancellationToken $cancellation = null): WorkflowResult;

    public function pause(string $workflowId): void;

    /** @param CancellationToken|null $cancellation as {@see run()} */
    public function resume(string $workflowId, ?CancellationToken $cancellation = null): WorkflowResult;

    public function getStatus(string $workflowId): WorkflowStatus;

    public function listWorkflows(): array;
}
