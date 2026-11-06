<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\BlankReturnResume;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Messages\Message as BlankLineMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * The engine-native resume-on-blank, seen through the pooled-stage path: a
 * stage whose TURN ends on a blank report is resumed on the SAME session with
 * the engine's own continue, the tenth consecutive blank fails through the
 * existing Failed machinery with the stall named, and a clean stage's bytes
 * never move.
 *
 * The scripts here answer with the silence shape {@see EngineBackend}
 * actually surfaces to the executor: a blank reply is re-asked INSIDE the
 * turn (two provider calls per step-capped attempt), so only an attempt that
 * ends its turn silent reaches this resume loop. The keyed closures below
 * report once — and only once — the continue instruction is on the wire,
 * which is exactly what proves the resume rode on a carried transcript.
 */
final class BlankReturnEngineExecutorTest extends TestCase
{
    public function testABlankCompletionResumesTheSameSessionWithOneContinue(): void
    {
        $stage = self::silentStage();
        $provider = new ScriptedProvider([self::silentUntilContinued('report after the nudge')]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm'));

        $result = $executor->execute($stage, self::silentRequest());

        $this->assertSame(AgentStatus::Completed, $result->status, 'the resumed report completes the stage');
        $this->assertSame($stage->id, $result->agentId, 'the result rides the SAME agent session');
        $this->assertSame(
            "report after the nudge\n\n[resumed 1 blank return(s) with a continue instruction]",
            $result->output,
            'the success discloses the resume it rode on',
        );
    }

    public function testTheResumeCarriesTheWholeBlankTurnForwardAndEndsWithContinue(): void
    {
        $provider = new ScriptedProvider([self::silentUntilContinued('now reporting')]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm'));

        $executor->execute(self::silentStage(), self::silentRequest());

        $first = self::linesOf($provider->requests[0]);
        $resumed = self::linesOf($provider->requests[2]);

        // Session continuity, byte for byte: the resumed attempt's input
        // EXTENDS the original session — the silent answer rode along — and
        // ends with the one minimal continue instruction. A wrong-session
        // resume (restarting from the original messages) reddens the prefix
        // leg or the trailing-instruction leg on the spot.
        $this->assertSame(\array_slice($resumed, 0, \count($first)), $first, 'the resumed attempt carries the whole earlier session');
        $this->assertGreaterThan(\count($first), \count($resumed), 'the silent answer itself rode along');
        $this->assertSame(
            ['user', BlankReturnResume::INSTRUCTION],
            $resumed[\count($resumed) - 1],
            'the resume instruction is exactly "continue"',
        );
    }

    public function testTenConsecutiveBlanksFailHonestlyNamingTheStallAndTheBudget(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: '')]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm'));

        $result = $executor->execute(self::silentStage(), self::silentRequest());

        $this->assertSame(AgentStatus::Failed, $result->status);
        $why = (string) $result->error?->getMessage();
        $this->assertStringContainsString('without a final report (step cap 2)', $why, 'the legacy recognition sentence survives');
        $this->assertStringContainsString('blank-return stall', $why);
        $this->assertStringContainsString('10 consecutive blank returns', $why);
        $this->assertStringContainsString('9 continue-resume(s) consumed', $why);
        // Two provider calls per blank turn is EngineBackend's in-turn re-ask;
        // the 20-call ceiling is the executor's own budget spent to the wire.
        $this->assertCount(20, $provider->requests, 'ten silent turns, nine nudged resumes, then the engine stopped asking');
    }

    public function testAReportOnTheFirstShotIsByteIdenticalToTheLegacyShape(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'clean report')]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm'));

        $result = $executor->execute(self::silentStage(), self::silentRequest());

        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertSame('clean report', $result->output, 'a first-shot report gains no tail note at all');
        $this->assertCount(1, $provider->requests);
        $this->assertSame(0, $result->tokensUsed, 'nothing extra was billed for a run that never went quiet');
    }

    public function testAWhitespaceOnlyTurnEndIsSilenceToo(): void
    {
        $provider = new ScriptedProvider([self::silentUntilContinued('after the whitespace nudge', silence: " \t\n")]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm'));

        $result = $executor->execute(self::silentStage(), self::silentRequest());

        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertStringContainsString('[resumed 1 blank return(s)', $result->output);
    }

    /**
     * A script entry that stays silent for every call that has not been
     * nudged, and reports the moment the continue instruction is on the wire.
     *
     * @return \Closure(CompleteRequest): CompleteResponse
     */
    private static function silentUntilContinued(string $report, string $silence = ''): \Closure
    {
        return static function (CompleteRequest $request) use ($report, $silence): CompleteResponse {
            $last = $request->messages[\count($request->messages) - 1] ?? null;
            if ($last !== null && $last->role() === 'user' && $last->content() === BlankReturnResume::INSTRUCTION) {
                return new CompleteResponse(content: $report);
            }

            return new CompleteResponse(content: $silence);
        };
    }

    private static function silentStage(?int $maxTurns = 2): SubAgent
    {
        return new SubAgent(
            id: 'silent-' . bin2hex(random_bytes(4)),
            agent: RosterAgent::named('coder', maxTurns: $maxTurns),
            task: 'report nothing at all',
        );
    }

    private static function silentRequest(): CompleteRequest
    {
        return new CompleteRequest(
            model: 'm',
            messages: [['role' => 'user', 'content' => 'report nothing at all']],
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function linesOf(CompleteRequest $request): array
    {
        return array_values(array_map(
            static fn (BlankLineMessage $m): array => [$m->role(), $m->content()],
            array_filter($request->messages, static fn (BlankLineMessage $m): bool => !TurnContextBlock::isTurnContext($m)),
        ));
    }
}
