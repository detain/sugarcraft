<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use React\Promise;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Sessions\BackgroundSessionRunner;

/**
 * ISS-041, background half: a `/bg` daemon runs the same
 * `UserPromptSubmit`/`SessionStart` chain its TUI sibling does, through the
 * same single body, BEFORE the first billed call — and a denial is failure:
 * the task exits non-zero with the reason logged, exactly like every other
 * `[session:task:failed]` the buffer can carry.
 *
 * The chain is pinned ({@see BackgroundSessionRunner::pinTurnHooksForTesting()})
 * so no ambient hooks file can join, and the backend is injected so no
 * provider is contacted; only the buffer proves what the daemon decided.
 */
final class BackgroundSessionRunnerTurnHookTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        BackgroundSessionRunner::pinTurnHooksForTesting(null);
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testADeniedTaskExitsNonZeroAndNeverCallsTheModel(): void
    {
        $buffer = $this->buffer();
        BackgroundSessionRunner::pinTurnHooksForTesting($this->chain([
            $this->turnGuard('guard', HookEvent::UserPromptSubmit, HookResult::deny('policy blocks this')),
        ]));
        $seen = new TurnHookProbeBackend();

        $exit = $this->bgRunner($buffer, 'drop the database')->executeTask($seen);

        self::assertSame(1, $exit);
        self::assertSame(0, $seen->calls);
        $log = (string) file_get_contents($buffer);
        self::assertStringContainsString('[session:task:failed] turn hook blocked:', $log);
        self::assertStringContainsString('policy blocks this', $log);
        self::assertStringNotContainsString('[session:task:complete]', $log);
    }

    public function testAPermittedTaskSplicesItsNotesAheadOfTheLineAndCompletes(): void
    {
        $buffer = $this->buffer();
        BackgroundSessionRunner::pinTurnHooksForTesting($this->chain([
            $this->turnGuard('p', HookEvent::UserPromptSubmit, HookResult::allow('', 'PROMPT-NOTE')),
            $this->turnGuard('s', HookEvent::SessionStart, HookResult::allow('', 'SESSION-NOTE')),
        ]));
        $seen = new TurnHookProbeBackend();

        $exit = $this->bgRunner($buffer, 'audit the CI matrix')->executeTask($seen);

        self::assertSame(0, $exit);
        self::assertSame(1, $seen->calls);
        self::assertSame(Role::System, $seen->history[0]->role);
        self::assertSame('SESSION-NOTE', $seen->history[0]->content);
        self::assertSame(Role::System, $seen->history[1]->role);
        self::assertSame('PROMPT-NOTE', $seen->history[1]->content);
        self::assertSame(Role::User, $seen->history[2]->role);
        self::assertSame('audit the CI matrix', $seen->history[2]->content);
        $log = (string) file_get_contents($buffer);
        self::assertStringContainsString('[session:task:complete]', $log);
    }

    private function buffer(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iss041_bg_');
        $this->paths[] = $path;

        return $path;
    }

    private function bgRunner(string $bufferPath, string $task): BackgroundSessionRunner
    {
        return new BackgroundSessionRunner(
            sessionId: 'sess_iss041',
            socketPath: $bufferPath . '.sock',
            bufferPath: $bufferPath,
            task: $task,
        );
    }

    /**
     * @param list<HookInterface> $hooks
     */
    private function chain(array $hooks): HookManager
    {
        $registry = new HookRegistry();
        foreach ($hooks as $hook) {
            $registry->register($hook);
        }

        return new HookManager($registry);
    }

    private function turnGuard(string $name, HookEvent $event, HookResult $verdict): HookInterface
    {
        return new class($name, $event, $verdict) implements HookInterface {
            public function __construct(
                private readonly string $guardName,
                private readonly HookEvent $guardEvent,
                private readonly HookResult $guardVerdict,
            ) {}

            public function name(): string
            {
                return $this->guardName;
            }

            public function event(): HookEvent
            {
                return $this->guardEvent;
            }

            public function matcher(): string
            {
                return '';
            }

            public function execute(HookContext $context): HookResult
            {
                return $this->guardVerdict;
            }
        };
    }
}

/**
 * The daemon's backend double for this file: records the history it was
 * handed and answers in one shot, which is the shape `executeTask` documents
 * ("Backend answered in one shot; nothing has been written yet").
 */
final class TurnHookProbeBackend implements Backend
{
    /** @var list<Message> */
    public array $history = [];
    public int $calls = 0;

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;
        $this->history = $history;

        return Message::assistant('bg answer');
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): Promise\PromiseInterface {
        return Promise\resolve($this->complete($history, $onToken, $onEvent));
    }
}
