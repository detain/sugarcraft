<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\ScriptHook;

/**
 * C-EXT-6.a: a JSON envelope's `systemMessage` is HONORED — as a notice to
 * the human, never as model context.
 *
 * The defect this closes was honest-but-inert: `fromEnvelope()` type-checked
 * `systemMessage` and then dropped it. `docs/HOOKS.md` said "not read", and
 * that was true of the whole pipeline — no UI printed a field that is neither
 * verdict nor context. Claude Code shows the string to its user, and a hook
 * file that wrote one for a human was talking to nobody. The carrier is the
 * runtime-notice inbox this step's dependency C-EXT-1.a established for
 * dialect downgrades: `RuntimeNoticeSink` crosses forks, and `/notices` reads
 * it between turns.
 *
 * The two properties that must NOT regress, and drive every pin below:
 *
 * 1. THE ROW IS HUMAN-ONLY. `additionalContext` is the model's surface; the
 *    message never rides it, whatever else the envelope says or the verdict
 *    turns out to be.
 * 2. `suppressOutput` does NOT hide the notice (Claude Code parity). The
 *    suppression governs the hook's stdout; the notice is not stdout.
 *
 * Everything reaching the row from the hook is untrusted text: control bytes
 * are swept by `Sanitize::untrusted()`, whitespace collapsed to one line, and
 * the result clipped to the notice ceiling — an inbox row is a row, not a
 * frame.
 *
 * @see ScriptHook::recordSystemMessageNotice()
 * @see RuntimeNoticeSink
 */
final class ScriptHookSystemMessageNoticeTest extends TestCase
{
    private string $locale;

    protected function setUp(): void
    {
        // English copy, in-process backend — same pair of reasons as
        // ScriptHookClaudeDialectTest, restated here because these tests own
        // their own inbox.
        $this->locale = T::locale();
        T::setLocale('en');

        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);
        self::assertTrue(RuntimeNoticeSink::isArmed(), 'the notice sink refused to arm');
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        T::setLocale($this->locale);
    }

    // =========================================================================
    // The happy path: shown to the human, absent from the model
    // =========================================================================

    public function testASystemMessageRidesTheInboxAndNothingElse(): void
    {
        $result = $this->noticeHook('printf %s \'{"systemMessage":"backup runs at midnight"}\'')
            ->execute($this->plainContext());

        self::assertSame('allow', $result->action);
        self::assertSame('', $result->message);
        self::assertSame('', $result->additionalContext, 'a human-directed string must never borrow the model\'s surface');

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows, 'the notice is recorded exactly once');
        self::assertSame(
            '⚠ The hook `sysmsg_hook` left a message for you: backup runs at midnight',
            $rows[0],
            'the whole row is pinned, hook name and house prefix included',
        );
    }

    public function testSuppressOutputKeepsTheHumanNoticeWhileDroppingTheNote(): void
    {
        $result = $this->noticeHook(
            'printf %s \'{"suppressOutput":true,"additionalContext":"a note anyway","systemMessage":"still show me"}\''
        )->execute($this->plainContext());

        self::assertSame('allow', $result->action);
        self::assertSame('', $result->additionalContext, 'suppressOutput drops the stdout note, as before');

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows, 'Claude Code parity: suppression governs stdout, not the notice');
        self::assertStringContainsString('still show me', $rows[0]);
    }

    // =========================================================================
    // Every honored verdict carries the row; none reaches the model
    // =========================================================================

    public function testADenyWithASystemMessageKeepsBothHalves(): void
    {
        $result = $this->noticeHook(
            'printf %s \'{"decision":"deny","reason":"policy says no","systemMessage":"tell the operator"}\''
        )->execute($this->plainContext());

        self::assertSame('deny', $result->action);
        self::assertSame('policy says no', $result->message);

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows, 'a deny is not a reason to lose the human line');
        self::assertStringContainsString('tell the operator', $rows[0]);
    }

    public function testAnAskWithASystemMessageKeepsBothHalves(): void
    {
        $result = $this->noticeHook(
            'printf %s \'{"decision":"ask","reason":"run the deploy?","systemMessage":"staging only"}\''
        )->execute($this->plainContext());

        self::assertSame('ask', $result->action);
        self::assertSame('run the deploy?', $result->message);

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows);
        self::assertStringContainsString('staging only', $rows[0]);
    }

    public function testATurnStopWithASystemMessageRecordsTheRowToo(): void
    {
        $result = $this->noticeHook(
            'printf %s \'{"continue":false,"stopReason":"budget spent","systemMessage":"call a human"}\''
        )->execute($this->plainContext());

        self::assertSame('deny', $result->action, 'the stop verdict is a deny, unchanged by this step');
        self::assertTrue($result->haltsTurn());

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows, 'the notice rides the stop as it rides every honored verdict');
        self::assertStringContainsString('call a human', $rows[0]);
    }

    // =========================================================================
    // Untrusted text: the row is one line of printable message, nothing more
    // =========================================================================

    public function testControlBytesAreSweptAndTheVisibleTextSurvives(): void
    {
        // JSON escapes (not shell escapes): `printf %s` passes the six
        // characters `\u001b` through verbatim and json_decode turns them
        // into the raw byte — which is exactly the path a real hook takes,
        // since a hook speaks JSON, not the terminal.
        $result = $this->noticeHook('printf %s \'{"systemMessage":"be\\u001b[31mware\\u0000 of the extra\\u0007"}\'')
            ->execute($this->plainContext());

        self::assertSame('allow', $result->action);

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows);
        self::assertStringNotContainsString("\x1b", $rows[0], 'an ESC byte in the row repaints the human\'s screen');
        self::assertStringNotContainsString("\x00", $rows[0]);
        self::assertStringNotContainsString("\x07", $rows[0], 'BEL is the classic "make their terminal beep" hook payload');
        // Whole-row pin: `Sanitize::untrusted` removes the FULL SGR sequence
        // (`\x1b[31m`), not just the ESC byte, so the split word rejoins as
        // "beware" — a row reading "be[31mware" would prove the sweep stopped
        // at the introducer, and "bewware" would prove it deleted real text.
        self::assertSame(
            '⚠ The hook `sysmsg_hook` left a message for you: beware of the extra',
            $rows[0],
            'the visible words survive the sweep exactly, with no residue of the sequence',
        );
    }

    public function testAWhitespaceBearingMessageCollapsesToOneRow(): void
    {
        $result = $this->noticeHook('printf %s \'{"systemMessage":"first\\nline\\r\\nsecond\\ttab"}\'')
            ->execute($this->plainContext());

        self::assertSame('allow', $result->action);

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows);
        self::assertStringNotContainsString("\n", $rows[0], 'an inbox row is ONE row');
        self::assertStringNotContainsString("\t", $rows[0]);
        self::assertStringContainsString('first line second tab', $rows[0]);
    }

    public function testAnOversizedMessageBecomesAHonestClippedRow(): void
    {
        $payload = '{"systemMessage":"' . str_repeat('x', 900) . '"}';
        $result = $this->noticeHook('printf %s ' . escapeshellarg($payload))
            ->execute($this->plainContext());

        self::assertSame('allow', $result->action);

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows);
        // The row's OWN ceiling governs what the human sees: the sink clips a
        // row to MAX_CHARS and says so (C-EXT-1.a's contract), and the hook's
        // internal reason-clip runs first so the row still leads with the
        // envelope's words, not with 900 filler bytes.
        self::assertStringEndsWith(RuntimeNoticeSink::clipSuffix(), $rows[0], 'a silently short row is worse than a long one');
        self::assertSame(
            RuntimeNoticeSink::MAX_CHARS,
            mb_strlen($rows[0], 'UTF-8'),
            'the suffix counts against the budget — the same identity RuntimeNoticeSinkTest pins for the sink itself',
        );
        self::assertStringContainsString('left a message for you: xxx', $rows[0], 'the human lead survives at the head; the filler is what goes');
    }

    // =========================================================================
    // The negatives: nothing blank, malformed, or unrelated becomes a row
    // =========================================================================

    public function testABlankOrControlOnlySystemMessageRecordsNothing(): void
    {
        foreach (['   ', '', '\\u001b[31m'] as $spelling) {
            RuntimeNoticeSink::reset();
            RuntimeNoticeSink::arm(crossFork: false);

            $result = $this->noticeHook('printf %s \'{"systemMessage":"' . $spelling . '"}\'')
                ->execute($this->plainContext());

            self::assertSame('allow', $result->action);
            self::assertSame(
                [],
                RuntimeNoticeSink::drain(),
                'a message with nothing displayable in it is not a message',
            );
        }
    }

    public function testAMalformedSystemMessageDeniesAndRecordsNothing(): void
    {
        $result = $this->noticeHook('printf %s \'{"systemMessage":42}\'')
            ->execute($this->plainContext());

        self::assertSame('deny', $result->action);
        self::assertStringContainsString('"systemMessage" must be a string', $result->message);
        self::assertSame([], RuntimeNoticeSink::drain(), 'a broken envelope must not hand out notices');
    }

    public function testAnUnhonourableDecisionWithASystemMessageRecordsNothing(): void
    {
        $result = $this->noticeHook('printf %s \'{"decision":"maybe","systemMessage":"ignore me"}\'')
            ->execute($this->plainContext());

        self::assertSame('deny', $result->action);
        self::assertSame([], RuntimeNoticeSink::drain(), 'the record sits behind every malformed return on purpose');
    }

    public function testAHookWithoutASystemMessageRecordsNothingNew(): void
    {
        // Byte-stability guard for every hook that never wrote the key: the
        // note path, the deny path and silence all stay exactly as they were.
        $note = $this->noticeHook('printf \'plain note\'')->execute($this->plainContext());
        self::assertSame('allow', $note->action);
        self::assertSame('plain note', $note->additionalContext);
        self::assertSame([], RuntimeNoticeSink::drain());

        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);

        $silent = $this->noticeHook('printf %s \'{"additionalContext":"just context"}\'')
            ->execute($this->plainContext());
        self::assertSame('allow', $silent->action);
        self::assertSame('just context', $silent->additionalContext);
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /**
     * A NATIVE row (`hooks.yaml` shape, no dialect): the notice must be the
     * envelope's, not the dialect's machinery.
     */
    private function noticeHook(string $command): ScriptHook
    {
        return ScriptHook::fromConfig([
            'name' => 'sysmsg_hook',
            'event' => 'PreToolUse',
            'matcher' => '.*',
            'command' => $command,
            'description' => 'a hook that leaves a message for the human',
            'timeout' => 5.0,
        ]);
    }

    private function plainContext(): HookContext
    {
        return new HookContext(
            sessionId: 'test_session_123',
            toolName: 'TestTool',
            toolArgs: [],
            toolInput: 'test input',
            toolOutput: 'test output',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp',
        );
    }
}
