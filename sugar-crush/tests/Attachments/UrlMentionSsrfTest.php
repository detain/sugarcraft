<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Attachments;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Attachments\ContextMentions;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\ToolCall;

/**
 * Roadmap 5.8: an `@https://…` mention is fetched by the USER's hand, but
 * through the same guards the model's `WebFetch` meets — the SSRF block list
 * refuses loopback, link-local, private and metadata addresses before any
 * socket is opened — and a permission rule that denies `WebFetch` a domain
 * denies the mention too.
 */
final class UrlMentionSsrfTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function internalUrls(): iterable
    {
        yield 'localhost' => ['http://localhost:8080/admin'];
        yield 'loopback literal' => ['http://127.0.0.1/'];
        yield 'loopback v6' => ['http://[::1]/'];
        yield 'unspecified' => ['http://0.0.0.0/'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'private 10/8' => ['http://10.0.0.1/'];
        yield 'private 192.168/16' => ['https://192.168.1.1/router'];
        yield 'v4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
    }

    #[DataProvider('internalUrls')]
    public function testTheDefaultFetchRefusesInternalAddresses(string $url): void
    {
        $r = ContextMentions::new(sys_get_temp_dir())->resolve("look at @$url");

        self::assertSame([], $r['attachments'], "$url was attached");
        self::assertCount(1, $r['notices']);
        self::assertStringStartsWith("@$url was not attached: Error", $r['notices'][0]);
    }

    public function testADomainRuleThatDeniesWebFetchDeniesTheMention(): void
    {
        $gate = new PermissionGate(
            PermissionMode::BypassPermissions,
            [new PermissionRule('WebFetch(domain:intranet.example)', PermissionAction::Deny)],
        );

        self::assertSame(
            PermissionDecision::Deny,
            $gate->ruleDecision(new ToolCall('WebFetch', ['url' => 'https://wiki.intranet.example/page'])),
        );
        self::assertNull($gate->ruleDecision(new ToolCall('WebFetch', ['url' => 'https://example.com/'])));

        $fetched = false;
        $r = ContextMentions::new(sys_get_temp_dir())
            ->withUrlPolicy(static fn (string $url): ?string => $gate->ruleDecision(new ToolCall('WebFetch', ['url' => $url])) === PermissionDecision::Deny
                ? 'a permission rule denies WebFetch for it.'
                : null)
            ->withFetcher(static function () use (&$fetched): never {
                $fetched = true;

                throw new \LogicException('must not be fetched');
            })
            ->resolve('@https://wiki.intranet.example/page');

        self::assertFalse($fetched);
        self::assertSame([], $r['attachments']);
        self::assertStringContainsString('was not fetched: a permission rule denies WebFetch for it.', $r['notices'][0]);
    }

    public function testRuleDecisionIgnoresTheModeAndMovesNoCounter(): void
    {
        // Plan mode refuses WebFetch as a TOOL call; the rules alone say nothing
        // about it, so a user-typed URL is not refused by the mode.
        $gate = new PermissionGate(PermissionMode::Plan);

        self::assertNull($gate->ruleDecision(new ToolCall('WebFetch', ['url' => 'https://example.com/'])));
    }
}
