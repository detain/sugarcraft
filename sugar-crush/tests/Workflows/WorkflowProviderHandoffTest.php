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
 * E649: a sub-agent WORKER with no provider refuses and the stage reports a
 * FAILED agent — the deliberate behaviour change that replaced the fabricated
 * "Completed". This pins both directions of the seam: the refusal is honest
 * and provider-shaped, and configuring one through the constructor parameter
 * ProcessExecutor already exposes makes the stage complete via a REAL round
 * trip. A fabricating worker could pass the second test while failing the
 * first; the pair is what pins "no fabricated completion survives here".
 * E663 added the launch half: the same two directions re-pinned against the
 * engine `Bootstrap::chat()` actually hands the session, so the wiring that
 * feeds the spec cannot silently vanish while the constructor seam still
 * passes.
 */
final class WorkflowProviderHandoffTest extends TestCase
{
    // E670 (PSR-12 properties-before-methods): the launch-pin pair sat below
    // the test methods it serves. Zero behaviour change — declaration order of
    // properties is semantically inert.
    private WorkflowRegistry $registry;

    private string $launchRepo;

    private string $launchHome;

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

    /**
     * E663: the two tests above prove the ENGINE honours a spec handed to its
     * constructor. They cannot prove the LAUNCH hands one — which is the seam
     * this closes. This pair drives the real `Bootstrap::chat()` engine: with a
     * selected provider the engine's pool carries the SAME spec
     * `agentPoolConfig()` derives for the chat path (one selection site, two
     * consumers), and with none the pool is spec-less and a stage run through
     * that launched engine still ends FAILED naming the absence — the wiring
     * must not have quietly reintroduced the fabricated completion it replaced.
     */
    public function testTheLaunchedEnginePoolCarriesTheConfiguredWorkerSpec(): void
    {
        $restore = $this->isolateLaunchEnvironment(['SUGARCRUSH_PROVIDER' => 'anthropic']);

        try {
            $engine = \SugarCraft\Crush\Cli\Bootstrap::chat($this->launchRepo)->workflowEngine();
            $this->assertInstanceOf(WorkflowEngine::class, $engine);

            $pool = $this->enginePool($engine);
            $spec = $pool->workerProvider();

            $this->assertNotNull(
                $spec,
                'E663: the launched engine lost the worker-provider feed — /workflow run stages '
                . 'would fork refusing workers although the launch has a provider',
            );
            $this->assertSame('anthropic', $spec['type'], 'the pool spec must name the selected provider');
            $this->assertArrayHasKey('model', $spec, 'the spec must carry the launch model, as workerProviderSpec documents');
        } finally {
            $this->restoreLaunchEnvironment($restore);
        }
    }

    public function testTheLaunchedEngineWithoutAnyProviderStillFailsClosedOnARun(): void
    {
        $restore = $this->isolateLaunchEnvironment([]);

        try {
            $engine = \SugarCraft\Crush\Cli\Bootstrap::chat($this->launchRepo)->workflowEngine();
            $this->assertInstanceOf(WorkflowEngine::class, $engine);

            $this->assertNull(
                $this->enginePool($engine)->workerProvider(),
                'nothing derivable must mean NO spec — never an echo-degrade standing in for a model',
            );

            $this->registerOnLaunchedRegistry($engine, 'launched-no-provider', 'PROBE-LAUNCHED-REFUSAL');
            $result = $engine->run('launched-no-provider', []);

            $this->assertFalse($result->isSuccess());
            $error = (string) $result->stageResults[0]->error;
            $this->assertStringContainsString('No provider configured', $error);
            $this->assertStringNotContainsString('Task finished', $error);
        } finally {
            $this->restoreLaunchEnvironment($restore);
        }
    }

    /**
     * Redirects HOME (both spellings — Bootstrap reads getenv, ForeignSkill-
     * Discovery reads $_SERVER) into a throwaway sandbox and pins the provider
     * selection to the values given, absent meaning cleared. Returns the
     * state {@see restoreLaunchEnvironment()} needs.
     *
     * @param array<string, string> $env
     */
    private function isolateLaunchEnvironment(array $env): array
    {
        $originals = [
            'HOME' => getenv('HOME'),
            'SERVER_HOME' => $_SERVER['HOME'] ?? null,
        ];

        $dir = sys_get_temp_dir() . '/crush_launch_pin_' . uniqid('', true);
        $this->launchRepo = $dir . '/repo';
        $this->launchHome = $dir . '/home';
        mkdir($this->launchHome . '/.sugar-crush', 0o700, true);
        mkdir($this->launchRepo, 0o755, true);

        $home = 'HOME=' . $this->launchHome;
        putenv($home);
        $_SERVER['HOME'] = $this->launchHome;
        $originals['DIR'] = $dir;

        foreach (['SUGARCRUSH_PROVIDER', 'SUGARCRUSH_MODEL', 'SUGARCRUSH_BACKEND_CMD', 'SUGARCRUSH_BACKEND_CMD_STREAM'] as $name) {
            $originals[$name] = getenv($name);
            if (array_key_exists($name, $env)) {
                putenv($name . '=' . $env[$name]);
            } else {
                putenv($name);
            }
        }

        return $originals;
    }

    /**
     * @param array<string, string|false|null> $originals
     */
    private function restoreLaunchEnvironment(array $originals): void
    {
        foreach (['SUGARCRUSH_PROVIDER', 'SUGARCRUSH_MODEL', 'SUGARCRUSH_BACKEND_CMD', 'SUGARCRUSH_BACKEND_CMD_STREAM'] as $name) {
            if ($originals[$name] === false) {
                putenv($name);
            } elseif ($originals[$name] !== null) {
                putenv($name . '=' . $originals[$name]);
            }
        }

        if ($originals['HOME'] === false) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $originals['HOME']);
        }

        if ($originals['SERVER_HOME'] === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $originals['SERVER_HOME'];
        }

        exec('rm -rf ' . escapeshellarg((string) $originals['DIR']));
    }

    private function enginePool(WorkflowEngine $engine): AgentWorkerPool
    {
        /** @var AgentWorkerPool $pool */
        $pool = (new \ReflectionProperty(WorkflowEngine::class, 'pool'))->getValue($engine);

        return $pool;
    }

    private function registerOnLaunchedRegistry(WorkflowEngine $engine, string $name, string $task): void
    {
        /** @var WorkflowRegistry $registry */
        $registry = (new \ReflectionProperty(WorkflowEngine::class, 'registry'))->getValue($engine);
        $registry->register(
            (new WorkflowBuilder())
                ->name($name)
                ->description("Launch wiring probe: {$name}")
                ->stage('work', Tasks::agent('coder')->prompt($task))
                ->build(),
        );
    }
}
