<?php

declare(strict_types=1);

namespace SugarCraft\Code\Skills;

use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Context\EnvironmentBlock;

/**
 * Shared vocabulary of the `context: fork` execution path (C-EXT-2.c).
 *
 * Two channels run a forked skill and they must not disagree about WHAT
 * forks: the model-driven one — {@see \SugarCraft\Code\Tools\BuiltIn\SkillTool}
 * hands the composed body to
 * {@see \SugarCraft\Code\Tools\BuiltIn\TaskTool::runForkedSkill()}, which
 * delegates through the turn's bound Task, so engine, permission relay,
 * activity emitter and spend accounting all ride the Task path — and the
 * embedder one — {@see \SugarCraft\Code\App\App::dispatchSkill()}, which
 * builds the {@see Agent} here and runs it through
 * {@see \SugarCraft\Code\Agents\AgentWorkerPool::executeOne()}.
 *
 * `agentName()` is the single site that decides WHICH roster preset a fork
 * runs as: the skill's `agent:` frontmatter when it names one, otherwise
 * {@see DEFAULT_AGENT}. The default is safe precisely because
 * `Bootstrap::agentRoster()` registers the six built-in
 * {@see \SugarCraft\Code\Agents\AgentDefinition} presets on every launch,
 * so `coder` is present whatever the user's preset files add or shadow; a
 * name the roster does not know is refused loudly by the Task path rather
 * than invented.
 *
 * `agent()` is the Agent construction the embedder seam performed inline:
 * name/description/body off the skill, the skill's declared model winning
 * over the session's, the environment block captured at the session root
 * and under that model — the two reasons established there are carried
 * verbatim below, because the shape being shared is the shape being right.
 */
final class SkillFork
{
    /**
     * The roster preset a `context: fork` skill runs as when its frontmatter
     * names no `agent:`. One of the six built-ins every launch registers.
     */
    public const DEFAULT_AGENT = 'coder';

    /**
     * Which roster agent runs `$skill`'s fork — the trimmed `agent:` value
     * when it is non-empty, {@see DEFAULT_AGENT} otherwise.
     */
    public static function agentName(Skill $skill): string
    {
        $named = trim($skill->agent);

        return $named !== '' ? $named : self::DEFAULT_AGENT;
    }

    /**
     * Build the {@see Agent} a forked run speaks as: the skill's identity,
     * body-as-prompt, declared model over `$fallbackModel`, and the declared
     * reasoning effort (null when it names none — see below).
     *
     * Attached BEFORE any SubAgent is built, because two different consumers
     * read the orientation back out and they must agree: the executor sends
     * the request's systemPrompt AND, separately, `$agent->systemPrompt()`.
     * Handing `$skill->content` straight to a request bypasses
     * {@see Agent::systemPrompt()} altogether, so a fork would run with no
     * cwd, no git state, no platform and no date.
     *
     * Captured here rather than left to `Agent::systemPrompt()`'s
     * last-resort `EnvironmentBlock::capture(getcwd(), ...)` for the reasons
     * `Bootstrap::agentManager()` already established for the agent roster:
     *
     *   - At `$root`, not the process directory. The block renders the
     *     "Working directory"/"Is directory a git repo" lines that orient
     *     the model, and on a `--root <dir>` run they have to name the
     *     directory the tools are jailed to. Null stays distinguishable from
     *     "explicitly rooted at getcwd()", mirroring
     *     {@see \SugarCraft\Code\Runtime::projectRoot()}.
     *   - At THIS agent's model, not the session's. `render()` writes a
     *     `Model:` line, and a skill may declare its own `model:` in
     *     frontmatter — one shared session-wide instance would stamp the
     *     session's model onto a fork running a different one.
     *   - Under the `env.*` settings (roadmap N-P4d), as the main session's
     *     block is.
     *
     * The effort rides the constructor (C-EXT-2.c): a skill's `effort:` now
     * selects the run's reasoning effort, and NULL IS NOT MEDIUM — an
     * undeclared skill sends nothing and lets the provider's own per-model
     * default stand, exactly as before this field was read.
     */
    public static function agent(Skill $skill, string $fallbackModel, string $provider, ?string $root): Agent
    {
        $agent = new Agent(
            name: $skill->name,
            description: $skill->description,
            prompt: $skill->content,
            model: $skill->model ?? $fallbackModel,
            provider: $provider,
            tools: [],
            skillNames: [$skill->name],
            hooks: [],
            isActive: true,
            effort: Effort::tryFrom(trim((string) $skill->effort)),
        );

        return $agent->withEnvironment(
            EnvironmentBlock::capture($root ?? (getcwd() ?: ''), $agent->model)->withSettings(),
        );
    }
}
