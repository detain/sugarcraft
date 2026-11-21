<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Agents\SuspendedDelegations;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillListingSection;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;

/**
 * Skills QA lane D: the fenced Level-1 `<available-skills>` listing the main
 * chat assembles per turn must reach SUB-AGENT worker prompts too, through
 * AgentManager's splice points: systemPromptFor(), which a Task delegation's
 * engine run speaks, and the batch map behind the pool's per-member requests.
 *
 * The assertions are structural against SkillListingSection's public consts —
 * never a re-typed copy of its body — so a geometry change there lands here
 * without a hunt, while an omission here cannot hide behind a mirrored typo.
 */
final class AgentWorkerSkillListingTest extends TestCase
{
    private function manifestSkill(string $name): Skill
    {
        $content = <<<SKILL
        ---
        description: {$name} description for the listing
        user-invocable: true
        disable-model-invocation: false
        paths: []
        ---

        The body text of {$name}.
        SKILL;

        return Skill::parse($content, $name, "/path/to/{$name}/SKILL.md");
    }

    /**
     * @param list<string> $names
     */
    private function registryHolding(array $names): SkillRegistry
    {
        $registry = new SkillRegistry();
        foreach ($names as $name) {
            $registry->register([$name => $this->manifestSkill($name)]);
        }

        return $registry;
    }

    /**
     * @param list<string> $skillNames
     */
    private function workerAgent(string $name, string $prompt = 'You are worker.', array $skillNames = []): Agent
    {
        return new Agent(
            name: $name,
            description: "$name description",
            prompt: $prompt,
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: $skillNames,
            hooks: [],
            isActive: true,
        );
    }

    private function managerWith(SkillRegistry $registry, ?ExecutorInterface $executor = null): AgentManager
    {
        $manager = new AgentManager(
            $this->createMock(ProviderInterface::class),
            $registry,
            $executor === null ? null : new AgentWorkerPool(2, $executor),
        );

        return $manager;
    }

    /**
     * Every sub-agent benefits, not just skill-granting ones: a plain worker
     * with no `skills:` declaration still sees the fence and both entries.
     */
    public function testTheWorkerPromptCarriesTheFencedListingWhenTheRegistryIsNonEmpty(): void
    {
        $manager = $this->managerWith($this->registryHolding(['alpha', 'beta']));
        $manager->register($this->workerAgent('listed-worker'));
        $subAgent = $manager->createSubAgent('listed-worker', 'do the thing');

        $prompt = $manager->systemPromptFor($subAgent);

        $this->assertStringStartsWith('You are worker.', $prompt);
        $this->assertStringContainsString(SkillListingSection::OPEN, $prompt);
        $this->assertStringContainsString(SkillListingSection::CLOSE, $prompt);
        $this->assertStringContainsString('- [project] alpha: alpha description for the listing', $prompt);
        $this->assertStringContainsString('- [project] beta: beta description for the listing', $prompt);
    }

    /**
     * One skill, two faces: the GRANTED name rides as its full body and is
     * excluded from the one-line listing; the ungranted one stays a line.
     * Mirrors the main chat's enabled-bodies exclusion, per member.
     */
    public function testAGrantedSkillRidesAsABodyAndItsNameLeavesTheListing(): void
    {
        $manager = $this->managerWith($this->registryHolding(['alpha', 'beta']));
        $manager->register($this->workerAgent('grantee', skillNames: ['alpha']));
        $subAgent = $manager->createSubAgent('grantee', 'do the thing');

        $prompt = $manager->systemPromptFor($subAgent);

        $this->assertStringContainsString('## Skill: alpha', $prompt);
        $this->assertStringContainsString('The body text of alpha.', $prompt);
        $this->assertStringNotContainsString('- [project] alpha:', $prompt);
        $this->assertStringContainsString('- [project] beta:', $prompt);
    }

    /**
     * Fail-soft emptiness: with nothing discovered the worker prompt is the
     * pre-lane-D bytes exactly — no dangling fence, no stray separator.
     */
    public function testAnEmptyRegistryLeavesTheWorkerPromptByteIdentical(): void
    {
        $manager = $this->managerWith(new SkillRegistry());
        $manager->register($this->workerAgent('plain', skillNames: []));
        $subAgent = $manager->createSubAgent('plain', 'do the thing');

        $this->assertSame('You are worker.', $manager->systemPromptFor($subAgent));
    }

    /**
     * The batch splice behind the pool path: a SILENT member (no own prompt,
     * no grants) used to receive the shared prompt verbatim through a null
     * map. With a non-empty registry the map is non-null and the silent
     * member's request carries shared + fence, so the listing cannot be
     * dropped by the very silence-optimisation E654 shipped.
     */
    public function testASilentBatchMemberReceivesTheListingBesideTheSharedPrompt(): void
    {
        $captured = [];
        $capture = function (SubAgent $agent, CompleteRequest $request) use (&$captured): AgentResult {
            $captured[$agent->id] = $request->systemPrompt;

            return new AgentResult(
                agentId: $agent->id,
                status: AgentStatus::Completed,
                output: 'ok',
                tokensUsed: 1,
                costUsd: 0.0,
            );
        };
        $executor = new class($capture) implements ExecutorInterface {
            /** @param \Closure(SubAgent, CompleteRequest): AgentResult $onSpeak */
            public function __construct(private \Closure $onSpeak) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return ($this->onSpeak)($agent, $request);
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $manager = $this->managerWith($this->registryHolding(['alpha']), $executor);
        $manager->register($this->workerAgent('quiet-member', prompt: ''));
        $silent = $manager->createSubAgent('quiet-member', 'task');

        iterator_to_array($manager->executeAll(
            [$silent],
            new CompleteRequest(model: 'test-model', messages: [], systemPrompt: 'SHARED'),
        ));

        $prompt = $captured[$silent->id];
        $this->assertIsString($prompt);
        $this->assertStringStartsWith("SHARED\n\n" . SkillListingSection::OPEN, $prompt);
        $this->assertStringContainsString('- [project] alpha:', $prompt);
        $this->assertStringEndsWith(SkillListingSection::CLOSE, $prompt);
    }

    /**
     * Order law: granted bodies fold above the listing so the fence's mandate
     * text indexes only what follows it — the two halves are disjoint by the
     * exclusion, and the prompt reads declaration, bodies, then catalogue.
     */
    public function testGrantedBodiesFoldAboveTheFencedListing(): void
    {
        $manager = $this->managerWith($this->registryHolding(['alpha', 'beta']));
        $manager->register($this->workerAgent('ordered', skillNames: ['alpha']));
        $subAgent = $manager->createSubAgent('ordered', 'do the thing');

        $prompt = $manager->systemPromptFor($subAgent);

        $bodyAt = strpos($prompt, '## Skill: alpha');
        $fenceAt = strpos($prompt, SkillListingSection::OPEN);
        $this->assertIsInt($bodyAt);
        $this->assertIsInt($fenceAt);
        $this->assertLessThan($fenceAt, $bodyAt, 'bodies first, listing last');
        $this->assertStringStartsWith('You are worker.', $prompt);
    }

    /**
     * The Task delegation's engine run speaks the same layer as the batch
     * splice (the splice docblocks claim parity; this pins it end to end):
     * the system prompt the provider receives on a TaskTool run carries the
     * granted body above the fenced listing.
     */
    public function testATaskDelegationSpeaksTheListingAfterItsBodies(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);
        $manager = new AgentManager($this->createMock(ProviderInterface::class), $this->registryHolding(['alpha', 'beta']));
        $manager->register($this->workerAgent('in-process', skillNames: ['alpha']));

        $storeDir = sys_get_temp_dir() . '/sc_skill_listing_task_' . bin2hex(random_bytes(6));
        try {
            $result = (new TaskTool($manager, suspended: new SuspendedDelegations($storeDir)))
                ->withEngine(EngineBackend::new($provider, 'm'))
                ->execute(['description' => 'Listing check', 'prompt' => 'do the thing', 'agent' => 'in-process', 'id' => 'task-call']);
        } finally {
            foreach (glob($storeDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storeDir);
        }

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(1, $provider->requests);
        // The engine run speaks the agent's prompt as the delegated
        // transcript's system turn, beside the engine's own system prompt.
        $systemTurns = array_values(array_filter(
            $provider->requests[0]->messages,
            static fn (mixed $message): bool => $message instanceof Message && $message->role() === 'system',
        ));
        $this->assertCount(1, $systemTurns, 'the delegated run carries the agent prompt as one system turn');
        $capturedPrompt = $systemTurns[0]->content();
        // The layer law is relative order, not absolute bytes.
        $this->assertStringContainsString('You are worker.', $capturedPrompt);
        $this->assertStringContainsString('## Skill: alpha', $capturedPrompt);
        $this->assertStringContainsString(SkillListingSection::OPEN, $capturedPrompt);
        $this->assertStringNotContainsString('- [project] alpha:', $capturedPrompt);
        $this->assertStringContainsString('- [project] beta:', $capturedPrompt);
        $this->assertLessThan(
            (int) strpos($capturedPrompt, SkillListingSection::OPEN),
            (int) strpos($capturedPrompt, '## Skill: alpha'),
            'bodies first, listing last, on the Task path too',
        );
    }
}
