<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;

/**
 * {@see HookManager::runsOutOfProcess()} is the question Chat asks before it
 * forks a turn-hook chain off update() (audit 15b-04): it must say yes exactly
 * when a hook that would RUN for the event is a script, and read the same
 * selection the chain itself runs — a disabled script or one on another event
 * must not cost a fork.
 */
final class HookManagerRunsOutOfProcessTest extends TestCase
{
    public function testAScriptHookOnTheEventRunsOutOfProcess(): void
    {
        $manager = $this->manager($registry, $this->script('s', HookEvent::UserPromptSubmit));

        $this->assertTrue($manager->runsOutOfProcess(HookEvent::UserPromptSubmit, 'UserPromptSubmit'));
        $this->assertFalse($manager->runsOutOfProcess(HookEvent::SessionStart, 'SessionStart'), 'another event has no script');
    }

    public function testAnInProcessHookDoesNot(): void
    {
        $manager = $this->manager($registry, $this->php('p', HookEvent::UserPromptSubmit));

        $this->assertFalse($manager->runsOutOfProcess(HookEvent::UserPromptSubmit, 'UserPromptSubmit'));
    }

    public function testADisabledScriptHookDoesNot(): void
    {
        $manager = $this->manager($registry, $this->script('s', HookEvent::UserPromptSubmit));
        $registry->disable('s');

        $this->assertFalse($manager->runsOutOfProcess(HookEvent::UserPromptSubmit, 'UserPromptSubmit'));
    }

    public function testAScriptWhoseMatcherMissesDoesNot(): void
    {
        $manager = $this->manager($registry, new ScriptHook('s', HookEvent::UserPromptSubmit, '^Nope$', '/bin/true', ''));

        $this->assertFalse($manager->runsOutOfProcess(HookEvent::UserPromptSubmit, 'UserPromptSubmit'));
    }

    private function manager(?HookRegistry &$registry, HookInterface ...$hooks): HookManager
    {
        $registry = new HookRegistry();
        foreach ($hooks as $hook) {
            $registry->register($hook);
        }

        return new HookManager($registry);
    }

    private function script(string $name, HookEvent $event): ScriptHook
    {
        return new ScriptHook($name, $event, '.*', '/bin/true', '');
    }

    private function php(string $name, HookEvent $event): HookInterface
    {
        return new class($name, $event) implements HookInterface {
            public function __construct(private string $name, private HookEvent $event) {}

            public function name(): string
            {
                return $this->name;
            }

            public function event(): HookEvent
            {
                return $this->event;
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
