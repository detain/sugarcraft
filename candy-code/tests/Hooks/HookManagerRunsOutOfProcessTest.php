<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Hooks\ScriptHook;

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
