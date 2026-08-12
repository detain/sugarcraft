<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolResult;

/**
 * crush_feat.md §1 E7's classification half: which results are refusals,
 * which are restart-orphans, and how a checkpoint row taken mid-tool-call is
 * healed on the way back in.
 *
 * @see Chat::isDeniedResult()
 * @see Chat::isInterruptedResult()
 * @see Chat::reviveCheckpointMessage()
 */
final class DeniedToolCallTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'user rejected the prompt' => ['Permission denied: bash was not run.'],
            'ask reached the fork'     => ['Permission required: bash was not approved.'],
            'hook gate'                => ['Hook denied: rm -rf is not allowed'],
        ];
    }

    /**
     * @dataProvider refusalProvider
     */
    public function testEveryRefusalProducerIsClassifiedAsDenied(string $error): void
    {
        $this->assertTrue(Chat::isDeniedResult(ToolResult::error('bash', $error, 'call_1')));
    }

    public function testAGenuineFailureIsNotADenial(): void
    {
        $this->assertFalse(Chat::isDeniedResult(ToolResult::error('bash', 'exit status 1', 'call_1')));
        $this->assertFalse(Chat::isDeniedResult(ToolResult::ok('bash', 'total 0', 'call_1')));
    }

    public function testInterruptionIsItsOwnStateNotADenial(): void
    {
        $orphan = ToolResult::error('bash', Chat::INTERRUPTED_TOOL_CALL, 'call_1');

        $this->assertTrue(Chat::isInterruptedResult($orphan));
        $this->assertFalse(Chat::isDeniedResult($orphan));
        $this->assertFalse(Chat::isInterruptedResult(ToolResult::error('bash', 'exit status 1')));
        $this->assertFalse(Chat::isInterruptedResult(ToolResult::ok('bash', 'total 0')));
    }

    /**
     * The step-defining regression: a checkpoint row that still carries a
     * `pendingToolCallId` used to come back as a plain user/assistant bubble,
     * which meant the dead call's `tool_use` block had no matching result on
     * the next request's wire. It now comes back as a synthetic assistant
     * turn carrying an interrupted result under the SAME call id, with the
     * pending marker cleared so the renderer draws no perpetual spinner.
     */
    public function testAPlaceholderRowIsHealedIntoAnInterruptedResult(): void
    {
        $revived = Chat::reviveCheckpointMessage([
            'role'              => 'system',
            'content'           => 'bash(cmd: "sleep 900")',
            'pendingToolCallId' => 'call_7',
        ]);

        $this->assertSame(Role::Assistant, $revived->role);
        $this->assertSame(Chat::INTERRUPTED_TOOL_CALL, $revived->content);
        $this->assertNull($revived->pendingToolCallId, 'a revived placeholder must never keep spinning');
        $this->assertCount(1, $revived->toolResults);
        $this->assertSame('call_7', $revived->toolResults[0]->id);
        $this->assertSame('bash(cmd: "sleep 900")', $revived->toolResults[0]->name);
        $this->assertTrue(Chat::isInterruptedResult($revived->toolResults[0]));
    }

    public function testAPlaceholderWithNoDescriptionFallsBackToTheCallId(): void
    {
        $revived = Chat::reviveCheckpointMessage(['role' => 'system', 'pendingToolCallId' => 'call_7']);

        $this->assertSame('call_7', $revived->toolResults[0]->name);
    }

    public function testOrdinaryRowsSurviveTheRoundTripUnchanged(): void
    {
        $user = Chat::reviveCheckpointMessage(['role' => 'user', 'content' => 'Hello']);
        $assistant = Chat::reviveCheckpointMessage(['role' => 'assistant', 'content' => 'Hi there!']);
        $unknown = Chat::reviveCheckpointMessage(['content' => 'no role']);

        $this->assertSame(Role::User, $user->role);
        $this->assertSame('Hello', $user->content);
        $this->assertSame([], $user->toolResults);
        $this->assertSame(Role::Assistant, $assistant->role);
        $this->assertSame('Hi there!', $assistant->content);
        $this->assertSame(Role::User, $unknown->role, 'an unrecognised role still falls back to user');
    }
}
