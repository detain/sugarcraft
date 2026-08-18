<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentDefinition;
use SugarCraft\Crush\Skills\SkillLoader;

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
        $this->assertSame(['Read', 'Grep', 'Bash(git:*)'], $reviewer->defaultTools);
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
}
