<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tests\Support\RosterAgent;

/**
 * {@see AgentManager::grantedToolsFor()} and {@see AgentManager::systemPromptFor()}:
 * the per-sub-agent grant and prompt the Task tool's in-process engine path
 * runs under. They must resolve EXACTLY as a pooled batch member does, or the
 * in-process path would be the lax one.
 */
final class AgentManagerDelegationAccessorsTest extends TestCase
{
    public function testTheGrantIsNarrowedToThePresetsDeclaration(): void
    {
        $manager = $this->manager([new Read(), new Grep()]);
        $manager->register(RosterAgent::named('coder', tools: ['Read']));

        $granted = $manager->grantedToolsFor($manager->createSubAgent('coder', 'task'));

        $this->assertSame(['Read'], array_map(static fn ($tool): string => $tool->name(), $granted ?? []));
    }

    public function testNoRegistryMeansNoNarrowingRatherThanNoTools(): void
    {
        $manager = $this->manager(null);
        $manager->register(RosterAgent::named('coder', tools: ['Read']));

        $this->assertNull($manager->grantedToolsFor($manager->createSubAgent('coder', 'task')));
    }

    public function testAGrantThatDoesNotResolveIsRefused(): void
    {
        $manager = $this->manager([new Read()]);
        $manager->register(RosterAgent::named('coder', tools: ['Reed']));

        $this->expectException(\RuntimeException::class);
        $manager->grantedToolsFor($manager->createSubAgent('coder', 'task'));
    }

    public function testThePromptIsThePresetsOwn(): void
    {
        $manager = $this->manager(null);
        $manager->register(RosterAgent::named('coder'));

        $this->assertSame('You are coder.', $manager->systemPromptFor($manager->createSubAgent('coder', 'task')));
    }

    public function testAGrantedSkillTheRegistryCannotResolveIsRefused(): void
    {
        $manager = $this->manager(null);
        $manager->register(RosterAgent::named('coder', skills: ['no-such-skill']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no-such-skill');
        $manager->systemPromptFor($manager->createSubAgent('coder', 'task'));
    }

    /**
     * @param ?list<\SugarCraft\Crush\Tools\Tool> $registry
     */
    private function manager(?array $registry): AgentManager
    {
        return new AgentManager(
            $this->createMock(ProviderInterface::class),
            new SkillRegistry(),
            toolRegistry: $registry,
            toolUniverse: $registry,
        );
    }

}
