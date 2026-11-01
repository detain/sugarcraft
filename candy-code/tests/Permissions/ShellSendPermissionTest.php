<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Permissions\ShellSubjectTools;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\ShellSend;

/**
 * C-TOOL-6.k: the permission posture of a typed send. The design's argument
 * is a roster decision — ShellSend is NOT a shell subject (a keystroke
 * template is not a `/bin/sh -c` line), and it buys the honesty with a
 * stricter ask: every send is a forced question in every non-bypass mode,
 * no session "always" can answer it, `auto` neither Allows nor strikes, and
 * an explicit rule must name ShellSend itself — a `Bash(git *)` grant
 * covering `git status<Enter>` typed into a REPL would hand the terminal
 * whatever program happens to be holding it.
 *
 * Underneath all of that sits the mode-independent floor: the decoded lines
 * of the template run the same rm-rf word check as a direct shell call, so
 * typing `rm -rf /` at a rescue shell is refused even under
 * `bypass-permissions`. No call in this file executes — pure gate
 * evaluation, so the destructive spellings are safe as strings.
 */
final class ShellSendPermissionTest extends TestCase
{
    /**
     * @param array<string, mixed> $arguments
     */
    private function spCall(array $arguments = ['shell_id' => 'sh-12345678', 'keys' => 'ls<Enter>']): ToolCall
    {
        return new ToolCall(ShellSend::NAME, $arguments);
    }

    /**
     * @param list<PermissionRule> $rules
     */
    private function spGate(PermissionMode $mode, array $rules = []): PermissionGate
    {
        return new PermissionGate($mode, $rules, new SafetyClassifier());
    }

    // ── the forced ask ───────────────────────────────────────────────────

    public function testEveryNonBypassModeAsksForASend(): void
    {
        foreach ([PermissionMode::Default, PermissionMode::AcceptEdits, PermissionMode::Auto] as $mode) {
            $gate = $this->spGate($mode);
            self::assertSame(
                PermissionDecision::Ask,
                $gate->evaluate($this->spCall()),
                'a typed send is a question in ' . $mode->value . ' too — the shell it reaches is not the command this call spawned',
            );
            self::assertTrue($gate->lastAskForced(), 'the modal badge must say this always asks');
            self::assertStringContainsString('keystrokes', (string) $gate->lastAutoReason());
        }
    }

    public function testPlanAndDontAskRefuseAWriteClassSend(): void
    {
        // The ask is forced, but a mode that REFUSES the class outright still
        // refuses: forced-ask raises a question, it never downgrades a Deny.
        self::assertSame(PermissionDecision::Deny, $this->spGate(PermissionMode::Plan)->evaluate($this->spCall()));
        self::assertSame(PermissionDecision::Deny, $this->spGate(PermissionMode::DontAsk)->evaluate($this->spCall()));
    }

    public function testASessionAlwaysCannotAnswerATypedSend(): void
    {
        $grant = new PermissionRule(ShellSend::NAME, PermissionAction::Allow);
        self::assertSame(
            PermissionDecision::Ask,
            $this->spGate(PermissionMode::Default)->withSessionRules([$grant])->evaluate($this->spCall()),
            'the forced-ask mark skips the session fallback — no "always" was legitimately minted from a keystroke question',
        );
    }

    public function testAnExplicitPermissionRuleAnswersBeforeTheMode(): void
    {
        // The designed exception to the every-time ask: the rules layer is
        // first-match and its Allow is a written decision by the user, not a
        // remembered button press.
        self::assertSame(
            PermissionDecision::Allow,
            $this->spGate(PermissionMode::Default, [new PermissionRule(ShellSend::NAME, PermissionAction::Allow)])
                ->evaluate($this->spCall()),
            'an explicit Allow ShellSend covers every shell and every key — the name-only grant is the whole spellable surface',
        );
        self::assertSame(
            PermissionDecision::Deny,
            $this->spGate(PermissionMode::BypassPermissions, [new PermissionRule(ShellSend::NAME, PermissionAction::Deny)])
                ->evaluate($this->spCall()),
            'and the user can close the door entirely — deny beats bypass, as always',
        );
    }

    // ── auto: asks, never strikes, never resets ─────────────────────────

    public function testAutoAsksWithoutTouchingTheBreakerCounters(): void
    {
        $gate = $this->spGate(PermissionMode::Auto);
        $before = $gate->autoBreaker();

        self::assertSame(PermissionDecision::Ask, $gate->evaluate($this->spCall()));
        $after = $gate->autoBreaker();

        self::assertSame($before, $after, 'an Ask returned before classify() is neither a strike nor a safe call — the counters stay exactly where the turn left them');
        self::assertSame(0, $after['consecutiveBlocks']);
        self::assertSame(0, $after['totalBlocks']);

        // Non-vacuity: the SAME gate judging a shell-subject call whose text
        // the classifier flags does strike — the untouched counters above are
        // the keystroke arm returning first, not counters that never move.
        $striking = $this->spGate(PermissionMode::Auto);
        $striking->evaluate(new ToolCall('Bash', ['command' => 'terraform destroy --auto-approve']));
        $afterStrike = $striking->autoBreaker();
        self::assertGreaterThan(0, $afterStrike['totalBlocks'], 'the pin compares against a gate whose counters DO move');

        // And a send judged by that armed gate neither adds a strike nor
        // resets the run it already carries — the arm returns before the
        // counters are ever reached, in both directions.
        self::assertSame(PermissionDecision::Ask, $striking->evaluate($this->spCall()));
        self::assertSame($afterStrike, $striking->autoBreaker(), 'the strike run stands exactly where the flagged Bash call left it');
    }

    public function testAutoNeverAllowsATypedSendEvenWithBlandKeys(): void
    {
        // The keystroke arm sits BEFORE the classifier: 'ls<Enter>' classifies
        // nothing (no command subject at all), yet still cannot be Allowed —
        // auto's answer is the question, not a pass.
        $gate = $this->spGate(PermissionMode::Auto);
        self::assertSame(PermissionDecision::Ask, $gate->evaluate($this->spCall(['shell_id' => 'sh-12345678', 'keys' => 'ls<Enter>'])));
    }

    // ── bypass: answers, but never past the floor ────────────────────────

    public function testBypassAnswersASendAndOnlyTheFloorStillRefuses(): void
    {
        self::assertSame(
            PermissionDecision::Allow,
            $this->spGate(PermissionMode::BypassPermissions)->evaluate($this->spCall(['shell_id' => 'sh-12345678', 'keys' => 'make<Enter>'])),
            'bypass-permissions answers the ask like it answers every ask — the design sentence is "every NON-bypass mode asks"',
        );
        foreach ([
            'rm -rf /<Enter>',
            'rm -rf ~<Enter>',
            'echo hi && rm -rf /<Enter>',
            'clear<Enter>sudo rm -rf $HOME<Enter>',
        ] as $typed) {
            self::assertSame(
                PermissionDecision::Deny,
                $this->spGate(PermissionMode::BypassPermissions)->evaluate($this->spCall(['shell_id' => 'sh-12345678', 'keys' => $typed])),
                'the floor reads the DECODED lines: ' . $typed . ' is the same self-destruct typed at a live shell',
            );
        }
        // The floor keeps its honesty bound: a breaker-clean rm still passes
        // (it is a guard rail, not a containment wall — same ceiling the
        // Bash/PtyRun breaker documents).
        self::assertNotSame(
            PermissionDecision::Deny,
            $this->spGate(PermissionMode::BypassPermissions)->evaluate($this->spCall(['shell_id' => 'sh-12345678', 'keys' => 'rm -rf build<Enter>'])),
        );
    }

    public function testTheFloorFiresBeforeAnyModeOrGrant(): void
    {
        // Step-0 discipline: an explicit Allow ShellSend does NOT buy a typed
        // root wipe — the breaker runs before the rules layer, exactly as it
        // does for a direct Bash call.
        $gate = $this->spGate(PermissionMode::Default, [new PermissionRule(ShellSend::NAME, PermissionAction::Allow)]);
        self::assertSame(PermissionDecision::Deny, $gate->evaluate($this->spCall(['shell_id' => 'sh-12345678', 'keys' => 'rm -rf /<Enter>'])));
    }

    // ── the roster decision, EXEC'd ──────────────────────────────────────

    public function testShellRulesNeverReachATypedSend(): void
    {
        // The over-grant the design refuses: shell-subject rules — cross-
        // binding proxy included — must not answer for keystrokes. None of
        // these matches a ShellSend call.
        $call = $this->spCall(['shell_id' => 'sh-12345678', 'keys' => 'git status<Enter>']);
        foreach ([
            new PermissionRule('Bash(git *)', PermissionAction::Allow),
            new PermissionRule('PtyRun(git *)', PermissionAction::Allow),
            new PermissionRule('Bash', PermissionAction::Allow),
            new PermissionRule('PtyRun', PermissionAction::Deny),
            new PermissionRule('Bash(rm *)', PermissionAction::Deny),
        ] as $rule) {
            self::assertFalse($rule->matches($call), 'a shell-subject rule must not bind the typed half: ' . $rule->pattern);
        }

        self::assertFalse(ShellSubjectTools::covers(ShellSend::NAME));
        self::assertSame(['Bash', 'PtyRun'], ShellSubjectTools::NAMES, 'the roster did not grow — the keystroke arm is a NAME test in the gate, not a subject claim');
        self::assertContains(ShellSend::NAME, Runtime::WRITE_CAPABLE_TOOL_NAMES);
    }

    public function testTheClassDocblockCarriesTheRosterArgument(): void
    {
        // The review asks for the WHY beside the code, not only in the PR:
        // the non-membership argument lives in the class docblock and this
        // pin keeps it from being tidied away.
        $doc = (string) (new \ReflectionClass(ShellSend::class))->getDocComment();
        self::assertStringContainsString('ShellSubjectTools', $doc);
        self::assertStringContainsString('/bin/sh -c', $doc, 'the sentence naming what the roster means must survive');
    }
}
