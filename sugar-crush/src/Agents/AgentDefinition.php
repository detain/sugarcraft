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
            defaultTools: ['Read', 'Grep', 'Bash(git:*)'],
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
            // five do. It used to assert a tool grant ("You have read-only
            // tools"), which is not something $defaultTools can make true:
            // the field reaches Agent::$tools and is thereafter only copied
            // and serialised, and AgentManager::executeSubAgent() builds its
            // CompleteRequest with no `tools` field at all. See §C7 of
            // docs/plans/crush_code_hardening_backlog.md — the seam is to be
            // wired, and until it is, a prompt must not describe the roster
            // it would produce.
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
