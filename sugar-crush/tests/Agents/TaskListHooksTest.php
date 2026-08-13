<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Task;
use SugarCraft\Crush\Agents\TaskBlockedException;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Agents\TaskStatus;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookDispatchResult;
use SugarCraft\Crush\Hooks\HookDispatcher;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;

/**
 * Hook integration tests for TaskList.
 *
 * @see TaskList
 */
final class TaskListHooksTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = \sys_get_temp_dir() . '/tasklist_hooks_test_' . \uniqid() . '.sqlite3';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->dbPath)) {
            \unlink($this->dbPath);
        }
    }

    // -------------------------------------------------------------------------
    // addTask — null dispatcher (no hooks wired)
    // -------------------------------------------------------------------------

    public function testAddTaskWithoutHookDispatcherAddsTask(): void
    {
        // null dispatcher = no hooks = allow
        $list = new TaskList($this->dbPath, null);
        $task = $this->makeTask('task-1', 'team-a', 'No hooks');

        $id = $list->addTask($task);

        $this->assertSame('task-1', $id);
        $this->assertNotNull($list->getTask('task-1'));
    }

    // -------------------------------------------------------------------------
    // addTask — dispatcher returns allow
    // -------------------------------------------------------------------------

    public function testAddTaskWithDispatcherAllowingHookAddsTask(): void
    {
        $dispatcher = $this->makeDispatcherAllowing(HookEvent::TaskCreated);
        $list = new TaskList($this->dbPath, $dispatcher);
        $task = $this->makeTask('task-2', 'team-a', 'Allowed task');

        $id = $list->addTask($task);

        $this->assertSame('task-2', $id);
        $this->assertNotNull($list->getTask('task-2'));
    }

    // -------------------------------------------------------------------------
    // addTask — dispatcher returns block → TaskCreated is a pre-action block
    // -------------------------------------------------------------------------

    public function testAddTaskWithDispatcherBlockingHookThrowsTaskBlockedException(): void
    {
        $dispatcher = $this->makeDispatcherBlocking(HookEvent::TaskCreated);
        $list = new TaskList($this->dbPath, $dispatcher);
        $task = $this->makeTask('task-3', 'team-a', 'Blocked task');

        $this->expectException(TaskBlockedException::class);
        $this->expectExceptionMessage('Blocked with continue');

        $list->addTask($task);
    }

    // -------------------------------------------------------------------------
    // completeTask — null dispatcher
    // -------------------------------------------------------------------------

    public function testCompleteTaskWithoutHookDispatcherCompletesTask(): void
    {
        $list = new TaskList($this->dbPath, null);
        $list->addTask($this->makeTask('task-4', 'team-b', 'Complete me'));

        $list->completeTask('task-4', 'all done');

        $found = $list->getTask('task-4');
        $this->assertSame(TaskStatus::Completed, $found->status);
        $this->assertSame('all done', $found->result);
    }

    // -------------------------------------------------------------------------
    // completeTask — dispatcher returns allow
    // -------------------------------------------------------------------------

    public function testCompleteTaskWithDispatcherAllowingHookCompletesTask(): void
    {
        $dispatcher = $this->makeDispatcherAllowing(HookEvent::TaskCompleted);
        $list = new TaskList($this->dbPath, $dispatcher);
        $list->addTask($this->makeTask('task-5', 'team-b', 'Complete me'));

        $list->completeTask('task-5', 'result here');

        $found = $list->getTask('task-5');
        $this->assertSame(TaskStatus::Completed, $found->status);
        $this->assertSame('result here', $found->result);
        $this->assertFalse($found->isContested);
    }

    // -------------------------------------------------------------------------
    // completeTask — dispatcher returns block with shouldContinueOnBlock=true
    //   TaskCompleted uses continueOnBlock semantics: the action (completion)
    //   already happened, so shouldContinueOnBlock=true marks the task contested
    //   to signal the completion is disputed. Note: cannot produce
    //   shouldContinueOnBlock=false for TaskCompleted — the event type always
    //   forces continueOnBlock=true for any block result.
    // -------------------------------------------------------------------------

    public function testCompleteTaskWithDispatcherBlockingMarksTaskContested(): void
    {
        // For TaskCompleted, any block has shouldContinueOnBlock=true because
        // the event type forces it. The task is complete but flagged contested.
        $dispatcher = $this->makeDispatcherBlocking(HookEvent::TaskCompleted);
        $list = new TaskList($this->dbPath, $dispatcher);
        $list->addTask($this->makeTask('task-6', 'team-b', 'Complete me'));

        $list->completeTask('task-6', 'result');

        $found = $list->getTask('task-6');
        $this->assertSame(TaskStatus::Completed, $found->status);
        $this->assertTrue($found->isContested, 'TaskCompleted block should mark task as contested');
    }

    // -------------------------------------------------------------------------
    // dispatchTeammateIdle — null dispatcher
    // -------------------------------------------------------------------------

    public function testDispatchTeammateIdleWithoutDispatcherReturnsAllow(): void
    {
        $list = new TaskList($this->dbPath, null);

        $result = $list->dispatchTeammateIdle('team-c', 'teammate-x');

        $this->assertTrue($result->isAllowed());
        $this->assertFalse($result->isBlock());
    }

    // -------------------------------------------------------------------------
    // dispatchTeammateIdle — dispatcher returns block
    // -------------------------------------------------------------------------

    public function testDispatchTeammateIdleWithDispatcherBlockingReturnsBlockResult(): void
    {
        $dispatcher = $this->makeDispatcherBlocking(HookEvent::TeammateIdle);
        $list = new TaskList($this->dbPath, $dispatcher);

        $result = $list->dispatchTeammateIdle('team-c', 'teammate-y');

        $this->assertTrue($result->isBlock());
        $this->assertFalse($result->isAllowed());
    }

    // -------------------------------------------------------------------------
    // projectRoot — the HookContext field that becomes a proc_open() cwd
    // -------------------------------------------------------------------------

    /**
     * `makeHookContext()` used to hardcode `projectRoot: ''`. That is not a
     * cosmetic blank: {@see \SugarCraft\Crush\Hooks\ScriptHook::execute()}
     * hands the field straight to `proc_open()` as the cwd, and a directory
     * that does not exist stops the hook from running at all — which used to
     * mean a DENYING hook silently allowed the call (crush_code.md Phase 0
     * item 6).
     */
    public function testTaskScopedHookContextsCarryAUsableProjectRoot(): void
    {
        $recorder = $this->rootRecordingHook();
        $list = new TaskList($this->dbPath, $this->dispatcherFor($recorder));

        $list->addTask($this->makeTask('task-root-1', 'team-a', 'Rooted'));

        $this->assertNotSame([], $recorder->roots, 'the recording hook never saw a TaskCreated context');
        foreach ($recorder->roots as $root) {
            $this->assertDirectoryExists($root, 'a task hook context must name a directory a hook can run in');
        }
    }

    public function testTaskScopedHookContextsUseTheInjectedProjectRootWhenGivenOne(): void
    {
        $root = \sys_get_temp_dir() . '/tasklist_root_' . \uniqid('', true);
        \mkdir($root, 0755, true);

        try {
            $recorder = $this->rootRecordingHook();
            $list = new TaskList($this->dbPath, $this->dispatcherFor($recorder), $root);

            $list->addTask($this->makeTask('task-root-2', 'team-a', 'Injected root'));

            $this->assertSame([$root], $recorder->roots);
        } finally {
            \rmdir($root);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A TaskCreated hook that records every context's projectRoot and allows
     * the task through.
     */
    private function rootRecordingHook(): HookInterface
    {
        return new class implements HookInterface {
            /** @var list<string> */
            public array $roots = [];

            public function name(): string
            {
                return 'RootRecordingHook';
            }

            public function event(): HookEvent
            {
                return HookEvent::TaskCreated;
            }

            public function matcher(): string
            {
                return 'TaskList';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->roots[] = $context->projectRoot;

                return HookResult::allow();
            }
        };
    }

    private function dispatcherFor(HookInterface $hook): HookDispatcher
    {
        $registry = new HookRegistry();
        $registry->register($hook);

        return new HookDispatcher($registry);
    }

    private function makeTask(
        string $id,
        string $teamId,
        string $title,
        array $dependsOn = [],
        TaskStatus $status = TaskStatus::Pending,
        ?string $assignedTo = null,
    ): Task {
        return new Task(
            id: $id,
            teamId: $teamId,
            title: $title,
            description: "Description for {$title}",
            prompt: "Prompt for {$title}",
            assignedTo: $assignedTo,
            status: $status,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            dependsOn: $dependsOn,
            isContested: false,
        );
    }

    /**
     * Build a HookDispatcher that returns allow for the given event.
     */
    private function makeDispatcherAllowing(HookEvent $event): HookDispatcher
    {
        $registry = new HookRegistry();
        $registry->register($this->makeAllowHook($event));

        return new HookDispatcher($registry);
    }

    /**
     * Build a HookDispatcher that returns a hard block (exit 2) for the given event.
     * continueOnBlock=true for events that use continueOnBlock semantics.
     */
    private function makeDispatcherBlocking(HookEvent $event): HookDispatcher
    {
        $registry = new HookRegistry();
        $registry->register($this->makeBlockHook($event, true));

        return new HookDispatcher($registry);
    }

    private function makeAllowHook(HookEvent $event): HookInterface
    {
        return new class($event) implements HookInterface {
            public function __construct(private HookEvent $event) {}

            public function name(): string
            {
                return 'TestAllowHook';
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
                return HookResult::allow();
            }
        };
    }

    private function makeBlockHook(HookEvent $event, bool $continueOnBlock): HookInterface
    {
        return new class($event, $continueOnBlock) implements HookInterface {
            public function __construct(
                private HookEvent $event,
                private bool $continueOnBlock,
            ) {}

            public function name(): string
            {
                return 'TestBlockHook';
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
                $msg = $this->continueOnBlock
                    ? '[exit-2] Blocked with continue'
                    : '[exit-2] Blocked no continue';
                return HookResult::deny($msg);
            }
        };
    }
}
