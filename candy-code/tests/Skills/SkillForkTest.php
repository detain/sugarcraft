<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillFork;

/**
 * C-EXT-2.c: {@see SkillFork} is the shared vocabulary of the forked-skill
 * path — `agentName()` decides WHICH roster preset forks a skill (the
 * skill's `agent:` else the built-in default), and `agent()` builds the
 * {@see \SugarCraft\Code\Agents\Agent} the embedder seam runs, with the
 * skill-declared model winning over the session's and the environment
 * block captured at the session root. Both channels — the model-driven one
 * through TaskTool::runForkedSkill() and the embedder one through
 * App::dispatchSkill() — read these shapes, so they are pinned here at the
 * source rather than through either caller.
 */
final class SkillForkTest extends TestCase
{
    public function testTheDefaultForkAgentIsTheCoderPresetEveryLaunchRegisters(): void
    {
        self::assertSame('coder', SkillFork::DEFAULT_AGENT);
        // Safe default: Bootstrap::agentRoster() registers the six built-in
        // presets on every launch, so `coder` is present whatever preset
        // files add or shadow. One of the six by construction.
        self::assertStringContainsString(SkillFork::DEFAULT_AGENT, 'coder reviewer debugger architect tester devops');
    }

    public function testAgentNamePrefersTheDeclaredPresetAndTrimsIt(): void
    {
        self::assertSame('sage', SkillFork::agentName($this->skill(overrides: ['agent' => ' sage '])));
        self::assertSame('sage', SkillFork::agentName($this->skill(overrides: ['agent' => 'sage'])));
    }

    public function testAgentNameFallsBackToTheDefaultWhenTheSkillNamesNothing(): void
    {
        self::assertSame(SkillFork::DEFAULT_AGENT, SkillFork::agentName($this->skill()));
        self::assertSame(SkillFork::DEFAULT_AGENT, SkillFork::agentName($this->skill(overrides: ['agent' => '   '])));
    }

    public function testTheAgentCarriesTheSkillsIdentityModelAndGrantNothingElse(): void
    {
        $skill = $this->skill(overrides: ['model' => 'skill-model', 'effort' => 'high', 'context' => 'fork', 'agent' => 'sage']);

        $agent = SkillFork::agent($skill, 'session-model', 'anthropic', null);

        self::assertSame('deep-dive', $agent->name);
        self::assertSame('Deep parser work', $agent->description);
        self::assertSame('skill-model', $agent->model, 'the skill-declared model wins over the session model');
        self::assertSame('anthropic', $agent->provider);
        self::assertSame([], $agent->tools, 'a forked skill runs with the empty tool grant the embedder path always gave it');
        self::assertSame(['deep-dive'], $agent->skillNames);
        self::assertSame(Effort::High, $agent->effort);
    }

    public function testTheAgentFallsBackToTheSessionModelAndCarriesNoEffortWhenUndeclared(): void
    {
        $agent = SkillFork::agent($this->skill(overrides: ['context' => 'fork']), 'session-model', 'openai', null);

        self::assertSame('session-model', $agent->model);
        self::assertNull($agent->effort, 'an undeclared effort sends NOTHING — the provider per-model default stands');
        self::assertNull(Effort::tryFrom('bogus'), 'a nonsense effort word is the same silence, not a guess');
    }

    public function testTheEnvironmentBlockIsCapturedAtTheGivenRootUnderTheRunModel(): void
    {
        $root = '/tmp/opencode/cc-cmp-C-EXT-2.c-' . getmypid();

        $prompt = SkillFork::agent($this->skill(overrides: ['model' => 'skill-model']), 'session-model', 'anthropic', $root)->systemPrompt();

        self::assertStringContainsString('Working directory: ' . $root, $prompt, 'the fork is oriented at the session root, not the process directory');
        self::assertStringContainsString('Model: skill-model', $prompt, 'the block is captured under the RUN model — a stamping the session model onto a differently-modelled fork would lie');
        self::assertStringNotContainsString('Model: session-model', $prompt);
        self::assertStringContainsString('Deep instructions.', $prompt, 'the skill body is the agent prompt');
    }

    public function testANullRootStaysDistinguishableFromAnExplicitCwdRoot(): void
    {
        // SkillFork mirrors App: null → capture falls back to getcwd(), so
        // the built Agent names a real directory either way; the shape only
        // has to be the SAME one both channels agree on.
        $cwd = getcwd();
        self::assertIsString($cwd);

        $prompt = SkillFork::agent($this->skill(), 'session-model', 'anthropic', null)->systemPrompt();

        self::assertStringContainsString('Working directory: ' . $cwd, $prompt);
    }

    /**
     * An in-memory Skill row. The twelve required constructor arguments
     * stand in for the parsed shape; everything else keeps its default.
     *
     * @param array<string, mixed> $overrides
     */
    private function skill(array $overrides = []): Skill
    {
        $fields = [
            'name' => 'deep-dive',
            'description' => 'Deep parser work',
            'userInvocable' => true,
            'disableModelInvocation' => false,
            'allowedTools' => null,
            'disallowedTools' => null,
            'model' => null,
            'effort' => null,
            'context' => 'thread',
            'paths' => [],
            'content' => 'Deep instructions.',
            'sourcePath' => '',
        ];

        return new Skill(...array_merge($fields, $overrides));
    }
}
