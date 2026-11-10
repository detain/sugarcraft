<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\ChannelToolScope;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ToolDeclaration;
use SugarCraft\Code\ToolCall;

/**
 * D6: the channel tool scope is a restriction arm on the ONE decision path —
 * it refuses before the configured rules and every mode speak, it can never
 * grant, and it keeps the state law the declaration path already lives under
 * (a refuses() call must not rewrite what the last real evaluate() left).
 *
 * The pins below are read as a table of ARM ORDER, not of features: each one
 * is only green because the arm sits where {@see PermissionGate::decide()}
 * puts it, between the unconditional rm breaker and the keystroke/rules chain.
 *
 * @see PermissionGate::withChannelScope()
 */
final class PermissionGateChannelScopeTest extends TestCase
{
    /**
     * A lone '*' spelled through chr(42): a bare wildcard literal in a test
     * file is a glob-shaped token the GlobDialect corpus harvests.
     */
    private static function star(): string
    {
        return \chr(42);
    }

    private static function scope(array $allow = [], array $alsoAllow = [], array $deny = []): ChannelToolScope
    {
        return ChannelToolScope::of('discord group guild-1', 'group', $allow, $alsoAllow, $deny);
    }

    private static function allow(string $pattern): PermissionRule
    {
        return new PermissionRule($pattern, PermissionAction::Allow);
    }

    private static function deny(string $pattern): PermissionRule
    {
        return new PermissionRule($pattern, PermissionAction::Deny);
    }

    // ------------------------------------------------------------------
    // The arm refuses before rules and modes.
    // ------------------------------------------------------------------

    public function testAScopeDenialRefusesEvenAgainstAConfiguredAllowAndTheSilentestMode(): void
    {
        // A stale permissive Allow rule and bypassPermissions both ANSWER for
        // WebFetch below the chain — the scope answers before either speaks.
        $gate = (new PermissionGate(PermissionMode::BypassPermissions, [self::allow('WebFetch')]))
            ->withChannelScope(self::scope(deny: ['WebFetch']));

        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('WebFetch', ['url' => 'https://example.test'])),
        );
        $this->assertSame('denied by the discord group guild-1 tool policy', $gate->lastChannelReason());
    }

    public function testAMissingFromALiveAllowlistRefusesUnderBypassPermissions(): void
    {
        // OpenClaw allowlist semantics: a live list bars what is missing from
        // it, and an operator channel policy is not a question the session
        // mode gets to re-answer.
        $gate = (new PermissionGate(PermissionMode::BypassPermissions))
            ->withChannelScope(self::scope(allow: ['Read']));

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Edit', ['path' => 'a.php'])));
        $this->assertSame('not in the discord group guild-1 tool allowlist', $gate->lastChannelReason());
    }

    public function testAGlobSpelledScopeDenialRefusesTheFamilyItNames(): void
    {
        $gate = (new PermissionGate(PermissionMode::BypassPermissions))
            ->withChannelScope(self::scope(deny: ['mcp__git__' . self::star()]));

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('mcp__git__push', [])));
        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('mcp__notes__read', [])));
    }

    // ------------------------------------------------------------------
    // The arm can restrict — it can never grant.
    // ------------------------------------------------------------------

    public function testAScopeAllowFallsThroughToTheConfiguredDeny(): void
    {
        // The scope has no objection to Bash here; the configured Deny does.
        // If a scope allow ever SPOKE, this decision would flip and the reason
        // carrier would name the wrong authority.
        $gate = (new PermissionGate(PermissionMode::BypassPermissions, [self::deny('Bash')]))
            ->withChannelScope(self::scope(allow: ['Bash']));

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
        $this->assertNull($gate->lastChannelReason(), 'a falling-through allow must not author the refusal');
    }

    public function testAScopeAllowLeavesAnOrdinaryAskStanding(): void
    {
        $gate = (new PermissionGate(PermissionMode::Default))
            ->withChannelScope(self::scope(allow: ['Write']));

        $this->assertSame(PermissionDecision::Ask, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
        $this->assertNull($gate->lastChannelReason());
    }

    public function testTheUnconditionalRmBreakerSpeaksBeforeTheScope(): void
    {
        // The scope would refuse Bash by allowlist absence; the breaker is
        // first, so the refusal it authored carries no channel reason.
        $gate = (new PermissionGate(PermissionMode::Default))
            ->withChannelScope(self::scope(allow: ['Edit']));

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'rm -rf /'])));
        $this->assertNull($gate->lastChannelReason(), 'the breaker outranks the scope and authors its own refusal');
    }

    // ------------------------------------------------------------------
    // Clone law: the scope rides clones, never the receiver.
    // ------------------------------------------------------------------

    public function testAnEmptyScopeIsTheSameObjectAndARealScopeIsAClone(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);

        $this->assertSame($gate, $gate->withChannelScope(null), 'unscoped sessions stay byte-identical');
        $this->assertNull($gate->channelScope());

        $scoped = $gate->withChannelScope(self::scope(allow: ['Read']));
        $this->assertNotSame($gate, $scoped);
        $this->assertNull($gate->channelScope(), 'the receiver is never mutated');
        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Read', ['path' => 'a.php'])));
        $this->assertSame($scoped->channelScope(), $scoped->channelScope());
    }

    public function testWithModeCarriesTheScopeAcrossTheToggle(): void
    {
        $scope = self::scope(allow: ['Read']);
        $scoped = (new PermissionGate(PermissionMode::Default))->withChannelScope($scope);

        $toggled = $scoped->withMode(PermissionMode::BypassPermissions);
        $this->assertSame($scope, $toggled->channelScope(), 'a mode toggle must not silently un-scope the session');
        $this->assertSame(
            PermissionDecision::Deny,
            $toggled->evaluate(new ToolCall('Edit', ['path' => 'a.php'])),
            'the carried scope still binds after the toggle',
        );
    }

    // ------------------------------------------------------------------
    // State law: declarations read the scope but never write its reason.
    // ------------------------------------------------------------------

    public function testTheDeclarationPathHonoursTheScopeAndLeavesItsReasonAlone(): void
    {
        // The two denials must carry DIFFERENT reasons: a declaration that
        // overwrote the carrier with its own verdict would otherwise stay
        // invisible whenever the two strings happen to match.
        $scope = self::scope(allow: ['Read'], deny: ['Bash']);
        $gate = (new PermissionGate(PermissionMode::Default))->withChannelScope($scope);

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
        $reason = $gate->lastChannelReason();
        $this->assertNotNull($reason);

        // A declaration for a name the scope denies answers true — for the
        // allowlist-missing name and for the deny-listed one — while leaving
        // the REAL call's reason in place.
        $this->assertTrue($gate->refuses(new ToolDeclaration('Write')));
        $this->assertTrue($gate->refuses(new ToolDeclaration('Bash')));
        $this->assertStringContainsString('allowlist', $reason, 'the real call was an allowlist absence');
        $this->assertSame($reason, $gate->lastChannelReason(), 'a declaration must leave what the last real call wrote');
        $this->assertNull($gate->lastAutoReason(), 'the scope arm authors only its own carrier');
    }

    public function testARealCallResetsTheChannelReasonLeftByThePreviousCall(): void
    {
        $gate = (new PermissionGate(PermissionMode::Default))->withChannelScope(self::scope(allow: ['Read']));

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
        $this->assertNotNull($gate->lastChannelReason());

        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Read', ['path' => 'a.php'])));
        $this->assertNull($gate->lastChannelReason(), 'a scope verdict is per call, not sticky');
    }

    // ------------------------------------------------------------------
    // Orthogonality with the existing scopes.
    // ------------------------------------------------------------------

    public function testTurnDeniesAndTheScopeCoexistWithTheirOwnAttribution(): void
    {
        // The scope allows Read and Bash; a turn grant denies Bash. Bash's
        // refusal is the TURN arm's authorship (the scope fell through), and
        // Write's refusal is the SCOPE's (the turn list never named it).
        $gate = (new PermissionGate(PermissionMode::BypassPermissions))
            ->withChannelScope(self::scope(allow: ['Read', 'Bash']))
            ->withTurnGrants([], [self::deny('Bash')]);

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
        $this->assertNull($gate->lastChannelReason(), 'the turn arm spoke, not the scope');

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
        $this->assertSame('not in the discord group guild-1 tool allowlist', $gate->lastChannelReason());
    }

    public function testASessionGrantCannotResurrectWhatTheScopeBars(): void
    {
        // Session grants answer Ask only — but they live BELOW this arm
        // anyway: an allowlist absence never even reaches them.
        $gate = (new PermissionGate(PermissionMode::Default))
            ->withSessionRules([self::allow('Write')])
            ->withChannelScope(self::scope(allow: ['Read']));

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Write', ['path' => 'a.php'])));
        $this->assertSame('not in the discord group guild-1 tool allowlist', $gate->lastChannelReason());
    }

    public function testABrokenScopeRefusesEverythingItIsAskedAbout(): void
    {
        $broken = ChannelToolScope::refusingAll('discord group guild-1', 'group', 'tools.deny was not a list');
        $gate = (new PermissionGate(PermissionMode::BypassPermissions))->withChannelScope($broken);

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Read', ['path' => 'a.php'])));
        $this->assertStringContainsString('could not be honoured', (string) $gate->lastChannelReason());
        $this->assertTrue($broken->blocksEverything());
    }
}
