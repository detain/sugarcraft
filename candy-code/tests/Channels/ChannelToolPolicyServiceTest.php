<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Channels\ChannelToolPolicyService;
use SugarCraft\Code\Permissions\ChannelToolScope;

/**
 * D6: the admission-time resolver — grammar, inheritance, trust door,
 * fail-closed shapes. Every leg has a pin written to go RED when the leg is
 * neutered (the lane's mutation table names which).
 */
final class ChannelToolPolicyServiceTest extends TestCase
{
    /** @return \Closure(callable():array<string,mixed>, array<int,string>):ChannelToolPolicyService */
    private static function service(): callable
    {
        return static fn (callable $record, array $trusted = ['candy-discord']): ChannelToolPolicyService =>
            new ChannelToolPolicyService($record, $trusted);
    }

    /** The GlobDialect law: wildcards never appear inside a literal. */
    private static function star(): string
    {
        return \chr(42);
    }

    public function testNamesThatAreNotChannelGrammarGetNoScopeAtAll(): void
    {
        $svc = (self::service())(static fn (): array => ['channels' => ['candy-discord|discord' => ['tools' => ['allow' => ['Read']]]]]);

        self::assertNull($svc->scopeFor(null));
        self::assertNull($svc->scopeFor(''));
        self::assertNull($svc->scopeFor('Refactor the parser'));
        self::assertNull($svc->scopeFor('agents-notes'), 'a human name merely starting near the grammar is not an agent name');
    }

    public function testAnAbsentChannelsSectionIsTheOldWorld(): void
    {
        $svc = (self::service())(static fn (): array => ['seenEntrypoints' => ['/x' => true]]);

        self::assertNull($svc->scopeFor('agent:main:discord:group:g1'));
    }

    public function testTheChannelLevelAllowlistSpeaksForSessionsItDoesNotOverride(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => ['tools' => ['allow' => ['Read']]]],
        ]);

        $scope = $svc->scopeFor('agent:main:discord:channel:c5');
        self::assertInstanceOf(ChannelToolScope::class, $scope);
        self::assertNull($scope->verdict('Read'));
        self::assertStringContainsString('not in the discord channel tool allowlist', (string) $scope->verdict('Edit'));
        self::assertSame('channel', $scope->scopeKind());
    }

    public function testTheTrustDoorKeepsAnUntrustedBundlesPolicyInert(): void
    {
        $svc = (self::service())(
            static fn (): array => ['channels' => ['candy-evil|discord' => ['tools' => ['allow' => ['Read']]]]],
            ['candy-discord'],
        );

        self::assertNull($svc->scopeFor('agent:main:discord:channel:c5'), 'policy only binds for bundles the user trusted by name');
    }

    public function testAGroupOverrideWinsAndAnAbsentGroupInheritsTheChannel(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => [
                'tools' => ['allow' => ['Read']],
                'groups' => ['g1' => ['tools' => ['allow' => ['Read', 'Edit']]]],
            ]],
        ]);

        $group = $svc->scopeFor('agent:main:discord:group:g1');
        self::assertNull($group->verdict('Edit'), 'the group level widened its own allowlist');
        self::assertSame('discord group g1', $group->label());

        $other = $svc->scopeFor('agent:main:discord:group:g2');
        self::assertStringContainsString('not in the discord channel tool allowlist', (string) $other->verdict('Edit'), 'an addressed group with no entry inherits the channel, and the refusal says the channel');

        $plain = $svc->scopeFor('agent:main:discord:channel:c9');
        self::assertStringContainsString('not in the discord channel tool allowlist', (string) $plain->verdict('Edit'));
    }

    public function testADenyAtAnyLiveScopeBindsAcrossALevelAllow(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => [
                'tools' => ['deny' => ['WebFetch']],
                'groups' => ['g1' => ['tools' => ['allow' => ['WebFetch'], 'alsoAllow' => ['Note']]]],
            ]],
        ]);

        $scope = $svc->scopeFor('agent:main:discord:group:g1');
        self::assertStringContainsString('denied by the discord group g1 tool policy', (string) $scope->verdict('WebFetch'), 'the channel deny is unioned into the group scope');
        self::assertNull($scope->verdict('Note'), 'the level allow still falls through to the chain');
    }

    public function testTheSlashTailIsTheSenderLevel(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => [
                'tools' => ['deny' => ['WebFetch']],
                'toolsBySender' => ['u9' => ['tools' => ['deny' => ['Bash']]]],
            ]],
        ]);

        $sender = $svc->scopeFor('agent:main:discord:slash:u9');
        self::assertStringContainsString('denied by the discord slash u9 tool policy', (string) $sender->verdict('Bash'));
        self::assertStringContainsString('denied by the discord slash u9 tool policy', (string) $sender->verdict('WebFetch'), 'the channel deny unions into the sender level');

        $other = $svc->scopeFor('agent:main:discord:slash:u8');
        self::assertNull($other->verdict('Bash'), 'an unlisted sender is not bound by u9s deny');
        self::assertStringContainsString('denied by the discord channel tool policy', (string) $other->verdict('WebFetch'), 'the channel deny still binds it, and the refusal says the channel');
    }

    public function testAPerPeerDmSessionAddressesTheSameSenderLevel(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => [
                'tools' => ['deny' => ['WebFetch']],
                'toolsBySender' => ['u9' => ['tools' => ['deny' => ['Bash']]]],
            ]],
        ]);

        $peer = $svc->scopeFor('agent:main:discord:dm:u9');
        self::assertStringContainsString('denied by the discord dm u9 tool policy', (string) $peer->verdict('Bash'), 'the DM session IS one human, so sender policy binds and says so');
        self::assertStringContainsString('denied by the discord dm u9 tool policy', (string) $peer->verdict('WebFetch'), 'the channel deny unions into the per-peer level');

        $unlisted = $svc->scopeFor('agent:main:discord:dm:u8');
        self::assertNull($unlisted->verdict('Bash'), 'a peer with no sender entry is not bound by another peers deny');
        self::assertStringContainsString('denied by the discord channel tool policy', (string) $unlisted->verdict('WebFetch'), 'the channel deny still reaches the unlisted peer');
    }

    public function testAPerPeerDmInheritsTheChannelAllowlistLikeAGroupWould(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => [
                'tools' => ['allow' => ['Read']],
            ]],
        ]);

        $scope = $svc->scopeFor('agent:main:discord:dm:u7');
        self::assertNull($scope->verdict('Read'), 'the inherited channel allowlist speaks for the peer');
        self::assertStringContainsString('not in the discord channel tool allowlist', (string) $scope->verdict('Edit'), 'with no sender entry the channel level speaks, and the refusal says which level bound');
    }

    public function testAnEmptyAllowlistAtTheLevelInheritsTheChannelOne(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => [
                'tools' => ['allow' => ['Read']],
                'groups' => ['g1' => ['tools' => ['allow' => []]]],
            ]],
        ]);

        $scope = $svc->scopeFor('agent:main:discord:group:g1');
        self::assertNull($scope->verdict('Read'), 'absent/empty per ruling means inherit, never replace-with-nothing');
        self::assertStringContainsString('allowlist', (string) $scope->verdict('Edit'));
    }

    public function testLiveButEmptyPolicyResolvesToNoScope(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => ['tools' => ['allow' => [], 'deny' => []]]],
        ]);

        self::assertNull($svc->scopeFor('agent:main:discord:channel:c5'), 'nothing restricts, so the gate stays byte-identical');
    }

    public function testAGlobSpelledEntryCarriesTheNameDialect(): void
    {
        $star = self::star();
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => ['tools' => ['deny' => ['mcp__git__' . $star]]]],
        ]);

        $scope = $svc->scopeFor('agent:main:discord:group:g1');
        self::assertStringContainsString('denied by', (string) $scope->verdict('mcp__git__push'));
        self::assertNull($scope->verdict('Note'));
    }

    public function testAnArgumentSpelledEntryFailsClosed(): void
    {
        $star = self::star();
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => ['tools' => ['deny' => ['Bash(rm ' . $star . ')']]]],
        ]);

        $scope = $svc->scopeFor('agent:main:discord:group:g1');
        self::assertTrue($scope->blocksEverything());
        $why = (string) $scope->verdict('Read');
        self::assertStringContainsString('could not be honoured', $why);
        self::assertStringContainsString('spells arguments', $why);
    }

    public function testAToolsBlockThatIsNotADeclarationFailsClosed(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-discord|discord' => ['tools' => 'yes please']],
        ]);

        self::assertTrue($svc->scopeFor('agent:main:discord:channel:c5')->blocksEverything());
    }

    public function testAnUnparseableAgentNameFailsClosed(): void
    {
        $svc = (self::service())(static fn (): array => ['channels' => []]);

        $scope = $svc->scopeFor('agent:main:discord');
        self::assertTrue($scope->blocksEverything());
        self::assertStringContainsString('unparsed agent session name', $scope->label());

        self::assertTrue($svc->scopeFor('agent:main:discord:sudo:all')->blocksEverything(), 'an unknown kind is not a wildcard');
        self::assertTrue($svc->scopeFor('agent:main:discord:group:g1:thread:t3')->blocksEverything());
    }

    public function testTopicTailsAddressTheirGroupAndNotThemselves(): void
    {
        $svc = (self::service())(static fn (): array => [
            'channels' => ['candy-telegram|telegram' => [
                'tools' => ['allow' => ['Read']],
                'groups' => ['g7' => ['tools' => ['allow' => ['Read', 'Edit']]]],
            ]],
        ], ['candy-telegram']);

        $scope = $svc->scopeFor('agent:main:telegram:group:g7:topic:t3');
        self::assertNull($scope->verdict('Edit'), 'the topic rides its group policy — the topic id is not a policy level');
        self::assertSame('telegram group g7', $scope->label());
    }

    public function testTwoTrustedBundlesClaimingOneChannelNameRefuseRatherThanPick(): void
    {
        $svc = (self::service())(
            static fn (): array => ['channels' => [
                'candy-discord|discord' => ['tools' => ['allow' => ['Read']]],
                'candy-mirror|discord' => ['tools' => ['deny' => ['Read']]],
            ]],
            ['candy-discord', 'candy-mirror'],
        );

        self::assertTrue($svc->scopeFor('agent:main:discord:channel:c5')->blocksEverything(), 'the file disagrees with the registry; refusing is the only honest answer');
    }

    public function testAdmissionReReadsTheRecordSoPolicyLandsWithoutARestart(): void
    {
        $generation = 0;
        $svc = (self::service())(static function () use (&$generation): array {
            ++$generation;
            if (1 === $generation) {
                return ['channels' => ['candy-discord|discord' => ['tools' => ['allow' => ['Read']]]]];
            }

            return ['channels' => ['candy-discord|discord' => ['tools' => ['allow' => ['Read', 'Edit']]]]];
        });

        self::assertStringContainsString('allowlist', (string) $svc->scopeFor('agent:main:discord:group:g1')->verdict('Edit'));
        self::assertNull($svc->scopeFor('agent:main:discord:group:g1')->verdict('Edit'), 'the next admission sees the edited file');
    }
}
