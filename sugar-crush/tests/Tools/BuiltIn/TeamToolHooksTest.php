<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\TaskStatus;
use SugarCraft\Crush\Agents\TeamManager;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookDispatcher;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\TeamTool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.6-2: the team task events reach the hook chain. `TaskCreated`,
 * `TaskCompleted` and `TeammateIdle` are raised by a team's TaskList through
 * a HookDispatcher over the launch's registry, which Bootstrap::hooks()
 * installs on TeamManager — so a `hooks.yaml` entry reaches the `Team` tool.
 */
final class TeamToolHooksTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_team_hooks_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/repo', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
        TeamManager::useLaunchHooks(null);
    }

    protected function tearDown(): void
    {
        TeamManager::useLaunchHooks(null);
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testTheManagersDispatcherScansTheSameRegistryIncludingLaterHooks(): void
    {
        $manager = new HookManager(new HookRegistry());
        $dispatcher = $manager->dispatcher();
        $manager->register($this->hook(HookEvent::TaskCreated, HookResult::deny('registered after the dispatcher was taken')));

        $result = $dispatcher->dispatchTaskCreated($this->context());

        self::assertTrue($result->isBlock());
        self::assertSame('registered after the dispatcher was taken', $result->message);
    }

    public function testABlockingTaskCreatedHookRefusesTheAddAndStoresNothing(): void
    {
        $seen = new \ArrayObject();
        $tool = $this->toolWith($this->hook(HookEvent::TaskCreated, HookResult::deny('tasks need a ticket number'), $seen));
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));

        $refusal = $this->err($tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Write the parser', 'prompt' => 'Do it.']));

        self::assertStringContainsString('a TaskCreated hook refused "Write the parser": tasks need a ticket number', $refusal);
        self::assertSame('Team "alpha" has no tasks.', $this->ok($tool->execute(['action' => 'list', 'team' => 'alpha'])));
        self::assertCount(1, $seen);
        $context = $seen[0];
        self::assertSame('TaskList', $context->toolName);
        self::assertSame('alpha', $context->sessionId);
        self::assertSame(
            ['task_id' => 't1', 'task_subject' => 'Write the parser', 'team_name' => 'alpha', 'teammate_name' => null],
            json_decode($context->toolInput, true),
        );
        self::assertSame($this->tempDir . '/repo', $context->projectRoot);
    }

    public function testABlockingTaskCompletedHookLeavesTheCompletionStandingButContested(): void
    {
        $seen = new \ArrayObject();
        $tool = $this->toolWith($this->hook(HookEvent::TaskCompleted, HookResult::deny('no tests were run'), $seen));
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));
        $this->ok($tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Only', 'prompt' => 'Do it.']));
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice']));

        $this->ok($tool->execute(['action' => 'complete', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1', 'result' => 'done']));

        $list = $this->ok($tool->execute(['action' => 'list', 'team' => 'alpha']));
        self::assertStringContainsString('t1 [completed] "Only"; owner alice; completion contested', $list);
        self::assertSame('alice', json_decode($seen[0]->toolInput, true)['teammate_name']);
    }

    public function testABlockingTeammateIdleHookHoldsTheNextTaskBackAndSaysWhy(): void
    {
        $seen = new \ArrayObject();
        $tool = $this->toolWith($this->hook(HookEvent::TeammateIdle, HookResult::deny('review your last task first'), $seen));
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));
        $this->ok($tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Only', 'prompt' => 'Do it.']));

        $claim = $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice']));

        self::assertSame('Nothing claimed: a TeammateIdle hook held the next task back: review your last task first.', $claim);
        self::assertSame(['team_name' => 'alpha', 'teammate_name' => 'alice'], json_decode($seen[0]->toolInput, true));
        self::assertSame(TaskStatus::Pending, (new TeamManager($this->tempDir . '/teams'))->getTeam('alpha')?->getTaskList()->getTask('t1')?->status);

        // Naming the task is not an idle teammate asking for work: no hook.
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1']));
        self::assertCount(1, $seen);
    }

    public function testTheLaunchChainFromHooksYamlReachesTheTeamTool(): void
    {
        $dir = $this->tempDir . '/home/.sugar-crush';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/hooks.yaml', <<<'YAML'
            hooks:
              TaskCreated:
                - name: ticket-gate
                  matcher: '^TaskList$'
                  command: 'echo "no ticket in the title" >&2; exit 2'
            YAML);
        chmod($dir . '/hooks.yaml', 0600);

        Bootstrap::hooks(null, $this->tempDir . '/repo');

        // A manager built with no chain of its own uses the launch's.
        $tool = TeamTool::new(fn (): TeamManager => new TeamManager($this->tempDir . '/teams'), 4242);
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));

        self::assertStringContainsString(
            'a TaskCreated hook refused "Untracked": no ticket in the title',
            $this->err($tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Untracked', 'prompt' => 'Do it.'])),
        );
    }

    public function testAManagerHandedItsOwnChainKeepsIt(): void
    {
        TeamManager::useLaunchHooks($this->dispatcherFor($this->hook(HookEvent::TaskCreated, HookResult::deny('launch chain'))));
        $tool = $this->toolWith($this->hook(HookEvent::TaskCreated, HookResult::allow()));
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));

        $this->ok($tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Allowed', 'prompt' => 'Do it.']));
    }

    private function toolWith(HookInterface $hook): TeamTool
    {
        $dispatcher = $this->dispatcherFor($hook);
        $root = $this->tempDir . '/repo';

        return TeamTool::new(
            fn (): TeamManager => new TeamManager($this->tempDir . '/teams', $dispatcher, $root),
            4242,
            static fn (): bool => true,
        );
    }

    private function dispatcherFor(HookInterface $hook): HookDispatcher
    {
        $registry = new HookRegistry();
        $registry->register($hook);

        return new HookDispatcher($registry);
    }

    /**
     * @param \ArrayObject<int, HookContext>|null $seen every context the hook was handed
     */
    private function hook(HookEvent $event, HookResult $result, ?\ArrayObject $seen = null): HookInterface
    {
        return new class ($event, $result, $seen) implements HookInterface {
            public function __construct(
                private readonly HookEvent $event,
                private readonly HookResult $result,
                private readonly ?\ArrayObject $seen,
            ) {
            }

            public function name(): string
            {
                return 'team-' . $this->event->value;
            }

            public function event(): HookEvent
            {
                return $this->event;
            }

            public function matcher(): string
            {
                return 'TaskList';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->seen?->append($context);

                return $this->result;
            }
        };
    }

    private function context(): HookContext
    {
        return new HookContext(
            sessionId: 'alpha',
            toolName: 'TaskList',
            toolArgs: [],
            toolInput: '{}',
            toolOutput: '',
            model: '',
            provider: '',
            projectRoot: $this->tempDir,
        );
    }

    private function ok(ToolResult $result): string
    {
        self::assertFalse($result->isError(), $result->content());

        return $result->content();
    }

    private function err(ToolResult $result): string
    {
        self::assertTrue($result->isError(), $result->content());

        return $result->content();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
