<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Channels\ChannelToolPolicyService;

/**
 * m05 (cc-review-1111): the admission-identity interlock around the policy
 * resolver's entryFor-null arm, pinned so a future tightening lands eyes-open.
 *
 * The finding asked whether a FORGED grammar-valid session name that was never
 * admitted can land on the `entryFor() === null` release path (`scopeFor`
 * returns null, the session runs unrestricted) and escape tool policy. It can —
 * and it MUST, because that same arm is the D6 day-one positive control: the
 * channels map is operator-authored only (ChannelsStore has exactly one writer,
 * the quarantine ledger, so no admission step ever stamps a "registered" row),
 * and "channel the operator never configured" is structurally INDISTINGUISHABLE
 * from "name nobody admitted" by the file alone. Owner-PROMOTED ruling D6
 * (code-chat spec §12) commands the release; tightening the arm to refusingAll
 * would silently un-work every day-one channel. The anti-forgery load therefore
 * rides the edges this class cross-references, never the null arm itself:
 *
 *  - unreadable/garbage record       → refusingAll  (M-1, F-M1 welded);
 *  - unparsed `agent:`-prefixed name → refusingAll  (m54, rename collision);
 *  - reserved prefix at create/rename/import for non-minter clients
 *                                     → wire refusal (ccfix/m4m5, pool);
 *  - in-process rename side doors    → reported to closeout, out of this seat.
 *
 * The shared ChannelToolPolicyServiceTest keeps the resolution-maths pins; this
 * class owns only the release-vs-refuse ASYMMETRY legs, which nothing pinned
 * before (the forged/unmatched case had no test anywhere — that absence is the
 * real m05 defect surface: the ruling was load-bearing but unprosecuted).
 */
final class ChannelPolicyAdmissionInterlockTest extends TestCase
{
    /** @param (callable():array<string,mixed>) $record */
    private static function service(callable $record, array $trusted = ['candy-discord']): ChannelToolPolicyService
    {
        return new ChannelToolPolicyService($record, $trusted);
    }

    /**
     * D6 day-one, the load-bearing leg: a grammar-valid name whose channel has
     * NO entry while the file configures OTHER channels must resolve to NO
     * scope (unrestricted). This is the pin that reddens any future attempt to
     * fail-close the null arm — the mutation witness in the lane report shows
     * tightening :127-129 to refusingAll flips exactly this family to red.
     */
    public function testAnUnconfiguredChannelResolvesUnrestrictedEvenWhenTheFileConfiguresOthers(): void
    {
        $svc = self::service(static fn (): array => [
            'channels' => [
                'candy-discord|team-a' => ['tools' => ['allow' => ['Read']]],
            ],
        ]);

        self::assertNull(
            $svc->scopeFor('agent:main:team-b:group:g9'),
            'a channel the operator never configured is day-one per D6 — the file holds no admission record, so "forged" and "unconfigured" are the SAME leg and both must release',
        );
    }

    /**
     * The forged spelling of the same leg, named honestly: a name minted by no
     * admission path whatsoever (no bundle ever routed a conversation under it)
     * lands on the identical release. Pinned as the trace's conclusion, NOT as
     * an aspiration — the defense against exactly this name arriving at a
     * session is the creation-edge guard (ccfix/m4m5), which never needed the
     * resolver to grow a second, contradictory opinion about the same bytes.
     */
    public function testAForgedUnmatchedGrammarNameSharesTheDayOneReleaseAndTheSameRedLine(): void
    {
        $configured = ['channels' => ['candy-discord|team-a' => ['tools' => ['allow' => ['Read']]]]];
        $dayOne = self::service(static fn (): array => $configured)
            ->scopeFor('agent:main:team-b:group:g9');
        $forged = self::service(static fn (): array => $configured)
            ->scopeFor('agent:whatever:nowhere:channel:c123');

        self::assertNull($dayOne);
        self::assertNull($forged, 'indistinguishable inputs must answer identically — any asymmetry here would require an admission record the zero-wire D6 design does not have');
    }

    /**
     * The asymmetry that IS by design: the grammar door fails CLOSED where the
     * config door fails open. A name whose KIND the parser does not know is not
     * "a channel without policy" — it is a broken stamp, and M-1's family law
     * ("broken configuration restricts, never releases") binds it to refusingAll.
     */
    public function testTheKindDoorRefusesWhileTheUnconfiguredChannelDoorReleases(): void
    {
        $svc = self::service(static fn (): array => [
            'channels' => ['candy-discord|team-a' => ['tools' => ['allow' => ['Read']]]],
        ]);

        $scope = $svc->scopeFor('agent:main:team-a:thread:t9');
        self::assertNotNull($scope, 'an unknown kind is an unparsed name, not an unconfigured channel');
        self::assertTrue($scope->blocksEverything(), 'the grammar door is fail-CLOSED: a name the parser rejects can never ride the day-one release');
    }

    /**
     * Trust-door forged variant: a channel configured ONLY under a bundle the
     * user never trusted also lands on entryFor-null (the scan skips untrusted
     * bundles), releasing the session — policy inert, per the trust door that
     * predates this finding. The forged lookalike and the real untrusted-bundle
     * conversation answer identically, again for want of any admission record.
     */
    public function testAChannelOwnedByAnUntrustedBundleLandsOnTheSameReleasePath(): void
    {
        $svc = self::service(
            static fn (): array => [
                'channels' => ['candy-slack|room-x' => ['tools' => ['allow' => ['Read']]]],
            ],
            ['candy-discord'],
        );

        self::assertNull($svc->scopeFor('agent:main:room-x:group:g1'));
    }

    /**
     * Admitted-unknown-grammar edge: the 7-segment topic tail addresses its
     * PARENT channel leg — while that channel stays unconfigured, the thread
     * session rides the day-one release exactly like the 5-segment shape does
     * (addressing itself is pinned in the shared suite; this leg pins that the
     * release arm serves the topic tail too, so a tightening cannot exempt one
     * spelling and silently un-work per-thread day-one conversations).
     */
    public function testTheTopicTailShapeRidesTheSameDayOneReleaseAsItsParentLeg(): void
    {
        $svc = self::service(static fn (): array => [
            'channels' => ['candy-discord|team-a' => ['tools' => ['allow' => ['Read']]]],
        ]);

        self::assertNull($svc->scopeFor('agent:main:team-b:group:g9:topic:t1'));
    }
}
