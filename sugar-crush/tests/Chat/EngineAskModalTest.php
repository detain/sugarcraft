<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Permissions\SessionPermissionMemo;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 1.C-2: an ASK raised inside an ENGINE turn becomes the TUI's
 * existing y/n/a modal, and the answer goes back to the forked child through
 * the question's {@see PendingAsk} — the caller half of the frame channel
 * 1.C-1 built.
 *
 * Two layers. The first drives the Chat's own fold — a {@see PermissionAsked}
 * on the live inbox, exactly where {@see EngineBackend::completeInteractive()}
 * puts it — with a hand-built handle whose settlement the test records. The
 * second runs a real forked turn through `Chat::update()` and the loop, so the
 * wiring in `scheduleBackendCompletion()` is what is under test, not a double
 * of it.
 */
final class EngineAskModalTest extends TestCase
{
    /** The generation every hand-built in-flight Chat is stamped with. */
    private const GENERATION = 7;

    /** @var list<PermissionResolved> */
    private array $settled = [];

    protected function setUp(): void
    {
        $this->settled = [];
    }

    // =====================================================================
    // The Chat's fold
    // =====================================================================

    public function testAnEngineQuestionPutsUpTheModalAndKeepsTheTurnInFlight(): void
    {
        $ask = $this->ask('Bash', ['command' => 'git status'], 'Bash needs approval');
        [$chat, $inbox] = $this->inFlightChat();
        $inbox[] = [self::GENERATION, new PermissionAsked($ask)];

        [$asking, $cmd] = $chat->update(new ToolEventPumpMsg());

        $prompt = $asking->pendingPermission();
        self::assertNotNull($prompt, 'the question never reached the screen');
        self::assertSame($ask, $prompt->pendingAsk);
        self::assertSame('Bash', $prompt->toolCall->name);
        self::assertSame(['command' => 'git status'], $prompt->toolCall->arguments);
        self::assertSame('Bash needs approval', $prompt->prompt);
        self::assertSame(PermissionPromptStage::Armed, $asking->permissionStage());
        self::assertTrue($asking->inFlight);
        self::assertNotNull($cmd, 'the prompt suspends on its own Cmd');
        self::assertFalse($ask->isSettled(), 'nothing answered yet');
    }

    public function testYAnswersOnceThroughTheHandleAndTheTurnRunsOn(): void
    {
        [$asking, $ask] = $this->asking('Edit', ['file_path' => 'a.txt']);
        $before = count($asking->history);

        [$answered, $cmd] = $asking->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertSame(PermissionReply::Once, $ask->resolution()?->reply);
        self::assertNull($answered->pendingPermission());
        self::assertTrue($answered->inFlight, 'the child runs the call; the turn is not over');
        self::assertNull($cmd, 'nothing is dispatched from here — the child owns the call');
        self::assertCount($before, $answered->history, 'no Chat-native batch to resume or refuse');
        self::assertSame([], $answered->permissionGrants());
    }

    public function testEscapeRefusesThroughTheHandleWithoutEndingTheTurn(): void
    {
        [$asking, $ask] = $this->asking('Edit', ['file_path' => 'a.txt']);

        [$answered] = $asking->update(new KeyMsg(KeyType::Escape, ''));

        self::assertSame(PermissionReply::Reject, $ask->resolution()?->reply);
        self::assertNull($answered->pendingPermission());
        self::assertTrue($answered->inFlight, 'the model is handed the refusal and carries on in the child');
    }

    public function testAlwaysRemembersAPatternThatAnswersTheNextQuestionWithoutAPrompt(): void
    {
        [$asking, $ask, $inbox] = $this->asking('Bash', ['command' => 'git status']);

        [$confirming] = $asking->update(new KeyMsg(KeyType::Char, 'a'));
        [$granted] = $confirming->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertSame(PermissionReply::Always, $ask->resolution()?->reply);
        self::assertSame(
            [SessionPermissionMemo::RULE_KEY . 'Bash(git status)' => true, SessionPermissionMemo::RULE_KEY . 'Bash(git status *)' => true],
            $granted->permissionGrants(),
        );

        $next = $this->ask('Bash', ['command' => 'git status --short']);
        $inbox[] = [self::GENERATION, new PermissionAsked($next)];
        [$after] = $granted->update(new ToolEventPumpMsg());

        self::assertNull($after->pendingPermission(), 'a remembered grant puts up no prompt');
        self::assertSame(PermissionReply::Once, $next->resolution()?->reply);

        $other = $this->ask('Bash', ['command' => 'git push']);
        $inbox[] = [self::GENERATION, new PermissionAsked($other)];
        [$askedAgain] = $after->update(new ToolEventPumpMsg());
        self::assertSame($other, $askedAgain->pendingPermission()?->pendingAsk, 'a different subcommand is a different question');
    }

    public function testAUserHooksQuestionIsPutEveryTimeWhateverWasRemembered(): void
    {
        $grants = SessionPermissionMemo::new()->withGrant('Bash', ['command' => 'git status'])->grants();
        [$chat, $inbox] = $this->inFlightChat($grants);
        $hookAsk = $this->ask('Bash', ['command' => 'git status'], 'touching prod?', [PermissionReply::Once->value, PermissionReply::Reject->value]);
        $inbox[] = [self::GENERATION, new PermissionAsked($hookAsk)];

        [$asking] = $chat->update(new ToolEventPumpMsg());

        self::assertSame($hookAsk, $asking->pendingPermission()?->pendingAsk);

        // ...and `always` there settles as `once`, remembering nothing.
        [$confirming] = $asking->update(new KeyMsg(KeyType::Char, 'a'));
        [$answered] = $confirming->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertSame(PermissionReply::Once, $hookAsk->resolution()?->reply);
        self::assertSame($grants, $answered->permissionGrants());
    }

    public function testAQuestionTheTurnsEndSettledTakesTheModalDown(): void
    {
        [$asking, $ask, $inbox] = $this->asking('Edit', ['file_path' => 'a.txt']);
        $ask->cancel(\SugarCraft\Crush\Backend\ChildChannel::PARENT_GONE);
        $inbox[] = [self::GENERATION, $ask->resolution()];

        [$down] = $asking->update(new ToolEventPumpMsg());

        self::assertNull($down->pendingPermission());
        self::assertTrue($ask->resolution()?->cancelled);
    }

    public function testASettlementForAnotherQuestionLeavesTheModalUp(): void
    {
        [$asking, $ask, $inbox] = $this->asking('Edit', ['file_path' => 'a.txt']);
        $inbox[] = [self::GENERATION, PermissionResolved::cancelled('0123456789abcdef')];

        [$still] = $asking->update(new ToolEventPumpMsg());

        self::assertSame($ask, $still->pendingPermission()?->pendingAsk);
    }

    public function testAQuestionFromAnAbandonedTurnIsRefusedNotLeftBlocking(): void
    {
        [$chat, $inbox] = $this->inFlightChat();
        $stale = $this->ask('Edit', ['file_path' => 'a.txt']);
        $inbox[] = [self::GENERATION - 1, new PermissionAsked($stale)];

        [$after] = $chat->update(new ToolEventPumpMsg());

        self::assertNull($after->pendingPermission());
        self::assertSame(PermissionReply::Reject, $stale->resolution()?->reply);
    }

    public function testASecondQuestionWaitsForTheFirstAnswer(): void
    {
        [$asking, $first, $inbox] = $this->asking('Edit', ['file_path' => 'a.txt']);
        $second = $this->ask('Edit', ['file_path' => 'b.txt']);
        $inbox[] = [self::GENERATION, new PermissionAsked($second)];

        [$waiting] = $asking->update(new ToolEventPumpMsg());
        self::assertSame($first, $waiting->pendingPermission()?->pendingAsk);
        self::assertCount(1, $inbox, 'the second question is kept, not dropped');

        [$answered] = $waiting->update(new KeyMsg(KeyType::Char, 'y'));
        [$next] = $answered->update(new ToolEventPumpMsg());

        self::assertSame($second, $next->pendingPermission()?->pendingAsk);
        self::assertSame(PermissionPromptStage::Armed, $next->permissionStage(), 'each question arms afresh');
    }

    // =====================================================================
    // A real forked turn
    // =====================================================================

    public function testARealEngineTurnAsksThroughTheModalAndRunsTheCallOnYes(): void
    {
        $this->requireFork();
        $inbox = new \ArrayObject();
        $chat = new Chat(
            inputBuf: 'go',
            backend: self::engine([
                new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Edit', ['file_path' => 'a.txt'])]),
                new CompleteResponse(content: 'done'),
            ]),
            liveToolEvents: $inbox,
        );

        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $resolved = $this->start($cmd);

        $asking = $this->pumpUntil($running, static fn (Chat $c): bool => $c->pendingPermission() !== null);
        self::assertNotNull($asking->pendingPermission(), 'the forked turn\'s question never reached the modal');
        self::assertSame('Edit', $asking->pendingPermission()->toolCall->name);
        self::assertSame(['file_path' => 'a.txt'], $asking->pendingPermission()->toolCall->arguments);

        [$answered] = $asking->update(new KeyMsg(KeyType::Char, 'y'));
        $this->runUntil(static fn (): bool => $resolved->msg !== null);
        self::assertNotNull($resolved->msg, 'the turn never settled after the answer');

        $done = $this->apply($answered, $resolved->msg);
        $contents = array_map(static fn (Message $m): string => $m->content, $done->history);
        self::assertContains('done', $contents);
        self::assertFalse($done->inFlight);
        $toolOutput = implode("\n", array_map(
            static fn (Message $m): string => implode("\n", array_map(static fn ($r): string => (string) $r->result, $m->toolResults)),
            $done->history,
        ));
        self::assertStringContainsString('ran', $toolOutput, 'the approved call ran in the child');
    }

    public function testAnAlwaysAnswerReachesTheNextTurnsGate(): void
    {
        $this->requireFork();
        $inbox = new \ArrayObject();
        $script = [
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Bash', ['command' => 'git status'])]),
            new CompleteResponse(content: 'done'),
        ];
        $chat = new Chat(inputBuf: 'go', backend: self::engine($script, 'Bash'), liveToolEvents: $inbox);

        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $first = $this->start($cmd);
        $asking = $this->pumpUntil($running, static fn (Chat $c): bool => $c->pendingPermission() !== null);
        self::assertNotNull($asking->pendingPermission());
        [$confirming] = $asking->update(new KeyMsg(KeyType::Char, 'a'));
        [$granted] = $confirming->update(new KeyMsg(KeyType::Char, 'y'));
        $this->runUntil(static fn (): bool => $first->msg !== null);
        $settled = $this->apply($granted, $first->msg);
        self::assertArrayHasKey(SessionPermissionMemo::RULE_KEY . 'Bash(git status)', $settled->permissionGrants());

        // The second turn runs the same command in a NEW child, whose
        // per-turn memo is empty. Nothing pumps the inbox this time: had the
        // gate asked, the child would block on a question nobody puts up and
        // the turn would never settle.
        foreach (mb_str_split('again') as $char) {
            [$settled] = $settled->update(new KeyMsg(KeyType::Char, $char));
        }
        [, $cmd2] = $settled->update(new KeyMsg(KeyType::Enter, ''));
        $second = $this->start($cmd2);
        $this->runUntil(static fn (): bool => $second->msg !== null, 15.0);

        self::assertNotNull($second->msg, 'the remembered grant did not reach the next turn\'s gate — it asked again');
        foreach ($inbox as [, $event]) {
            self::assertNotInstanceOf(PermissionAsked::class, $event);
        }
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>|null    $suggestions null = a gate-only question
     */
    private function ask(string $tool, array $arguments, string $reason = 'needs approval', ?array $suggestions = null): PendingAsk
    {
        $settled = &$this->settled;

        return new PendingAsk(
            PendingAsk::askId('call_' . $tool, $tool, $arguments),
            'call_' . $tool,
            $tool,
            $arguments,
            $reason,
            $suggestions === null ? 'gate' : 'hook:prod-guard',
            'default',
            $suggestions ?? [PermissionReply::Once->value, PermissionReply::Always->value, PermissionReply::Reject->value],
            $suggestions === null ? ['tool' => $tool] : [],
            static function (PermissionResolved $resolution) use (&$settled): void {
                $settled[] = $resolution;
            },
        );
    }

    /**
     * A Chat mid-turn, as `submit()` leaves one, over an inbox the test owns.
     *
     * @param array<string, bool> $grants
     *
     * @return array{0: Chat, 1: \ArrayObject}
     */
    private function inFlightChat(array $grants = []): array
    {
        $inbox = new \ArrayObject();
        $chat = new Chat(
            history: [Message::user('go')],
            backend: new EchoBackend(),
            inFlight: true,
            generation: self::GENERATION,
            liveToolEvents: $inbox,
            permissionGrants: $grants,
        );

        return [$chat, $inbox];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: Chat, 1: PendingAsk, 2: \ArrayObject}
     */
    private function asking(string $tool, array $arguments): array
    {
        [$chat, $inbox] = $this->inFlightChat();
        $ask = $this->ask($tool, $arguments);
        $inbox[] = [self::GENERATION, new PermissionAsked($ask)];
        [$asking] = $chat->update(new ToolEventPumpMsg());
        self::assertSame($ask, $asking->pendingPermission()?->pendingAsk, 'fixture: the modal is up');

        return [$asking, $ask, $inbox];
    }

    private function requireFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('an engine turn asks over completeAsync()\'s fork, which needs ext-pcntl.');
        }
    }

    /** @param list<CompleteResponse> $script */
    private static function engine(array $script, string $toolName = 'Edit'): EngineBackend
    {
        return EngineBackend::new(new ScriptedProvider($script), 'm')
            ->withTools([self::tool($toolName)])
            ->withoutHooks()
            ->withPermissionGate(new PermissionGate(PermissionMode::Default));
    }

    private static function tool(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private readonly string $name) {}
            public function name(): string { return $this->name; }
            public function description(): string { return 'says it ran'; }
            public function inputSchema(): array { return []; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call', content: 'ran');
            }
        };
    }

    /**
     * Start the turn's Cmd and capture the Msg its promise settles to.
     */
    private function start(?\Closure $cmd): \stdClass
    {
        self::assertNotNull($cmd);
        $box = new \stdClass();
        $box->msg = null;
        $pending = [$cmd];
        $found = false;
        while (($next = array_shift($pending)) !== null) {
            $out = $next();
            if ($out instanceof BatchMsg) {
                array_push($pending, ...$out->cmds);
            } elseif ($out instanceof AsyncCmd) {
                $found = true;
                $out->promise->then(static function (mixed $msg) use ($box): void {
                    $box->msg = $msg;
                });
            }
        }
        self::assertTrue($found, 'submitting started no async turn');

        return $box;
    }

    private function pumpUntil(Chat $chat, \Closure $done, float $seconds = 15.0): Chat
    {
        $deadline = microtime(true) + $seconds;
        while (!$done($chat) && microtime(true) < $deadline) {
            $this->tick();
            [$chat] = $chat->update(new ToolEventPumpMsg());
        }

        return $chat;
    }

    private function runUntil(\Closure $done, float $seconds = 15.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (!$done() && microtime(true) < $deadline) {
            $this->tick();
        }
    }

    private function tick(): void
    {
        $loop = Loop::get();
        $timer = $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($timer);
    }

    /**
     * Fold a settled turn's Msg the way Program::runCmd() does.
     */
    private function apply(Chat $chat, Msg $msg): Chat
    {
        $next = $msg;
        for ($i = 0; $i < 32 && $next !== null; $i++) {
            [$chat, $cmd] = $chat->update($next);
            $next = null;
            if ($cmd !== null) {
                $produced = $cmd();
                if ($produced instanceof Msg && !$produced instanceof AsyncCmd && !$produced instanceof BatchMsg) {
                    $next = $produced;
                }
            }
        }

        return $chat;
    }
}
