<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Backend\ToolCallLoopGuard;
use SugarCraft\Code\Context\Pruning\CompressPreviewHook;
use SugarCraft\Code\Hooks\BuiltIn\BashEscapeDenyHook;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\BuiltIn\RepeatCallCountHook;
use SugarCraft\Code\Hooks\BuiltIn\RepeatCallGuardHook;
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

    public function testThePerTurnGuardNamesAreReservedToo(): void
    {
        // ISS-090(b): EngineBackend registers these four on the manager the
        // yaml script-hook chain loads into. Before the reservation, a
        // project hooks.yaml entry that copied one of the names arrived at
        // the same registry by the same door as the guard itself.
        $this->assertTrue(HookRegistry::isReserved(RepeatCallGuardHook::NAME));
        $this->assertTrue(HookRegistry::isReserved(RepeatCallCountHook::NAME));
        $this->assertTrue(HookRegistry::isReserved('bash-escape-deny'));
        $this->assertTrue(HookRegistry::isReserved(CompressPreviewHook::NAME));
    }

    public function testAnImpostorCannotTakeAGuardName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'repeat-call-guard' is reserved for the repeat-call guard");

        (new HookRegistry())->register(self::named(RepeatCallGuardHook::NAME));
    }

    public function testTheOwningClassesStillRegisterUnderTheirReservedNames(): void
    {
        // The reservation discriminates by class, not by name — the four
        // per-turn registrants must keep walking through the door their own
        // names guard, or the fix would break the launch it protects.
        $guard = new ToolCallLoopGuard();
        $registry = new HookRegistry();
        $registry->register(new RepeatCallGuardHook($guard));
        $registry->register(new RepeatCallCountHook($guard));
        $registry->register(new BashEscapeDenyHook(sys_get_temp_dir()));
        $registry->register(new CompressPreviewHook());

        $this->assertNotNull($registry->get(HookEvent::PreToolUse->value, RepeatCallGuardHook::NAME));
        $this->assertNotNull($registry->get(HookEvent::PreToolUse->value, 'bash-escape-deny'));
        $this->assertNotNull($registry->get(HookEvent::PreToolUse->value, CompressPreviewHook::NAME));
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
