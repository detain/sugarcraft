<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * ISS-041: `-p` must dispatch `UserPromptSubmit` and `SessionStart` through
 * the single body the TUI and the host use, before the first billed call.
 * Before this lane the chain was configured, documented and — for a guard
 * script — expected on every interactive prompt, while `candy-code -p`
 * reached {@see Backend::complete()} having fired nothing.
 *
 * Every case pins its own chain through
 * {@see NonInteractive::pinTurnHooksForTesting()} and injects its backend —
 * the pin exists precisely so the $ownsSession gate can be opened here
 * without letting an ambient `~/.candy-code/hooks.yaml` into the suite.
 */
final class NonInteractiveTurnHookTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandboxRoot = '';
    private string|false $originalCap = false;

    protected function setUp(): void
    {
        $this->sandboxRoot = sys_get_temp_dir() . '/iss041_home_' . uniqid('', true);
        $this->useHomeSandbox($this->sandboxRoot . '/home');
        $this->originalCap = getenv('CANDY_CODE_MAX_COST');
        putenv('CANDY_CODE_MAX_COST');
        NonInteractive::pinTurnHooksForTesting(null);
    }

    protected function tearDown(): void
    {
        NonInteractive::pinTurnHooksForTesting(null);
        $this->restoreHomeSandbox();
        putenv($this->originalCap === false ? 'CANDY_CODE_MAX_COST' : 'CANDY_CODE_MAX_COST=' . $this->originalCap);
        $this->purge($this->sandboxRoot);
    }

    private function purge(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->purge($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testADeniedPromptIsNeverSentAndRefusesTheRun(): void
    {
        $seen = new HistoryProbe();
        NonInteractive::pinTurnHooksForTesting($this->chainWith([
            $this->verdictHook('guard', HookEvent::UserPromptSubmit, HookResult::deny('guard said no')),
        ]));

        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'do the thing']),
            $seen,
            NonInteractive::FORMAT_JSON,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_FAILURE, $code);
        self::assertSame(0, $seen->calls, 'a denied prompt must never reach the backend');

        $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($decoded['result']);
        self::assertSame('hook', $decoded['error']['type']);
        self::assertStringContainsString('never sent', $decoded['error']['message']);
        self::assertStringContainsString('guard said no', $decoded['error']['message']);
        self::assertSame('turn', $decoded['refusals'][0]['tool']);
        self::assertSame('hook', $decoded['refusals'][0]['kind']);
        self::assertStringContainsString('guard said no', $decoded['refusals'][0]['reason']);
    }

    public function testAnAskWithNobodyToAnswerItFailsClosed(): void
    {
        $seen = new HistoryProbe();
        NonInteractive::pinTurnHooksForTesting($this->chainWith([
            $this->verdictHook('guard', HookEvent::UserPromptSubmit, HookResult::ask('are you sure?')),
        ]));

        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'do the thing']),
            $seen,
            NonInteractive::FORMAT_JSON,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_FAILURE, $code);
        self::assertSame(0, $seen->calls, 'honouring an ASK as permission would run a prompt a hook asked to pause');
        $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hook', $decoded['error']['type']);
    }

    public function testPermittedNotesAreSplicedAheadOfTheUsersLineInTheHostsOrder(): void
    {
        $seen = new HistoryProbe();
        NonInteractive::pinTurnHooksForTesting($this->chainWith([
            $this->verdictHook('p', HookEvent::UserPromptSubmit, HookResult::allow('', 'PROMPT-NOTE')),
            $this->verdictHook('s', HookEvent::SessionStart, HookResult::allow('', 'SESSION-NOTE')),
        ]));

        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'do the thing']),
            $seen,
            NonInteractive::FORMAT_TEXT,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertSame('the answer', trim($stdout));
        self::assertSame(1, $seen->calls);

        // The host's insertion order (TurnController::turnHookNotes): the
        // SessionStart note ahead of the prompt note, both ahead of the line.
        self::assertSame(Role::System, $seen->history[0]->role);
        self::assertSame('SESSION-NOTE', $seen->history[0]->content);
        self::assertSame(Role::System, $seen->history[1]->role);
        self::assertSame('PROMPT-NOTE', $seen->history[1]->content);
        self::assertSame(Role::User, $seen->history[2]->role);
        self::assertSame('do the thing', $seen->history[2]->content);
    }

    public function testGateFirstOrderADeniedPromptNeverFiresSessionStart(): void
    {
        $sessionFires = 0;
        $promptFires = 0;
        NonInteractive::pinTurnHooksForTesting($this->chainWith([
            $this->verdictHook('g', HookEvent::UserPromptSubmit, HookResult::deny('nope'), function () use (&$promptFires): void {
                $promptFires++;
            }),
            $this->verdictHook('s', HookEvent::SessionStart, HookResult::allow('', 'S'), function () use (&$sessionFires): void {
                $sessionFires++;
            }),
        ]));

        $seen = new HistoryProbe();
        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'do the thing']),
            $seen,
            NonInteractive::FORMAT_TEXT,
        );
        (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_FAILURE, $code);
        self::assertSame(1, $promptFires);
        self::assertSame(0, $sessionFires, 'a SessionStart script must not spawn just to learn its prompt was blocked');
    }

    public function testAnEmptyChainLeavesTheRunByteIdenticalToThePreHookShape(): void
    {
        $seen = new HistoryProbe();
        NonInteractive::pinTurnHooksForTesting(new HookManager(new HookRegistry()));

        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['candy-code', '-p', 'do the thing']),
            $seen,
            NonInteractive::FORMAT_JSON,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertSame([Role::User], array_map(static fn (Message $m): Role => $m->role, $seen->history));
        $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('the answer', $decoded['result']);
        self::assertArrayNotHasKey('refusals', $decoded);
        self::assertArrayNotHasKey('error', $decoded);
    }

    /**
     * @param list<HookInterface> $hooks
     */
    private function chainWith(array $hooks): HookManager
    {
        $registry = new HookRegistry();
        foreach ($hooks as $hook) {
            $registry->register($hook);
        }

        return new HookManager($registry);
    }

    /**
     * A verdict-only hook, counting its own firings through $onFire. Same four
     * arms as the interface; empty matcher fires on every smuggled event name
     * (the manager filters by $event, so one shape per event is all the chain
     * ever sees).
     */
    private function verdictHook(string $name, HookEvent $event, HookResult $verdict, ?\Closure $onFire = null): HookInterface
    {
        return new class($name, $event, $verdict, $onFire) implements HookInterface {
            public function __construct(
                private readonly string $hookName,
                private readonly HookEvent $hookEvent,
                private readonly HookResult $verdict,
                private readonly ?\Closure $onFire,
            ) {}

            public function name(): string
            {
                return $this->hookName;
            }

            public function event(): HookEvent
            {
                return $this->hookEvent;
            }

            public function matcher(): string
            {
                return '';
            }

            public function execute(HookContext $context): HookResult
            {
                if ($this->onFire !== null) {
                    ($this->onFire)($context);
                }

                return $this->verdict;
            }
        };
    }
}

/**
 * The turn's backend double: counts calls and keeps the exact history it
 * was asked to complete. Public within its file so both polarities — never
 * called, called with which rows — are assertable from the outside.
 */
final class HistoryProbe implements Backend
{
    /** @var list<Message> */
    public array $history = [];
    public int $calls = 0;

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;
        $this->history = $history;

        return Message::assistant('the answer');
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): \React\Promise\PromiseInterface {
        return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
    }
}
