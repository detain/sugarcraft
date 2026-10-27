<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Hooks\ClaudeSettingsHooks;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\ScriptHook;

/**
 * C-EXT-1: a hook that came from a Claude Code settings file is read under
 * Claude Code's exit-code dialect, and `async: true` means "never block".
 *
 * The defect this closes is a wrong-shaped DENY. Claude Code defines two codes
 * for a `command` hook — `0` proceeds, `2` blocks — and treats EVERY OTHER
 * non-zero as a non-blocking complaint it shows its user and moves on with.
 * candy-code's table reserves every other non-zero for a deny, so a settings
 * file written for Claude Code, run here, turned a hook's "my optional lint
 * crashed" (exit 1) into a refused tool call. That is the guard failing in the
 * unsafe direction: the tool the user asked for is blocked by a script whose
 * author never meant it to block anything.
 *
 * THE FIX IS A DOWNGRADE, NOT A SILENCE. Every code this reading lets through
 * is announced on {@see RuntimeNoticeSink}, because the alternative to blocking
 * is not "pretend the hook said nothing" — an operator who moves a Claude Code
 * config into candy-code has to be able to see what the hooks actually did.
 * The sink crosses forks and is read between turns, so a hook reached from a
 * worker path still lands in the parent's inbox.
 *
 * The pins below drive real `sh` processes, and the pair that matters most is
 * the negative one: a `hooks.yaml` row resolves byte-for-byte as it did before
 * this step existed, and records nothing.
 *
 * @see ScriptHook::claudeDialectVerdict()
 * @see ClaudeSettingsHooks
 */
final class ScriptHookClaudeDialectTest extends TestCase
{
    private string $locale;

    protected function setUp(): void
    {
        // These rows are English copy; a developer's LANG must not decide them.
        $this->locale = T::locale();
        T::setLocale('en');

        RuntimeNoticeSink::reset();
        // The in-process backend: a datagram transport would put the rows on a
        // socket this test cannot read. arm()'s false return says "no
        // cross-fork transport", which is what was asked for.
        RuntimeNoticeSink::arm(crossFork: false);
        self::assertTrue(RuntimeNoticeSink::isArmed(), 'the notice sink refused to arm');
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        T::setLocale($this->locale);
    }

    // =========================================================================
    // Claude Code's dialect: two codes, and everything else is a complaint
    // =========================================================================

    public function testAClaudeHookExitingOneProceedsAndTellsTheHuman(): void
    {
        $hook = $this->claudeRow("printf 'my optional lint died' >&2; exit 1");

        $result = $hook->execute($this->contextFor());

        self::assertTrue($result->isAllowed(), 'exit 1 is a non-blocking complaint in Claude Code, not a deny');
        self::assertSame('', $result->message, 'the downgraded verdict carries no message');
        self::assertSame('', $result->additionalContext, 'and nothing of the hook is laundered into the model context');

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows, 'the downgrade is announced exactly once');
        self::assertStringContainsString('cc:user:PreToolUse:0', $rows[0]);
        self::assertStringContainsString('exited 1', $rows[0]);
        self::assertStringContainsString('my optional lint died', $rows[0]);
        self::assertStringContainsString('proceeded', $rows[0]);
    }

    public function testAClaudeHookExitingAnyOtherCodeProceedsToo(): void
    {
        // The `default` arm of the native table is open-ended; so is Claude
        // Code's reading of it. 5 is a shell's "couldn't open the redirect".
        foreach ([5, 7, 127] as $code) {
            RuntimeNoticeSink::reset();
            RuntimeNoticeSink::arm(crossFork: false);

            $result = $this->claudeRow("exit {$code}")->execute($this->contextFor());

            self::assertTrue($result->isAllowed(), "exit {$code} must proceed under the claude dialect");
            $rows = RuntimeNoticeSink::drain();
            self::assertCount(1, $rows, "exit {$code} must still be announced");
            self::assertStringContainsString("exited {$code}", $rows[0]);
            self::assertStringContainsString(
                '(nothing on stderr)',
                $rows[0],
                "a hook that said nothing is not a hook that said ''",
            );
        }
    }

    public function testAClaudeHookExitingTwoStillBlocks(): void
    {
        // The one code Claude Code DOES mean as a stop, and the one this step
        // must not soften: it stays the deny of the native table, unchanged.
        $result = $this->claudeRow("printf 'nope' >&2; exit 2")->execute($this->contextFor());

        self::assertFalse($result->isAllowed(), 'exit 2 is Claude Code\'s block and must still stop the call');
        self::assertStringContainsString('nope', $result->message);
        self::assertSame([], RuntimeNoticeSink::drain(), 'a verdict the dialect honours announces nothing');
    }

    public function testAClaudeHookAtExitZeroStillReadsItsEnvelope(): void
    {
        // "0 allows or reads the envelope" — the downgrade never touches the
        // allow path, so the JSON channel this file format also speaks stays on.
        $hook = $this->claudeRow('printf %s \'{"additionalContext":"from the envelope"}\'; exit 0');

        $result = $hook->execute($this->contextFor());

        self::assertTrue($result->isAllowed());
        self::assertSame('from the envelope', $result->additionalContext);
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    public function testAClaudeHookThatExceededItsTimeoutStillDenies(): void
    {
        // A timeout is not an exit code. "Has not answered" is not Claude
        // Code's non-blocking complaint — it is candy-code's own verdict, and
        // C-EXT-1 does not get to soften it.
        $hook = $this->claudeRow('sleep 5', timeoutSeconds: 0.4);

        $result = $hook->execute($this->contextFor());

        self::assertFalse($result->isAllowed(), 'a hook that never finished has not allowed anything');
        self::assertStringContainsString('did not finish within', $result->message);
        self::assertSame([], RuntimeNoticeSink::drain(), 'the timeout denies on its own voice, not a downgrade notice');
    }

    // =========================================================================
    // `async: true`: the mark that gives up the power to block
    // =========================================================================

    public function testAnAdvisoryClaudeHookExitingTwoProceedsAndSaysWhy(): void
    {
        // The case the stamp exists for: an `async` hook is not waiting for the
        // tool call, so Claude Code could not act on its block even if it
        // wanted to. candy-code runs it inline, but never lets it hold the door.
        $result = $this->claudeRow("printf 'too late anyway' >&2; exit 2", advisory: true)
            ->execute($this->contextFor());

        self::assertTrue($result->isAllowed(), 'an advisory row may not block a tool call');

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows);
        self::assertStringContainsString('async', $rows[0], 'the notice must say that it was the async mark, not the dialect, that let it through');
        self::assertStringContainsString('exited 2', $rows[0]);
        self::assertStringContainsString('too late anyway', $rows[0]);
    }

    public function testAnAdvisoryRowIsToldApartFromADialectDowngrade(): void
    {
        // Two different reasons, two different sentences: "Claude Code does not
        // treat this code as a decision" and "this hook asked only to watch".
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);
        $advisory = $this->claudeRow('exit 1', advisory: true)->execute($this->contextFor())->isAllowed();
        $advisoryRows = RuntimeNoticeSink::drain();

        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);
        $blocking = $this->claudeRow('exit 1')->execute($this->contextFor())->isAllowed();
        $blockingRows = RuntimeNoticeSink::drain();

        self::assertTrue($advisory && $blocking);
        self::assertCount(1, $advisoryRows);
        self::assertCount(1, $blockingRows);
        self::assertStringNotContainsString('async', $blockingRows[0]);
        self::assertStringNotContainsString('non-blocking complaint', $advisoryRows[0]);
        self::assertStringContainsString('non-blocking complaint', $blockingRows[0]);
    }

    // =========================================================================
    // The native path, untouched
    // =========================================================================

    public function testANativeRowStillDeniesOnEveryNonZeroAndRecordsNothing(): void
    {
        foreach ([1, 2, 5] as $code) {
            RuntimeNoticeSink::reset();
            RuntimeNoticeSink::arm(crossFork: false);

            $result = $this->nativeRow("printf 'native says stop' >&2; exit {$code}")
                ->execute($this->contextFor());

            self::assertFalse($result->isAllowed(), "a hooks.yaml row at exit {$code} keeps the table's deny");
            self::assertStringContainsString('native says stop', $result->message);
            self::assertSame([], RuntimeNoticeSink::drain(), 'the dialect must not reach into the native path at all');
        }
    }

    public function testExitThreeStillAsksNativelyAndProceedsUnderTheDialect(): void
    {
        // Under the claude dialect those two codes are not Claude Code's verbs
        // at all, so they lose their native power; the native rows keep them.
        $asked = $this->nativeRow("printf 'shall I?' >&2; printf 'please confirm'; exit 3")
            ->execute($this->contextFor());
        self::assertTrue($asked->isAsk());

        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);

        $modified = $this->claudeRow("printf '{\"command\":\"ls\"}'; exit 4")->execute($this->contextFor());
        self::assertTrue($modified->isAllowed(), 'exit 4 is not a code Claude Code defines, so it proceeds');
        self::assertCount(1, RuntimeNoticeSink::drain());
    }

    // =========================================================================
    // How the pair reaches a row at all
    // =========================================================================

    public function testFromConfigReadsThePairAndDefaultsToNative(): void
    {
        $stamped = ScriptHook::fromConfig([
            'name' => 'cc:project:PreToolUse:3',
            'event' => 'PreToolUse',
            'command' => 'exit 0',
            'dialect' => ScriptHook::DIALECT_CLAUDE,
            'advisory' => true,
        ]);
        self::assertSame(ScriptHook::DIALECT_CLAUDE, $stamped->dialect());
        self::assertTrue($stamped->isAdvisory());

        $plain = ScriptHook::fromConfig([
            'name' => 'native-entry',
            'event' => 'PreToolUse',
            'command' => 'exit 0',
        ]);
        self::assertSame(ScriptHook::DIALECT_NATIVE, $plain->dialect());
        self::assertFalse($plain->isAdvisory());
    }

    public function testNeitherFlagIsGuessedAt(): void
    {
        // No-coercion law, same as HookConfig's `disabled`: a dialect spelled
        // any other way than the constant is not that dialect, and a string
        // 'true' in a boolean slot is a file that means something else.
        foreach (['Claude', 'claude ', 1, true] as $spelling) {
            $hook = ScriptHook::fromConfig([
                'name' => 'x',
                'event' => 'PreToolUse',
                'command' => 'exit 0',
                'dialect' => $spelling,
            ]);
            self::assertSame(ScriptHook::DIALECT_NATIVE, $hook->dialect(), get_debug_type($spelling) . ' is not the claude dialect');
        }

        foreach (['true', 1, '1'] as $spelling) {
            $hook = ScriptHook::fromConfig([
                'name' => 'y',
                'event' => 'PreToolUse',
                'command' => 'exit 0',
                'dialect' => ScriptHook::DIALECT_CLAUDE,
                'advisory' => $spelling,
            ]);
            self::assertFalse($hook->isAdvisory(), 'a non-boolean advisory mark is no mark at all');
        }
    }

    public function testTheTimeoutClampCarriesTheDialect(): void
    {
        // {@see HookRegistry} clamps a row's budget by re-creating it through
        // withTimeoutSeconds(); if the pair did not ride that clone, a
        // long-timeout Claude hook would come back native and start denying.
        $hook = $this->claudeRow('exit 1', timeoutSeconds: 30.0, advisory: true);
        $clamped = $hook->withTimeoutSeconds(2.0);

        self::assertSame(ScriptHook::DIALECT_CLAUDE, $clamped->dialect());
        self::assertTrue($clamped->isAdvisory());
        self::assertSame(2.0, $clamped->timeoutSeconds());

        $result = $clamped->execute($this->contextFor());
        self::assertTrue($result->isAllowed(), 'and the clamp kept the reading, not only the fields');
        self::assertCount(1, RuntimeNoticeSink::drain());
    }

    // =========================================================================
    // The page that sells it
    // =========================================================================

    /**
     * The dialect is a documented behaviour, not a code reading. Both halves of
     * the promise live in `docs/HOOKS.md`: which keys the loader takes, and what
     * the codes mean once it has them.
     */
    public function testThePageSpellsTheDialectTheCodeRuns(): void
    {
        $doc = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');

        $from = strpos($doc, '## The exit-code contract');
        $to = strpos($doc, '### JSON on stdout');
        self::assertIsInt($from);
        self::assertIsInt($to);
        $exitSection = (string) preg_replace(
            '/\s+/',
            ' ',
            substr($doc, $from, $to - $from),
        );

        self::assertStringContainsString('Claude Code\'s two codes', $exitSection);
        self::assertMatchesRegularExpression(
            '/`(2|two)`[^.]{0,60}(block|stops)/',
            $exitSection,
            'the page must say which code Claude Code DOES mean as a stop',
        );
        self::assertStringContainsString('async', $exitSection);
        self::assertStringContainsString('proceeds at every non-zero code', $exitSection);

        // And the timeout claim, because that is the one a reader would
        // otherwise assume: "non-blocking" cannot mean "cannot fail to finish".
        self::assertMatchesRegularExpression(
            '/timeout[^.]{0,80}not[^.]{0,40}(downgrade|a downgrade)/',
            $exitSection,
            'the page must say a timeout stays a deny',
        );
    }

    // =========================================================================
    // Harness
    // =========================================================================

    private function claudeRow(string $command, float $timeoutSeconds = 5.0, bool $advisory = false): ScriptHook
    {
        return ScriptHook::fromConfig([
            // The name shape {@see \SugarCraft\Code\Hooks\ClaudeSettingsHooks}
            // mints since C-EXT-1.b: tier-qualified `cc:{scope}:{event}:{index}`
            // (rv1 NIT — a bare `cc:PreToolUse:0` is a name production can no
            // longer produce, and a fixture is what a reader copies).
            'name' => 'cc:user:PreToolUse:0',
            'event' => 'PreToolUse',
            'matcher' => '.*',
            'command' => $command,
            'description' => 'a Claude Code guard',
            'timeout' => $timeoutSeconds,
            'dialect' => ScriptHook::DIALECT_CLAUDE,
            'advisory' => $advisory,
        ]);
    }

    private function nativeRow(string $command, float $timeoutSeconds = 5.0): ScriptHook
    {
        return ScriptHook::fromConfig([
            'name' => 'native-entry',
            'event' => 'PreToolUse',
            'matcher' => '.*',
            'command' => $command,
            'description' => 'a hooks.yaml guard',
            'timeout' => $timeoutSeconds,
        ]);
    }

    private function contextFor(): HookContext
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
