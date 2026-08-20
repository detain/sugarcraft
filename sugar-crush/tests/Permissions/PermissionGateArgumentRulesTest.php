<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\ToolDeclaration;
use SugarCraft\Crush\ToolCall;

/**
 * Argument-scoped rules, THROUGH THE GATE — the deliverable of this change-set.
 *
 * The bug, measured on the build immediately before it, at
 * `PermissionGate::ruleMatches()`:
 *
 *     Deny Bash(rm -rf *)  vs  Bash(command: "rm -rf /tmp/mine")   => allow
 *     Deny Read(./.env)    vs  Read(file_path: "./.env")           => allow
 *
 * `Bash(rm -rf *)` ends with `*`, so the name matcher took `Bash(rm -rf ` as a
 * PREFIX of the tool name and missed; `Read(./.env)` does not, so it was
 * compared for equality with `Read` and missed. Both spellings are advertised on
 * `PermissionRule` and in `PermissionGate`'s own class doc-block, so a user who
 * wrote either had denied nothing while the documentation told them they had.
 *
 * THE MODE MATTERS TO THIS FIXTURE. Every case that observes a `Deny` runs under
 * {@see PermissionMode::BypassPermissions}, whose evaluator answers `Allow` for
 * everything, so the only thing that can produce a `Deny` is the rule — a
 * stricter mode would deny anyway and the test would pass against the broken
 * matcher. Every case that observes an `Allow` runs under
 * {@see PermissionMode::DontAsk}, whose evaluator denies non-read-only tools, so
 * the only thing that can produce an `Allow` is the rule. That pairing is what
 * makes each assertion about the RULE rather than about the mode; a first draft
 * of these cases that used BypassPermissions throughout reported `allow` for the
 * permissive cases no matter what the matcher did.
 */
final class PermissionGateArgumentRulesTest extends TestCase
{
    /** @param list<PermissionRule> $rules */
    private static function denyObservable(array $rules): PermissionGate
    {
        return new PermissionGate(PermissionMode::BypassPermissions, $rules);
    }

    /** @param list<PermissionRule> $rules */
    private static function allowObservable(array $rules): PermissionGate
    {
        return new PermissionGate(PermissionMode::DontAsk, $rules);
    }

    /**
     * THE regression. Fails against the unfixed build with `allow`.
     */
    public function testAnArgumentScopedBashDenyNowBlocksTheCall(): void
    {
        $gate = self::denyObservable([new PermissionRule('Bash(rm -rf *)', PermissionAction::Deny)]);

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'rm -rf /tmp/mine'])),
        );
    }

    /**
     * The second advertised spelling, which failed for the OTHER reason (no
     * trailing `*`, so it took the exact-equality branch). Two distinct broken
     * paths, so both need their own case.
     */
    public function testAnArgumentScopedPathDenyNowBlocksTheCall(): void
    {
        $gate = self::denyObservable([new PermissionRule('Read(./.env)', PermissionAction::Deny)]);

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Read', ['file_path' => './.env'])),
        );
        // Still a rule about one path.
        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Read', ['file_path' => './README.md'])),
        );
    }

    /**
     * PREFIX MATCHING ON THE REAL-CALL PATH, which had no test at all: only
     * `refuses()` (the declaration path) pinned it, so the whole `evaluate()`
     * side of the name matcher was unpinned while it was being rewritten.
     */
    public function testNameOnlyPrefixAndGlobRulesStillDecideARealCall(): void
    {
        $gate = self::denyObservable([
            new PermissionRule('Bash*', PermissionAction::Deny),
            new PermissionRule('mcp__git__*', PermissionAction::Deny),
        ]);

        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('mcp__git__push', [])));
        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('mcp__jira__push', [])));
        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Read', ['file_path' => 'x'])));
    }

    public function testAnArgumentScopedAllowGrantsOnlyTheCommandsItNames(): void
    {
        $gate = self::allowObservable([new PermissionRule('Bash(git *)', PermissionAction::Allow)]);

        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'git log --oneline'])),
        );
        // The chain the greedy `*` would have swallowed. DontAsk denies it
        // because the rule declined to fire, which is the safe direction.
        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'git log && rm -rf /'])),
        );
    }

    /**
     * First match wins, and it still does with argument scoping: a narrow
     * `Deny` written above a broad `Allow` must survive.
     */
    public function testFirstMatchWinsAcrossAMixOfScopedAndNameOnlyRules(): void
    {
        $gate = self::denyObservable([
            new PermissionRule('Bash(rm -rf *)', PermissionAction::Deny),
            new PermissionRule('Bash', PermissionAction::Allow),
        ]);

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'rm -rf /tmp/x'])),
        );
        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])),
        );
    }

    /**
     * THE BUNDLE'S CENTRAL DESIGN DECISION, pinned: an argument-scoped `Deny`
     * does NOT refuse a bare declaration, while a name-only one does.
     *
     * Chosen on cost — one `Deny Bash(rm -rf *)` would otherwise make every
     * workflow stage declaring `Bash` unusable, and a control that gets deleted
     * protects nothing. The reasoning is on
     * {@see PermissionRule::matches()}; this is the pin, and it fails in BOTH
     * directions: flip the decision and the first assertion breaks, switch rules
     * off for declarations wholesale and the second does.
     */
    public function testAnArgumentScopedDenyDoesNotRefuseABareDeclarationButANameOnlyOneDoes(): void
    {
        self::assertFalse(
            self::denyObservable([new PermissionRule('Bash(rm -rf *)', PermissionAction::Deny)])
                ->refuses(new ToolDeclaration('Bash')),
        );

        self::assertTrue(
            self::denyObservable([new PermissionRule('Bash', PermissionAction::Deny)])
                ->refuses(new ToolDeclaration('Bash')),
        );
    }

    /**
     * The declaration decision must not be reachable by the fail-closed
     * unknowable-subject branch either — an `mcp__*` tool has no mapped subject,
     * so a scoped rule naming one over-blocks a real CALL (safe) and still lets
     * the DECLARATION through (the decision above). Two different reasons, one
     * gate, and they must not be confused.
     */
    public function testAnUnmappedToolsScopedDenyOverBlocksTheCallButNotTheDeclaration(): void
    {
        $gate = self::denyObservable([new PermissionRule('mcp__git__*(force)', PermissionAction::Deny)]);

        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('mcp__git__push', [])));
        self::assertFalse($gate->refuses(new ToolDeclaration('mcp__git__push')));
    }

    /**
     * The unconditional `rm -rf /` breaker runs BEFORE rules, so an
     * argument-scoped `Allow` cannot talk the gate into a self-destruct — the
     * one thing the new matcher must not have made reachable.
     */
    public function testAnArgumentScopedAllowCannotDefeatTheRmRfRootBreaker(): void
    {
        $gate = self::denyObservable([new PermissionRule('Bash(rm -rf /)', PermissionAction::Allow)]);

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'rm -rf /'])),
        );
    }

    /**
     * B1, THROUGH THE GATE: the newline spelling of a command chain.
     *
     * The two decisions measured on the build immediately before this fix, with
     * the same rule and the same mode:
     *
     *     'echo hi && rm -rf /tmp/x'   => Deny
     *     "echo hi\nrm -rf /tmp/x"     => Ask   <- evaded
     *
     * Both spellings must now reach the same decision, and this asserts they are
     * EQUAL TO EACH OTHER as well as equal to `Deny`, so a future mode change
     * cannot make it pass for the wrong reason.
     */
    public function testTheNewlineSpellingOfACommandChainReachesTheSameDecisionAsTheAmpersandOne(): void
    {
        $gate = self::denyObservable([new PermissionRule('Bash(rm -rf *)', PermissionAction::Deny)]);

        $ampersand = $gate->evaluate(new ToolCall('Bash', ['command' => 'echo hi && rm -rf /tmp/x']));
        $newline = $gate->evaluate(new ToolCall('Bash', ['command' => "echo hi\nrm -rf /tmp/x"]));

        self::assertSame(PermissionDecision::Deny, $ampersand);
        self::assertSame($ampersand, $newline, 'a newline separates commands exactly as `&&` does');
    }

    /**
     * B3, THROUGH THE GATE: the spelling of the documented path example that a
     * model actually emits.
     *
     * `Deny Read(./.env)` is the example on {@see PermissionRule}, on
     * {@see PermissionGate} and in the README. Measured before the fix, under
     * this same fixture: `./.env` -> Deny, `.env` -> Ask. The `./`-prefixed
     * spelling is the LEAST likely one to arrive.
     */
    public function testThePathDenyInTheDocumentationCatchesTheSpellingAModelEmits(): void
    {
        $gate = self::denyObservable([new PermissionRule('Read(./.env)', PermissionAction::Deny)]);

        foreach (['./.env', '.env', './/.env', './foo/../.env', '/home/u/proj/.env'] as $path) {
            self::assertSame(
                PermissionDecision::Deny,
                $gate->evaluate(new ToolCall('Read', ['file_path' => $path])),
                $path,
            );
        }

        // Still a rule about `.env`, not about every file.
        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Read', ['file_path' => './README.md'])),
        );
    }

    /**
     * THE OPEN FINDING this change-set was asked to close: name-only PREFIX
     * matching on the REAL-CALL path was pinned by no test — only the
     * declaration path ({@see PermissionGate::refuses()}) had one — even though
     * it is the behaviour of the old matcher that had to survive the rewrite
     * verbatim.
     *
     * {@see testNameOnlyPrefixAndGlobRulesStillDecideARealCall()} covers the
     * positive direction; this covers the NEGATIVE one, which is where a prefix
     * matcher goes wrong: `Bash*` must not reach a differently-named tool, and
     * `mcp__git__*` must not reach another server's bridge.
     */
    public function testANameOnlyPrefixRuleStopsAtItsPrefixOnARealCall(): void
    {
        $gate = self::denyObservable([
            new PermissionRule('Bash*', PermissionAction::Deny),
            new PermissionRule('mcp__git__*', PermissionAction::Deny),
        ]);

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'git log'])),
        );
        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('mcp__git__push', ['branch' => 'master'])),
        );
        // The prefix must not spread to a tool that merely starts differently.
        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Read', ['file_path' => './x'])),
        );
        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('mcp__jira__push', ['id' => '1'])),
        );
    }

    /**
     * A malformed pattern reaching a gate anyway must not become a
     * deny-everything. {@see \SugarCraft\Crush\Cli\Bootstrap} warns and skips
     * such an entry before it gets here; this is the second line.
     */
    public function testAMalformedPatternDoesNotBrickTheGate(): void
    {
        $gate = self::denyObservable([new PermissionRule('Bash(rm -rf', PermissionAction::Deny)]);

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
    }
}
