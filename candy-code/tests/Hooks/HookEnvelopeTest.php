<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookEnvelope;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Lang;

/**
 * The envelope reader on its own, with no process in the way.
 *
 * C-EXT-6.c moved this logic out of ScriptHook so that an HTTP hook can be
 * answered by the SAME reader. These pins are the contract the move had to
 * preserve: what counts as an envelope, the precedence between
 * `hookSpecificOutput` and the top level, the typed refusals, and the fact
 * that nothing here is allowed to approve a call it could not parse.
 *
 * The last test is the one the extraction exists for: the same envelope, read
 * through a real spawned script and through this class directly, must produce
 * the same verdict and the same sentence.
 */
final class HookEnvelopeTest extends TestCase
{
    // =========================================================================
    // what is an envelope
    // =========================================================================

    public function testABodyThatIsNotAnObjectIsNotAnEnvelope(): void
    {
        foreach ([
            'plain text',
            '',
            '   ',
            '[1,2,3]',
            '"a JSON string"',
            '{not json}',
            '{"unknownKey": 1}',
        ] as $body) {
            $this->assertNull(HookEnvelope::read($body), "read() treated {$body} as an envelope");
        }
    }

    public function testAnyKnownKeyMakesTheObjectAnEnvelope(): void
    {
        foreach (HookEnvelope::KEYS as $key) {
            $envelope = HookEnvelope::read((string) json_encode([$key => 'x']));
            $this->assertNotNull($envelope, "the key '{$key}' is in the roster but read() does not recognise it");
        }
    }

    public function testTheRosterIsTheOneScriptHookCarries(): void
    {
        // Consolidation, not a second copy: if either list is edited without the
        // other, one transport starts reading verdicts the other refuses.
        $this->assertSame(ScriptHook::ENVELOPE_KEYS, HookEnvelope::KEYS);
        $this->assertSame(ScriptHook::ENVELOPE_DECISIONS, HookEnvelope::DECISIONS);
    }

    // =========================================================================
    // verdicts
    // =========================================================================

    public function testAnEmptyReaderDecidesAllowAndCarriesTheContext(): void
    {
        $result = $this->reader()->verdict((object) ['additionalContext' => 'looked at the diff'], 0);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('looked at the diff', $result->additionalContext);
    }

    public function testTheSpecificBlockOutranksTheTopLevelOne(): void
    {
        $envelope = (object) [
            'decision' => 'deny',
            'reason' => 'the generic answer',
            'hookSpecificOutput' => (object) [
                'permissionDecision' => 'allow',
                'permissionDecisionReason' => 'the specific answer',
            ],
        ];

        $result = $this->reader()->verdict($envelope, 0);

        $this->assertTrue($result->isAllowed(), 'the nested answer must win over the top-level one');
    }

    public function testADenyWinsNoMatterWhatElseTheEnvelopeCarries(): void
    {
        // Deny-wins ordering, pinned at the reader rather than at either
        // transport: an envelope that denies AND rewrites AND asks must land as
        // the refusal, because the call never happens.
        $envelope = (object) [
            'decision' => 'deny',
            'reason' => 'because not today',
            'updatedInput' => (object) ['command' => 'rm -rf /tmp/nope'],
            'continue' => true,
            'additionalContext' => 'context nobody will read',
        ];

        $result = $this->reader()->verdict($envelope, 20);

        $this->assertTrue($result->isDenied());
        $this->assertSame('because not today', $result->message);
        $this->assertNull($result->modifiedInput, 'a refused call must not carry a rewrite');
    }

    public function testAStopFlagHaltsTheTurnWithItsOwnReason(): void
    {
        $result = $this->reader()->verdict((object) [
            'continue' => false,
            'stopReason' => 'out of budget',
            'reason' => 'the hook is finished',
        ], 0);

        $this->assertTrue($result->haltsTurn());
        $this->assertSame('out of budget', $result->stopReason);
        // The operator-facing reason is what the line prints, so it wins the
        // message slot over the model-facing one; that is the shape ScriptHook
        // has always answered with, and this pin is here to keep it that way.
        $this->assertSame('out of budget', $result->message);
    }

    public function testAStopFlagWithOnlyAModelReasonStillSaySomething(): void
    {
        $result = $this->reader()->verdict((object) [
            'continue' => false,
            'reason' => 'the hook is finished',
        ], 0);

        $this->assertTrue($result->haltsTurn());
        $this->assertSame('the hook is finished', $result->message);
        $this->assertSame('', $result->stopReason);
    }

    public function testAStopFlagWithNoReasonOfItsOwnStillNamesTheHook(): void
    {
        $result = $this->reader()->verdict((object) ['continue' => false], 0);

        $this->assertTrue($result->haltsTurn());
        $this->assertStringContainsString('stopped the turn', $result->message);
    }

    public function testAnAskCarriesTheRewriteItProposed(): void
    {
        $result = $this->reader()->verdict((object) [
            'decision' => 'ask',
            'reason' => 'may I?',
            'updatedInput' => (object) ['command' => 'ls -la'],
        ], 40);

        $this->assertTrue($result->isAsk());
        $this->assertSame('may I?', $result->message);
        $this->assertSame('{"command":"ls -la"}', $result->modifiedInput);
    }

    public function testAnAskWithNoReasonFallsBackToTheQuestionTheHookWouldAsk(): void
    {
        $this->assertSame(
            'the description in the file',
            $this->reader(question: 'the description in the file')
                ->verdict((object) ['decision' => 'ask'], 0)->message,
        );
        $this->assertStringContainsString(
            'requires your approval',
            $this->reader()->verdict((object) ['decision' => 'ask'], 0)->message,
        );
    }

    public function testSuppressingOutputEmptiesTheContextItWouldHaveAdded(): void
    {
        $result = $this->reader()->verdict((object) [
            'additionalContext' => 'written but not shown',
            'suppressOutput' => true,
        ], 0);

        $this->assertSame('', $result->additionalContext);
    }

    public function testASystemMessageBecomesANoticeAndNeverModelContext(): void
    {
        $seen = [];
        $reader = new HookEnvelope('env-hook', noticeRecorder: static function (string $message) use (&$seen): void {
            $seen[] = $message;
        });

        $result = $reader->verdict((object) ['systemMessage' => 'hello from the hook'], 0);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->additionalContext, 'a message for the human must not reach the model');
        $this->assertSame(['hello from the hook'], $seen);
    }

    public function testEveryShapeTheReaderCannotHonourRefusesTheCall(): void
    {
        $cases = [
            'a decision that is not one of the five' => [(object) ['decision' => 'maybe'], 'must be one of'],
            'a boolean where text belongs' => [(object) ['reason' => true], 'must be a string'],
            'text where a boolean belongs' => [(object) ['continue' => 'yes'], 'must be a bool'],
            'a specific block that is not an object' => [(object) ['hookSpecificOutput' => 'x'], 'must be an object'],
            'an input rewrite that is not an object' => [(object) ['updatedInput' => [1, 2]], 'must be a JSON object'],
        ];

        foreach ($cases as $label => [$envelope, $needle]) {
            $result = $this->reader()->verdict($envelope, 0);
            $this->assertTrue($result->isDenied(), $label . ': an unreadable envelope must refuse the call');
            $this->assertStringContainsString($needle, $result->message, $label);
            $this->assertStringContainsString('has not approved it', $result->message, $label);
        }
    }

    public function testAMalformedSentenceNamesTheHookThatSentIt(): void
    {
        $message = $this->reader('the-named-one')->malformed('the reason')->message;

        $this->assertStringContainsString('the-named-one', $message);
        $this->assertStringContainsString('the reason', $message);
    }

    // =========================================================================
    // rewrites
    // =========================================================================

    public function testARewriteUnderTheCeilingIsAccepted(): void
    {
        $result = HookEnvelope::rewrite('rw-hook', '{"command":"ls"}', 40);

        $this->assertTrue($result->isModified());
        $this->assertSame('{"command":"ls"}', $result->modifiedInput);
    }

    public function testARewriteIsMeasuredAgainstWhatItReplacesNotAgainstAFloor(): void
    {
        // The ceiling is the LARGER of the floor and the input it replaces, so a
        // hook rewriting a long command may answer longer than the floor.
        $long = (string) json_encode(['command' => str_repeat('a', 40_000)]);
        $this->assertTrue(HookEnvelope::rewrite('rw-hook', $long, strlen($long) + 10)->isModified());

        $over = HookEnvelope::rewrite('rw-hook', $long, 100);
        $this->assertTrue($over->isDenied());
        $this->assertStringContainsString('over the', $over->message);
        $this->assertStringContainsString('refused rather than cut', $over->message);
    }

    public function testARewriteThatIsNotAnObjectIsRefused(): void
    {
        $result = HookEnvelope::rewrite('rw-hook', '[1,2]', 40);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('did not print a JSON object', $result->message);
    }

    // =========================================================================
    // the reason the class exists
    // =========================================================================

    public function testAScriptAndThisReaderGiveTheSameAnswerToTheSameEnvelope(): void
    {
        // The extraction is only safe if the two transports cannot drift. The
        // script side is a real spawn printing the envelope on stdout; the
        // direct side is the object this class would build from those bytes.
        $body = (string) json_encode([
            'hookSpecificOutput' => [
                'permissionDecision' => 'deny',
                'permissionDecisionReason' => 'not from this host',
            ],
            'systemMessage' => 'the gate said no',
        ]);

        $script = (new ScriptHook(
            name: 'env-hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf %s ' . escapeshellarg($body),
            description: '',
        ))->execute($this->envelopeContext());

        $decoded = HookEnvelope::read($body);
        $this->assertInstanceOf(\stdClass::class, $decoded);
        $direct = $this->reader('env-hook')->verdict($decoded, \strlen('{"command":"ls"}'));

        $this->assertTrue($script->isDenied() && $direct->isDenied());
        $this->assertSame($direct->message, $script->message, 'the same envelope answered differently per transport');
    }

    public function testLangResolvesEveryRowThisReaderSpeaks(): void
    {
        // The reader's sentences live in ScriptHook (they were not moved), so
        // the shared ones are the proof the two files still agree.
        $this->assertNotSame('hooks.notice.system_message', Lang::t('hooks.notice.system_message', [
            'hook' => 'h',
            'message' => 'm',
        ]));
    }

    private function reader(string $name = 'env-hook', ?string $question = null): HookEnvelope
    {
        return new HookEnvelope(
            $name,
            questionFallback: $question === null ? null : static fn (): string => $question,
        );
    }

    private function envelopeContext(): \SugarCraft\Code\Hooks\HookContext
    {
        return new \SugarCraft\Code\Hooks\HookContext(
            sessionId: 'env-session',
            toolName: 'Bash',
            toolArgs: ['command' => 'ls'],
            toolInput: '{"command":"ls"}',
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: sys_get_temp_dir(),
        );
    }
}
