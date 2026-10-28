<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\ServerConfigException;

/**
 * AUT-6: the `webhooks` map is parsed and refused at LAUNCH — one malformed
 * hook is a startup refusal (exit 2 via `Serve::start()`), never a half-mounted
 * route and never a surprise on the first delivery.
 *
 * The floors pinned here: routeable names, a secret long enough to be a
 * credential, a prompt (the body is spliced INTO it; with no prompt the payload
 * would BE the instruction), the per-minute rate bounds, unknown fields, the
 * signature scheme's header pair (C-AGT-5.d: a named scheme, two optional
 * single-token header names), and —
 * the restrictive permission floor — `bypass-permissions` refused even under
 * `--allow-bypass`, because a webhook is an unattended session and bypass
 * answers every permission ask YES with nobody asked (AUT-6-P2 narrowed the
 * door to bypass alone: `dont-ask` answers the same ask by REFUSING, so it is
 * the accepted strictest row).
 */
final class ServerConfigWebhooksTest extends TestCase
{
    private const SECRET = 's';

    public function testAnAbsentKeyMeansNoHooksAndNoRoute(): void
    {
        self::assertSame([], self::resolve([])->webhooks);
    }

    public function testAValidMapResolvesToTheTrustedRuntimeShape(): void
    {
        $config = self::resolve([
            'webhooks' => [
                'deploy' => ['secret' => \str_repeat(self::SECRET, 40), 'prompt' => 'Look at {{payload}}'],
                'nightly' => ['secret' => \str_repeat('t', 32), 'prompt' => 'Run the nightly review', 'permissionMode' => 'plan', 'rate' => 2],
            ],
        ]);

        self::assertSame([
            'deploy' => ['secret' => \str_repeat(self::SECRET, 40), 'prompt' => 'Look at {{payload}}', 'permissionMode' => null, 'rate' => ServerConfig::DEFAULT_HOOK_RATE_PER_MINUTE, 'signature' => null],
            'nightly' => ['secret' => \str_repeat('t', 32), 'prompt' => 'Run the nightly review', 'permissionMode' => PermissionMode::Plan, 'rate' => 2, 'signature' => null],
        ], $config->webhooks);
    }

    public function testTheSecretLowerBoundIsInclusiveAndNamed(): void
    {
        $hooks = self::resolve(['webhooks' => ['h' => ['secret' => \str_repeat(self::SECRET, ServerConfig::MIN_HOOK_SECRET_BYTES), 'prompt' => 'p']]])->webhooks;
        self::assertCount(1, $hooks);

        $this->expectException(ServerConfigException::class);
        $this->expectExceptionMessage('webhook "h": secret must be a string of at least 32 characters');
        ServerConfig::webhooksValue(['h' => ['secret' => \str_repeat(self::SECRET, ServerConfig::MIN_HOOK_SECRET_BYTES - 1), 'prompt' => 'p']]);
    }

    public function testTheRateBoundsAreInclusive(): void
    {
        foreach ([1, ServerConfig::MAX_HOOK_RATE_PER_MINUTE] as $rate) {
            $hooks = self::resolve(['webhooks' => ['h' => ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p', 'rate' => $rate]]])->webhooks;
            self::assertSame($rate, $hooks['h']['rate']);
        }
    }

    public function testTheWebhooksSurviveEveryOtherWither(): void
    {
        $config = self::resolve(['webhooks' => ['h' => ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p']]]);

        // mutate() rebuilds from get_object_vars: a new promoted property rides
        // every sibling setter without the setters knowing it exists.
        $moved = $config->withHost('127.0.0.1')->withPort(7421)->withMaxOpenSessions(3);
        self::assertSame($config->webhooks, $moved->webhooks);
    }

    public function testABypassModeIsRefusedEvenWhenTheServerAllowsBypass(): void
    {
        $hooks = ['h' => ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p', 'permissionMode' => 'bypass-permissions']];
        $refusals = 0;
        foreach ([['--allow-bypass' => true], []] as $flags) {
            try {
                ServerConfig::resolve($flags, [], ['server.allowBypass' => true, 'webhooks' => $hooks], '/state');
                self::fail('a bypass-class hook mode must refuse the launch');
            } catch (ServerConfigException $e) {
                self::assertStringContainsString('refused for hooks even with --allow-bypass', $e->getMessage());
                ++$refusals;
            }
        }
        self::assertSame(2, $refusals, 'both the flag route and the config route carry the refusal');
    }

    /**
     * The AUT-6-P2 flip: `dont-ask` is the strictest row an operator can name
     * — it never asks, it refuses — so it is ACCEPTED where the old door
     * refused it. Refusing it taught omission of the field, and omission
     * inherits, which is the path the widened clamp used to loosen.
     */
    public function testADontAskModeIsAcceptedAsTheStrictestRow(): void
    {
        $hooks = ServerConfig::webhooksValue(['h' => ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p', 'permissionMode' => 'dont-ask']]);

        self::assertSame(PermissionMode::DontAsk, $hooks['h']['permissionMode']);
    }

    /**
     * C-AGT-5.d: a scheme resolves its header pair; an override replaces one
     * member and is lower-cased (PSR-7 header lookup is case-insensitive, and
     * the scheme defaults are spelled lower — one spelling avoids surprises in
     * logs); `hmac-sha256` carries no default delivery header, so dedupe is
     * opt-in for generic senders.
     */
    public function testTheSignatureSchemeResolvesItsHeaderPair(): void
    {
        $hooks = ServerConfig::webhooksValue([
            'gh' => ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p', 'signature' => ['scheme' => 'github-sha256']],
            'custom' => ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p', 'signature' => ['scheme' => 'github-sha256', 'header' => 'X-Hub-Signature-256', 'deliveryHeader' => 'X-Request-UUID']],
            'hmac' => ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p', 'signature' => ['scheme' => 'hmac-sha256']],
        ]);

        self::assertSame(['scheme' => 'github-sha256', 'header' => 'x-hub-signature-256', 'deliveryHeader' => 'x-github-delivery'], $hooks['gh']['signature']);
        self::assertSame(['scheme' => 'github-sha256', 'header' => 'x-hub-signature-256', 'deliveryHeader' => 'x-request-uuid'], $hooks['custom']['signature']);
        self::assertSame(['scheme' => 'hmac-sha256', 'header' => 'x-signature', 'deliveryHeader' => null], $hooks['hmac']['signature']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function signatureRefusals(): array
    {
        $good = ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p'];

        return [
            'signature not a map' => [['webhooks' => ['h' => $good + ['signature' => 'github-sha256']]], 'signature must be a map'],
            'missing scheme' => [['webhooks' => ['h' => $good + ['signature' => ['header' => 'x-signature']]]], 'signature scheme "null" is not one of'],
            'unknown scheme' => [['webhooks' => ['h' => $good + ['signature' => ['scheme' => 'yolo']]]], 'signature scheme "yolo" is not one of'],
            'non-string scheme' => [['webhooks' => ['h' => $good + ['signature' => ['scheme' => 7]]]], 'signature scheme "7" is not one of'],
            'header not a header name' => [['webhooks' => ['h' => $good + ['signature' => ['scheme' => 'hmac-sha256', 'header' => 'not a header']]]], 'signature header must be an HTTP header name'],
            'deliveryHeader not a header name' => [['webhooks' => ['h' => $good + ['signature' => ['scheme' => 'hmac-sha256', 'deliveryHeader' => 42]]]], 'signature deliveryHeader must be an HTTP header name'],
            'signature unknown setting' => [['webhooks' => ['h' => $good + ['signature' => ['scheme' => 'hmac-sha256', 'algo' => 'sha1']]]], 'signature unknown setting "algo"'],
        ];
    }

    #[DataProvider('signatureRefusals')]
    public function testAMalformedSignatureIsAStartupExceptionNamingItself(array $userConfig, string $needle): void
    {
        try {
            ServerConfig::resolve([], [], $userConfig, '/state');
        } catch (ServerConfigException $e) {
            self::assertStringContainsString($needle, $e->getMessage());

            return;
        }
        self::fail('expected a ServerConfigException mentioning: ' . $needle);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusalCases(): array
    {
        $good = ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => 'p'];

        return [
            'list instead of map' => [['webhooks' => [$good]], 'must be a map'],
            'hook value not a map' => [['webhooks' => ['h' => $good['secret']]], 'webhook "h": its value must be a map'],
            'uppercase name' => [['webhooks' => ['Deploy' => $good]], 'the name must be'],
            'leading hyphen' => [['webhooks' => ['-x' => $good]], 'the name must be'],
            'dotted name' => [['webhooks' => ['a.b' => $good]], 'the name must be'],
            'slash in name' => [['webhooks' => ['a/b' => $good]], 'the name must be'],
            'over-long name' => [['webhooks' => [\str_repeat('x', 65) => $good]], 'the name must be'],
            'missing secret' => [['webhooks' => ['h' => ['prompt' => 'p']]], 'secret must be a string'],
            'non-string secret' => [['webhooks' => ['h' => ['secret' => 12345, 'prompt' => 'p']]], 'secret must be a string'],
            'missing prompt' => [['webhooks' => ['h' => ['secret' => \str_repeat(self::SECRET, 32)]]], 'prompt must be a non-empty string'],
            'blank prompt' => [['webhooks' => ['h' => ['secret' => \str_repeat(self::SECRET, 32), 'prompt' => '   ']]], 'prompt must be a non-empty string'],
            'unknown mode' => [['webhooks' => ['h' => $good + ['permissionMode' => 'yolo']]], 'permission mode "yolo" is not one of'],
            'zero rate' => [['webhooks' => ['h' => $good + ['rate' => 0]]], 'rate must be a whole number'],
            'over ceiling rate' => [['webhooks' => ['h' => $good + ['rate' => ServerConfig::MAX_HOOK_RATE_PER_MINUTE + 1]]], 'rate must be a whole number'],
            'string rate' => [['webhooks' => ['h' => $good + ['rate' => '5']]], 'rate must be a whole number'],
            'unknown field' => [['webhooks' => ['h' => $good + ['webhook' => true]]], 'unknown setting "webhook"'],
        ];
    }

    #[DataProvider('refusalCases')]
    public function testMalformedConfigIsAStartupExceptionNamingItself(array $userConfig, string $needle): void
    {
        try {
            ServerConfig::resolve([], [], $userConfig, '/state');
        } catch (ServerConfigException $e) {
            self::assertStringContainsString($needle, $e->getMessage());

            return;
        }
        self::fail('expected a ServerConfigException mentioning: ' . $needle);
    }

    /** @param array<string, mixed> $userConfig */
    private static function resolve(array $userConfig): ServerConfig
    {
        return ServerConfig::resolve([], [], $userConfig, '/state');
    }
}
