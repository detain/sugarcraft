<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

/**
 * What `/workflow run` actually TELLS the user when a run fails.
 *
 * The enforcement layer this file guards is only worth having if its refusal
 * is visible: the engine puts the reason on the stage it belongs to, and the
 * command used to print `**Workflow 'x' completed**` in bold with
 * `Status: failed` underneath and not one word about why. A stage refused for
 * declaring a tool the session's permission mode denies read, to the person who
 * typed the command, as a workflow that had simply not worked.
 */
final class WorkflowFailureReportingTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\DrivesWorkflowRunsTrait;

    /**
     * A refused declaration reaches the transcript, with the stage that caused
     * it and the mode that refused it.
     */
    public function testARefusedDeclarationIsReportedInTheTranscriptWithItsReason(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register(
            (new WorkflowBuilder())
                ->name('refused')
                ->description('Declares a tool dont-ask refuses')
                ->stage('shell-out', Tasks::agent('coder')->prompt('Go')->tools(['Bash']))
                ->build(),
        );

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->never())->method('execute');

        $engine = new WorkflowEngine(
            $registry,
            new AgentWorkerPool(5, $executor),
            permissionGate: new PermissionGate(PermissionMode::DontAsk),
        );

        $response = $this->runCommand('/workflow run refused', $engine);

        $this->assertStringContainsString("**Workflow 'refused' failed**", $response);
        $this->assertStringContainsString('Status: failed', $response);
        $this->assertStringContainsString("Stage 'shell-out'", $response);
        $this->assertStringContainsString('Bash', $response);
        $this->assertStringContainsString('dont-ask', $response);
    }

    /**
     * The control: a run that succeeds still says `completed`, with no failure
     * line appended. The heading is chosen from the status, so a build that
     * always reported failure would pass the test above.
     */
    public function testASuccessfulRunStillReportsCompletedWithNoFailureLine(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register(
            (new WorkflowBuilder())
                ->name('fine')
                ->description('Nothing to refuse')
                ->stage('look', Tasks::agent('reviewer')->prompt('Look')->tools(['Read']))
                ->build(),
        );

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())
            ->method('execute')
            ->willReturn(new \SugarCraft\Crush\Agents\AgentResult(
                agentId: 'a1',
                status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                output: 'looked',
                startedAt: new \DateTimeImmutable(),
                completedAt: new \DateTimeImmutable(),
            ));

        $engine = new WorkflowEngine(
            $registry,
            new AgentWorkerPool(5, $executor),
            permissionGate: new PermissionGate(PermissionMode::DontAsk),
        );

        $response = $this->runCommand('/workflow run fine', $engine);

        $this->assertStringContainsString("**Workflow 'fine' completed**", $response);
        $this->assertStringContainsString('Status: completed', $response);
        $this->assertStringNotContainsString("Stage '", $response);
    }

    /**
     * Submit a slash command to a Chat holding $engine and return the
     * assistant's reply.
     */
    private function runCommand(string $command, WorkflowEngine $engine): string
    {
        return $this->runWorkflowCommandToReply(new Chat(inputBuf: $command, workflowEngine: $engine));
    }
}
