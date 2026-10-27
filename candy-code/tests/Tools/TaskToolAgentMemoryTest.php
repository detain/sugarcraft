<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Memory\AgentMemory;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\MemoryTool;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;

/**
 * C-AGT-3.b — `memory:` on a preset is live at both delegation seams.
 *
 * The TaskTool roster seam re-binds the session's `Memory` tool to the
 * agent's own store and auto-grants the tool when the declared roster (or a
 * silent one) did not name it, while honouring the `disallowedTools` deny
 * floor; the AgentManager prompt seam folds the agent-store index under the
 * member's own words. Both seams are private here on purpose — they are
 * internal decisions of the delegation path — so the proof is reflection over
 * the exact helpers the path calls, plus the behaviour the helpers compose
 * (which store the writer ends up bound to is pinned end-to-end in
 * tests/Memory/AgentMemoryTest.php; the fold's bytes are pinned here because
 * they are prompt content, which no writer test touches).
 *
 * @see \SugarCraft\Code\Tools\BuiltIn\TaskTool
 * @see \SugarCraft\Code\Agents\AgentManager::resolveBatchSystemPrompts()
 */
final class TaskToolAgentMemoryTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->rrmdirCagt3b($root);
        }

        $this->roots = [];
    }

    // ---- the roster seam: deny floor + carries --------------------------------

    public function testTheCarriesCheckSeesAMemoryToolAtAnyPosition(): void
    {
        $carries = new ReflectionMethod(TaskTool::class, 'carriesMemoryTool');

        self::assertFalse($carries->invoke(null, []));
        // The check reads class identity, not name: a MemoryTool built with no
        // writer (the session's shape before a bind) still counts, so the
        // auto-grant never hands a run two memories.
        self::assertTrue($carries->invoke(null, [new MemoryTool(null)]));
    }

    public function testTheDenyFloorMatchesTheManagersOwnDenialGrammar(): void
    {
        $denied = new ReflectionMethod(\SugarCraft\Code\Tools\BuiltIn\TaskTool::class, 'memoryToolDenied');

        self::assertTrue($denied->invoke(null, $this->presetAgent(disallowed: ['Memory'])),
            'an exact denial of Memory is a floor — the auto-grant must not re-hand it');
        self::assertTrue($denied->invoke(null, $this->presetAgent(disallowed: ['Edit', 'Bash', 'Mem' . chr(42)])),
            'a wildcard denial that covers Memory is a floor too');
        self::assertFalse($denied->invoke(null, $this->presetAgent(disallowed: ['Bash'])),
            'denying other tools says nothing about Memory');
        self::assertFalse($denied->invoke(null, $this->presetAgent(disallowed: ['Memory(read)'])),
            'an argument-scoped denial gates at the call, exactly as resolveGrantedTools leaves it');
        self::assertFalse($denied->invoke(null, $this->presetAgent(disallowed: [])),
            'no denials, no floor');
    }

    // ---- the prompt seam: the fold rides, or adds zero bytes -----------------

    public function testASilentMemberWithNoTierIsForwardedVerbatim(): void
    {
        // Alone, a member that declares nothing keeps the batch's null answer
        // (the E654 contract test 4 pins); beside a declarer the map exists and
        // the silent member still receives the shared prompt byte for byte —
        // exactly the pre-C-AGT-3.b forward, because it has no tier to fold.
        $prompts = $this->batch(
            [$this->member(prompt: ''), $this->member(name: 'loud', prompt: 'DECLARED.')],
            'SHARED WORDS',
        );

        self::assertIsArray($prompts);
        self::assertSame('SHARED WORDS', $prompts['silent-1'],
            'no listing, no index: the pre-C-AGT-3.b verbatim forward, byte for byte');
    }

    public function testNothingDeclaredAnswersTheNullMapEvenWithATierWhoseStoreIsEmpty(): void
    {
        $root = $this->scratchRoot();
        // The tier is declared and the directory exists, but no note has ever
        // been written: the index renders '' and the member stays silent. A
        // preset declaring memory on a fresh checkout must not mint prompt
        // bytes, or the null-map contract every E654 pin keeps would move.
        AgentMemory::createStore(MemoryScope::Project, 'coder', $root);

        $prompts = $this->batch(
            [$this->member(prompt: '', memory: MemoryScope::Project, root: $root)],
            null,
        );

        self::assertNull($prompts, 'an empty agent store counts as no declaration at all');
    }

    public function testAStoredNoteRidesTheSilentMemberUnderTheSharedPrompt(): void
    {
        $root = $this->scratchRoot();
        $this->plantNote($root, 'ship lanes with their guards');

        $prompts = $this->batch(
            [$this->member(name: 'coder', prompt: '', memory: MemoryScope::Project, root: $root)],
            'SHARED WORDS',
        );

        self::assertIsArray($prompts);
        $prompt = $prompts['coder-1'];
        self::assertStringStartsWith('SHARED WORDS', $prompt, 'the shared prompt still leads');
        self::assertStringContainsString('<agent-memory>', $prompt, 'the index fence rides the silent member too');
        self::assertStringContainsString('ship lanes with their guards', $prompt);
        self::assertStringEndsWith('</agent-memory>', $prompt, 'the index is the last layer (no listing exists)');
    }

    public function testTheIndexSitsBetweenTheOwnWordsAndTheListingLayer(): void
    {
        $root = $this->scratchRoot();
        $this->plantNote($root, 'prefer the small diff');

        $prompts = $this->batch(
            [$this->member(name: 'coder', prompt: 'YOU ARE CODER.', memory: MemoryScope::Project, root: $root)],
            null,
        );

        self::assertIsArray($prompts);
        $prompt = $prompts['coder-1'];
        $own = strpos($prompt, 'YOU ARE CODER.');
        $fence = strpos($prompt, '<agent-memory>');
        self::assertNotFalse($own);
        self::assertNotFalse($fence);
        self::assertLessThan($fence, $own, 'bodies first, memory index next');
        self::assertStringContainsString('coder', $prompt, 'the header names the agent whose store this is');
    }

    public function testAFailingStoreReadFailsOpenToNoIndex(): void
    {
        // environmentRoot names a FILE, not a directory: the reader refuses
        // every tier layout under it and the fold must swallow the whole thing
        // — a delegation that was accepted may not die on its optional layer.
        $root = $this->scratchRoot();
        file_put_contents($root . '/blocked', 'not a directory\n');

        $prompts = $this->batch(
            [$this->member(prompt: 'P.', memory: MemoryScope::Project, root: $root . '/blocked')],
            'SHARED',
        );

        self::assertIsArray($prompts);
        self::assertSame("SHARED\n\nP.", $this->onlyValue($prompts),
            'the fold adds zero bytes when the store cannot be read');
    }

    // ---- serialization sweep: the nullable rides every frame shape ----------

    public function testLegacyFramesDecodeToTheUserTierAndModernNullsStayNull(): void
    {
        // BackgroundSupervisor persists Agent::toArray() frames. Old builds
        // defaulted every agent to 'user' on the row, so those frames carry
        // the value even where the author declared nothing. The disclosure:
        // such a run opens a fresh, empty USER-tier store — AgentMemory never
        // reads another store into it — and an index of nothing is zero bytes.
        $legacy = Agent::fromArray($this->frame(legacy: 'user'));
        self::assertSame(MemoryScope::User, $legacy->memory);

        $absent = Agent::fromArray($this->frame(legacy: null));
        self::assertNull($absent->memory, 'a frame without the key is no store, not the user one');

        $roundTripped = Agent::fromArray($absent->toArray());
        self::assertNull($roundTripped->memory, 'null survives the record round trip');

        $restored = Agent::fromArray($legacy->toArray());
        self::assertSame(MemoryScope::User, $restored->memory);
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * @param list<SubAgent> $members
     * @return array<string, ?string>|null
     */
    private function batch(array $members, ?string $shared): ?array
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [], toolUniverse: []);
        $resolve = new ReflectionMethod(AgentManager::class, 'resolveBatchSystemPrompts');

        return $resolve->invoke($manager, $members, $shared);
    }

    private function member(string $name = 'silent', string $prompt = '', ?MemoryScope $memory = null, ?string $root = null): SubAgent
    {
        $agent = new Agent(
            name: $name,
            description: 'test row',
            prompt: $prompt,
            model: 'test-model',
            provider: 'test',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
            memory: $memory,
            environmentRoot: $root,
        );

        return new SubAgent(id: $name . '-1', agent: $agent, task: 'inspect it');
    }

    private function presetAgent(?MemoryScope $memory = null, array $disallowed = []): Agent
    {
        return new Agent(
            name: 'coder',
            description: 'test row',
            prompt: '',
            model: 'test-model',
            provider: 'test',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
            disallowedTools: $disallowed,
            memory: $memory,
        );
    }

    private function plantNote(string $root, string $content): void
    {
        $store = AgentMemory::createStore(MemoryScope::Project, 'coder', $root);
        self::assertNotNull($store, 'the writer path lays the directory down');
        $store->add($content, MemoryScope::Project);
    }

    /** @return array<string, string> */
    private function frame(?string $legacy): array
    {
        $frame = [
            'name' => 'old',
            'description' => 'a stored row',
            'prompt' => 'p',
            'model' => 'm',
            'provider' => 'anthropic',
            'tools' => [],
            'skills' => [],
            'hooks' => [],
            'is_active' => false,
        ];

        if ($legacy !== null) {
            $frame['memory'] = $legacy;
        }

        return $frame;
    }

    /** @param array<string, ?string> $prompts */
    private function onlyValue(array $prompts): ?string
    {
        $values = array_values(array_filter($prompts, static fn (?string $v): bool => $v !== null));
        self::assertCount(1, $values);

        return $values[0];
    }

    private function scratchRoot(): string
    {
        $root = sys_get_temp_dir() . '/cagt3b-tt-' . uniqid('', true);
        mkdir($root, 0o700, true);
        $this->roots[] = $root;

        return $root;
    }

    private function rrmdirCagt3b(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rrmdirCagt3b($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
