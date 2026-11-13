<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Hooks\BuiltIn\SubAgentGrantHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * S-P04 (candy-code-providers-005): the mode-gate deny/ask copy is keyed, with
 * {tool}/{agent}/{mode} placeholders, not a hardcoded English literal.
 */
final class SubAgentGrantHookTest extends TestCase
{
    private function hook(PermissionMode $mode): SubAgentGrantHook
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('reviewer'));

        return new SubAgentGrantHook($manager, $manager->createSubAgent('reviewer', 'x'), new PermissionGate($mode));
    }

    private static function context(): HookContext
    {
        return new HookContext('s', 'Write', ['file_path' => '/tmp/x', 'content' => 'y'], '', '', 'm', 'p', '');
    }

    public function testADeniedCallSaysSoThroughTheCatalogue(): void
    {
        $result = $this->hook(PermissionMode::Plan)->execute(self::context());

        $this->assertFalse($result->permitsExecution());
        $this->assertSame(
            Lang::t('hooks.subagent_grant.denied', ['mode' => 'plan', 'agent' => 'reviewer', 'tool' => 'Write']),
            $result->message,
        );
        $this->assertSame("Permission mode 'plan' of agent \"reviewer\" does not allow Write.", $result->message);
    }

    public function testAnAskedCallNamesToolAgentAndMode(): void
    {
        $result = $this->hook(PermissionMode::Default)->execute(self::context());

        $this->assertSame(
            Lang::t('hooks.subagent_grant.ask', ['tool' => 'Write', 'agent' => 'reviewer', 'mode' => 'default']),
            $result->message,
        );
        $this->assertStringContainsString('Allow Write to run?', $result->message);
    }

    public function testGermanLocaleRendersTheSameKeysWithEveryPlaceholderFilled(): void
    {
        $text = Lang::inLocale('de', static fn (): string => Lang::t('hooks.subagent_grant.denied', ['mode' => 'plan', 'agent' => 'reviewer', 'tool' => 'Write']));

        $this->assertStringContainsString('Berechtigungsmodus', $text);
        $this->assertStringNotContainsString('{', $text);
    }
}
