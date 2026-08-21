<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Agents\Effort;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Skills\SkillSource;

/**
 * {@see Agent::fromPreset()} read SIX of {@see AgentPreset}'s SIXTEEN fields
 * and dropped ten: `disallowedTools`, `permissionMode`, `maxTurns`,
 * `mcpServers`, `memory`, `background`, `effort`, `isolation`, `color` and
 * `source`.
 *
 * The bridge is the only way a discovered preset becomes something
 * {@see \SugarCraft\Crush\Agents\AgentManager::register()} can hold, so a
 * field dropped here was unreachable from a registered agent — not merely
 * unread.
 *
 * NINE OF THE TEN NOW CARRY UNCONDITIONALLY. The tenth, `permissionMode`, is
 * GATED ON PROVENANCE — carried for a `SkillSource::Native` preset, forced to
 * {@see PermissionMode::Default} for a foreign one — because it is the only
 * field of the sixteen that is a privilege decision rather than a description,
 * and the foreign tiers are repository directories read with no
 * `trustedProject*` opt-in. Both sides are pinned by
 * {@see testPermissionModeIsTheOneFieldGatedOnProvenance()}, and the fixture
 * below is deliberately Claude-sourced so the gate is exercised by the main
 * carry test rather than only by its own.
 *
 * NOTHING ELSE HERE ASSERTS A BEHAVIOUR CHANGE, and that is deliberate: no
 * consumer of a registered `Agent` reads the other nine yet. These pin the
 * CARRY, which is what a future consumer depends on and what the next edit
 * to this class can silently undo.
 *
 * @internal
 */
final class AgentPresetFieldCarryTest extends TestCase
{
    /**
     * The count that made this a finding. If someone adds a seventeenth field
     * to AgentPreset, this fails and points at the carry below.
     */
    public function testAgentPresetStillDeclaresSixteenFields(): void
    {
        $constructor = (new \ReflectionClass(AgentPreset::class))->getConstructor();
        self::assertNotNull($constructor);

        $this->assertCount(16, $constructor->getParameters());
    }

    /**
     * Every promoted parameter on AgentPreset must have a same-named property
     * on Agent. This is the drift guard: it is the mapping, not a sample of
     * it, and a new preset field fails it by name.
     *
     * Two names differ by design and are mapped rather than excused:
     * `skills` is Agent's `skillNames`, and `initialPrompt` is its `prompt`.
     */
    public function testEveryPresetFieldHasAnAgentCounterpart(): void
    {
        $rename = ['skills' => 'skillNames', 'initialPrompt' => 'prompt'];

        $constructor = (new \ReflectionClass(AgentPreset::class))->getConstructor();
        self::assertNotNull($constructor);

        $agent = new \ReflectionClass(Agent::class);
        $missing = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $rename[$parameter->getName()] ?? $parameter->getName();
            if (!$agent->hasProperty($name)) {
                $missing[] = $parameter->getName();
            }
        }

        $this->assertSame([], $missing, 'AgentPreset fields with nowhere to land on Agent');
    }

    /**
     * The carry itself, with every field set AWAY from its default so a
     * dropped one shows up as the default rather than as a coincidence.
     */
    public function testFromPresetCarriesAllSixteenFields(): void
    {
        $agent = Agent::fromPreset($this->fullPreset(), 'anthropic', 'session-model');

        $this->assertSame('reviewer', $agent->name);
        $this->assertSame('Reviews diffs', $agent->description);
        $this->assertSame('You review.', $agent->prompt);
        $this->assertSame('preset-model', $agent->model);
        $this->assertSame(['Read', 'Grep'], $agent->tools);
        $this->assertSame(['php-pro'], $agent->skillNames);

        // The ten that used to be dropped.
        $this->assertSame(['Bash', 'Write'], $agent->disallowedTools);
        // NINE of the ten carry unconditionally. `permissionMode` is the tenth
        // and it is GATED ON PROVENANCE — this fixture is `SkillSource::Claude`,
        // i.e. repository content, so its `Plan` is deliberately NOT carried.
        // See testPermissionModeIsTheOneFieldGatedOnProvenance() for both sides
        // of that; asserted here too so a reader of this list is not left
        // believing all sixteen travel identically.
        $this->assertSame(PermissionMode::Default, $agent->permissionMode);
        $this->assertSame(7, $agent->maxTurns);
        $this->assertSame(['fs' => ['command' => 'mcp-fs']], $agent->mcpServers);
        $this->assertSame(MemoryScope::Project, $agent->memory);
        $this->assertTrue($agent->background);
        $this->assertSame(Effort::High, $agent->effort);
        $this->assertSame(Isolation::Worktree, $agent->isolation);
        $this->assertSame('#ff00ff', $agent->color);
        $this->assertSame(SkillSource::Claude, $agent->source);
    }

    /**
     * A preset that declares none of the ten must land on the SAME defaults
     * AgentPreset declares — otherwise the carry has quietly changed what a
     * minimal preset means.
     */
    public function testFromPresetOnAMinimalPresetLandsOnThePresetsOwnDefaults(): void
    {
        $preset = new AgentPreset(name: 'bare', description: 'nothing set');
        $agent = Agent::fromPreset($preset, 'anthropic', 'session-model');

        $this->assertSame($preset->disallowedTools, $agent->disallowedTools);
        $this->assertSame($preset->permissionMode, $agent->permissionMode);
        $this->assertSame($preset->maxTurns, $agent->maxTurns);
        $this->assertSame($preset->mcpServers, $agent->mcpServers);
        $this->assertSame($preset->memory, $agent->memory);
        $this->assertSame($preset->background, $agent->background);
        $this->assertSame($preset->effort, $agent->effort);
        $this->assertSame($preset->isolation, $agent->isolation);
        $this->assertSame($preset->color, $agent->color);
        $this->assertSame($preset->source, $agent->source);
    }

    /**
     * `isolation` is the only nullable enum in the set, and null is a
     * distinct state from {@see Isolation::None} on the preset. Pin it apart
     * so a `?? Isolation::None` fallback cannot creep in.
     */
    public function testFromPresetKeepsANullIsolationDistinctFromNone(): void
    {
        $null = Agent::fromPreset(new AgentPreset(name: 'a', description: 'b'), 'p', 'm');
        $none = Agent::fromPreset(
            new AgentPreset(name: 'a', description: 'b', isolation: Isolation::None),
            'p',
            'm',
        );

        $this->assertNull($null->isolation);
        $this->assertSame(Isolation::None, $none->isolation);
    }

    /**
     * `with*()` rebuilds the whole value. A field the builder forgets is lost
     * on the next rename or activation, which is exactly how the six-of-
     * sixteen drop stayed invisible.
     *
     * @dataProvider builderProvider
     */
    public function testBuildersCarryTheFieldsForward(callable $apply): void
    {
        $before = Agent::fromPreset($this->fullPreset(), 'anthropic', 'session-model');
        $after = $apply($before);

        $this->assertSame($before->disallowedTools, $after->disallowedTools);
        $this->assertSame($before->permissionMode, $after->permissionMode);
        $this->assertSame($before->maxTurns, $after->maxTurns);
        $this->assertSame($before->mcpServers, $after->mcpServers);
        $this->assertSame($before->memory, $after->memory);
        $this->assertSame($before->background, $after->background);
        $this->assertSame($before->effort, $after->effort);
        $this->assertSame($before->isolation, $after->isolation);
        $this->assertSame($before->color, $after->color);
        $this->assertSame($before->source, $after->source);
    }

    /** @return array<string, array{0: callable(Agent): Agent}> */
    public static function builderProvider(): array
    {
        return [
            'withName' => [static fn (Agent $a): Agent => $a->withName('renamed')],
            'withActive' => [static fn (Agent $a): Agent => $a->withActive(true)],
            'withEnvironment' => [static fn (Agent $a): Agent => $a->withEnvironment(null)],
        ];
    }

    /**
     * `toArray()`/`fromArray()` are a dormant seam — no `src/` caller — but
     * they are the documented persistence shape, so a round trip must not
     * lose the ten either.
     */
    public function testToArrayFromArrayRoundTripsTheCarriedFields(): void
    {
        $before = Agent::fromPreset($this->fullPreset(), 'anthropic', 'session-model');
        $after = Agent::fromArray($before->toArray());

        $this->assertSame($before->disallowedTools, $after->disallowedTools);
        $this->assertSame($before->permissionMode, $after->permissionMode);
        $this->assertSame($before->maxTurns, $after->maxTurns);
        $this->assertSame($before->mcpServers, $after->mcpServers);
        $this->assertSame($before->memory, $after->memory);
        $this->assertSame($before->background, $after->background);
        $this->assertSame($before->effort, $after->effort);
        $this->assertSame($before->isolation, $after->isolation);
        $this->assertSame($before->color, $after->color);
        $this->assertSame($before->source, $after->source);
    }

    /** The persisted frame must survive json_encode/decode unchanged. */
    public function testToArrayIsJsonEncodable(): void
    {
        $array = Agent::fromPreset($this->fullPreset(), 'anthropic', 'm')->toArray();
        $json = json_encode($array);

        $this->assertIsString($json);
        $this->assertSame($array, json_decode($json, true));
        // 'default' rather than the fixture's 'plan': the fixture is
        // Claude-sourced and fromPreset() gates that field. Asserting the
        // POST-GATE value here is the point — toArray() is the persistence
        // seam, and a frame that persisted a repository-supplied
        // bypass-permissions would reintroduce the escape on the next load.
        $this->assertSame('default', $array['permission_mode']);
        $this->assertSame('worktree', $array['isolation']);
    }

    /**
     * THE ONE FIELD THAT DOES NOT CARRY UNCONDITIONALLY, both sides.
     *
     * Fifteen of the preset's sixteen fields describe an agent. Only
     * `permissionMode` decides what it may do without asking, and
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} reads
     * `{projectRoot}/.claude/agents` and `{projectRoot}/.opencode/agents` with
     * no `trustedProject*` opt-in — so a file that arrives with a `git clone`
     * would otherwise declare `bypass-permissions` for itself. MEASURED before
     * the gate, through the real {@see \SugarCraft\Crush\Cli\Bootstrap::agentRoster()}:
     * it did exactly that.
     *
     * The native side is asserted with equal weight, because a gate that also
     * silenced `.sugar-crush/agents` would look identical from the foreign
     * side and would break the tier a user configures deliberately.
     */
    public function testPermissionModeIsTheOneFieldGatedOnProvenance(): void
    {
        $modes = [];
        foreach (SkillSource::cases() as $source) {
            $preset = new AgentPreset(
                name: 'p',
                description: 'd',
                permissionMode: PermissionMode::BypassPermissions,
                source: $source,
            );
            $modes[$source->value] = Agent::fromPreset($preset, 'anthropic', 'm')->permissionMode;
        }

        $this->assertSame(
            [
                'native' => PermissionMode::BypassPermissions,
                'claude' => PermissionMode::Default,
                'opencode' => PermissionMode::Default,
                'spec' => PermissionMode::Default,
            ],
            $modes,
            'only the native tier may raise its own permission mode',
        );

        // The gate is on the MODE, not on the preset: a foreign preset still
        // carries everything else, so this is a narrowing of one field rather
        // than of the import.
        $foreign = Agent::fromPreset($this->fullPreset(), 'anthropic', 'm');
        $this->assertSame(7, $foreign->maxTurns);
        $this->assertSame(Effort::High, $foreign->effort);
        $this->assertSame(['Bash', 'Write'], $foreign->disallowedTools);
        $this->assertSame(SkillSource::Claude, $foreign->source);
    }

    /**
     * THE INVARIANT IS THE TYPE'S, NOT ONE CONSTRUCTOR'S. {@see Agent} has two
     * factories that read outside data — `fromPreset()` and `fromArray()` —
     * and gating only the first would leave the persistence seam as a way back
     * in. `toArray()`/`fromArray()` have no `Agent`-typed caller in `src/`
     * today and are documented as the seam to be wired later, which is exactly
     * why the hole is closed before it is used: the day it is wired is the day
     * nobody re-reads that file.
     *
     * A frame this process wrote cannot even express the escape, because
     * `fromPreset()` forced the mode to Default before `toArray()` ran. What
     * this refuses is a frame edited by hand, or written by something else.
     */
    public function testAForeignFrameCannotRestoreARaisedPermissionMode(): void
    {
        foreach (['claude', 'opencode', 'spec'] as $foreign) {
            $restored = Agent::fromArray([
                'name' => 'n',
                'description' => 'd',
                'permission_mode' => PermissionMode::BypassPermissions->value,
                'source' => $foreign,
            ]);

            $this->assertSame(
                PermissionMode::Default,
                $restored->permissionMode,
                "a hand-edited {$foreign} frame must not restore a raised mode",
            );
            $this->assertSame(SkillSource::from($foreign), $restored->source, 'the provenance itself still restores');
        }

        // The native half, so the gate cannot be "always Default" by accident.
        $this->assertSame(
            PermissionMode::BypassPermissions,
            Agent::fromArray([
                'name' => 'n',
                'description' => 'd',
                'permission_mode' => PermissionMode::BypassPermissions->value,
                'source' => 'native',
            ])->permissionMode,
        );

        // And the real round trip is lossless for a native preset, which is
        // what the seam exists to do.
        $native = Agent::fromPreset(
            new AgentPreset(
                name: 'n',
                description: 'd',
                permissionMode: PermissionMode::BypassPermissions,
                source: SkillSource::Native,
            ),
            'anthropic',
            'm',
        );
        $this->assertSame($native->permissionMode, Agent::fromArray($native->toArray())->permissionMode);
    }

    /**
     * A frame written before the widening carries none of the ten keys. It
     * must decode to the defaults, not throw — the same tolerance the nine
     * original keys already had.
     */
    public function testFromArrayToleratesAPreWideningFrame(): void
    {
        $agent = Agent::fromArray([
            'name' => 'old',
            'description' => 'written by an older build',
            'prompt' => 'p',
            'model' => 'm',
            'provider' => 'anthropic',
            'tools' => [],
            'skills' => [],
            'hooks' => [],
            'is_active' => false,
        ]);

        $this->assertSame(PermissionMode::Default, $agent->permissionMode);
        $this->assertNull($agent->maxTurns);
        $this->assertNull($agent->isolation);
        $this->assertSame(MemoryScope::User, $agent->memory);
        $this->assertSame(Effort::Medium, $agent->effort);
        $this->assertSame(SkillSource::Native, $agent->source);
    }

    /**
     * An unknown enum value costs THAT field its stored value and nothing
     * else — the alternative, a ValueError out of `from()`, would cost the
     * whole agent.
     */
    public function testFromArrayFallsBackFieldWiseOnAnUnknownEnumValue(): void
    {
        $agent = Agent::fromArray([
            'name' => 'weird',
            'permission_mode' => 'not-a-mode',
            'effort' => 'not-an-effort',
            'isolation' => 'not-an-isolation',
            'memory' => 'not-a-scope',
            'source' => 'not-a-source',
            'max_turns' => 4,
        ]);

        $this->assertSame('weird', $agent->name);
        $this->assertSame(4, $agent->maxTurns, 'a bad enum must not cost a good neighbour');
        $this->assertSame(PermissionMode::Default, $agent->permissionMode);
        $this->assertSame(Effort::Medium, $agent->effort);
        $this->assertNull($agent->isolation);
        $this->assertSame(MemoryScope::User, $agent->memory);
        $this->assertSame(SkillSource::Native, $agent->source);
    }

    /**
     * `fromDefinition()` builds from an {@see \SugarCraft\Crush\Agents\AgentDefinition}
     * template, which HAS no preset fields. It must land on the defaults
     * rather than acquiring anything from this change.
     */
    public function testFromDefinitionIsUnaffectedByTheWidening(): void
    {
        $definition = new \SugarCraft\Crush\Agents\AgentDefinition(
            type: 'coder',
            name: 'coder',
            description: 'writes code',
            prompt: 'p',
            defaultTools: ['Read'],
            defaultSkills: [],
        );

        $agent = Agent::fromDefinition($definition, 'anthropic', 'm');

        $this->assertSame([], $agent->disallowedTools);
        $this->assertSame(PermissionMode::Default, $agent->permissionMode);
        $this->assertNull($agent->maxTurns);
        $this->assertSame(SkillSource::Native, $agent->source);
    }

    private function fullPreset(): AgentPreset
    {
        return new AgentPreset(
            name: 'reviewer',
            description: 'Reviews diffs',
            tools: ['Read', 'Grep'],
            disallowedTools: ['Bash', 'Write'],
            model: 'preset-model',
            permissionMode: PermissionMode::Plan,
            maxTurns: 7,
            skills: ['php-pro'],
            mcpServers: ['fs' => ['command' => 'mcp-fs']],
            memory: MemoryScope::Project,
            background: true,
            effort: Effort::High,
            isolation: Isolation::Worktree,
            color: '#ff00ff',
            initialPrompt: 'You review.',
            source: SkillSource::Claude,
        );
    }
}
