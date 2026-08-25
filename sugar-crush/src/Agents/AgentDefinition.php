<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

final readonly class AgentDefinition
{
    public const TYPE_CODER = 'coder';
    public const TYPE_REVIEWER = 'reviewer';
    public const TYPE_DEBUGGER = 'debugger';
    public const TYPE_ARCHITECT = 'architect';
    public const TYPE_TESTER = 'tester';
    public const TYPE_DEVOPS = 'devops';

    /**
     * $prompt carries the preset's METHOD, not just its identity.
     *
     * Where $defaultSkills is non-empty the prompt names each granted skill,
     * because a preset is handed its skills silently: nothing in the launch
     * path tells the sub-agent that `php-best-practices` is available to it,
     * so a prompt that does not name the skill leaves the model to infer the
     * connection from a separate listing block — which is exactly the
     * mechanism-exists-but-is-never-reached failure crush_code.md section 12
     * finding 3 is about. `AgentDefinitionTest` asserts the invariant over
     * every preset {@see fromType()} can build, so a preset added later
     * without naming its skills fails rather than shipping.
     */
    public function __construct(
        public string $type,
        public string $name,
        public string $description,
        public string $prompt,
        public array $defaultTools,
        public array $defaultSkills,
    ) {}

    public static function coder(string $name = 'coder'): self
    {
        return new self(
            type: self::TYPE_CODER,
            name: $name,
            description: 'General coding assistant',
            prompt: 'You are a coding assistant focused on implementation. Make the smallest change '
                . 'that correctly satisfies the task, and match the conventions already in the '
                . 'surrounding code rather than introducing your own. Finish with a short summary '
                . 'naming every file you changed and calling out anything that alters a public API '
                . 'or an observable behaviour.',
            defaultTools: ['Read', 'Edit', 'Bash'],
            defaultSkills: [],
        );
    }

    public static function reviewer(string $name = 'reviewer'): self
    {
        return new self(
            type: self::TYPE_REVIEWER,
            name: $name,
            description: 'Code review specialist',
            prompt: 'You are a code review specialist. Review the diff or the files you are given '
                . 'for correctness bugs, security issues, and violations of this project\'s own '
                . 'conventions — consult the php-best-practices and security-audit skills you have '
                . 'been granted rather than relying on general knowledge alone. Report findings '
                . 'grouped by severity, blocking before suggestion, each one naming the file and '
                . 'line it is about. Do not rewrite the code yourself unless you are asked to.',
            // `git *`, NOT `git:*`, AND THE COLON WAS NOT A TYPO — it was
            // Claude Code's dialect, in which `Bash(git:*)` means "any command
            // whose first word is git". This project's dialect is
            // {@see \SugarCraft\Crush\Permissions\PermissionRule}'s, whose
            // argument half is an `fnmatch()` glob over the command string, so
            // the colon was matched LITERALLY. MEASURED on PHP 8.3.6:
            // `(new PermissionRule('Bash(git:*)', Allow))->matches(new
            // ToolCall('Bash', ['command' => 'git status']))` is FALSE, and so
            // is every other real git command — the grant was well-formed and
            // unmatchable, which is precisely the defect PermissionRule was
            // rewritten to make impossible for a user's config and which had
            // survived here in a preset. `AgentDefinitionTest` now refuses any
            // argument-scoped declaration in any preset that matches nothing.
            //
            // The `Allow` arm is an INTERSECTION over `[;&|\r\n]+` segments, so
            // this admits `git status` and refuses `git log && rm -rf /`. That
            // is enforced per call by {@see AgentManager::refuseCallOutsideGrant()};
            // the roster {@see AgentManager::resolveGrantedTools()} sends can
            // only carry the NAME half, because a tool schema has no field for
            // "git commands only".
            defaultTools: ['Read', 'Grep', 'Bash(git *)'],
            defaultSkills: ['php-best-practices', 'security-audit'],
        );
    }

    public static function debugger(string $name = 'debugger'): self
    {
        return new self(
            type: self::TYPE_DEBUGGER,
            name: $name,
            description: 'Bug investigation and fixing',
            prompt: 'You are a debugging specialist. Work from evidence, not from guesses: '
                . 'reproduce the failure first, narrow it to the smallest input that still shows '
                . 'it, and read the code on that path before proposing anything. Report the root '
                . 'cause, the probe that proves it, and the smallest fix — and say plainly when '
                . 'the evidence does not settle the question instead of offering a plausible '
                . 'story.',
            defaultTools: ['Read', 'Grep', 'Bash'],
            defaultSkills: [],
        );
    }

    public static function architect(string $name = 'architect'): self
    {
        return new self(
            type: self::TYPE_ARCHITECT,
            name: $name,
            description: 'System design and architecture',
            // The last clause states this preset's METHOD, the way the other
            // five do, and it still must not assert a tool grant. The reason
            // has changed, so the reason is rewritten rather than deleted.
            //
            // WHAT THIS SAID: that $defaultTools "is not something the field
            // can make true", because it reached Agent::$tools and was
            // thereafter only copied and serialised, with
            // AgentManager::executeSubAgent() building its CompleteRequest
            // with no `tools` field at all.
            //
            // WHAT IS TRUE NOW: half of that is fixed. executeSubAgent() does
            // pass `tools:`, resolved from this very field by
            // {@see AgentManager::resolveGrantedTools()}, and a call outside
            // the grant is refused by
            // {@see AgentManager::refuseCallOutsideGrant()}. So the field CAN
            // make a roster true — but only for a caller that hands
            // AgentManager a tool registry, and the production construction
            // site ({@see \SugarCraft\Crush\Cli\Bootstrap::agentManager()})
            // does not yet, so a launched sub-agent still reaches its provider
            // with `tools: null`.
            //
            // WHY THIS STILL EARNS ITS PLACE: a prompt that asserts "you have
            // read-only tools" would be false on exactly the path that runs
            // today, and would go on being false silently — the failure this
            // whole item exists to end. The clause may be restored when the
            // registry reaches AgentManager on the launch path, and not before.
            prompt: 'You are a software architect. Read enough of the existing code to describe '
                . 'the design that is actually there before proposing a different one. Offer at '
                . 'least two options with their trade-offs, recommend one, and state what would '
                . 'have to be true for the recommendation to be wrong. Produce a design, not an '
                . 'implementation: describe the change precisely enough that someone else could '
                . 'make it, and leave the editing to them.',
            defaultTools: ['Read', 'Grep', 'Glob'],
            defaultSkills: [],
        );
    }

    public static function tester(string $name = 'tester'): self
    {
        return new self(
            type: self::TYPE_TESTER,
            name: $name,
            description: 'Test writing and coverage',
            prompt: 'You are a testing specialist. Follow the phpunit-master skill you have been '
                . 'granted for this project\'s test conventions. Every test you write must fail '
                . 'when the behaviour it covers is broken — prove that, and never weaken an '
                . 'assertion to make a suite pass. Assert the property rather than an incidental '
                . 'literal, and finish by reporting the tests you added and the run that showed '
                . 'them green.',
            defaultTools: ['Read', 'Bash'],
            defaultSkills: ['phpunit-master'],
        );
    }

    public static function devops(string $name = 'devops'): self
    {
        return new self(
            type: self::TYPE_DEVOPS,
            name: $name,
            description: 'CI/CD and deployment',
            prompt: 'You are a DevOps specialist working on CI/CD, deployment and infrastructure. '
                . 'Read the workflow, manifest or script you are changing in full before editing '
                . 'it — a pipeline edit is not observable locally, so being sure beforehand is the '
                . 'only check available. Prefer a change that fails loudly over one that degrades '
                . 'silently, and report what you changed together with how it can be verified.',
            defaultTools: ['Read', 'Bash', 'Glob'],
            defaultSkills: [],
        );
    }

    public static function fromType(string $type, string $name): ?self
    {
        return match ($type) {
            self::TYPE_CODER => self::coder($name),
            self::TYPE_REVIEWER => self::reviewer($name),
            self::TYPE_DEBUGGER => self::debugger($name),
            self::TYPE_ARCHITECT => self::architect($name),
            self::TYPE_TESTER => self::tester($name),
            self::TYPE_DEVOPS => self::devops($name),
            default => null,
        };
    }
}
