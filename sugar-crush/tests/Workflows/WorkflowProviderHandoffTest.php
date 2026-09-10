<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ProcessExecutor;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

/**
 * E649: nothing in src/ hands a sub-agent WORKER a provider, so `/workflow
 * run` reaches the live worker's refusal and reports a FAILED agent — the
 * deliberate behaviour change that replaced the fabricated "Completed". This
 * pins both directions of the seam that exists today: the refusal is honest
 * and provider-shaped, and configuring one through the constructor parameter
 * ProcessExecutor already exposes makes the stage complete via a REAL round
 * trip. A fabricating worker could pass the second test while failing the
 * first; the pair is what pins "no fabricated completion survives here".
 */
final class WorkflowProviderHandoffTest extends TestCase
{
    private WorkflowRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new WorkflowRegistry();
    }

    private function singleStage(string $name, string $task): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name($name)
                ->description("Provider handoff probe: {$name}")
                ->stage('work', Tasks::agent('coder')->prompt($task))
                ->build(),
        );
    }

    public function testAWorkflowStageWithoutAWorkerProviderFailsNamingTheAbsence(): void
    {
        // The shipped default: AgentWorkerPool builds its own executor, which
        // carries no workerProvider — exactly the state every src/ launch is
        // in until the E649 seam (see ProcessExecutor::__construct) lands.
        $engine = new WorkflowEngine($this->registry, new AgentWorkerPool(2));

        $this->singleStage('no-provider', 'PROBE-NO-PROVIDER');
        $result = $engine->run('no-provider', []);

        $this->assertFalse($result->isSuccess());
        $error = (string) $result->stageResults[0]->error;
        $this->assertStringContainsString('No provider configured', $error);
        // The fabricated answer this change ended was simulation prose naming
        // the task with "[Coder] Task finished:" — its absence, plus the
        // refusal below, is what distinguishes honest failure from theatre.
        $this->assertStringNotContainsString('Task finished', $error);
    }

    public function testAWorkflowStageWithAConfiguredProviderCompletesThroughARealRoundTrip(): void
    {
        $engine = new WorkflowEngine(
            $this->registry,
            new AgentWorkerPool(2, workerProvider: ['type' => 'echo']),
        );

        $this->singleStage('with-echo', 'PROBE-ECHO-ROUND-TRIP');
        $result = $engine->run('with-echo', []);

        $this->assertTrue($result->isSuccess(), (string) $result->stageResults[0]->error);
        $output = (string) $result->stageResults[0]->output;
        // EchoProvider assembles the answer INSIDE the provider from the
        // request's own user turn — the task crossing back proves the child
        // constructed the provider and called it, which no simulation of the
        // fork/pipe transport could fake without consuming a provider spec.
        $this->assertStringContainsString('PROBE-ECHO-ROUND-TRIP', $output);
        $this->assertStringNotContainsString('Task finished', $output);
    }

    public function testTheExecutorCarriesItsConfiguredProviderForReadBack(): void
    {
        // The introspection half of the seam: a prebuilt executor's provider
        // is readable (AgentWorkerPool::workerProvider() answers only for the
        // pool's own parameter, and Chat's fallback wires the EXECUTOR).
        $configured = new ProcessExecutor(workerProvider: ['type' => 'echo']);
        $this->assertSame(['type' => 'echo'], $configured->workerProvider());

        $shipped = new ProcessExecutor();
        $this->assertNull($shipped->workerProvider(), 'the shipped default must stay the documented refusal');
    }
}
