<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\BuiltIn\SubAgentGrantHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * The reserved hook names: the permission gate's, and the sub-agent grant's
 * (`subagent-grant`), which a launch-time hook could otherwise occupy on the
 * copy of the chain a `Task` turn runs on — or a disable could switch off.
 *
 * @see HookRegistry::isReserved()
 */
final class HookRegistryReservedNameTest extends TestCase
{
    public function testBothBuiltInNamesAreReserved(): void
    {
        $this->assertTrue(HookRegistry::isReserved(PermissionGateHook::NAME));
        $this->assertTrue(HookRegistry::isReserved(SubAgentGrantHook::NAME));
        $this->assertFalse(HookRegistry::isReserved('subagent-grant-audit'));
    }

    public function testAnImpostorCannotRegisterUnderTheGrantsName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'subagent-grant' is reserved for a sub-agent's tool grant");

        (new HookRegistry())->register(self::named(SubAgentGrantHook::NAME));
    }

    public function testTheGatesRefusalStillNamesTheGate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved for the permission gate/');

        (new HookRegistry())->register(self::named(PermissionGateHook::NAME));
    }

    public function testTheRealGrantRegistersAndCannotBeDisabledOrUnregistered(): void
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('reviewer', ['Read']));
        $grant = new SubAgentGrantHook($manager, $manager->createSubAgent('reviewer', 'x'));

        $registry = new HookRegistry();
        $registry->register($grant);
        $registry->disable(SubAgentGrantHook::NAME);
        $registry->unregister(SubAgentGrantHook::NAME);

        $this->assertFalse($registry->isDisabled(SubAgentGrantHook::NAME));
        $this->assertSame($grant, $registry->get(HookEvent::PreToolUse->value, SubAgentGrantHook::NAME));
    }

    private static function named(string $name): HookInterface
    {
        return new class ($name) implements HookInterface {
            public function __construct(private readonly string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::allow();
            }
        };
    }
}
