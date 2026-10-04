<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\Msg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Goal\GoalMode;
use SugarCraft\Crush\Goal\GoalState;
use SugarCraft\Crush\GoalJudgedMsg;
use SugarCraft\Crush\Goal\GoalVerdict;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tui\TerminalNotifier;

/**
 * Roadmap 5.14a: the `notify` setting rings the terminal — BEL, or an OSC 9
 * desktop notification — when a turn ends and when the agent stops to wait
 * for an approval. Off by default, and never from `view()`: the bytes leave as
 * a candy-core `Cmd::raw()`.
 *
 * @see TerminalNotifier
 */
final class TurnEndNotificationTest extends TestCase
{
    private string|false $suggestions = false;

    protected function setUp(): void
    {
        // One Cmd per settle, so the test can read it.
        $this->suggestions = getenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS');
        putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=1');
    }

    protected function tearDown(): void
    {
        $this->suggestions === false
            ? putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS')
            : putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=' . $this->suggestions);
    }

    public function testATurnEndRingsTheBell(): void
    {
        [, $cmd] = $this->chat('bell')->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertSame(["\x07"], self::rawBytes($cmd));
    }

    public function testOsc9CarriesTheText(): void
    {
        [, $cmd] = $this->chat('osc9')->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertSame(["\x1b]9;sugarcrush: turn finished\x07"], self::rawBytes($cmd));
    }

    public function testOffIsTheDefaultAndSendsNothing(): void
    {
        foreach ([null, 'off', 'nonsense'] as $mode) {
            [, $cmd] = $this->chat($mode)->update(new AssistantMsg(Message::assistant('Done.')));

            self::assertSame([], self::rawBytes($cmd), var_export($mode, true) . ' must not notify');
        }
        self::assertSame(TerminalNotifier::OFF, SettingsSchema::byKey('notify')?->default);
    }

    public function testATurnAQueuedPromptCarriesOnDoesNotNotify(): void
    {
        $chat = $this->chat('bell', ['queuedPrompts' => ['next one']]);

        [$next, $cmd] = $chat->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertTrue($next->inFlight, 'fixture: the queued prompt took the turn');
        self::assertSame([], self::rawBytes($cmd));
    }

    public function testAnApprovalWaitNotifiesWithTheToolName(): void
    {
        // Raised through the front door — a PreToolUse hook that asks — so
        // the prompt is the one a real turn puts up.
        $asks = new class implements HookInterface {
            public function name(): string
            {
                return 'ask-every-tool';
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
                return HookResult::ask('Run rm -rf build/?');
            }
        };
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($asks);
        $chat = $this->chat('osc9')
            ->registerTool('Bash', static fn(array $args): string => 'total 0')
            ->withHooks($hooks);

        [$asking, $cmd] = $chat->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([new ToolCall('Bash', ['command' => 'rm -rf build/'], 'call_ask')]),
        ));

        self::assertNotNull($asking->pendingPermission(), 'fixture: the hook raised a prompt');
        self::assertSame(["\x1b]9;sugarcrush: waiting for approval: Bash\x07"], self::rawBytes($cmd));
    }

    public function testAGoalLoopNotifiesWhenItEndsNotBetweenRounds(): void
    {
        $chat = $this->chat('bell', ['history' => [
            Message::user('the goal prompt'),
            GoalState::new(GoalMode::Goal, 'ship it')->toMessage(),
        ], 'titleBackend' => new EchoBackend()]);

        [$judging, $cmd] = $chat->update(new AssistantMsg(Message::assistant('Done.')));
        self::assertTrue($judging->inFlight, 'fixture: the goal is being judged');
        self::assertSame([], self::rawBytes($cmd), 'the turn is not over while the judge runs');

        $generation = (new \ReflectionProperty(Chat::class, 'generation'))->getValue($judging);
        [, $ended] = $judging->update(new GoalJudgedMsg($generation, null, GoalVerdict::new(true, 100)));
        self::assertSame(["\x07"], self::rawBytes($ended));
    }

    public function testTheNotificationTextCannotEscapeTheSequence(): void
    {
        $osc9 = TerminalNotifier::new('OSC9');

        self::assertSame("\x1b]9;a,b\x07", $osc9->sequence("a;\x1b]4;\x07b"), "an embedded OSC is stripped and ; cannot open a second parameter");
        self::assertNull(TerminalNotifier::new()->sequence('x'));
        self::assertSame("\x07", TerminalNotifier::fromConfig(['notify' => 'bell'])->sequence('ignored'));
        self::assertSame(TerminalNotifier::OFF, TerminalNotifier::fromConfig(['notify' => ['bell']])->mode);
    }

    public function testTheKeyIsLayered(): void
    {
        self::assertContains('notify', LayeredSettings::LAYERED_KEYS);
    }

    /** @param array<string, mixed> $with */
    private function chat(?string $mode, array $with = []): Chat
    {
        return new Chat(...array_merge([
            'history' => [Message::user('the prompt')],
            'inFlight' => true,
            'backend' => new EchoBackend(),
            'workspace' => WorkspaceContext::new(root: sys_get_temp_dir(), userConfig: $mode === null ? [] : ['notify' => $mode]),
        ], $with));
    }

    /**
     * Every RawMsg's bytes $cmd produces, batches opened.
     *
     * @return list<string>
     */
    private static function rawBytes(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $msg = $cmd();
        if ($msg instanceof BatchMsg) {
            $out = [];
            foreach ($msg->cmds as $inner) {
                // A pending promise (the permission wait) is not a write.
                $out = [...$out, ...self::rawBytes($inner)];
            }

            return $out;
        }

        return $msg instanceof RawMsg ? [$msg->bytes] : [];
    }
}
