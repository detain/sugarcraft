<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Context\DelegatedOutputFence;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Support\TranscriptDigest;
use SugarCraft\Code\Tools\BuiltIn\AdvisorTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-PRV-3.d — the Advisor tool: offered only when `advisorModel` is set,
 * capped per turn, refused once the session's spend cap is reached, dialling
 * tool-less over the bounded /btw digest, with the completion's usage riding
 * the tool result into the turn's spend fold (the audit-B4 channel).
 */
final class AdvisorToolTest extends TestCase
{
    private const ADVISOR_SESSION = 'advisor-test-session';

    private string|false $previousCap;

    protected function setUp(): void
    {
        $this->previousCap = getenv('CANDY_CODE_MAX_COST');

        // Neutralise any persisted / ambient cap so the non-cap tests dial
        // freely; cap-branch tests override this with their own putenv.
        putenv('CANDY_CODE_MAX_COST=1000000000');
    }

    protected function tearDown(): void
    {
        if ($this->previousCap === false) {
            putenv('CANDY_CODE_MAX_COST');
        } else {
            putenv('CANDY_CODE_MAX_COST=' . $this->previousCap);
        }
    }

    public function testTheToolIsAbsentWithoutAnAdvisorModelAndPresentWithOne(): void
    {
        $bare = self::context();
        $this->assertNull(AdvisorTool::fromCatalog($bare), 'no advisorModel — no tool on the wire');
        $names = array_map(static fn (object $tool): string => $tool->name(), ToolCatalog::build($bare));
        $this->assertNotContains('Advisor', $names);

        $armed = new ToolBuildContext(
            root: $bare->root,
            loader: new InstructionFileLoader($bare->root),
            skills: new SkillRegistry(),
            skillNudge: SkillPathNudge::new(new SkillRegistry()),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            advisorBackend: self::advisorEngine(self::answerProvider('standing by')),
        );
        $this->assertInstanceOf(AdvisorTool::class, AdvisorTool::fromCatalog($armed));
        $builtNames = array_map(static fn (object $tool): string => $tool->name(), ToolCatalog::build($armed));
        $this->assertContains('Advisor', $builtNames);

        // A conditional member: the plain-launch set the catalog pins read
        // must NOT carry it, and it must be rostered as conditional.
        $unconditional = array_map(
            static fn (object $entry): string => $entry->name,
            ToolCatalog::builtUnconditionally(),
        );
        $this->assertNotContains('Advisor', $unconditional);
        $this->assertContains('Advisor', ToolCatalog::CONDITIONAL_BUILDS);
    }

    public function testTheUnwiredAndUnboundCopiesRefuseWithoutDialling(): void
    {
        $unwired = AdvisorTool::new()->execute(['question' => 'anything']);
        $this->assertTrue($unwired->isError());
        $this->assertStringContainsString('advisorModel', $unwired->content());

        $provider = self::answerProvider('should never be dialed');
        $unbound = AdvisorTool::fromCatalog(self::armedContext($provider));
        $this->assertInstanceOf(AdvisorTool::class, $unbound);
        $refusal = $unbound->execute(['question' => 'anything']);
        $this->assertTrue($refusal->isError());
        $this->assertStringContainsString('not bound to a turn', $refusal->content());
        $this->assertCount(0, $provider->requests, 'a refusal costs nothing');
    }

    public function testTheTurnCapRefusesTheFourthDialAndAFreshTurnReAllows(): void
    {
        $provider = self::answerProvider('weigh the retry semantics');
        $tool = AdvisorTool::fromCatalog(self::armedContext($provider))->withEngine(self::sessionEngine());

        for ($call = 1; $call <= AdvisorTool::MAX_CALLS_PER_TURN; $call++) {
            $result = $tool->execute(['question' => 'q' . $call]);
            $this->assertFalse($result->isError(), 'dial ' . $call . ' of the turn budget');
        }

        $over = $tool->execute(['question' => 'one too many']);
        $this->assertTrue($over->isError());
        $this->assertStringContainsString('at most ' . AdvisorTool::MAX_CALLS_PER_TURN . ' times per turn', $over->content());
        $this->assertCount(AdvisorTool::MAX_CALLS_PER_TURN, $provider->requests, 'the cap refused before dialling');

        // "per turn", not "per launch": the next turn's binding is a fresh box.
        $nextTurn = AdvisorTool::fromCatalog(self::armedContext($provider))->withEngine(self::sessionEngine());
        $this->assertFalse($nextTurn->execute(['question' => 'first of the next turn'])->isError());
    }

    public function testTheAnswerReturnsFencedAndItsSpendRidesTheResult(): void
    {
        $usage = Usage::new(120, 0.02, 80, 40, 0);
        $provider = new ScriptedProvider([new CompleteResponse(content: 'reorder the migration after the lock work', usage: $usage)]);
        $tool = AdvisorTool::fromCatalog(self::armedContext($provider))->withEngine(self::sessionEngine());

        $result = $tool->execute(['question' => 'is this migration ordering safe given the lock discussion above?']);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString(DelegatedOutputFence::HEADER, $result->content());
        $this->assertStringContainsString('reorder the migration after the lock work', $result->content());

        // Audit B4: the reply's usage rides the result, exactly as TaskTool's
        // sub-agent spend does — that is the seam Runtime folds into the turn.
        $this->assertNotNull($result->usage());
        $this->assertSame(120, $result->usage()->totalTokens);
        $this->assertSame(0.02, $result->usage()->costUsd);

        $this->assertCount(1, $provider->requests);
        $request = $provider->requests[0];
        $this->assertNullOrEmpty($request->tools, 'the advisor dials tool-less');
        $wire = self::requestText($request);
        $this->assertStringContainsString('You advise an AI coding agent', $wire, 'the advisor voice rides');
        $this->assertStringContainsString('<QUESTION>', $wire);
        $this->assertStringContainsString('migration ordering safe', $wire, 'the question arrives fenced');
    }

    public function testTheSessionSpendCapRefusesBeforeDialling(): void
    {
        putenv('CANDY_CODE_MAX_COST=5.0');
        $provider = self::answerProvider('must not be dialed over cap');
        $context = self::armedContext(
            $provider,
            sessionSpend: static fn (string $sessionId): ?array => ['calls' => 9, 'totalTokens' => 12000, 'costUsd' => 6.0, 'unpriced' => 0],
        );
        $tool = AdvisorTool::fromCatalog($context)->withEngine(self::sessionEngine());

        $refusal = $tool->execute(['question' => 'any final advice?']);

        $this->assertTrue($refusal->isError());
        $this->assertStringContainsString('spend cap', $refusal->content());
        $this->assertCount(0, $provider->requests, 'a capped session does not pay for a refusal');
    }

    public function testTheAdvisorsOwnSpendCountsAgainstTheCapWithinTheTurn(): void
    {
        // No durable rows yet (this is all one unsettled turn), so the ONLY
        // spend the check can see is the box's own accumulation — two 0.6
        // answers fill a 1.00 cap and the third call refuses unseen by /status.
        putenv('CANDY_CODE_MAX_COST=1.0');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'first advice', usage: Usage::new(1000, 0.6)),
            new CompleteResponse(content: 'second advice', usage: Usage::new(1000, 0.6)),
            new CompleteResponse(content: 'third advice', usage: Usage::new(1000, 0.6)),
        ]);
        $context = self::armedContext($provider, sessionSpend: static fn (string $sessionId): ?array => null);
        $tool = AdvisorTool::fromCatalog($context)->withEngine(self::sessionEngine());

        $this->assertFalse($tool->execute(['question' => 'one'])->isError());
        $this->assertFalse($tool->execute(['question' => 'two'])->isError());
        $third = $tool->execute(['question' => 'three']);
        $this->assertTrue($third->isError());
        $this->assertStringContainsString('spend cap', $third->content());
        $this->assertCount(2, $provider->requests);
    }

    public function testTheDigestSentToTheAdvisorIsBounded(): void
    {
        $history = [];
        for ($row = 0; $row < 300; $row++) {
            $history[] = Message::user(str_repeat('x', 5000) . sprintf('-ROW%03d-', $row));
        }
        $provider = self::answerProvider('short answer');
        $context = self::armedContext(
            $provider,
            transcript: static fn (string $sessionId): array => $history,
        );
        $tool = AdvisorTool::fromCatalog($context)->withEngine(self::sessionEngine());

        $result = $tool->execute(['question' => 'what did we decide about the cache?']);
        $this->assertFalse($result->isError());

        $envelope = '';
        foreach ($provider->requests[0]->messages as $row) {
            $text = self::rowText($row);
            if (str_contains($text, '<transcript>')) {
                $envelope .= $text;
            }
        }
        $this->assertNotSame('', $envelope, 'the /btw envelope row is what gets sent');
        // The transcript section is capped at TOTAL_CHARS; the envelope's own
        // prose and the fenced question ride on top of that bound.
        $this->assertLessThanOrEqual(
            TranscriptDigest::TOTAL_CHARS + 3000 + strlen('what did we decide about the cache?'),
            strlen($envelope),
            'the digest sent to the advisor respects the envelope bound',
        );
        $wire = self::requestText($provider->requests[0]);
        $this->assertStringContainsString('-ROW299-', $wire, 'the newest rows ride the digest');
        $this->assertStringNotContainsString('-ROW000-', $wire, 'the oldest rows are cut');
    }

    /**
     * @param ?Closure(string): array<int, Message> $transcript
     * @param ?Closure(string): ?array{calls: int, totalTokens: int, costUsd: float, unpriced: int} $sessionSpend
     */
    private static function armedContext(
        ScriptedProvider $advisorProvider,
        ?\Closure $transcript = null,
        ?\Closure $sessionSpend = null,
    ): ToolBuildContext {
        $skills = new SkillRegistry();

        return new ToolBuildContext(
            root: sys_get_temp_dir(),
            loader: new InstructionFileLoader(sys_get_temp_dir()),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            advisorBackend: self::advisorEngine($advisorProvider),
            advisorTranscript: $transcript ?? static fn (string $sessionId): array => [Message::user('a settled turn')],
            advisorSpend: $sessionSpend,
        );
    }

    private static function context(): ToolBuildContext
    {
        $skills = new SkillRegistry();

        return new ToolBuildContext(
            root: sys_get_temp_dir(),
            loader: new InstructionFileLoader(sys_get_temp_dir()),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
        );
    }

    private static function advisorEngine(ScriptedProvider $provider): EngineBackend
    {
        return EngineBackend::new($provider, 'advisor-m')->withoutHooks();
    }

    private static function answerProvider(string $answer): ScriptedProvider
    {
        return new ScriptedProvider([new CompleteResponse(content: $answer, usage: Usage::new(50, 0.001))]);
    }

    /** The conversation's own engine — the tool only borrows its session id. */
    private static function sessionEngine(): EngineBackend
    {
        return EngineBackend::new(new ScriptedProvider([]), 'parent-m')
            ->withoutHooks()
            ->withSessionId(self::ADVISOR_SESSION);
    }

    /** Flattened text of everything the advisor's provider actually received. */
    private static function requestText(CompleteRequest $request): string
    {
        $parts = [(string) ($request->systemPrompt ?? '')];
        foreach (array_merge($request->messages, $request->systemBlocks ?? []) as $row) {
            $parts[] = self::rowText($row);
        }

        return implode("\n", $parts);
    }

    /** One wire row's text, whether it arrived typed or as a Chat Message. */
    private static function rowText(mixed $row): string
    {
        if (is_object($row)) {
            return method_exists($row, 'content') ? (string) $row->content() : (string) ($row->content ?? '');
        }

        return (string) (is_array($row) ? ($row['content'] ?? '') : $row);
    }

    private function assertNullOrEmpty(mixed $value, string $message = ''): void
    {
        $this->assertTrue($value === null || $value === [], $message);
    }
}
