<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\AssistantMsg;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\ApprovalVerdict;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tools\BuiltIn\AskUserTool;
use SugarCraft\Code\Tools\BuiltIn\PlanExitTool;
use SugarCraft\Code\Tools\ToolCall;

/**
 * Roadmap 5.7-2, the wiring the W10 integration added: the engine hands
 * `AskUser`/`PlanExit` the turn's approver, and an approved plan ends plan
 * mode once its turn is over — in the TUI and in a hosted session alike.
 */
final class QuestionToolWiringTest extends TestCase
{
    private const APPROVED = PlanExitTool::APPROVED . " plan mode ends when this turn ends, and the session switches to `accept-edits`.\n\nThe user approved the plan in .candy-code/plans/x.md.";

    public function testTheEngineHandsAskUserTheTurnsApprover(): void
    {
        $asked = [];
        $approver = static function (\SugarCraft\Code\Tools\ToolCall $call) use (&$asked): ApprovalVerdict {
            $asked[] = $call->name();

            return ApprovalVerdict::once();
        };
        $provider = self::providerCalling(new ToolCall('c1', AskUserTool::NAME, ['question' => 'SQLite or Postgres?', 'options' => ['SQLite', 'Postgres']]));
        $backend = EngineBackend::new($provider, 'scripted')
            ->withTools([AskUserTool::new()])
            ->withPermissionApprover($approver);

        $backend->complete([Message::user('set up the store')]);

        self::assertSame([AskUserTool::NAME], $asked, 'the question reached the approver the turn runs with');
        self::assertCount(1, $provider->toolResults);
        self::assertStringContainsString('SQLite', $provider->toolResults[0]);
        self::assertStringNotContainsString('no interactive user', $provider->toolResults[0]);
    }

    public function testTheTurnRunnerReadsOnlyTheEndedTurnsLatestPlanExit(): void
    {
        $approved = self::planExitRow(self::APPROVED);

        $switch = TurnRunner::approvedPlanExit([Message::user('plan it'), $approved, Message::assistant('done')]);
        self::assertNotNull($switch);
        self::assertSame(PermissionMode::AcceptEdits, $switch->mode);

        self::assertNull(TurnRunner::approvedPlanExit([Message::user('plan it'), $approved, Message::user('next'), Message::assistant('ok')]), 'an earlier turn\'s approval is not this one\'s');
        self::assertNull(TurnRunner::approvedPlanExit([Message::user('plan it'), $approved, self::planExitRow('The user did not approve the plan.')]), 'a later refusal wins');
        self::assertNull(TurnRunner::approvedPlanExit([Message::user('plan it'), $approved, Message::system(Chat::MODE_NOTICE_PREFIX . '`plan` to `default`.')]), 'an approval already applied is not applied twice');
        self::assertNull(TurnRunner::approvedPlanExit([Message::user('plan it'), Message::assistant('done')]));
    }

    public function testChatEndsPlanModeWhenTheApprovingTurnEnds(): void
    {
        $chat = self::chat(PermissionMode::Plan, [Message::user('plan it'), self::planExitRow(self::APPROVED)]);

        [$next] = $chat->update(new AssistantMsg(Message::assistant('I will carry it out next turn.')));

        self::assertFalse($next->inFlight);
        self::assertSame(PermissionMode::AcceptEdits, $next->currentPermissionMode());
    }

    public function testChatLeavesAModeTheUserAlreadyChangedAlone(): void
    {
        $chat = self::chat(PermissionMode::Default, [Message::user('plan it'), self::planExitRow(self::APPROVED)]);

        [$next] = $chat->update(new AssistantMsg(Message::assistant('done')));

        self::assertSame(PermissionMode::Default, $next->currentPermissionMode(), 'only a session still in plan is switched');
    }

    /** @param list<Message> $history */
    private static function chat(PermissionMode $mode, array $history): Chat
    {
        $hooks = new HookManager(new HookRegistry());
        $gate = new PermissionGate($mode, [], null, 'the built-in default');
        $hooks->register(new PermissionGateHook($gate));

        return (new Chat(
            history: $history,
            backend: EngineBackend::new(new ScriptedProvider([]), 'm')->withPermissionGate($gate),
            hooks: $hooks,
            inFlight: true,
        ))->withSize(100, 30);
    }

    private static function planExitRow(string $content): Message
    {
        return Message::assistant($content)->withToolResults([new ToolResult(PlanExitTool::NAME, $content)]);
    }

    private static function providerCalling(ToolCall $call): ProviderInterface
    {
        return new class ($call) implements ProviderInterface {
            public int $calls = 0;

            /** @var list<string> */
            public array $toolResults = [];

            public function __construct(private readonly ToolCall $call) {}

            public function name(): string { return 'ask-tc'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsDocuments(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return new CompleteResponse(content: 'asking', toolCalls: [$this->call]);
                }
                foreach ($r->messages as $message) {
                    if ($message instanceof \SugarCraft\Code\Messages\ToolResultMessage) {
                        $this->toolResults[] = $message->content();
                    }
                }

                return new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }
}
