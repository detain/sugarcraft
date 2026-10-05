<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tools\BuiltIn\AskUserTool;
use SugarCraft\Crush\Tools\BuiltIn\PlanExitTool;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;

/**
 * Roadmap 5.7-2 remainder: the permission modal knows when the model is
 * asking a question of its own (`AskUser`, `PlanExit`) rather than asking to
 * run a call — the ask's source names the tool, the footer words the keys as
 * answers, `AskUser`'s choices answer by number — and a permitting reply keeps
 * the user's note, so `once` + text is an answer from the modal and from a
 * server client alike.
 */
final class QuestionAwareModalTest extends TestCase
{
    private ?PermissionResolved $resolved = null;

    // ── the verdict ─────────────────────────────────────────────────────

    public function testAOnceReplyKeepsTheUsersNoteLabelledAsTheirs(): void
    {
        $verdict = ApprovalVerdict::fromReply(PermissionReply::Once, '  use the second one ');

        $this->assertTrue($verdict->permits());
        $this->assertSame('the user said: use the second one', $verdict->feedback);
        $this->assertSame('', ApprovalVerdict::fromReply(PermissionReply::Once)->feedback);
        $this->assertSame('', ApprovalVerdict::fromReply(PermissionReply::Once, '   ')->feedback);
        $this->assertSame(
            'the user said: x',
            ApprovalVerdict::fromResolution(PermissionResolved::replied('a', PermissionReply::Once, 'x'))->feedback,
            'what the frame channel delivers to the turn child keeps it too',
        );
    }

    public function testAskUserReadsAOnceWithANoteAsTheAnswer(): void
    {
        $options = ['red', 'green', 'blue'];

        $this->assertSame(
            'The user chose option 2: green',
            AskUserTool::answer(ApprovalVerdict::fromReply(PermissionReply::Once, '2'), $options)->content(),
        );
        $this->assertSame(
            'The user answered: teal, actually',
            AskUserTool::answer(ApprovalVerdict::fromReply(PermissionReply::Once, 'teal, actually'), $options)->content(),
        );
        $this->assertSame(
            'The user chose option 1: red',
            AskUserTool::answer(ApprovalVerdict::fromReply(PermissionReply::Once), $options)->content(),
            'a bare once is still the recommended choice',
        );
    }

    // ── the ask's source ────────────────────────────────────────────────

    public function testAToolsOwnQuestionIsSourcedToTheToolNotToAnUnknownHook(): void
    {
        $ask = $this->ask(AskUserTool::NAME, ['question' => 'Which?', 'options' => ['a', 'b']]);

        $this->assertSame('tool:AskUser', $ask->source);
        $this->assertSame(AskUserTool::NAME, $ask->question());
        $this->assertSame(['a', 'b'], $ask->choices());
        $this->assertFalse($ask->offers(PermissionReply::Always));

        $plan = $this->ask(PlanExitTool::NAME, ['plan_path' => '.sugar-crush/plans/p.md']);
        $this->assertSame('tool:PlanExit', $plan->source);
        $this->assertSame(PlanExitTool::NAME, $plan->question());
        $this->assertSame([], $plan->choices());
    }

    public function testGateAndHookAsksKeepTheirSourcesAndAreNotQuestions(): void
    {
        $gate = $this->ask('Bash', ['command' => 'ls'], HookResult::ask('Run?')->withAskedBy([PermissionGateHook::NAME]));
        $this->assertSame('gate', $gate->source);
        $this->assertNull($gate->question());

        $hooked = $this->ask(AskUserTool::NAME, ['question' => 'Which?', 'options' => ['a', 'b']], HookResult::ask('Allow?')->withAskedBy(['audit', 'prod-guard']));
        $this->assertSame('hook:audit,prod-guard', $hooked->source);
        $this->assertNull($hooked->question(), "a hook's ask about an AskUser call is a permission question");
        $this->assertSame([], $hooked->choices());
    }

    // ── the modal ───────────────────────────────────────────────────────

    public function testAnAskUserModalWordsItsKeysAsAnswersAndNumbersItsChoices(): void
    {
        $out = Renderer::render($this->asking(AskUserTool::NAME, ['question' => 'Which colour?', 'options' => ['red', 'green', 'blue']]));

        $this->assertStringContainsString('the agent asks', $out);
        $this->assertStringContainsString('1–3', $out);
        $this->assertStringContainsString('pick that choice', $out);
        $this->assertStringContainsString('answer in your own words', $out);
        $this->assertStringNotContainsString('permission required', $out);
        $this->assertStringNotContainsString('allow once', $out);
        $this->assertStringNotContainsString('calls like this one', $out, 'no session grant is offered');
    }

    public function testAPlanExitModalAsksForApproval(): void
    {
        $out = Renderer::render($this->asking(PlanExitTool::NAME, ['plan_path' => '.sugar-crush/plans/p.md']));

        $this->assertStringContainsString('approve the plan', $out);
        $this->assertStringContainsString('send feedback, keep planning', $out);
        $this->assertStringNotContainsString('allow once', $out);
    }

    public function testAnOrdinaryPermissionPromptIsUnchanged(): void
    {
        $out = Renderer::render($this->asking('Bash', ['command' => 'ls'], HookResult::ask('Run?')->withAskedBy([PermissionGateHook::NAME])));

        $this->assertStringContainsString('permission required', $out);
        $this->assertStringContainsString('allow once', $out);
    }

    // ── the keys ────────────────────────────────────────────────────────

    public function testANumberKeyAnswersOnceWithThatChoice(): void
    {
        [$answered] = $this->asking(AskUserTool::NAME, ['question' => 'Which?', 'options' => ['red', 'green']])
            ->update(new KeyMsg(KeyType::Char, '2'));

        $this->assertNull($answered->pendingPermission());
        $this->assertSame(PermissionReply::Once, $this->resolved?->reply);
        $this->assertSame('2', $this->resolved?->note);
    }

    public function testANumberPastTheChoicesAndAOnAQuestionAreNonAnswers(): void
    {
        $asking = $this->asking(AskUserTool::NAME, ['question' => 'Which?', 'options' => ['red', 'green']]);

        [$past] = $asking->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertNotNull($past->pendingPermission());
        $this->assertSame(PermissionPromptStage::Disarmed, $past->permissionStage());

        [$a] = $asking->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertNotNull($a->pendingPermission());
        $this->assertSame(PermissionPromptStage::Disarmed, $a->permissionStage(), 'no session-grant confirm on a question');
        $this->assertNull($this->resolved);
    }

    public function testAnAnswerTypedAfterRGoesBackAsOnceWithTheWords(): void
    {
        $chat = $this->typeNote($this->asking(AskUserTool::NAME, ['question' => 'Which?']), 'teal');
        $this->assertStringContainsString('Your answer: teal', Renderer::render($chat));

        $chat->update(new KeyMsg(KeyType::Enter));

        $this->assertSame(PermissionReply::Once, $this->resolved?->reply);
        $this->assertSame('teal', $this->resolved?->note);
    }

    public function testAnEmptyAnswerAndAPlanNoteStayRefusals(): void
    {
        $this->typeNote($this->asking(AskUserTool::NAME, ['question' => 'Which?']), '')
            ->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(PermissionReply::Reject, $this->resolved?->reply, 'nothing typed is a decline');

        $this->resolved = null;
        $chat = $this->typeNote($this->asking(PlanExitTool::NAME, ['plan_path' => '.sugar-crush/plans/p.md']), 'add tests');
        $this->assertStringContainsString('What should change? add tests', Renderer::render($chat));
        $chat->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(PermissionReply::Reject, $this->resolved?->reply, 'plan feedback is a refusal of the plan');
        $this->assertSame('add tests', $this->resolved?->note);
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $arguments */
    private function ask(string $tool, array $arguments, ?HookResult $hook = null): PendingAsk
    {
        $ask = PendingAsk::fromFrame(
            PendingAsk::describe(new EngineToolCall('call_q', $tool, $arguments), $hook ?? HookResult::ask('Which?'), 'default'),
            function (PermissionResolved $resolution): void {
                $this->resolved = $resolution;
            },
        );
        $this->assertNotNull($ask);

        return $ask;
    }

    /** @param array<string, mixed> $arguments */
    private function asking(string $tool, array $arguments, ?HookResult $hook = null): Chat
    {
        // Through the engine turn's real route: the question arrives on the
        // live inbox as a PermissionAsked and the pump puts the modal up.
        $ask = $this->ask($tool, $arguments, $hook);
        $inbox = new \ArrayObject();
        $chat = (new Chat(
            history: [Message::user('go')],
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
            liveToolEvents: $inbox,
        ))->withSize(100, 40);
        $inbox[] = [1, new PermissionAsked($ask)];
        [$chat] = $chat->update(new ToolEventPumpMsg());
        $this->assertSame($ask, $chat->pendingPermission()?->pendingAsk, 'fixture: the modal must be up');

        return $chat;
    }

    private function typeNote(Chat $chat, string $text): Chat
    {
        [$chat] = $chat->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame(PermissionPromptStage::WritingNote, $chat->permissionStage());
        foreach (mb_str_split($text) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat;
    }
}
