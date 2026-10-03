<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 1.C-2: the approver's answer is an {@see ApprovalVerdict}, not a
 * bit, and what it carries reaches the MODEL — the tool result the next
 * request replays — through `Runtime::settleAsk()` and
 * `HookManager::resolveAsk()`.
 *
 * Driven end to end through {@see EngineBackend::complete()}: a default-mode
 * gate asks about `Edit`, the approver answers, and the assertion reads the
 * provider's NEXT request, which is the only place the model ever sees a
 * refusal.
 */
final class RejectionFeedbackReachesModelTest extends TestCase
{
    public function testARejectionsFeedbackIsAppendedToTheQuestionTheModelReads(): void
    {
        [$result, $finished] = $this->turn(static fn (): ApprovalVerdict => ApprovalVerdict::rejectedByUser('use the Write tool on a new file instead'));

        self::assertStringStartsWith(DenialKind::Refused->value, $result, 'a person refused this call');
        self::assertStringContainsString('the user said: use the Write tool on a new file instead', $result);
        self::assertStringContainsString('Edit', $result, 'the question itself is kept, not replaced');
        self::assertSame(DenialKind::Refused, $finished?->result->denial());
    }

    public function testAQuestionNobodyAnsweredIsUnansweredNotRefused(): void
    {
        [$result, $finished] = $this->turn(static fn (): ApprovalVerdict => ApprovalVerdict::unanswered('the turn ended before the question was answered'));

        self::assertStringStartsWith(DenialKind::Unanswered->value, $result, 'nobody refused this call; nobody was there');
        self::assertStringContainsString('the turn ended before the question was answered', $result);
        self::assertSame(DenialKind::Unanswered, $finished?->result->denial(), 'the kind is stamped on the result, not re-derived from text');
    }

    public function testAReasonThatIsNotAPersonsIsNotLabelledAsOne(): void
    {
        [$result] = $this->turn(static fn (): ApprovalVerdict => ApprovalVerdict::reject('approval from a parallel sub-agent is not yet supported'));

        self::assertStringStartsWith(DenialKind::Refused->value, $result);
        self::assertStringContainsString('approval from a parallel sub-agent is not yet supported', $result);
        self::assertStringNotContainsString('the user said', $result);
    }

    /** @return array<string, array{\Closure(): mixed, bool}> */
    public static function answers(): array
    {
        return [
            'literal true still grants' => [static fn (): bool => true, true],
            'false still refuses' => [static fn (): bool => false, false],
            'once grants' => [static fn (): ApprovalVerdict => ApprovalVerdict::once(), true],
            'always grants' => [static fn (): ApprovalVerdict => ApprovalVerdict::always(), true],
            // A truthy object that is not a verdict must never grant — every
            // PermissionReply case, Reject included, is one.
            'a PermissionReply is not consent' => [static fn (): object => \SugarCraft\Crush\Permissions\PermissionReply::Reject, false],
            'a truthy string is not consent' => [static fn (): string => 'yes', false],
        ];
    }

    #[DataProvider('answers')]
    public function testTheBoolContractStillHoldsBesideTheVerdict(\Closure $answer, bool $runs): void
    {
        [$result] = $this->turn($answer);

        if ($runs) {
            self::assertSame('ran', $result);
        } else {
            self::assertStringStartsWith(DenialKind::Refused->value, $result);
            self::assertStringNotContainsString(' — ', $result, 'a bare refusal carries no feedback');
        }
    }

    /**
     * @param \Closure(): mixed $answer
     *
     * @return array{0: string, 1: ?ToolFinished} the tool result the model's
     *         next request carries, and the finished event
     */
    private function turn(\Closure $answer): array
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Edit', ['file_path' => 'a.txt'])]),
            new CompleteResponse(content: 'done'),
        ]);

        $finished = null;
        EngineBackend::new($provider, 'm')
            ->withTools([self::editTool()])
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withPermissionApprover(static fn (ToolCall $call, HookResult $ask): mixed => $answer())
            ->complete([Message::user('go')], null, static function (object $event) use (&$finished): void {
                if ($event instanceof ToolFinished) {
                    $finished = $event;
                }
            });

        self::assertCount(2, $provider->requests, 'the turn should have gone back to the model once');
        $result = null;
        foreach ($provider->requests[1]->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $result = $message->content();
            }
        }
        self::assertIsString($result, 'the second request replays no tool result');

        return [$result, $finished];
    }

    private static function editTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'Edit'; }
            public function description(): string { return 'edits'; }
            public function inputSchema(): array { return []; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call_1', content: 'ran');
            }
        };
    }
}
