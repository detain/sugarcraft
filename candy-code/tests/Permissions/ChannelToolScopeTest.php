<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\ChannelToolScope;

/**
 * The D6 scope leaf: a resolved channel tool policy only ever RESTRICTS, and
 * every leg of that restriction is pinned here — each pin goes red the moment
 * its leg is neutered, because a silently-widening channel policy is the exact
 * failure owner ruling D6 exists to prevent (openclaw-grade per-group tool
 * allow/deny, code-chat.md §9.3).
 *
 * Pattern literals are built through chr(42) so no test string here is
 * glob-shaped for the PathGlob corpus harvest (GlobDialect law).
 */
final class ChannelToolScopeTest extends TestCase
{
    /** A wildcard spelled through chr(42) — a bare glob literal would drift the PathGlob corpus (GlobDialect law). */
    private static function star(): string
    {
        return chr(42);
    }

    public function testADenyEntryRefusesItsNameWithTheScopeLabel(): void
    {
        $scope = ChannelToolScope::of('discord group 1234', 'group', allow: [], alsoAllow: [], deny: ['Bash']);

        $verdict = $scope->verdict('Bash');

        self::assertNotNull($verdict);
        self::assertStringContainsString('discord group 1234', $verdict);
        self::assertStringContainsString('denied by', $verdict);
    }

    public function testADenyOnlyScopeHasNoOpinionAboutNamesItDoesNotCarry(): void
    {
        // An empty allow list is NO allowlist: absence must not refuse. This
        // is the inherit-vs-explicit-deny line the brief pins.
        $scope = ChannelToolScope::of('the channel', 'channel', allow: [], alsoAllow: [], deny: ['Bash']);

        self::assertNull($scope->verdict('Read'));
    }

    public function testALiveAllowListRefusesWhatIsMissingFromIt(): void
    {
        $scope = ChannelToolScope::of('the group', 'group', allow: ['Read', 'Grep'], alsoAllow: [], deny: []);

        self::assertNull($scope->verdict('Read'));
        self::assertNull($scope->verdict('Grep'));
        $verdict = $scope->verdict('Bash');
        self::assertNotNull($verdict);
        self::assertStringContainsString('allowlist', $verdict);
    }

    public function testAlsoAllowOnlySpeaksWhenTheAllowListIsLive(): void
    {
        // openclaw semantics: alsoAllow ADDS to a live allowlist; with no
        // allowlist in force there is nothing to add to.
        $withList = ChannelToolScope::of('g', 'group', allow: ['Read'], alsoAllow: ['WebFetch'], deny: []);
        self::assertNull($withList->verdict('WebFetch'));

        $withoutList = ChannelToolScope::of('g', 'group', allow: [], alsoAllow: ['WebFetch'], deny: ['Bash']);
        // The addition must NOT become an allowlist — a name outside it is no
        // bar; only the deny speaks.
        self::assertNull($withoutList->verdict('Read'));
        self::assertNotNull($withoutList->verdict('Bash'));
    }

    public function testDenyWinsOverAllowWithinOneScope(): void
    {
        $scope = ChannelToolScope::of('g', 'channel', allow: ['Bash'], alsoAllow: [], deny: ['Bash']);

        $verdict = $scope->verdict('Bash');

        self::assertNotNull($verdict, 'a name on both lists must refuse, not fall through');
        self::assertStringContainsString('denied by', $verdict);
    }

    public function testNameGlobsUseTheRuleChainsOwnDialect(): void
    {
        $star = 'mcp__git__' . self::star();
        $scope = ChannelToolScope::of('g', 'group', allow: [], alsoAllow: [], deny: [$star]);

        self::assertNotNull($scope->verdict('mcp__git__commit'));
        self::assertNull($scope->verdict('mcp__other__thing'));
    }

    public function testTheFailClosedScopeRefusesEveryNameHoweverWellBehavedTheLists(): void
    {
        $scope = ChannelToolScope::refusingAll('the channel', 'channel', 'deny entry was not a string');

        self::assertTrue($scope->blocksEverything());
        $verdict = $scope->verdict('Read');
        self::assertNotNull($verdict);
        self::assertStringContainsString('broken policy refuses every tool', $verdict);
        // The broken section is refused even for names the operator would
        // have allowed: fail CLOSED, never silently open.
        self::assertStringContainsString('could not be honoured', $verdict);
    }

    public function testOfRefusesEntriesThatAreNotCleanStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @psalm-suppress InvalidArgument - the malformed-shape probe */
        ChannelToolScope::of('g', 'group', allow: [' Bash '], alsoAllow: [], deny: []);
    }

    public function testOfRefusesAnEmptyEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ChannelToolScope::of('g', 'group', allow: [], alsoAllow: [], deny: ['']);
    }

    public function testAccessorsReportTheResolvedLists(): void
    {
        $scope = ChannelToolScope::of('g', 'sender', allow: ['Read'], alsoAllow: ['Grep'], deny: ['Bash']);

        self::assertSame('sender', $scope->scopeKind());
        self::assertSame('g', $scope->label());
        self::assertSame(['Read'], $scope->allowPatterns());
        self::assertSame(['Grep'], $scope->alsoAllowPatterns());
        self::assertSame(['Bash'], $scope->denyPatterns());
        self::assertFalse($scope->blocksEverything());
    }
}
