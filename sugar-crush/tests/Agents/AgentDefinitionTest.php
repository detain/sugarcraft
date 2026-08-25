<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentDefinition;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\ToolCall;

/**
 * Tests for AgentDefinition - factory for pre-configured agent types.
 */
final class AgentDefinitionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Factory methods
    // -------------------------------------------------------------------------

    public function testCoder(): void
    {
        // Act
        $coder = AgentDefinition::coder();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_CODER, $coder->type);
        $this->assertSame('coder', $coder->name);
        $this->assertSame('General coding assistant', $coder->description);
        $this->assertStringStartsWith('You are a coding assistant', $coder->prompt);
        $this->assertSame(['Read', 'Edit', 'Bash'], $coder->defaultTools);
        $this->assertSame([], $coder->defaultSkills);
    }

    public function testCoderWithCustomName(): void
    {
        // Act
        $coder = AgentDefinition::coder('my-coder');

        // Assert
        $this->assertSame('my-coder', $coder->name);
        $this->assertSame(AgentDefinition::TYPE_CODER, $coder->type);
    }

    public function testReviewer(): void
    {
        // Act
        $reviewer = AgentDefinition::reviewer();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_REVIEWER, $reviewer->type);
        $this->assertSame('reviewer', $reviewer->name);
        $this->assertSame('Code review specialist', $reviewer->description);
        $this->assertStringStartsWith('You are a code review specialist', $reviewer->prompt);
        $this->assertSame(['Read', 'Grep', 'Bash(git *)'], $reviewer->defaultTools);
        $this->assertSame(['php-best-practices', 'security-audit'], $reviewer->defaultSkills);
    }

    public function testReviewerHasSecurityAuditSkill(): void
    {
        // Act
        $reviewer = AgentDefinition::reviewer();

        // Assert
        $this->assertContains('security-audit', $reviewer->defaultSkills);
    }

    public function testDebugger(): void
    {
        // Act
        $debugger = AgentDefinition::debugger();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_DEBUGGER, $debugger->type);
        $this->assertSame('debugger', $debugger->name);
        $this->assertSame('Bug investigation and fixing', $debugger->description);
        $this->assertStringStartsWith('You are a debugging specialist', $debugger->prompt);
        $this->assertSame(['Read', 'Grep', 'Bash'], $debugger->defaultTools);
        $this->assertSame([], $debugger->defaultSkills);
    }

    public function testArchitect(): void
    {
        // Act
        $architect = AgentDefinition::architect();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_ARCHITECT, $architect->type);
        $this->assertSame('architect', $architect->name);
        $this->assertSame('System design and architecture', $architect->description);
        $this->assertStringStartsWith('You are a software architect', $architect->prompt);
        $this->assertSame(['Read', 'Grep', 'Glob'], $architect->defaultTools);
        $this->assertSame([], $architect->defaultSkills);
    }

    public function testTester(): void
    {
        // Act
        $tester = AgentDefinition::tester();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_TESTER, $tester->type);
        $this->assertSame('tester', $tester->name);
        $this->assertSame('Test writing and coverage', $tester->description);
        $this->assertStringStartsWith('You are a testing specialist', $tester->prompt);
        $this->assertSame(['Read', 'Bash'], $tester->defaultTools);
        $this->assertSame(['phpunit-master'], $tester->defaultSkills);
    }

    public function testDevops(): void
    {
        // Act
        $devops = AgentDefinition::devops();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_DEVOPS, $devops->type);
        $this->assertSame('devops', $devops->name);
        $this->assertSame('CI/CD and deployment', $devops->description);
        $this->assertStringStartsWith('You are a DevOps specialist', $devops->prompt);
        $this->assertSame(['Read', 'Bash', 'Glob'], $devops->defaultTools);
        $this->assertSame([], $devops->defaultSkills);
    }

    // -------------------------------------------------------------------------
    // fromType() - type-based factory
    // -------------------------------------------------------------------------

    public function testFromTypeCoder(): void
    {
        // Act
        $agent = AgentDefinition::fromType(AgentDefinition::TYPE_CODER, 'my-coder');

        // Assert
        $this->assertNotNull($agent);
        $this->assertSame(AgentDefinition::TYPE_CODER, $agent->type);
        $this->assertSame('my-coder', $agent->name);
    }

    public function testFromTypeUnknown(): void
    {
        // Act
        $agent = AgentDefinition::fromType('unknown-type', 'some-name');

        // Assert
        $this->assertNull($agent);
    }

    public function testFromTypeRoundTrip(): void
    {
        // Test that fromType produces the same result as the direct factory
        $types = [
            AgentDefinition::TYPE_CODER => AgentDefinition::TYPE_CODER,
            AgentDefinition::TYPE_REVIEWER => AgentDefinition::TYPE_REVIEWER,
            AgentDefinition::TYPE_DEBUGGER => AgentDefinition::TYPE_DEBUGGER,
            AgentDefinition::TYPE_ARCHITECT => AgentDefinition::TYPE_ARCHITECT,
            AgentDefinition::TYPE_TESTER => AgentDefinition::TYPE_TESTER,
            AgentDefinition::TYPE_DEVOPS => AgentDefinition::TYPE_DEVOPS,
        ];

        foreach ($types as $type) {
            // Act
            $fromType = AgentDefinition::fromType($type, 'round-trip-test');
            $directFactory = match ($type) {
                AgentDefinition::TYPE_CODER => AgentDefinition::coder('round-trip-test'),
                AgentDefinition::TYPE_REVIEWER => AgentDefinition::reviewer('round-trip-test'),
                AgentDefinition::TYPE_DEBUGGER => AgentDefinition::debugger('round-trip-test'),
                AgentDefinition::TYPE_ARCHITECT => AgentDefinition::architect('round-trip-test'),
                AgentDefinition::TYPE_TESTER => AgentDefinition::tester('round-trip-test'),
                AgentDefinition::TYPE_DEVOPS => AgentDefinition::devops('round-trip-test'),
            };

            // Assert
            $this->assertNotNull($fromType);
            $this->assertSame($directFactory->type, $fromType->type, "Type mismatch for $type");
            $this->assertSame($directFactory->name, $fromType->name, "Name mismatch for $type");
            $this->assertSame($directFactory->description, $fromType->description, "Description mismatch for $type");
            $this->assertSame($directFactory->prompt, $fromType->prompt, "Prompt mismatch for $type");
            $this->assertSame($directFactory->defaultTools, $fromType->defaultTools, "Tools mismatch for $type");
            $this->assertSame($directFactory->defaultSkills, $fromType->defaultSkills, "Skills mismatch for $type");
        }
    }

    public function testAllTypesHaveFromType(): void
    {
        // Ensure all type constants are handled by fromType
        $allTypes = [
            AgentDefinition::TYPE_CODER,
            AgentDefinition::TYPE_REVIEWER,
            AgentDefinition::TYPE_DEBUGGER,
            AgentDefinition::TYPE_ARCHITECT,
            AgentDefinition::TYPE_TESTER,
            AgentDefinition::TYPE_DEVOPS,
        ];

        foreach ($allTypes as $type) {
            $agent = AgentDefinition::fromType($type, 'test-agent');
            $this->assertNotNull($agent, "fromType should handle type: $type");
        }
    }

    // -------------------------------------------------------------------------
    // Preset prompt quality (crush_code.md Phase 5 item 10 / section 12
    // finding 3). These assert PROPERTIES, not wording: the prose will be
    // edited again, and a test pinning an exact paragraph would be rewritten
    // with it instead of catching anything.
    // -------------------------------------------------------------------------

    /**
     * Every preset type this class can build, discovered from its own TYPE_*
     * constants rather than listed by hand.
     *
     * Enumerating them here is the whole point: a preset added later is
     * covered by the invariants below the moment its constant exists, instead
     * of the day someone remembers to extend a literal array. The count is
     * deliberately NOT asserted anywhere — it is whatever the constants say.
     *
     * @return \Generator<string, array{AgentDefinition}>
     */
    public static function everyPreset(): \Generator
    {
        foreach ((new \ReflectionClass(AgentDefinition::class))->getConstants() as $name => $type) {
            if (!str_starts_with($name, 'TYPE_')) {
                continue;
            }

            $definition = AgentDefinition::fromType((string) $type, (string) $type);
            self::assertNotNull($definition, "fromType() cannot build {$name}");

            yield (string) $type => [$definition];
        }
    }

    /**
     * A preset is handed its skills SILENTLY — nothing in the launch path
     * tells the sub-agent that `php-best-practices` is available to it — so a
     * prompt that never names a granted skill leaves the model to infer the
     * connection from a separate listing block, or not at all.
     *
     * Presets with no granted skills pass trivially, which is correct: there
     * is nothing for them to name.
     *
     * @dataProvider everyPreset
     */
    public function testEveryPresetNamesEverySkillItIsGranted(AgentDefinition $definition): void
    {
        // ONE always-executed assertion rather than a loop: a preset with no
        // granted skills is a legitimate pass, and a loop over an empty list
        // asserts nothing at all — which phpunit.xml's failOnRisky correctly
        // reports as a test with no power.
        $prompt = $definition->prompt;
        $unnamed = array_values(array_filter(
            $definition->defaultSkills,
            static fn (string $skill): bool => !str_contains($prompt, $skill),
        ));

        $this->assertSame(
            [],
            $unnamed,
            sprintf(
                'preset "%s" is granted skill(s) its prompt never names: %s',
                $definition->type,
                implode(', ', $unnamed),
            ),
        );
    }

    /**
     * The finding this closes is that every preset was ONE generic sentence:
     * an identity with no method and no statement of what to produce. Two
     * sentences is a floor, not a target — it is the cheapest structural proxy
     * for "says more than who you are" that does not pin any wording.
     *
     * @dataProvider everyPreset
     */
    public function testEveryPresetPromptSaysMoreThanItsIdentity(AgentDefinition $definition): void
    {
        $sentences = preg_match_all('/[.!?](?:\s|$)/', $definition->prompt);

        $this->assertGreaterThanOrEqual(
            2,
            $sentences,
            sprintf(
                'preset "%s" prompt is %d sentence(s); it needs a method and an output shape, '
                . 'not just an identity clause',
                $definition->type,
                $sentences,
            ),
        );
    }

    /**
     * The other direction, which nothing held: a preset's prompt must name ONLY
     * skills it is granted.
     *
     * The invariant above catches a granted skill the prompt forgets. It says
     * nothing about the reverse, and the reverse is the worse failure — a prompt
     * that tells a sub-agent to "consult the php-best-practices skill" it was
     * never handed sends the model looking for guidance that is not in its
     * context, and the honest response to that is either a fabrication or a
     * wasted turn. The shipped six are clean in both directions today; this is
     * what keeps them there.
     *
     * The universe of skill names is READ OFF {@see SkillLoader::loadBuiltInSkills()}
     * rather than listed here, so a skill added under `src/Skills/BuiltIn/` is
     * covered the moment it exists. A name that is not a real skill at all is a
     * different bug and is not this test's business — it cannot be a grant
     * violation, since it cannot be granted.
     *
     * @dataProvider everyPreset
     */
    public function testNoPresetPromptNamesASkillItIsNotGranted(AgentDefinition $definition): void
    {
        $everySkill = array_keys((new SkillLoader())->loadBuiltInSkills());
        $this->assertNotEmpty($everySkill, 'no built-in skills were discovered to check against');

        $prompt = $definition->prompt;
        $granted = $definition->defaultSkills;

        $ungranted = array_values(array_filter(
            $everySkill,
            static fn (string $skill): bool => str_contains($prompt, $skill)
                && !in_array($skill, $granted, true),
        ));

        $this->assertSame(
            [],
            $ungranted,
            sprintf(
                'preset "%s" names skill(s) it is NOT granted: %s. Its grant is [%s]. Either '
                . 'add them to defaultSkills or stop naming them — a prompt that points at a '
                . 'skill the sub-agent does not have is worse than one that points at nothing.',
                $definition->type,
                implode(', ', $ungranted),
                implode(', ', $granted) ?: 'none',
            ),
        );
    }

    /**
     * Six presets sharing a prompt would satisfy both invariants above while
     * being exactly the undifferentiated set the finding was about.
     */
    public function testEveryPresetPromptIsDistinct(): void
    {
        $prompts = [];
        foreach (self::everyPreset() as $type => [$definition]) {
            $prompts[(string) $type] = $definition->prompt;
        }

        $this->assertSameSize(
            array_unique($prompts),
            $prompts,
            'two presets share a prompt: ' . implode(', ', array_keys($prompts)),
        );
    }

    // -------------------------------------------------------------------------
    // Tool GRANTS. A preset's defaultTools are not decoration any more: they
    // are resolved into the roster a sub-agent's provider receives, and
    // enforced per call, by AgentManager. A declaration that matches nothing is
    // therefore a live defect rather than dead prose.
    // -------------------------------------------------------------------------

    /**
     * One call per argument-scoped declaration any preset spells, proving the
     * grant admits something real.
     *
     * KEYED BY THE WHOLE DECLARATION, and a declaration with no entry here is a
     * FAILURE rather than a skip — see {@see declarationDefect()}. That is the
     * only shape in which this guard covers a grant added after it was written:
     * a silent skip would let the next `Bash(git:*)` through exactly as the
     * first one got through.
     *
     * @var array<string, array{string, array<string, mixed>}>
     */
    private const GRANT_PROBES = [
        'Bash(git *)' => ['Bash', ['command' => 'git status']],
    ];

    /**
     * Why this declaration is unusable, or null when it is fine.
     *
     * Extracted so the presets and the KNOWN-POSITIVE fixtures below go through
     * the SAME code. An assertion that every preset is clean is worth nothing
     * on its own: a scanner mutated to always answer "clean" would pass it, and
     * this project has measured exactly that outcome. The fixtures are what
     * prove the instrument is alive.
     *
     * @param array<string, array{string, array<string, mixed>}> $probes
     */
    private static function declarationDefect(string $declaration, array $probes): ?string
    {
        $reason = PermissionRule::patternRejectionReason($declaration);
        if ($reason !== null) {
            return "is malformed: it {$reason}";
        }

        $rule = new PermissionRule($declaration, PermissionAction::Allow);
        if ($rule->argumentPattern() === null) {
            // A bare name. Nothing to probe: the name half is matched against
            // the live registry by AgentManager, not against a fixture here.
            return null;
        }

        if (!array_key_exists($declaration, $probes)) {
            return 'is argument-scoped but has no probe in GRANT_PROBES, so nothing here '
                . 'knows whether it can match any real call at all';
        }

        [$toolName, $arguments] = $probes[$declaration];
        if (!$rule->matches(new ToolCall($toolName, $arguments))) {
            return sprintf(
                'matches nothing: its own probe %s(%s) is refused by it. A well-formed grant '
                . 'that can never fire is the defect PermissionRule was rewritten to make '
                . 'impossible — check the dialect (this project globs with fnmatch(), so '
                . 'Claude Code\'s `git:*` prefix form is matched literally and never fires)',
                $toolName,
                json_encode($arguments),
            );
        }

        return null;
    }

    /**
     * ITS KEEPER IS testDeclarationDefectDetectsTheDefectsItClaimsTo, AND THEY
     * MUST NOT BE SEPARATED.
     *
     * This method asserts an ABSENCE — `assertSame([], $defects)` — and on its
     * own that is worth nothing. MEASURED: with declarationDefect() replaced by
     * `return null;`, running this test under `--filter` is GREEN (6 tests, 6
     * assertions). The mutation is caught only because the fixture test in this
     * same file always runs alongside it and pushes known-positive declarations
     * through the same helper. Moving either one to another file, or gating one
     * behind a skip, silently disarms this guard.
     *
     * @dataProvider everyPreset
     */
    public function testEveryPresetToolGrantCanActuallyFire(AgentDefinition $definition): void
    {
        $defects = [];
        foreach ($definition->defaultTools as $declaration) {
            $defect = self::declarationDefect((string) $declaration, self::GRANT_PROBES);
            if ($defect !== null) {
                $defects[] = "\"{$declaration}\" {$defect}";
            }
        }

        $this->assertSame(
            [],
            $defects,
            sprintf(
                "preset \"%s\" declares a tool grant that cannot work:\n  %s",
                $definition->type,
                implode("\n  ", $defects),
            ),
        );
    }

    /**
     * The instrument above, run against declarations whose answers are already
     * known — including the exact one this guard was written for.
     *
     * `Bash(git:*)` is what `reviewer()` shipped before this round. It is
     * WELL-FORMED, so a well-formedness check alone would have blessed it, and
     * it matches no git command on this project's fnmatch dialect. It is spelled
     * by concatenation so a future sweep over the literal cannot eat the one
     * fixture that documents it.
     */
    public function testDeclarationDefectDetectsTheDefectsItClaimsTo(): void
    {
        $foreignDialect = 'Bash(git' . ':*)';
        $probe = ['Bash', ['command' => 'git status']];

        $this->assertTrue(
            PermissionRule::isWellFormedPattern($foreignDialect),
            'the fixture is only interesting because well-formedness does NOT catch it',
        );

        $defect = self::declarationDefect($foreignDialect, [$foreignDialect => $probe]);
        $this->assertNotNull($defect, 'the unmatchable-grant check is dead');
        $this->assertStringContainsString('matches nothing', $defect);

        $this->assertSame(
            'is argument-scoped but has no probe in GRANT_PROBES, so nothing here '
                . 'knows whether it can match any real call at all',
            self::declarationDefect('Bash(git *)', []),
            'a declaration with no probe must FAIL, never be skipped',
        );

        $missingClose = self::declarationDefect('Bash(git *', []);
        $this->assertNotNull($missingClose);
        $this->assertStringStartsWith('is malformed', $missingClose);

        // And the negative half: the spelling the preset now ships is clean
        // through the same call, so the instrument is not simply pessimistic.
        $this->assertNull(self::declarationDefect('Bash(git *)', self::GRANT_PROBES));
        $this->assertNull(self::declarationDefect('Read', self::GRANT_PROBES));
    }

    /**
     * THE HALF {@see declarationDefect()} DECLINES: a typo'd BARE name.
     *
     * That method answers `null` for any declaration with no argument half, on
     * the stated grounds that "the name half is matched against the live
     * registry by AgentManager, not against a fixture here". True — but
     * AgentManager only does that when a caller hands it a tool registry, and
     * MEASURED at this commit no production caller does. So `defaultTools:
     * ['Reed']` in a preset is caught by NOTHING: not by well-formedness (it is
     * well-formed), not by the probe guard (it is bare), and not at runtime.
     * That is the same "well-formed and resolves to nothing" class this round
     * was about, in the half the probe guard declines to cover.
     *
     * AGAINST AN UNNARROWED CEILING, DELIBERATELY, and this is not a detail.
     * {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} returns
     * `filterToolSet($tools)` — already reduced by the operator's own
     * `allowedTools`/`disabledTools` — so run against a developer's real config
     * this guard would red on a correct preset the moment they disabled a tool.
     * HOME is redirected to an empty sandbox and the emptiness of the merged
     * config is ASSERTED rather than assumed: if the sandbox ever stops
     * working, this reds on the config rather than lying about the presets.
     */
    public function testEveryBarePresetToolNameResolvesAgainstTheShippedToolSet(): void
    {
        $sandbox = sys_get_temp_dir() . '/sc_r60b_barename_' . bin2hex(random_bytes(6));
        mkdir($sandbox . '/home', 0700, true);
        $home = getenv('HOME');
        putenv('HOME=' . $sandbox . '/home');

        try {
            $config = \SugarCraft\Crush\Cli\Bootstrap::readUserConfig();
            $this->assertArrayNotHasKey('allowedTools', $config, 'the sandbox is not empty; this guard would measure a narrowed set');
            $this->assertArrayNotHasKey('disabledTools', $config, 'the sandbox is not empty; this guard would measure a narrowed set');

            $ceiling = array_map(
                static fn(\SugarCraft\Crush\Tools\Tool $t): string => $t->name(),
                \SugarCraft\Crush\Cli\Bootstrap::tools($sandbox),
            );

            // INSTRUMENT ALIVE. An empty ceiling would make every `assertTrue`
            // below vacuous in the wrong direction and every `assertFalse`
            // vacuously true, so the roster is checked for content first.
            $this->assertNotEmpty($ceiling, 'the tool ceiling is empty; nothing below means anything');
            $this->assertContains('Read', $ceiling);

            $resolves = static fn(string $declaration): bool => array_reduce(
                $ceiling,
                static fn(bool $carry, string $name): bool => $carry
                    || PermissionRule::matchesToolName(
                        (new PermissionRule($declaration, PermissionAction::Allow))->toolNamePattern(),
                        $name,
                    ),
                false,
            );

            // KNOWN POSITIVE, in the same test and through the same closure:
            // a name that is NOT shipped must fail to resolve. Without this the
            // assertions below would pass against a matcher mutated to answer
            // true unconditionally.
            $this->assertFalse($resolves('Reed'), 'the resolver is dead: a typo resolved');
            $this->assertTrue($resolves('Read'), 'the resolver is dead: a real tool did not resolve');

            $unresolvable = [];
            foreach (self::everyPreset() as $type => [$definition]) {
                foreach ($definition->defaultTools as $declaration) {
                    $rule = new PermissionRule((string) $declaration, PermissionAction::Allow);
                    if ($rule->argumentPattern() !== null) {
                        continue; // covered by testEveryPresetToolGrantCanActuallyFire
                    }

                    if (!$resolves((string) $declaration)) {
                        $unresolvable[] = "{$type}: \"{$declaration}\"";
                    }
                }
            }

            $this->assertSame(
                [],
                $unresolvable,
                "a preset declares a bare tool name this project does not ship:\n  "
                    . implode("\n  ", $unresolvable),
            );
        } finally {
            if ($home === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $home);
            }
            exec('rm -rf ' . escapeshellarg($sandbox));
        }
    }

    /**
     * The grant's ARGUMENT half is a real restriction, not a label.
     *
     * Pinned here rather than only in AgentManagerTest because it is the
     * PRESET's claim: `reviewer` says it wants git, and `Allow`'s intersection
     * over `[;&|\r\n]+` segments is what makes that mean something when a model
     * chains a second command onto the first.
     */
    public function testReviewerGitGrantAdmitsGitAndRefusesTheChainedEscape(): void
    {
        $rule = new PermissionRule('Bash(git *)', PermissionAction::Allow);

        $this->assertTrue($rule->matches(new ToolCall('Bash', ['command' => 'git diff --stat'])));
        $this->assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'rm -rf /'])));
        $this->assertFalse(
            $rule->matches(new ToolCall('Bash', ['command' => 'git log && rm -rf /'])),
            'a chained escape must not ride in on the first segment',
        );
        $this->assertFalse(
            $rule->matches(new ToolCall('Bash', ['command' => "git log\nrm -rf /"])),
            'a newline separates two commands exactly as && does',
        );
        $this->assertContains('Bash(git *)', AgentDefinition::reviewer()->defaultTools);
    }
}
