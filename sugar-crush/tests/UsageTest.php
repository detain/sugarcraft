<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Usage;

/**
 * {@see Usage} — the value object that carries a provider's own token count and
 * cost across the seams that used to drop them (crush_code.md Phase 5 item 7).
 *
 * The whole class exists to keep two claims apart: "the provider said this call
 * was free" and "no provider said anything". Every test here is about one of the
 * two, because collapsing them is what turns a status bar into a liar — since
 * P4.S1 (E17) the same test applies one level down, per bucket: "reported zero
 * cache reads" and "said nothing about cache reads" are two objects, and the
 * arithmetic (the `promptTokens` identity, `plus`, `sum`, and the fork wire)
 * must stay honest across all three of null, 0, and a positive number.
 */
final class UsageTest extends TestCase
{
    /**
     * The distinction the class exists for. A streamed turn commonly reports
     * `tokensUsed=0, costUsd=0.0` on every chunk — see
     * {@see \SugarCraft\Crush\Runtime}'s streaming note — and that is "we do not
     * know", which is why it must not become an object at all.
     */
    public function testNothingReportedIsNullAndNotAZeroValuedUsage(): void
    {
        $this->assertNull(Usage::reported(0, 0.0));
    }

    /**
     * The other half, and the one a naive `$total > 0 && $cost > 0` guard would
     * get wrong: a self-hosted provider genuinely bills nothing while still
     * counting tokens ({@see \SugarCraft\Crush\Providers\SglangProvider} and
     * {@see \SugarCraft\Crush\Providers\CustomProvider} both set
     * `costUsd: 0.0` beside a real `usage.total_tokens`). That is a MEASURED
     * free call, not an unknown one, and it has to survive.
     */
    public function testRealTokensAtZeroCostIsReportedBecauseFreeIsNotUnknown(): void
    {
        $usage = Usage::reported(1234, 0.0);

        $this->assertNotNull($usage);
        $this->assertSame(1234, $usage->totalTokens);
        $this->assertSame(0.0, $usage->costUsd);
    }

    /** And the mirror case: a cost with no token count is still a report. */
    public function testACostWithNoTokenCountIsReported(): void
    {
        $usage = Usage::reported(0, 0.25);

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->totalTokens);
        $this->assertSame(0.25, $usage->costUsd);
    }

    /**
     * A negative figure is a provider bug, and the choice made is to account it
     * as zero rather than to throw: failing a turn over a malformed usage block
     * would lose the reply the user was waiting for.
     */
    public function testNegativeFiguresClampRatherThanThrow(): void
    {
        $this->assertNull(Usage::reported(-5, -1.0));

        $usage = Usage::reported(-5, 2.0);
        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->totalTokens);
        $this->assertSame(2.0, $usage->costUsd);
    }

    /**
     * The operation an agentic turn needs: one turn is N provider calls, and the
     * turn's cost is their sum. See
     * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()}.
     */
    public function testPlusAddsBothFigures(): void
    {
        $summed = Usage::new(100, 0.01)->plus(Usage::new(250, 0.05));

        $this->assertSame(350, $summed->totalTokens);
        $this->assertSame(0.06, round($summed->costUsd, 10));
    }

    /** Immutable: `plus()` must not mutate either operand. */
    public function testPlusLeavesBothOperandsAlone(): void
    {
        $a = Usage::new(100, 0.01);
        $b = Usage::new(250, 0.05);
        $a->plus($b);

        $this->assertSame(100, $a->totalTokens);
        $this->assertSame(250, $b->totalTokens);
    }

    /**
     * `sum()` skips the nulls rather than reading them as zeros, which is what
     * lets a turn whose FIRST step reported usage and whose second did not keep
     * the figure it does have.
     */
    public function testSumSkipsUnreportedEntriesWithoutLosingTheReportedOnes(): void
    {
        $summed = Usage::sum([Usage::new(10, 0.1), null, Usage::new(5, 0.2)]);

        $this->assertNotNull($summed);
        $this->assertSame(15, $summed->totalTokens);
        $this->assertSame(0.30000000000000004, $summed->costUsd);
    }

    /**
     * A list of nothing-reported stays nothing-reported. If this returned a
     * zero-valued Usage, every offline run would attach one to every turn and
     * the status bar would print `$0.0000` for a session nobody measured.
     */
    public function testSumOfNothingIsNullAndNotAZero(): void
    {
        $this->assertNull(Usage::sum([]));
        $this->assertNull(Usage::sum([null, null]));
    }

    /**
     * The fork boundary: {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}
     * runs the turn in a child and the parent unserializes with
     * `allowed_classes => false`, so the object cannot cross — only this array
     * can.
     *
     * CORRECTED BY P4.S1 rather than weakened: the exact-shape assertion stood
     * when the wire had two keys, and the wire now has six because the buckets
     * had to cross it. A bucket missing from EITHER half of the pair is silent
     * and async-only — but the two halves fail differently, as the class
     * docblock (src/Usage.php "Zero is not the same as unknown") now states:
     * dropped from `toArray()`, async loses the bucket as UNREPORTED with sync
     * mode staying green (accounting lost on one side only); coercing
     * `fromArray()` turns the absent key into a FABRICATED measured zero. Both
     * errors are the one bug this test file exists to make impossible; this
     * exact-shape assert catches the first, and the value-by-value round trip
     * plus the old-frame test below catch the second. The assert still checks
     * the COMPLETE array: an added, renamed, or dropped key fails it by diff,
     * as before.
     */
    public function testItRoundTripsThroughThePlainArrayTheForkBoundaryAllows(): void
    {
        $usage = Usage::new(4321, 0.1234);
        $wire = $usage->toArray();

        $this->assertSame([
            'totalTokens' => 4321,
            'costUsd' => 0.1234,
            'inputTokens' => null,
            'outputTokens' => null,
            'cacheReadTokens' => null,
            'cacheCreationTokens' => null,
        ], $wire);

        $back = Usage::fromArray($wire);
        $this->assertNotNull($back);
        $this->assertSame(4321, $back->totalTokens);
        $this->assertSame(0.1234, $back->costUsd);
    }

    /**
     * A corrupt or absent frame costs the turn its ACCOUNTING, not the turn: the
     * reply still resolves, with no usage attached. Anything that is not the
     * shape `toArray()` wrote is refused rather than coerced, so a garbled
     * payload cannot invent a bill.
     *
     * @dataProvider malformedPayloads
     */
    public function testFromArrayRefusesAnythingItDidNotWrite(mixed $payload): void
    {
        $this->assertNull(Usage::fromArray($payload));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedPayloads(): iterable
    {
        yield 'null (no usage key in the frame at all)' => [null];
        yield 'not an array' => ['4321'];
        yield 'tokens as a string' => [['totalTokens' => '4321', 'costUsd' => 0.1]];
        yield 'cost as a string' => [['totalTokens' => 4321, 'costUsd' => '0.1']];
        yield 'missing tokens' => [['costUsd' => 0.1]];
        yield 'missing cost' => [['totalTokens' => 4321]];
        // Not malformed — a real "nothing reported" frame, which must come back
        // as null for the same reason reported() returns null for it.
        yield 'a zero report' => [['totalTokens' => 0, 'costUsd' => 0.0]];
    }

    /** An int cost survives, because JSON-ish payloads flatten 0.0 to 0. */
    public function testFromArrayAcceptsAnIntegerCost(): void
    {
        $back = Usage::fromArray(['totalTokens' => 10, 'costUsd' => 1]);

        $this->assertNotNull($back);
        $this->assertSame(1.0, $back->costUsd);
    }
    // =====================================================================
    // P4.S1 / E17 — the real buckets
    // =====================================================================

    /**
     * Done-when clause 1, as behaviour: the four buckets exist, and their
     * defaults are UNREPORTED — `new()` with no buckets must read null on
     * all four, not 0. The path this pins is `new()`'s OWN default
     * parameters; the private constructor's parallel `= null` defaults state
     * the same invariant but are not the enforced path — every internal
     * construction site passes all six arguments, so flipping only those
     * leaves this suite green (measured). If `new()`'s default ever flips to
     * 0, every unsplit provider instantly "reports" a zero cache and the
     * status bar starts asserting facts nobody measured.
     */
    public function testNewCarriesEachBucketExactlyAndDefaultsThemToUnreported(): void
    {
        $full = Usage::new(65000, 0.5, 5000, 20000, 30000, 10000);
        $this->assertSame(5000, $full->inputTokens);
        $this->assertSame(20000, $full->outputTokens);
        $this->assertSame(30000, $full->cacheReadTokens);
        $this->assertSame(10000, $full->cacheCreationTokens);

        $bare = Usage::new();
        $this->assertSame(null, $bare->inputTokens);
        $this->assertSame(null, $bare->outputTokens);
        $this->assertSame(null, $bare->cacheReadTokens);
        $this->assertSame(null, $bare->cacheCreationTokens);
    }

    /**
     * Done-when clause 3 — the three-bucket identity `total = cacheRead +
     * cacheCreation + input` — with its two deliberate omissions pinned, each
     * by a VALUE that changes only if the omission is "fixed".
     *
     * WHY `outputTokens` IS DELIBERATELY NOT IN THE IDENTITY (hazard 4 — pin
     * it or a later reader will "fix" it): `promptTokens()` counts what was
     * SENT, which is what fills the context window and what the 95% tier must
     * stop estimating with chars/4. What the model wrote is not part of what
     * was sent. Add `$this->outputTokens` to the sum and this test reds at the first
     * assertion — PHPUnit aborts there, so the two pins never fail in the same run;
     * they kill DIFFERENT mutations. The withOutputTokens(1) re-assert below is the
     * load-bearing one: it survives a "fix" that updates the 45000 constant, because
     * it fails whenever the sum moves by moving outputTokens at all, whatever the
     * fixture values are.
     *
     * `totalTokens` (99999 here, deliberately NOT the bucket sum 65000) is the
     * provider's own billable figure over both directions: `new()` derives it
     * from no bucket and `promptTokens()` derives nothing from it. If either
     * ever starts deriving, one of the two assertions fails by value.
     */
    public function testPromptTokensSumsTheThreeInputBucketsAndDeliberatelyExcludesOutput(): void
    {
        $usage = Usage::new(99999, 0.5, inputTokens: 5000, outputTokens: 20000, cacheReadTokens: 30000, cacheCreationTokens: 10000);

        $this->assertSame(45000, $usage->promptTokens(), 'the identity: 30000 cacheRead + 10000 cacheCreation + 5000 input');
        $this->assertSame(99999, $usage->totalTokens, 'totalTokens is the provider\'s own figure, not the bucket sum');

        // Output excluded: only outputTokens moves, promptTokens must not.
        $this->assertSame(45000, $usage->withOutputTokens(1)->promptTokens(), 'outputTokens is NOT in the identity — see docblock');

        // Each member bucket IS in it — sensitivity per term, so the identity
        // is live and not a constant dressed as a sum.
        $this->assertSame(45001, $usage->withInputTokens(5001)->promptTokens());
        $this->assertSame(45001, $usage->withCacheReadTokens(30001)->promptTokens());
        $this->assertSame(45001, $usage->withCacheCreationTokens(10001)->promptTokens());
    }

    /**
     * Done-when clause 4 as behaviour — the null-vs-zero distinction on the
     * accessor that does the arithmetic. "The provider reported zero cache
     * reads" and "the provider reported nothing about cache reads" are
     * different objects here and produce different answers: an explicit 0
     * still totals, a missing bucket voids the total rather than feeding it a
     * fabricated zero. If `promptTokens()` ever treats null as 0 (`?? 0` or a
     * non-strict check), the `null` assertions go red with the exact number
     * they would have wrongly produced.
     */
    public function testPromptTokensRefusesToTotalAcrossAnUnreportedBucket(): void
    {
        $reportedZero = Usage::new(100, 0.1, inputTokens: 60, cacheReadTokens: 40, cacheCreationTokens: 0);
        $this->assertSame(100, $reportedZero->promptTokens(), 'an EXPLICIT zero cacheCreation still totals — zero is a measurement');

        $unreported = $reportedZero->withCacheCreationTokens(null);
        $this->assertNull($unreported->promptTokens(), 'a MISSING bucket must not silently become 0 where null is the truth');

        // The missing polarity for every identity term: an unreported bucket
        // voids the total rather than feeding it a fabricated zero. (The
        // reported-zero polarity — the other half of "both polarities" — is
        // pinned per term by the loop below; the three asserts above cover
        // only the missing side, as measured by review cycle 4.)
        $this->assertNull(Usage::new(100, 0.1, cacheReadTokens: 40, cacheCreationTokens: 0)->promptTokens(), 'inputTokens missing');
        $this->assertNull(Usage::new(100, 0.1, inputTokens: 60, cacheCreationTokens: 0)->promptTokens(), 'cacheReadTokens missing');
        $this->assertNull(Usage::new(100, 0.1, inputTokens: 60, cacheReadTokens: 40)->promptTokens(), 'cacheCreationTokens missing');

        // The reported-zero polarity, pinned PER TERM rather than for
        // cacheCreationTokens alone. The guard in promptTokens() is a strict
        // `=== null` with one condition per term; relax ANY of the three to
        // `== null` and PHP's 0 == null makes a measured zero look missing —
        // exactly the Anthropic full-cache-hit shape (input_tokens: 0) — and
        // the sum voids to null. Each row zeroes ONE term and holds the other
        // two at distinct positives, so a loosened guard reds on its OWN row
        // with its OWN expected figure, never on a neighbour's value.
        $zeroRows = [
            'inputTokens' => 45,           // 0 + 40 + 5
            'cacheReadTokens' => 65,       // 60 + 0 + 5
            'cacheCreationTokens' => 100,  // 60 + 40 + 0
        ];
        foreach ($zeroRows as $zeroBucket => $expected) {
            $row = Usage::new(
                100,
                0.1,
                inputTokens: $zeroBucket === 'inputTokens' ? 0 : 60,
                cacheReadTokens: $zeroBucket === 'cacheReadTokens' ? 0 : 40,
                cacheCreationTokens: $zeroBucket === 'cacheCreationTokens' ? 0 : 5,
            );
            $this->assertSame($expected, $row->promptTokens(), "a $zeroBucket reported as EXACTLY zero still totals — zero is a measurement");
        }
    }

    /**
     * Done-when clause 2: `with*()` returns a NEW instance via the private
     * mutate() and only ever touches its own bucket — the sentinel behaviour.
     * The ride-along assertion (`cacheRead` still 4 after clearing input)
     * reds if mutate() loses its `bool $xSet` sentinels and defaults start
     * clobbering untouched fields.
     */
    public function testWithersAreFluentImmutableAndOnlyTouchTheirOwnBucket(): void
    {
        $base = Usage::new(10, 0.1);
        $built = $base
            ->withInputTokens(1)
            ->withOutputTokens(2)
            ->withCacheReadTokens(3)
            ->withCacheCreationTokens(4);

        $this->assertNotSame($base, $built);
        $this->assertSame(10, $base->totalTokens);
        $this->assertSame(0.1, $base->costUsd);
        $this->assertSame(null, $base->inputTokens);
        $this->assertSame(null, $base->cacheReadTokens);

        $this->assertSame(1, $built->inputTokens);
        $this->assertSame(2, $built->outputTokens);
        $this->assertSame(3, $built->cacheReadTokens);
        $this->assertSame(4, $built->cacheCreationTokens);
        $this->assertSame(8, $built->promptTokens());

        $cleared = $built->withInputTokens(null);
        $this->assertNull($cleared->inputTokens);
        $this->assertSame(2, $cleared->outputTokens, 'untouched buckets ride along — that is what the sentinels are for');
        $this->assertSame(3, $cleared->cacheReadTokens);
        $this->assertSame(4, $cleared->cacheCreationTokens);
        $this->assertNull($cleared->promptTokens(), 'a cleared bucket voids the identity rather than feeding it 1');
    }

    /**
     * `withCacheReadTokens(0)` and `withCacheReadTokens(null)` are DIFFERENT
     * operations — report-zero versus clear-to-unreported — and both are
     * distinct from never having called the wither at all. This is clause 4's
     * third face: if null-means-set and null-means-unchanged ever collapse
     * into one behaviour, either the (0) or the (null) leg goes red.
     */
    public function testSettingABucketToZeroReportsItAndSettingItToNullClearsIt(): void
    {
        $base = Usage::new(10, 0.1);

        $zeroed = $base->withCacheReadTokens(0);
        $this->assertSame(0, $zeroed->cacheReadTokens);

        $cleared = $zeroed->withCacheReadTokens(null);
        $this->assertNull($cleared->cacheReadTokens);

        $withAll = $base->withInputTokens(60)->withCacheReadTokens(40)->withCacheCreationTokens(0);
        $this->assertSame(100, $withAll->promptTokens());
        $this->assertNull($withAll->withCacheCreationTokens(null)->promptTokens());
    }

    /**
     * Same policy as the existing `testNegativeFiguresClampRatherThanThrow`
     * for totals: a negative bucket is a provider bug accounted as zero, but
     * "unreported" is NOT a negative number and survives as null through the
     * very same code path.
     */
    public function testNegativeBucketsClampToZeroWhileUnreportedSurvivesAsNull(): void
    {
        $clamped = Usage::new(5, 0.0, -7, -1, -3, -2);
        $this->assertSame(0, $clamped->inputTokens);
        $this->assertSame(0, $clamped->outputTokens);
        $this->assertSame(0, $clamped->cacheReadTokens);
        $this->assertSame(0, $clamped->cacheCreationTokens);
        $this->assertSame(5, $clamped->totalTokens);

        $mixed = Usage::new(5, 0.0)->withInputTokens(7)->withOutputTokens(-1);
        $this->assertSame(7, $mixed->inputTokens, 'the untouched neighbour must survive the clamp of the touched one');
        $this->assertSame(0, $mixed->outputTokens);

        // mutate()'s docblock promises its clamps run "exactly as new()
        // clamps" — that promise is PER BRANCH, and before this extension
        // only the outputTokens branch above was pinned. A leg per remaining
        // wither: chain distinct positives onto $mixed, then drive ONE bucket
        // negative through its own setter. Each clamp pin (assertSame(0, ...))
        // kills the deletion of that branch's clampBucket() — an unclamped
        // negative would ride through verbatim — and each neighbour pin keeps
        // the sentinels honest while a different branch does the clamping.
        $withReads = $mixed->withCacheReadTokens(9)->withCacheCreationTokens(6);

        $negInput = $withReads->withInputTokens(-7);
        $this->assertSame(0, $negInput->inputTokens, 'mutate() clamps a negative input to a reported zero');
        $this->assertSame(0, $negInput->outputTokens);
        $this->assertSame(9, $negInput->cacheReadTokens, 'positive neighbour survives the input clamp');
        $this->assertSame(6, $negInput->cacheCreationTokens, 'positive neighbour survives the input clamp');

        $negCacheRead = $withReads->withCacheReadTokens(-4);
        $this->assertSame(0, $negCacheRead->cacheReadTokens, 'mutate() clamps a negative cacheRead to a reported zero');
        $this->assertSame(7, $negCacheRead->inputTokens, 'positive neighbour survives the cacheRead clamp');
        $this->assertSame(0, $negCacheRead->outputTokens);
        $this->assertSame(6, $negCacheRead->cacheCreationTokens, 'positive neighbour survives the cacheRead clamp');

        $negCacheCreation = $withReads->withCacheCreationTokens(-2);
        $this->assertSame(0, $negCacheCreation->cacheCreationTokens, 'mutate() clamps a negative cacheCreation to a reported zero');
        $this->assertSame(7, $negCacheCreation->inputTokens, 'positive neighbour survives the cacheCreation clamp');
        $this->assertSame(0, $negCacheCreation->outputTokens);
        $this->assertSame(9, $negCacheCreation->cacheReadTokens, 'positive neighbour survives the cacheCreation clamp');
    }

    /**
     * HAZARD 2, the common path: `plus()` adds every bucket, with DISTINCT
     * per-bucket sums (110/220/330/44) so dropping any single bucket from the
     * addition reds exactly one assertion with the dropped field's name — a
     * two-field hand-add (the pre-P4.S1 body) fails the first bucket assert.
     * The operands must also come out untouched (immutability of both sides).
     */
    public function testPlusSumsEveryBucketWithExactValues(): void
    {
        $a = Usage::new(60, 0.25, 10, 20, 300, 4);
        $b = Usage::new(600, 0.50, 100, 200, 30, 40);
        $sum = $a->plus($b);

        $this->assertSame(660, $sum->totalTokens);
        $this->assertSame(0.75, $sum->costUsd);
        $this->assertSame(110, $sum->inputTokens);
        $this->assertSame(220, $sum->outputTokens);
        $this->assertSame(330, $sum->cacheReadTokens);
        $this->assertSame(44, $sum->cacheCreationTokens);
        $this->assertSame(484, $sum->promptTokens(), 'the identity survives the addition, not just the construction');

        $this->assertSame(10, $a->inputTokens);
        $this->assertSame(300, $a->cacheReadTokens);
        $this->assertSame(100, $b->inputTokens);
        $this->assertSame(40, $b->cacheCreationTokens);
    }

    /**
     * The merge's null policy, both polarities — with "reported" covering BOTH
     * values it can carry, a measured number AND a measured zero: a bucket
     * reported by EITHER side survives the addition (it is not thrown away
     * because the other step said nothing); a bucket reported by NEITHER side
     * stays unreported rather than becoming a fabricated turn zero. If
     * `plusBucket()` flips its null carry-through to a zero (the shape that
     * pretends the unreported half was measured), the all-null leg goes red.
     * If it collapses the other way — reading a measured ZERO as unreported
     * (`if ($own === null || $own === 0)`, the shape review cycle 5 measured
     * surviving the 37 tests this method stood alone against) — the per-bucket
     * zero-against-null loop goes red instead, one assertion per bucket in
     * each operand order. The 0-plus-N leg elsewhere cannot catch that
     * collapse itself: 0+5 and 5 are the same number, so only
     * zero-against-NULL distinguishes "the provider measured zero" from "the
     * provider said nothing".
     */
    public function testPlusCarriesABucketReportedByEitherOperandAndKeepsUnreportedOnesUnreported(): void
    {
        $a = Usage::new(10, 0.1, inputTokens: 10, cacheReadTokens: 5);
        $b = Usage::new(20, 0.2, inputTokens: 5, outputTokens: 7);
        $sum = $a->plus($b);

        $this->assertSame(15, $sum->inputTokens);
        $this->assertSame(7, $sum->outputTokens, 'reported by one side only — carried, not nulled, not zero-added');
        $this->assertSame(5, $sum->cacheReadTokens, 'reported by one side only — carried');
        $this->assertNull($sum->cacheCreationTokens, 'reported by neither side — stays unreported');

        // A measured ZERO against an unreported neighbour, per bucket, in BOTH
        // operand orders: the carry-through guards must test `=== null`, never
        // falsiness. Each order drives a different guard of plusBucket() —
        // zero-then-silent the theirs-is-null guard returning $own, silent-then-
        // zero the own-is-null guard returning $theirs.
        foreach (['inputTokens', 'outputTokens', 'cacheReadTokens', 'cacheCreationTokens'] as $bucket) {
            $zeroThenSilent = Usage::new(1, 0.0, ...[$bucket => 0])->plus(Usage::new(2, 0.0));
            $this->assertSame(0, $zeroThenSilent->$bucket, "a $bucket measured as exactly zero survives a merge with a step that said nothing — 0 is not null");

            $silentThenZero = Usage::new(1, 0.0)->plus(Usage::new(2, 0.0, ...[$bucket => 0]));
            $this->assertSame(0, $silentThenZero->$bucket, "...and the same measured zero survives on the OTHER side of the merge");
        }

        $bare = Usage::new(1, 0.0)->plus(Usage::new(2, 0.0));
        $this->assertNull($bare->inputTokens);
        $this->assertNull($bare->outputTokens);
        $this->assertNull($bare->cacheReadTokens);
        $this->assertNull($bare->cacheCreationTokens);
    }

    /**
     * `sum()` is built on `plus()` and must inherit every bucket — a turn of
     * steps where only SOME steps reported the split keeps the reported
     * figures (the EngineBackend loop this class exists for runs exactly that
     * mix once the wiring lands). Exact values per bucket, including the
     * explicit zero staying zero and the never-reported staying null.
     */
    public function testSumAccumulatesBucketsAcrossStepsIncludingStepsThatReportedNone(): void
    {
        $sum = Usage::sum([
            Usage::new(100, 0.1, 10, 20, 70, 0),
            null,
            Usage::new(50, 0.05),
            Usage::new(30, 0.02, 7, 13, 5, 2),
        ]);

        $this->assertNotNull($sum);
        $this->assertSame(180, $sum->totalTokens);
        $this->assertSame(17, $sum->inputTokens);
        $this->assertSame(33, $sum->outputTokens);
        $this->assertSame(75, $sum->cacheReadTokens);
        $this->assertSame(2, $sum->cacheCreationTokens);
        $this->assertSame(94, $sum->promptTokens());
    }

    /**
     * A bucket is itself a report: total 0 and cost $0 WITH a measured cache
     * read is the per-bucket face of "free is not unknown" (the existing
     * `testRealTokensAtZeroCost...` for totals), so `reported()` must not drop
     * it — and the all-null mirror case must still drop to null.
     *
     * "Measured" includes measured-as-ZERO: the gate's bucket conditions are
     * strict `=== null`, so a provider that reports exactly zero in a bucket
     * on an otherwise-free, otherwise-empty call has still said something
     * ("the cache was never read") and must get a Usage back, direct and
     * across the fork wire. Pinned per bucket because the gate has one
     * condition per bucket.
     */
    public function testBucketsCountAsReportsEvenWhenTotalAndCostAreZero(): void
    {
        $freeButMeasured = Usage::reported(0, 0.0, cacheReadTokens: 40);
        $this->assertNotNull($freeButMeasured, 'a measured cache read beside a zero bill is still a measurement');
        $this->assertSame(40, $freeButMeasured->cacheReadTokens);
        $this->assertSame(null, $freeButMeasured->inputTokens);

        foreach (['inputTokens', 'outputTokens', 'cacheReadTokens', 'cacheCreationTokens'] as $bucket) {
            $zeroMeasured = Usage::reported(0, 0.0, ...[$bucket => 0]);
            $this->assertNotNull($zeroMeasured, "a $bucket reported as EXACTLY zero is still a report");
            $this->assertSame(0, $zeroMeasured->$bucket, '...and it stays zero, not rewritten to unreported');

            $zeroOnTheWire = Usage::fromArray(['totalTokens' => 0, 'costUsd' => 0.0, $bucket => 0]);
            $this->assertNotNull($zeroOnTheWire, "the same zero-$bucket frame survives the fork wire as a report");
            $this->assertSame(0, $zeroOnTheWire->$bucket);
        }

        $this->assertNull(Usage::reported(0, 0.0), 'mirror polarity: nothing at all is still nothing reported');
    }

    /**
     * Done-when, the null-vs-zero contract CROSSING THE SOCKET (hazard 1):
     * bucket-by-bucket object equality through `fromArray($x->toArray())`, for
     * a full report AND a mixed one where some buckets are 0, some null, some
     * absent-by-default. Asserting the VALUES, so a bucket dropped from
     * either half of the pair — sync-mode-green, async-mode-zero — reds here.
     */
    public function testEveryBucketRoundTripsAcrossTheForkWireByValue(): void
    {
        $full = Usage::new(4321, 0.1234, 1000, 2000, 30000, 400);
        $back = Usage::fromArray($full->toArray());
        $this->assertEquals($full, $back);
        $this->assertSame(1000, $back->inputTokens);
        $this->assertSame(2000, $back->outputTokens);
        $this->assertSame(30000, $back->cacheReadTokens);
        $this->assertSame(400, $back->cacheCreationTokens);

        $mixed = Usage::new(11, 0.5, inputTokens: 1, cacheReadTokens: 0);
        $backMixed = Usage::fromArray($mixed->toArray());
        $this->assertEquals($mixed, $backMixed);
        $this->assertSame(1, $backMixed->inputTokens);
        $this->assertSame(0, $backMixed->cacheReadTokens, 'an explicit zero survives the wire as zero...');
        $this->assertNull($backMixed->outputTokens, '...and an unreported bucket survives it as null');
    }

    /**
     * The pre-P4.S1 frame: `fromArray()` must stay tolerant of a shape it did
     * not write, and the shape it tolerates is one WITHOUT the bucket keys —
     * it decodes to four UNREPORTED buckets. The brief's exact trap: "A frame
     * missing the new keys entirely must not become four zeroes." Asserted by
     * `null`, not `assertSame(0, ...)`-compatible behaviour.
     */
    public function testAnOldFrameWithoutBucketKeysDecodesToUnreportedBuckets(): void
    {
        $back = Usage::fromArray(['totalTokens' => 10, 'costUsd' => 0.1]);

        $this->assertNotNull($back, 'a pre-bucket frame still carries its accounting — tolerance, not rejection');
        $this->assertSame(10, $back->totalTokens);
        $this->assertSame(null, $back->inputTokens);
        $this->assertSame(null, $back->outputTokens);
        $this->assertSame(null, $back->cacheReadTokens);
        $this->assertSame(null, $back->cacheCreationTokens);
    }

    /**
     * A bucket value that is neither int nor null is a frame `toArray()` did
     * not write — the WHOLE frame is refused, per the existing
     * refuse-anything-it-did-not-write doctrine for the two original fields.
     * Without the whole-frame refusal, a garbled `'0'` string would decode to
     * unreported and a garbled field would silently impersonate the clean
     * absence of data.
     *
     * @dataProvider malformedBucketValues
     */
    public function testFromArrayRefusesAFullFrameWithGarbageInAnyBucket(array $frame): void
    {
        $this->assertNull(Usage::fromArray($frame));
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function malformedBucketValues(): iterable
    {
        yield 'input as a string' => [['totalTokens' => 10, 'costUsd' => 0.1, 'inputTokens' => '5']];
        yield 'output as a float' => [['totalTokens' => 10, 'costUsd' => 0.1, 'outputTokens' => 1.5]];
        yield 'cacheRead as a bool' => [['totalTokens' => 10, 'costUsd' => 0.1, 'cacheReadTokens' => true]];
        yield 'cacheCreation as an array' => [['totalTokens' => 10, 'costUsd' => 0.1, 'cacheCreationTokens' => [0]]];
        yield 'cacheRead as a zero float' => [['totalTokens' => 10, 'costUsd' => 0.1, 'cacheReadTokens' => 0.0]];
    }

    /**
     * A negative bucket on the wire is the same provider-bug case as a
     * negative total on the wire: the existing doctrine clamps rather than
     * rejects (see `testNegativeFiguresClampRatherThanThrow` and the int-cost
     * tolerance beside it), so it clamps to a REPORTED zero — which is still
     * materially different from the unreported null an absent key decodes to.
     */
    public function testANegativeBucketOnTheWireClampsRatherThanBeingRefused(): void
    {
        $back = Usage::fromArray(['totalTokens' => 10, 'costUsd' => 0.1, 'cacheReadTokens' => -3]);

        $this->assertNotNull($back);
        $this->assertSame(0, $back->cacheReadTokens);
        $this->assertSame(null, $back->inputTokens);
    }
    // =====================================================================
    // The provider enumeration the class docblock rests on
    // =====================================================================

    /**
     * `Usage`'s central justification is a COUNT — how many providers know an
     * input/output split and throw it away — and that count is the whole stated
     * reason `TokenTracker::addTotalUsage()` and its `unsplitTokens` bucket exist.
     * Nothing asserted it, and it was wrong: the docblock said two (Bedrock,
     * Vertex) and listed `OpenAIProvider` among the five that "never had one to
     * lose", while `OpenAIProvider::calculateCost()` reads `prompt_tokens` and
     * `completion_tokens`, prices each side at its own rate, and then reports only
     * `total_tokens`. Three, not two.
     *
     * DERIVED FROM THE PROVIDER SOURCES rather than restated, because a fourth
     * literal of a number that has already drifted once is a fourth thing to go
     * stale. The two sets are read off the files; the docblock is then required to
     * name each provider on the correct side of the sentence. A new provider, or
     * an existing one gaining or losing its split, reds this test with the name.
     */
    public function testTheDocblocksSplitEnumerationMatchesTheProviderSources(): void
    {
        $dir = dirname(__DIR__) . '/src/Providers';

        $split = [];
        $totalOnly = [];
        foreach ($this->providerClasses() as $short => $file) {
            $source = (string) file_get_contents($file);
            // The usage array keys, quoted, so a local variable named
            // $inputTokens cannot masquerade as a provider that reads one.
            $hasInput = preg_match("/'(?:inputTokens|input_tokens|prompt_tokens)'/", $source) === 1;
            $hasOutput = preg_match("/'(?:outputTokens|output_tokens|completion_tokens)'/", $source) === 1;
            if ($hasInput && $hasOutput) {
                $split[] = $short;
            } else {
                $totalOnly[] = $short;
            }
        }
        sort($split);
        sort($totalOnly);

        $this->assertSame(
            ['BedrockProvider', 'OpenAIProvider', 'VertexProvider'],
            $split,
            'the set of providers that read a separate input/output usage key changed',
        );
        $this->assertCount(
            7,
            [...$split, ...$totalOnly],
            'the provider count the docblock quotes ("three of the seven") changed',
        );

        // The docblock's two sides, read out of it by their own markers rather
        // than by position, so unrelated prose further down cannot be mistaken
        // for either list.
        $docblock = (string) (new \ReflectionClass(Usage::class))->getDocComment();
        $this->assertMatchesRegularExpression(
            '/THREE of the seven providers know the split(.*?)remaining four \(([^)]*)\)/s',
            $docblock,
            'the docblock no longer states the two-sided enumeration this test pins',
        );
        preg_match('/THREE of the seven providers know the split(.*?)remaining four \(([^)]*)\)/s', $docblock, $m);
        [, $splitSide, $totalOnlySide] = $m;

        foreach ($split as $name) {
            $this->assertStringContainsString(
                $name,
                $splitSide,
                "{$name} reads a separate input/output usage key; the docblock must name it on the split side",
            );
            $this->assertStringNotContainsString(
                $name,
                $totalOnlySide,
                "{$name} knows the split and must not be listed among those that never had one",
            );
        }
        foreach ($totalOnly as $name) {
            $this->assertStringContainsString(
                $name,
                $totalOnlySide,
                "{$name} reports a total only and must be listed on that side",
            );
            $this->assertStringNotContainsString(
                $name,
                $splitSide,
                "{$name} has no split to lose and must not be named on the split side",
            );
        }
    }

    /**
     * The one place the split is NOT already gone below this seam, which
     * `Usage`'s docblock claimed of all of them and `Runtime`'s docblock
     * contradicted. Runtime was right, and this pins which.
     *
     * Vertex's unary path sums (`tokensUsed: $inputTokens + $outputTokens`); its
     * Anthropic STREAM emits the two halves as separate `CompleteResponse`s with
     * `tokensUsed: $inputTokens` and `tokensUsed: $outputTokens`, which is why
     * `Runtime` sums across chunks instead of reading the last one.
     */
    public function testVertexsStreamEmitsTheTwoHalvesSeparatelyUnlikeItsUnaryPath(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Providers/VertexProvider.php');

        $this->assertStringContainsString(
            'tokensUsed: $inputTokens + $outputTokens',
            $source,
            'the unary path still collapses the split before the response leaves',
        );
        $this->assertMatchesRegularExpression(
            '/tokensUsed: \$inputTokens,/',
            $source,
            'the stream still emits input tokens on their own (message_start)',
        );
        $this->assertMatchesRegularExpression(
            '/tokensUsed: \$outputTokens,/',
            $source,
            'and output tokens on their own (message_delta)',
        );

        // Bedrock, whose docblock Vertex's used to claim equivalence with, really
        // does land its usage once - both of its paths sum.
        $bedrock = (string) file_get_contents(dirname(__DIR__) . '/src/Providers/BedrockProvider.php');
        $this->assertSame(
            2,
            preg_match_all('/tokensUsed: \$inputTokens \+ \$outputTokens/', $bedrock),
            'Bedrock sums on both its unary and its streaming path, which is the contract Vertex does NOT share',
        );
    }

    /**
     * @return array<string, string> short class name => absolute file path, for
     *                               every concrete {@see \SugarCraft\Crush\Providers\ProviderInterface}
     */
    private function providerClasses(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/src/Providers/*Provider.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $fqn = 'SugarCraft\\Crush\\Providers\\' . $short;
            if (!class_exists($fqn)) {
                continue;
            }
            $reflection = new \ReflectionClass($fqn);
            if ($reflection->isAbstract()
                || !$reflection->implementsInterface(\SugarCraft\Crush\Providers\ProviderInterface::class)
            ) {
                continue;
            }
            $out[$short] = $file;
        }

        return $out;
    }
}
