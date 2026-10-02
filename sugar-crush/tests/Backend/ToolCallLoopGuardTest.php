<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\ToolCallLoopGuard;
use SugarCraft\Crush\Hooks\BuiltIn\RepeatCallCountHook;
use SugarCraft\Crush\Hooks\BuiltIn\RepeatCallGuardHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;

/**
 * The repeat-call loop guard's ledger (WAVE_PLAN_2 §5): warn on the 3rd
 * identical call, refuse the 5th, end the turn on the 8th — where "identical"
 * is tool name + canonical arguments + the same result.
 */
final class ToolCallLoopGuardTest extends TestCase
{
    public function testTheThirdIdenticalCallIsWarnedAndTheFirstTwoAreNot(): void
    {
        $guard = ToolCallLoopGuard::new();

        $this->assertSame('', $this->drive($guard, 'Read', ['path' => 'a'], 'same'));
        $this->assertSame('', $this->drive($guard, 'Read', ['path' => 'a'], 'same'));
        $warning = $this->drive($guard, 'Read', ['path' => 'a'], 'same');

        $this->assertStringContainsString('identical call #3 to Read', $warning);
        $this->assertStringContainsString('#5 is refused; #8 ends the turn', $warning);
        $this->assertStringContainsString('identical call #4', $this->drive($guard, 'Read', ['path' => 'a'], 'same'));
    }

    public function testTheFifthIdenticalCallIsRefusedBeforeItRuns(): void
    {
        $guard = ToolCallLoopGuard::new();
        for ($i = 0; $i < 4; $i++) {
            $this->drive($guard, 'Read', ['path' => 'a'], 'same');
        }

        $refusal = $guard->beforeCall('Read', ['path' => 'a']);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('refused identical call #5 to Read', $refusal);
        $this->assertFalse($guard->endsTurn(), 'a refusal alone does not end the turn');
    }

    public function testTheEighthIdenticalCallRefusedAttemptsIncludedEndsTheTurn(): void
    {
        $guard = ToolCallLoopGuard::new();
        for ($i = 0; $i < 4; $i++) {
            $this->drive($guard, 'Grep', ['q' => 'x'], 'none');
        }

        foreach ([5, 6, 7] as $attempt) {
            $this->assertStringContainsString("refused identical call #{$attempt}", (string) $guard->beforeCall('Grep', ['q' => 'x']));
            $this->assertFalse($guard->endsTurn(), "attempt {$attempt} must not end the turn");
        }

        $last = (string) $guard->beforeCall('Grep', ['q' => 'x']);

        $this->assertStringContainsString('identical call #8 to Grep', $last);
        $this->assertStringContainsString('The turn is being ended', $last);
        $this->assertTrue($guard->endsTurn());
        $this->assertSame('Grep', $guard->endedBy());
    }

    public function testAChangedResultResetsTheRunSoPollingIsNeverCaught(): void
    {
        $guard = ToolCallLoopGuard::new();

        for ($i = 1; $i <= 20; $i++) {
            $this->assertNull($guard->beforeCall('Bash', ['command' => 'git status']));
            $this->assertSame('', $guard->afterCall('Bash', ['command' => 'git status'], "output {$i}"), 'a call that keeps producing new output is never warned');
        }

        $this->assertFalse($guard->endsTurn());
    }

    public function testAnIdenticalResultAfterAChangeStartsCountingAgainFromOne(): void
    {
        $guard = ToolCallLoopGuard::new();
        $this->drive($guard, 'Read', ['path' => 'a'], 'v1');
        $this->drive($guard, 'Read', ['path' => 'a'], 'v1');
        $this->drive($guard, 'Read', ['path' => 'a'], 'v2');

        $this->assertSame('', $this->drive($guard, 'Read', ['path' => 'a'], 'v2'), 'second identical call of the new run');
        $this->assertNotSame('', $this->drive($guard, 'Read', ['path' => 'a'], 'v2'), 'third identical call of the new run');
    }

    public function testArgumentKeyOrderDoesNotMakeACallDifferent(): void
    {
        $guard = ToolCallLoopGuard::new();
        $this->drive($guard, 'Grep', ['pattern' => 'x', 'path' => 'src', 'opts' => ['b' => 1, 'a' => 2]], 'r');
        $this->drive($guard, 'Grep', ['path' => 'src', 'opts' => ['a' => 2, 'b' => 1], 'pattern' => 'x'], 'r');

        $this->assertNotSame('', $this->drive($guard, 'Grep', ['opts' => ['b' => 1, 'a' => 2], 'pattern' => 'x', 'path' => 'src'], 'r'));
        $this->assertSame(
            ToolCallLoopGuard::signature('Grep', ['a' => 1, 'b' => ['y' => 1, 'x' => 2]]),
            ToolCallLoopGuard::signature('Grep', ['b' => ['x' => 2, 'y' => 1], 'a' => 1]),
        );
    }

    public function testListOrderAndToolNameDoMakeACallDifferent(): void
    {
        $this->assertNotSame(
            ToolCallLoopGuard::signature('Bash', ['argv' => ['rm', 'x']]),
            ToolCallLoopGuard::signature('Bash', ['argv' => ['x', 'rm']]),
            'a list is ordered: its order is meaning, not formatting',
        );
        $this->assertNotSame(
            ToolCallLoopGuard::signature('Read', ['path' => 'a']),
            ToolCallLoopGuard::signature('Glob', ['path' => 'a']),
        );
    }

    public function testInterleavedLoopsAreCaughtPerSignature(): void
    {
        $guard = ToolCallLoopGuard::new();
        for ($i = 0; $i < 4; $i++) {
            $this->drive($guard, 'Read', ['path' => 'a'], 'A');
            $this->drive($guard, 'Read', ['path' => 'b'], 'B');
        }

        $this->assertNotNull($guard->beforeCall('Read', ['path' => 'a']), 'an A, B, A, B ping-pong is two loops, not none');
        $this->assertNotNull($guard->beforeCall('Read', ['path' => 'b']));
        $this->assertNull($guard->beforeCall('Read', ['path' => 'c']), 'a call it has never seen runs');
    }

    public function testTheHookPairDrivesTheGuardFromTheChainContexts(): void
    {
        $guard = ToolCallLoopGuard::new();
        $pre = new RepeatCallGuardHook($guard);
        $post = new RepeatCallCountHook($guard);
        $context = new HookContext('s', 'Read', ['path' => 'a'], '{"path":"a"}', '', 'm', 'p', '/tmp');

        $this->assertSame(HookEvent::PreToolUse, $pre->event());
        $this->assertSame(HookEvent::PostToolUse, $post->event());

        $notes = [];
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($pre->execute($context)->isAllowed());
            $result = $post->execute($context->withToolOutput('same'));
            $this->assertTrue($result->permitsExecution(), 'the counting half never withholds output');
            $notes[] = $result->additionalContext;
        }

        $this->assertSame('', $notes[0]);
        $this->assertSame('', $notes[1]);
        $this->assertStringContainsString('identical call #3', $notes[2]);
        $this->assertTrue($pre->execute($context)->isDenied(), 'the 5th identical call is denied on PreToolUse');
    }

    /**
     * One call through both halves, as Runtime drives them: gate, run, settle.
     *
     * @param array<string, mixed> $args
     */
    private function drive(ToolCallLoopGuard $guard, string $tool, array $args, string $output): string
    {
        $this->assertNull($guard->beforeCall($tool, $args), 'this helper only drives calls the guard admits');

        return $guard->afterCall($tool, $args, $output);
    }
}
