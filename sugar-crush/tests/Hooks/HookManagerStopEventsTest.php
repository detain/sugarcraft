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

/**
 * Step 3.D-2's manager surface: the `Stop` / `SubagentStop` / `SessionEnd`
 * dispatch methods, the turn-halt record the engine reads a tool hook's
 * `"continue": false` from, the delegated-run scope, and the two lines a
 * stop writes.
 */
final class HookManagerStopEventsTest extends TestCase
{
    public function testEachStopEventRunsOnlyItsOwnChain(): void
    {
        $fired = [];
        $manager = new HookManager(new HookRegistry());
        foreach ([HookEvent::Stop, HookEvent::SubagentStop, HookEvent::SessionEnd] as $event) {
            $manager->register(self::hook($event, 'h-' . $event->value, static function (HookContext $context) use (&$fired, $event): HookResult {
                $fired[] = $event->value . ':' . $context->toolName;

                return HookResult::allow();
            }));
        }

        $manager->stop(HookManager::eventContext(HookEvent::Stop, [], 's', '/r'));
        $manager->subagentStop(HookManager::eventContext(HookEvent::SubagentStop, [], 's', '/r'));
        $manager->sessionEnd(HookManager::eventContext(HookEvent::SessionEnd, [], 's', '/r'));

        self::assertSame(['Stop:Stop', 'SubagentStop:SubagentStop', 'SessionEnd:SessionEnd'], $fired);
    }

    public function testAThrowingStopHookIsARefusalNamingIt(): void
    {
        $manager = new HookManager(new HookRegistry());
        $manager->register(self::hook(HookEvent::SessionEnd, 'boom', static function (): HookResult {
            throw new \RuntimeException('disk full');
        }));

        $verdict = $manager->sessionEnd(HookManager::eventContext(HookEvent::SessionEnd, ['reason' => 'other'], '', ''));

        self::assertFalse($verdict->permitsExecution());
        self::assertSame('boom', $verdict->refusingHook());
        self::assertStringContainsString('disk full', $verdict->message);
    }

    public function testEventContextCarriesTheEventNameAndJsonPayload(): void
    {
        $context = HookManager::eventContext(HookEvent::Stop, ['stop_hook_active' => true, 'last_assistant_message' => 'a/b é'], 'sid', '/root', 'm', 'p');

        self::assertSame('Stop', $context->toolName, 'the slot a matcher is tested against');
        self::assertSame('{"stop_hook_active":true,"last_assistant_message":"a/b é"}', $context->toolInput);
        self::assertSame(['stop_hook_active' => true, 'last_assistant_message' => 'a/b é'], $context->toolArgs);
        self::assertSame(['sid', '/root', 'm', 'p'], [$context->sessionId, $context->projectRoot, $context->model, $context->provider]);
    }

    public function testTheFirstHaltingToolVerdictIsRecordedAndACloneStartsClean(): void
    {
        $verdicts = [HookResult::deny('plain refusal'), HookResult::stop('first', 'reason one'), HookResult::stop('second', 'reason two')];
        $manager = new HookManager(new HookRegistry());
        $manager->register(self::hook(HookEvent::PreToolUse, 'gate', static function () use (&$verdicts): HookResult {
            return array_shift($verdicts) ?? HookResult::allow();
        }));
        $context = new HookContext('', 'Bash', [], '{}', '', '', '', '');

        $manager->preToolUse($context);
        self::assertNull($manager->turnHalt(), 'an ordinary refusal stops the call, not the run');

        $manager->preToolUse($context);
        $manager->preToolUse($context);
        self::assertSame('reason one', $manager->turnHalt()?->stopReason, 'the first halt is the reason the run stopped');

        self::assertNull((clone $manager)->turnHalt(), 'a per-turn copy does not inherit a halt');
    }

    public function testAHaltingPostToolUseVerdictIsRecordedToo(): void
    {
        $manager = new HookManager(new HookRegistry());
        $manager->register(self::hook(HookEvent::PostToolUse, 'scan', static fn (): HookResult => HookResult::stop('secret in output', 'leaked key')));

        $manager->postToolUse(new HookContext('', 'Read', [], '{}', 'AKIA…', '', '', ''));

        self::assertSame('leaked key', $manager->turnHalt()?->stopReason);
    }

    public function testTheSubagentScopeNestsAndUnwindsEvenOnAThrow(): void
    {
        self::assertNull(HookManager::currentSubagent());

        $inner = HookManager::runAsSubagent('a1', 'outer', static fn (): ?array => HookManager::runAsSubagent('a2', 'inner', static fn (): ?array => HookManager::currentSubagent()));
        self::assertSame(['agent_id' => 'a2', 'agent_type' => 'inner'], $inner);
        self::assertNull(HookManager::currentSubagent());

        try {
            HookManager::runAsSubagent('a3', 'x', static function (): never {
                throw new \LogicException('run failed');
            });
        } catch (\LogicException) {
        }
        self::assertNull(HookManager::currentSubagent(), 'a failed run does not leave the scope armed');
    }

    public function testTheSubagentScopeBelongsToItsFiber(): void
    {
        $fiber = new \Fiber(static fn (): mixed => HookManager::runAsSubagent('a1', 'stage', static function (): ?array {
            \Fiber::suspend(HookManager::currentSubagent());

            return HookManager::currentSubagent();
        }));

        self::assertSame(['agent_id' => 'a1', 'agent_type' => 'stage'], $fiber->start());
        self::assertNull(HookManager::currentSubagent(), 'while the delegated run is suspended, the code that resumed it is not inside it');
        $fiber->resume();
        self::assertSame(['agent_id' => 'a1', 'agent_type' => 'stage'], $fiber->getReturn());
    }

    public function testTheStopLinesNameTheHookAndItsReason(): void
    {
        $named = HookResult::stop('model text', 'operator text')->withRefusedBy("long\x1bname" . str_repeat('x', 80));
        self::assertSame(
            '[turn stopped by hook "long name' . str_repeat('x', HookResult::MAX_NAMED_HOOK_CHARS - 9) . '…": operator text]',
            HookManager::stopNotice($named),
        );
        self::assertSame('[turn stopped by a hook: model text]', HookManager::stopNotice(HookResult::stop('model text')));

        self::assertSame(
            'SubagentStop hook "gate" did not let you finish yet: add tests',
            HookManager::stopFeedback(HookResult::deny('add tests')->withRefusedBy('gate'), HookEvent::SubagentStop),
        );
        self::assertSame('Stop hook did not let you finish yet. Continue the task.', HookManager::stopFeedback(HookResult::deny(''), HookEvent::Stop));
    }

    /**
     * @param \Closure(HookContext): HookResult $verdict
     */
    private static function hook(HookEvent $event, string $name, \Closure $verdict): HookInterface
    {
        return new class ($event, $name, $verdict) implements HookInterface {
            public function __construct(private HookEvent $event, private string $name, private \Closure $verdict) {}

            public function name(): string { return $this->name; }

            public function event(): HookEvent { return $this->event; }

            public function matcher(): string { return '.*'; }

            public function execute(HookContext $context): HookResult
            {
                return ($this->verdict)($context);
            }
        };
    }
}
