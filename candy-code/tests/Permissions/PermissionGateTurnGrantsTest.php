<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ToolDeclaration;
use SugarCraft\Code\ToolCall;

/**
 * C-EXT-2.b (R-EXT-1): the turn grants a skill declared are a THIRD rule set
 * on the gate — beside the configured rules and the session grants — with its
 * own exact position in the decision order, and this file pins that position
 * from both ends.
 *
 *  - a turn DENY refuses before the mode speaks: what removing a tool from an
 *    agent's world means is that no mode, not even accept-edits, bypass or
 *    dont-ask, ever answers for it;
 *  - a turn ALLOW only ever answers a question — like a session grant it is
 *    consulted after everything else, it cannot lift a configured Deny, and it
 *    cannot outrank the unconditional rm breaker;
 *  - matching is AS WRITTEN, per call: the memo rewrite and segment coverage
 *    that session grants earn do not apply, because these grants were never
 *    negotiated with the user.
 *
 * @see PermissionGate::withTurnGrants()
 */
final class PermissionGateTurnGrantsTest extends TestCase
{
    private static function allow(string $pattern): PermissionRule
    {
        return new PermissionRule($pattern, PermissionAction::Allow);
    }

    private static function deny(string $pattern): PermissionRule
    {
        return new PermissionRule($pattern, PermissionAction::Deny);
    }

    // ------------------------------------------------------------------
    // A turn refusal refuses before the mode.
    // ------------------------------------------------------------------

    public function testATurnRefusalRefusesWhereAcceptEditsWouldAllow(): void
    {
        $gate = (new PermissionGate(PermissionMode::AcceptEdits))
            ->withTurnGrants([], [self::deny('Write')]);

        // AcceptEdits allows Write outright — the turn refusal answers first.
        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])),
        );
    }

    public function testATurnRefusalRefusesUnderEverySilentMode(): void
    {
        foreach ([PermissionMode::DontAsk, PermissionMode::BypassPermissions] as $mode) {
            $gate = (new PermissionGate($mode))->withTurnGrants([], [self::deny('Bash')]);

            $this->assertSame(
                PermissionDecision::Deny,
                $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])),
                $mode->value . ': a skill that removed Bash must not be routed around by the mode',
            );
        }
    }

    public function testATurnRefusalTakesAConfiguredAskAway(): void
    {
        // Default mode asks for Write. A configured rule could only ask too
        // (first-match answer), and the turn refusal still takes it away —
        // that is what "the tool is off while this skill runs" means.
        $gate = (new PermissionGate(PermissionMode::Default, [self::allow('Read')]))
            ->withTurnGrants([], [self::deny('Write')]);

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
    }

    public function testATurnRefusalCannotOvertakeAConfiguredAnswer(): void
    {
        // The configured rules are checked first and a configured ALLOW is an
        // answer — the user negotiated that one; the skill did not.
        $gate = (new PermissionGate(PermissionMode::Default, [self::allow('Write')]))
            ->withTurnGrants([], [self::deny('Write')]);

        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
    }

    public function testAnArgumentScopedRefusalRefusesTheCallNotTheTool(): void
    {
        $gate = (new PermissionGate(PermissionMode::BypassPermissions))
            ->withTurnGrants([], [self::deny('Bash(rm *)')]);

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'rm -rf tmp'])));
        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
    }

    public function testTurnRefusalsAnswerTheDeclarationPathOnlyWhenAsWritten(): void
    {
        // A declaration carries no arguments: a bare-name refusal must answer
        // it (the tool is off, full stop) while an argument-scoped one cannot
        // settle a question it was never asked — fail-closed, same shape as a
        // configured deny on the same path.
        $bare = (new PermissionGate(PermissionMode::AcceptEdits))->withTurnGrants([], [self::deny('WebFetch')]);
        $this->assertTrue($bare->refuses(new ToolDeclaration('WebFetch')));

        $scoped = (new PermissionGate(PermissionMode::AcceptEdits))->withTurnGrants([], [self::deny('Bash(rm *)')]);
        $this->assertFalse($scoped->refuses(new ToolDeclaration('Bash')));
    }

    // ------------------------------------------------------------------
    // A turn allowance only ever answers a question.
    // ------------------------------------------------------------------

    public function testATurnAllowmentAnswersTheModesAsk(): void
    {
        $gate = (new PermissionGate(PermissionMode::Default))
            ->withTurnGrants([self::allow('Write')], []);

        // Default would ask for Write; the skill listed it — R-EXT-1's
        // as-written honour answers the question the mode asked.
        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
    }

    public function testATurnAllowmentNeverLiftsAConfiguredDeny(): void
    {
        $gate = (new PermissionGate(PermissionMode::Default, [self::deny('Write')]))
            ->withTurnGrants([self::allow('Write')], []);

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
    }

    public function testATurnAllowmentNeverLiftsTheBreaker(): void
    {
        // The `rm -rf /` breaker is step zero and unconditional; a skill's
        // list has no standing there, and an agent could as easily have typed
        // the command as invoked the skill that granted it.
        $gate = (new PermissionGate(PermissionMode::Default))->withTurnGrants([self::allow('Bash')], []);

        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'rm -rf /'])),
        );
        // The same grant still answers an ordinary question.
        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
    }

    public function testATurnAllowmentMatchesAsWrittenPerCall(): void
    {
        // No session-grant machinery rides along: an argument-scoped
        // allowance matches the argument prefix, and a sibling prefix is a
        // different question — still asked, still parked.
        $gate = (new PermissionGate(PermissionMode::Default))
            ->withTurnGrants([self::allow('Bash(composer *)')], []);

        $this->assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'composer require x'])),
        );
        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'php -v'])),
        );
    }

    // ------------------------------------------------------------------
    // The seam itself: construction discipline and carrying.
    // ------------------------------------------------------------------

    public function testTheGrantListsAreValidatedAtTheDoor(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);

        $this->assertFalse($gate->hasTurnGrants());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A turn permission grant can only allow.');
        $gate->withTurnGrants([self::deny('Write')], []);
    }

    public function testARefusalListRejectsAnythingThatIsNotADeny(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A turn permission refusal can only deny.');
        (new PermissionGate(PermissionMode::Default))->withTurnGrants([], [self::allow('Write')]);
    }

    public function testAModeSwitchCarriesTheTurnGrantsForward(): void
    {
        // Changing the mode mid-turn must not launder away a skill's refusal
        // — the grants belong to the turn, and the turn survives the switch.
        $gate = (new PermissionGate(PermissionMode::Default, []))
            ->withTurnGrants([self::allow('Write')], [self::deny('WebFetch')])
            ->withMode(PermissionMode::AcceptEdits);

        $this->assertTrue($gate->hasTurnGrants());
        $this->assertCount(1, $gate->turnAllowRules());
        $this->assertCount(1, $gate->turnDenyRules());
        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('WebFetch', ['url' => 'https://example.test'])),
        );
    }

    public function testTheBaseGateAnswersUngrantedCallsUnchanged(): void
    {
        // The negative control for every pin above: with no grants at all the
        // mode answers exactly as before this step existed.
        $gate = new PermissionGate(PermissionMode::Default);

        $this->assertFalse($gate->hasTurnGrants());
        $this->assertSame(PermissionDecision::Ask, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
    }
}
