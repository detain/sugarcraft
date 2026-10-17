<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\CacheBreakpoints;
use SugarCraft\Code\Usage;

/**
 * P10.S2 — CacheBreakpoints wipe-then-reapply. P10.S3 adds the DISABLE_ENV
 * kill switch and the observeCacheHealth() consecutive-zero diagnostic; their
 * tests sit at the bottom of this file under the P10.S3 banner.
 *
 * Each of the plan's four load-bearing constraints (§4.15, all four measured
 * upstream failures) owns at least one test here, and every test asserts
 * VALUES — exact counts, exact mark positions, exact block arrays — never
 * shapes. §1.11: none of these pass on a decorative implementation; the
 * step-brief's deletion experiments are reported per test.
 *
 * The fixtures are Anthropic wire shapes copied from what the shipped
 * providers already build — {@see VertexProvider::formatAnthropicMessages()}
 * turns and {@see VertexProvider::formatAnthropicTools()} tool definitions —
 * not invented payload dialects.
 */
final class CacheBreakpointsTest extends TestCase
{
    /**
     * The fire message verbatim — written HERE, independently of the source's
     * concatenation, so the test pins what a consumer will actually read
     * (§16.8 rule 21: no literal derived from the implementation).
     */
    private const ZERO_REPORT_FIRE = 'CacheBreakpoints: 3 consecutive responses reported cache_read_input_tokens = 0 and cache_creation_input_tokens = 0; no prompt cache entry is being read or written. The minimum cacheable prefix is model-dependent (512 to 4096 tokens, §4.15) — below it the breakpoints are silently ignored upstream.';

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([CacheBreakpoints::DISABLE_ENV] as $name) {
            $this->originalEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }

        parent::tearDown();
    }
    /**
     * Constraint: the default mark plan. crush §5.8's four marks — last tool +
     * last system + last two messages — land on EXACTLY the right blocks, not
     * merely "four marks somewhere".
     */
    public function testDefaultMarkPlanIsLastToolPlusLastSystemPlusLastTwoMessages(): void
    {
        $breakpoints = new CacheBreakpoints();
        $tools = [
            ['name' => 'read', 'description' => 'r', 'input_schema' => []],
            ['name' => 'edit', 'description' => 'e', 'input_schema' => []],
        ];
        $messages = [
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'SYS']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'first']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'second']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'third']]],
        ];

        $out = $breakpoints->apply($messages, $tools);

        // The FIRST tool is not the marked one; the LAST tool is.
        self::assertArrayNotHasKey('cache_control', $out['tools'][0]);
        self::assertSame(['type' => 'ephemeral'], $out['tools'][1]['cache_control']);

        // System marked on its last (only) block; the FIRST message left alone.
        self::assertSame(
            ['type' => 'text', 'text' => 'SYS', 'cache_control' => ['type' => 'ephemeral']],
            $out['messages'][0]['content'][0],
        );
        self::assertArrayNotHasKey('cache_control', $out['messages'][1]['content'][0]);

        // Last two messages marked, on their exact blocks.
        self::assertSame(
            ['type' => 'text', 'text' => 'second', 'cache_control' => ['type' => 'ephemeral']],
            $out['messages'][2]['content'][0],
        );
        self::assertSame(
            ['type' => 'text', 'text' => 'third', 'cache_control' => ['type' => 'ephemeral']],
            $out['messages'][3]['content'][0],
        );

        self::assertSame(4, $this->census($out)['total']);
    }

    /**
     * CONSTRAINT 1 — THE WIPE. Anthropic caps cache_control at 4 per request;
     * a 20-step agentic loop feeding apply() output back into apply() must
     * never accumulate a fifth breakpoint. Pinned as the exact per-step count.
     */
    public function testTwentySimulatedStepsNeverLetTheBreakpointCountCrossTheApiCap(): void
    {
        $breakpoints = new CacheBreakpoints();
        $tools = [
            ['name' => 'read', 'description' => 'r', 'input_schema' => []],
            ['name' => 'edit', 'description' => 'e', 'input_schema' => []],
        ];
        $messages = [
            ['role' => 'system', 'content' => 'SYS'],
            ['role' => 'user', 'content' => 'go'],
        ];

        $perStep = [];
        for ($step = 0; $step < 20; $step++) {
            $messages[] = [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => "thinking $step"],
                    ['type' => 'tool_use', 'id' => "call-$step", 'name' => 'read', 'input' => []],
                ],
            ];
            $messages[] = [
                'role' => 'user',
                'content' => [['type' => 'tool_result', 'tool_use_id' => "call-$step", 'content' => "result $step"]],
            ];

            $out = $breakpoints->apply($messages, $tools);
            $messages = $out['messages'];
            $tools = $out['tools'];
            $perStep[] = $this->census($out)['total'];
        }

        self::assertSame(array_fill(0, 20, 4), $perStep);
        self::assertLessThanOrEqual(4, max($perStep));
    }

    /**
     * CONSTRAINT 2 — automatic caching consumes one of the four slots. With a
     * request already carrying a non-ephemeral (Bedrock cachePoint dialect)
     * breakpoint, this class adds AT MOST three explicit marks — and the
     * automatic mark survives byte-for-byte, because it is not ours to wipe.
     *
     * F-2 MECHANISM (chosen): the public BUDGET_WITH_AUTOMATIC constant is made
     * load-bearing here rather than in the src budget line. Two assertions pin
     * it: first its definition (MAX_BREAKPOINTS - 1), so a rename/retune of the
     * cap keeps it honest; second the observed ephemeral count for exactly this
     * one-automatic input, so mutating BUDGET_WITH_AUTOMATIC alone (3 -> 99)
     * reddens the test that documents it. The src computes the budget
     * dynamically (MAX_BREAKPOINTS minus the automatic count), which is correct
     * for the multi-automatic cases too — hardcoding the single constant into
     * that subtraction would be a step backward — so pinning the constant's
     * value and its named meaning from the test is the mechanism that fits the
     * arithmetic.
     */
    public function testAnAutomaticCacheBreakpointConsumesOneOfTheFourSlots(): void
    {
        $breakpoints = new CacheBreakpoints();
        $tools = [['name' => 'read', 'description' => 'r', 'input_schema' => []]];
        $messages = [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'text', 'text' => 'A'],
                    ['type' => 'text', 'text' => 'B', 'cache_control' => ['type' => 'default']],
                ],
            ],
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'assistant', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
        ];

        $out = $breakpoints->apply($messages, $tools);
        $census = $this->census($out);

        // The constant is MAX minus exactly one automatic slot, by definition.
        self::assertSame(
            CacheBreakpoints::MAX_BREAKPOINTS - 1,
            CacheBreakpoints::BUDGET_WITH_AUTOMATIC,
            'BUDGET_WITH_AUTOMATIC must be exactly one explicit slot under the cap — that is the case it names.',
        );

        // ...and that value IS the explicit budget this one-automatic input gets.
        self::assertSame(CacheBreakpoints::BUDGET_WITH_AUTOMATIC, $census['ephemeral']);
        self::assertSame(1, $census['automatic']);
        self::assertSame(CacheBreakpoints::MAX_BREAKPOINTS, $census['total']);

        // The automatic mark is exactly the block the gateway wrote, untouched:
        // its breakpoint slid to the nearest FREE block (text 'A'), leaving 'B'
        // (already owned) alone.
        self::assertSame(
            ['type' => 'text', 'text' => 'B', 'cache_control' => ['type' => 'default']],
            $out['messages'][0]['content'][1],
        );
        self::assertSame(
            ['type' => 'text', 'text' => 'A', 'cache_control' => ['type' => 'ephemeral']],
            $out['messages'][0]['content'][0],
        );
    }

    /**
     * Constraint 2, deeper edge: two automatic marks shrink the explicit
     * budget to two — the system mark and the chain head only.
     */
    public function testTwoAutomaticBreakpointsShrinkTheExplicitBudgetToTwo(): void
    {
        $breakpoints = new CacheBreakpoints();
        $auto = ['type' => 'default'];
        $tools = [['name' => 'read', 'description' => 'r', 'input_schema' => [], 'cache_control' => $auto]];
        $messages = [
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'S', 'cache_control' => $auto]]],
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'assistant', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
        ];

        $out = $breakpoints->apply($messages, $tools);
        $census = $this->census($out);

        self::assertSame(2, $census['ephemeral']);
        self::assertSame(2, $census['automatic']);
        self::assertSame(4, $census['total']);

        // Budget of two leaves only the two chain positions: the marked system
        // block is already owned by the automatic mark (it slides nowhere —
        // single-block region — so no slot is spent there), and no slot remains
        // for the tool.
        self::assertSame([2, 3], $this->markedPositions($out['messages']));
        self::assertSame($auto, $out['tools'][0]['cache_control']);
    }

    /**
     * Constraint 2, pathological: four automatic marks — exactly the cap —
     * leave zero explicit budget, and every automatic mark is still standing:
     * this class wipes only the dialect it owns, and at a zero budget it adds
     * nothing. The over-cap polarity (five marks) is no longer a budget
     * question at all — the producer's contract breach throws first, pinned by
     * testFiveForeignMarksAloneExceedingTheCapThrowNamingCountAndCap().
     */
    public function testFourAutomaticBreakpointsLeaveNoExplicitMarksAndStayIntact(): void
    {
        $breakpoints = new CacheBreakpoints();
        $auto = ['type' => 'default'];
        $messages = [
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'S', 'cache_control' => $auto]]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'u', 'cache_control' => $auto]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a', 'cache_control' => $auto]]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'b', 'cache_control' => $auto]]],
        ];

        $out = $breakpoints->apply($messages, []);
        $census = $this->census($out);

        self::assertSame(0, $census['ephemeral']);
        self::assertSame(4, $census['automatic']);
        self::assertSame(['S', 'u', 'a', 'b'], [
            $out['messages'][0]['content'][0]['text'],
            $out['messages'][1]['content'][0]['text'],
            $out['messages'][2]['content'][0]['text'],
            $out['messages'][3]['content'][0]['text'],
        ]);
        foreach ($out['messages'] as $i => $turn) {
            self::assertSame($auto, $turn['content'][0]['cache_control'], "automatic mark $i");
        }
    }

    /**
     * F-1 (b) — CONTRACT-VIOLATION FAIL-FAST. Preserved foreign (non-ephemeral)
     * marks that ALONE exceed MAX_BREAKPOINTS mean the producer handed us a
     * request that 400s whatever we do; the count 5 over the cap 4 is the broken
     * party's bug, surfaced at the seam in the same throw-on-garbage posture as
     * the shape guards. This is why "total <= MAX_BREAKPOINTS" is an invariant of
     * a successful return. Asserts the exception CLASS and that the message names
     * BOTH the offending count (5) and the cap (4), matching the fail-fast trio.
     */
    public function testFiveForeignMarksAloneExceedingTheCapThrowNamingCountAndCap(): void
    {
        $breakpoints = new CacheBreakpoints();
        $auto = ['type' => 'default'];
        $messages = [
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'S', 'cache_control' => $auto]]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'u', 'cache_control' => $auto]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a', 'cache_control' => $auto]]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'b', 'cache_control' => $auto]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'c', 'cache_control' => $auto]]],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/carries 5 automatic \(non-ephemeral\) cache marks, over the 4-breakpoint cap/');

        $breakpoints->apply($messages, []);
    }

    /**
     * F-1 (c) — POLARITY GUARD at the other side of the boundary. Exactly
     * MAX_BREAKPOINTS foreign marks and zero ephemeral is the largest SUCCESSFUL
     * input: `>` (not `>=`) is the operator, so this must return intact, not
     * throw. Distinct from the pathological four-automatic test above in shape —
     * the four foreign marks are DISTRIBUTED across a message-level, a tool-level,
     * and two block-level positions, pinning that the guard reads the SUMMED
     * count across wipeTools + wipeMessages, not any one region. A `>= MAX`
     * off-by-one (mutation F-1c) reddens this test with a spurious throw.
     */
    public function testExactlyFourForeignMarksAcrossAllSourcesSucceedsWithoutThrowing(): void
    {
        $breakpoints = new CacheBreakpoints();
        $auto = ['type' => 'default'];
        $tools = [
            ['name' => 'read', 'description' => 'r', 'input_schema' => []],
            ['name' => 'edit', 'description' => 'e', 'input_schema' => [], 'cache_control' => $auto],
        ];
        $messages = [
            ['role' => 'system', 'content' => 'S', 'cache_control' => $auto],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'u', 'cache_control' => $auto]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a', 'cache_control' => $auto]]],
            ['role' => 'user', 'content' => 'b'],
        ];

        $out = $breakpoints->apply($messages, $tools);
        $census = $this->census($out);

        // Succeeded, and the summed foreign count is exactly at the cap: no
        // explicit budget left, but nothing thrown.
        self::assertSame(4, $census['automatic']);
        self::assertSame(0, $census['ephemeral']);
        self::assertSame(CacheBreakpoints::MAX_BREAKPOINTS, $census['total']);
    }

    /**
     * CONSTRAINT 3 — a breakpoint on a varying block is never a hit, so the
     * blocks this class marks in the stable prefix must carry identical bytes
     * across consecutive requests built from the same fixture prefix. Pinned
     * two ways: the system-region mark's serialised bytes across a grown
     * transcript, and byte-identical output for byte-identical input (no
     * clock, no randomness).
     */
    public function testMarkedBlockBytesAreIdenticalAcrossConsecutiveRequestsBuiltFromTheSamePrefix(): void
    {
        $breakpoints = new CacheBreakpoints();
        $tools = [['name' => 'read', 'description' => 'r', 'input_schema' => []]];
        $prefix = [
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'SYS'], ['type' => 'text', 'text' => 'RULES']]],
            ['role' => 'user', 'content' => 'q1'],
        ];

        $first = $breakpoints->apply($prefix, $tools);
        $again = $breakpoints->apply($prefix, $tools);
        self::assertSame(json_encode($first), json_encode($again), 'same input, different bytes');

        $grown = $first['messages'];
        $grown[] = ['role' => 'assistant', 'content' => 'a1'];
        $grown[] = ['role' => 'user', 'content' => 'q2'];
        $second = $breakpoints->apply($grown, $tools);

        // Positive control: the first request really did mark the system tail.
        self::assertSame(
            ['type' => 'ephemeral'],
            $first['messages'][0]['content'][1]['cache_control'] ?? [],
        );

        // The marked prefix block survives into the next request byte-identical.
        self::assertSame(
            json_encode($first['messages'][0]['content'][1]),
            json_encode($second['messages'][0]['content'][1]),
        );
        self::assertSame(
            json_encode($first['tools'][0]),
            json_encode($second['tools'][0]),
        );
    }

    /**
     * CONSTRAINT 4 — 20-block lookback. One assistant turn of 41 blocks sits
     * above INTERMEDIATE_SPACING of history: intermediate marks must break it
     * into ≤15-block windows, the tool mark yields to repair slots (REPAIR
     * PRIORITY), and the totals land exactly. Positions derived from the wire
     * fixture: system block 0, setup block 1, long turn blocks 2..42.
     */
    public function testATurnLongerThanTheLookbackWindowCarriesIntermediateMarksEveryFifteenBlocks(): void
    {
        $breakpoints = new CacheBreakpoints();
        $tools = [
            ['name' => 'read', 'description' => 'r', 'input_schema' => []],
            ['name' => 'edit', 'description' => 'e', 'input_schema' => []],
        ];
        $longTurn = array_map(
            static fn (int $i): array => ['type' => 'tool_use', 'id' => "u$i", 'name' => 'bash', 'input' => ['c' => $i]],
            range(0, 40),
        );
        $messages = [
            ['role' => 'system', 'content' => 'SYS'],
            ['role' => 'user', 'content' => 'setup'],
            ['role' => 'assistant', 'content' => $longTurn],
        ];

        $out = $breakpoints->apply($messages, $tools);

        self::assertSame([0, 12, 27, 42], $this->markedPositions($out['messages']));
        self::assertSame(0, $this->census(['tools' => $out['tools'], 'messages' => []])['ephemeral'], 'the tool mark yields');

        // Every consecutive gap, anchored at the region start, stays within the
        // spacing, and every ~15-block window of the long turn holds a mark.
        $previous = 0;
        foreach ([0, 12, 27, 42] as $position) {
            self::assertLessThanOrEqual(15, $position - $previous, "gap into block $position");
            $previous = $position;
        }
        self::assertGreaterThanOrEqual(1, count(array_intersect($this->markedPositions($out['messages']), [12, 27, 42])));
        foreach ([[2, 16], [17, 31], [32, 46]] as [$from, $to]) {
            $window = array_values(array_filter(
                $this->markedPositions($out['messages']),
                static fn (int $p): bool => $p >= $from && $p <= $to,
            ));
            self::assertNotEmpty($window, "long-turn window $from..$to holds no breakpoint");
        }

        self::assertSame(4, $this->census($out)['total']);
    }

    /**
     * Constraint 4, polarity pair: the SAME transcript with a short last turn
     * keeps the tool mark — proving the yield is caused by the long turn, not
     * a blanket refusal to mark tools.
     */
    public function testAShortTurnKeepsTheToolMarkThatTheLongTurnSurrenders(): void
    {
        $breakpoints = new CacheBreakpoints();
        $tools = [['name' => 'read', 'description' => 'r', 'input_schema' => []]];
        $messages = [
            ['role' => 'system', 'content' => 'SYS'],
            ['role' => 'user', 'content' => 'setup'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'short']]],
        ];

        $out = $breakpoints->apply($messages, $tools);

        self::assertSame(['type' => 'ephemeral'], $out['tools'][0]['cache_control']);
        self::assertSame([0, 1, 2], $this->markedPositions($out['messages']));
        self::assertSame(4, $this->census($out)['total']);
    }

    /**
     * Constraint 1's reach: the wipe clears stale ephemeral marks at MESSAGE
     * level (the crush/opencode message-level spelling) and TOOL level, not
     * just block level, then re-marks only where this class places marks.
     */
    public function testWipeReachesMessageLevelAndToolLevelStaleMarks(): void
    {
        $breakpoints = new CacheBreakpoints();
        $ephemeral = ['type' => 'ephemeral'];
        $tools = [
            ['name' => 'read', 'description' => 'r', 'input_schema' => [], 'cache_control' => $ephemeral],
            ['name' => 'edit', 'description' => 'e', 'input_schema' => []],
        ];
        $messages = [
            ['role' => 'system', 'content' => 'SYS'],
            ['role' => 'user', 'content' => 'q', 'cache_control' => $ephemeral],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a', 'cache_control' => $ephemeral]]],
        ];

        $out = $breakpoints->apply($messages, $tools);

        self::assertArrayNotHasKey('cache_control', $out['messages'][1]);
        self::assertArrayNotHasKey('cache_control', $out['tools'][0]);
        self::assertSame($ephemeral, $out['tools'][1]['cache_control']);
        self::assertSame($ephemeral, $out['messages'][0]['content'][0]['cache_control']);
        self::assertSame($ephemeral, $out['messages'][2]['content'][0]['cache_control']);
        self::assertSame(4, $this->census($out)['total']);
    }

    /**
     * Boundary parsing: string content cannot carry a block-level marker, so it
     * normalises to exactly one text block — and that block then takes the
     * system mark. Pinned as an exact array, not a shape check.
     */
    public function testStringContentNormalisesToASingleMarkedTextBlock(): void
    {
        $breakpoints = new CacheBreakpoints();
        $out = $breakpoints->apply([['role' => 'system', 'content' => 'SYS']], []);

        self::assertSame(
            [['role' => 'system', 'content' => [['type' => 'text', 'text' => 'SYS', 'cache_control' => ['type' => 'ephemeral']]]]],
            $out['messages'],
        );
        self::assertSame([], $out['tools']);
    }

    /**
     * apply(apply(x)) === apply(x): the brief does not require idempotence but
     * it holds by construction (the wipe re-derives from scratch), so it is
     * pinned — on the short transcript and the 41-block long turn alike.
     */
    public function testApplyIsIdempotentOnShortAndLongTranscripts(): void
    {
        $breakpoints = new CacheBreakpoints();
        $tools = [['name' => 'read', 'description' => 'r', 'input_schema' => []]];

        $short = [
            ['role' => 'system', 'content' => 'SYS'],
            ['role' => 'user', 'content' => 'q'],
        ];
        $once = $breakpoints->apply($short, $tools);
        self::assertSame(json_encode($once), json_encode($breakpoints->apply($once['messages'], $once['tools'])));

        $long = [
            ['role' => 'system', 'content' => 'SYS'],
            ['role' => 'assistant', 'content' => array_map(
                static fn (int $i): array => ['type' => 'tool_use', 'id' => "u$i", 'name' => 'bash', 'input' => []],
                range(0, 44),
            )],
        ];
        $once = $breakpoints->apply($long, $tools);
        self::assertSame(json_encode($once), json_encode($breakpoints->apply($once['messages'], $once['tools'])));
    }

    /**
     * Both polarities of emptiness: nothing in, nothing marked, keys present;
     * tools-only still gets its one legitimate last-tool breakpoint.
     */
    public function testEmptyTranscriptMarksNothingWhileToolsOnlyMarksExactlyTheLastTool(): void
    {
        $breakpoints = new CacheBreakpoints();

        self::assertSame(['tools' => [], 'messages' => []], $breakpoints->apply([], []));

        $tools = [
            ['name' => 'read', 'description' => 'r', 'input_schema' => []],
            ['name' => 'edit', 'description' => 'e', 'input_schema' => []],
        ];
        $out = $breakpoints->apply([], $tools);

        self::assertArrayNotHasKey('cache_control', $out['tools'][0]);
        self::assertSame(['type' => 'ephemeral'], $out['tools'][1]['cache_control']);
        self::assertSame([], $out['messages']);
    }

    /**
     * Fail-fast at the boundary: a turn without a role 400s the wire later with
     * no useful detail, so it throws HERE, naming the bad key.
     */
    public function testATurnWithoutARoleThrowsNamingTheTurn(): void
    {
        $breakpoints = new CacheBreakpoints();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-empty string "role"; the turn at key 2/');

        $breakpoints->apply([
            ['role' => 'user', 'content' => 'ok'],
            ['role' => 'user', 'content' => 'ok'],
            ['content' => 'no role'],
        ], []);
    }

    /**
     * Fail-fast, second shape: content that is neither string nor array is a
     * bug upstream of us, not a state to paper over.
     */
    public function testNonStringNonArrayContentThrows(): void
    {
        $breakpoints = new CacheBreakpoints();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/message content must be a string or an array of blocks/');

        $breakpoints->apply([['role' => 'user', 'content' => 42]], []);
    }

    /**
     * Fail-fast, third shape: a non-array tool definition. The benign polarity
     * (array tools pass) is asserted by every other test in this file.
     */
    public function testANonArrayToolThrows(): void
    {
        $breakpoints = new CacheBreakpoints();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/every tool must be an array definition/');

        $breakpoints->apply([], ['read']);
    }

    // =========================================================================
    // P10.S3 — THE KILL SWITCH (DISABLE_ENV / marksEnabled) AND THE
    // CONSECUTIVE-ZERO REPORTS DIAGNOSTIC (observeCacheHealth).
    // =========================================================================

    /**
     * The flag's NAME and the presence-flag TRUTH TABLE: unset, empty and the
     * literal `0` read enabled; `1` and `true` read disabled. Every row is a
     * value pin on the shipped reader, and setUp hands the test a known-unset
     * starting state (Env hygiene per the EngineBackendParallelConfigTest
     * precedent — the flag can never leak past this file).
     */
    public function testDisabledFromEnvironmentFollowsThePresenceFlagTruthTable(): void
    {
        self::assertSame('CANDY_CODE_DISABLE_PROMPT_CACHE', CacheBreakpoints::DISABLE_ENV);

        self::assertFalse(CacheBreakpoints::disabledFromEnvironment());

        putenv(CacheBreakpoints::DISABLE_ENV . '=');
        self::assertFalse(CacheBreakpoints::disabledFromEnvironment());

        putenv(CacheBreakpoints::DISABLE_ENV . '=0');
        self::assertFalse(CacheBreakpoints::disabledFromEnvironment());

        putenv(CacheBreakpoints::DISABLE_ENV . '=1');
        self::assertTrue(CacheBreakpoints::disabledFromEnvironment());

        putenv(CacheBreakpoints::DISABLE_ENV . '=true');
        self::assertTrue(CacheBreakpoints::disabledFromEnvironment());
    }

    /**
     * Shared input for the enabled/disabled pair: the default-mark-plan fixture
     * PLUS one foreign automatic mark on the last tool PLUS one stale ephemeral
     * mark on the system block — so every duty apply() owes even when disabled
     * (the wipe, the preserve, the count) has a witness inside the same input.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function killSwitchFixture(): array
    {
        $tools = [
            ['name' => 'read', 'description' => 'r', 'input_schema' => []],
            ['name' => 'edit', 'description' => 'e', 'input_schema' => [], 'cache_control' => ['type' => 'default']],
        ];
        $messages = [
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'SYS', 'cache_control' => ['type' => 'ephemeral']]]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'first']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'second']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'third']]],
        ];

        return [$messages, $tools];
    }

    /**
     * THE TOGGLE, both polarities on ONE input. Disabled: full audit (the stale
     * ephemeral mark is wiped — its block comes back byte-clean), the foreign
     * mark rides on byte-intact, and not one ephemeral breakpoint is added.
     * Enabled: budget 3 under the foreign mark, marks land on system + the last
     * two messages, the tool mark surrenders per REPAIR PRIORITY. The bare
     * constructor must equal the enabled twin — default-true keeps every
     * pre-switch call site byte-identical.
     */
    public function testDisabledInstanceAuditsAndWipesButAddsNoEphemeralMarks(): void
    {
        [$messages, $tools] = $this->killSwitchFixture();

        $off = (new CacheBreakpoints(false))->apply($messages, $tools);

        self::assertSame(0, $this->census($off)['ephemeral']);
        self::assertSame([], $this->markedPositions($off['messages']));
        self::assertSame(['type' => 'text', 'text' => 'SYS'], $off['messages'][0]['content'][0]);
        self::assertSame(['type' => 'default'], $off['tools'][1]['cache_control']);
        self::assertSame(1, $this->census($off)['automatic']);
        self::assertArrayNotHasKey('cache_control', $off['tools'][0]);

        $on = (new CacheBreakpoints(true))->apply($messages, $tools);

        self::assertSame([0, 2, 3], $this->markedPositions($on['messages']));
        self::assertSame(3, $this->census($on)['ephemeral']);
        self::assertSame(1, $this->census($on)['automatic']);
        self::assertSame(4, $this->census($on)['total']);
        self::assertSame(['type' => 'default'], $on['tools'][1]['cache_control']);

        self::assertSame((new CacheBreakpoints())->apply($messages, $tools), $on);
    }

    /**
     * Disabling is NOT deleting: the F-1 over-cap breach on preserved foreign
     * marks is input-derived, so a disabled instance throws the SAME sentence
     * naming the SAME count — the audit runs before the switch short-circuits.
     */
    public function testDisabledInstanceStillThrowsOnFiveForeignMarksNamingCountAndCap(): void
    {
        $breakpoints = new CacheBreakpoints(false);
        $auto = ['type' => 'default'];
        $messages = [
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'S', 'cache_control' => $auto]]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'u', 'cache_control' => $auto]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'a', 'cache_control' => $auto]]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'b', 'cache_control' => $auto]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'c', 'cache_control' => $auto]]],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/carries 5 automatic \(non-ephemeral\) cache marks, over the 4-breakpoint cap/');

        $breakpoints->apply($messages, []);
    }

    /**
     * Disabling is NOT skipping validation: a turn whose content is neither
     * string nor array still fails loudly on a disabled instance.
     */
    public function testDisabledInstanceStillThrowsOnMalformedTurn(): void
    {
        $breakpoints = new CacheBreakpoints(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/message content must be a string or an array of blocks; the turn at key 0 carries integer\./');

        $breakpoints->apply([['role' => 'user', 'content' => 42]], []);
    }

    /**
     * The consecutive-zero boundary pinned from BOTH sides: reports 1 and 2 of
     * a row stay null, the 3rd fires the exact sentence, the 4th fires it again
     * (no internal suppression beyond the counter — the consumer decides).
     * The threshold VALUE is pinned as a literal, not read off the constant.
     */
    public function testTheSubMinimumDiagnosticFiresOnTheThirdConsecutiveZeroReportAndKeepsFiring(): void
    {
        self::assertSame(3, CacheBreakpoints::CONSECUTIVE_ZERO_REPORTS_THRESHOLD);

        $breakpoints = new CacheBreakpoints();
        $zero = Usage::new(100, 0.1, 90, 10, 0, 0);

        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertSame(self::ZERO_REPORT_FIRE, $breakpoints->observeCacheHealth($zero));
        self::assertSame(self::ZERO_REPORT_FIRE, $breakpoints->observeCacheHealth($zero));
    }

    /**
     * A reported non-zero in EITHER bucket resets the row — and a lone zero
     * bucket never counts: §4.15's fire condition is both at zero together.
     * Three consecutive (read 0, creation 5) reports fire nothing, three
     * (read 5, creation 0) fire nothing; only then does the two-zeros-reset-
     * then-three-zeros sequence show the reset really zeroed the count.
     */
    public function testReportedNonZeroInEitherBucketResetsTheRowWhileSingleBucketZerosNeverCount(): void
    {
        $breakpoints = new CacheBreakpoints();
        $zero = Usage::new(100, 0.1, 90, 10, 0, 0);
        $creationReported = Usage::new(100, 0.1, 90, 10, 0, 5);
        $readReported = Usage::new(100, 0.1, 90, 10, 5, 0);

        self::assertNull($breakpoints->observeCacheHealth($creationReported));
        self::assertNull($breakpoints->observeCacheHealth($creationReported));
        self::assertNull($breakpoints->observeCacheHealth($creationReported));
        self::assertNull($breakpoints->observeCacheHealth($readReported));
        self::assertNull($breakpoints->observeCacheHealth($readReported));
        self::assertNull($breakpoints->observeCacheHealth($readReported));

        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth($readReported));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertSame(self::ZERO_REPORT_FIRE, $breakpoints->observeCacheHealth($zero));
    }

    /**
     * Null means UNREPORTED (Usage.php null-vs-0 doctrine), and unreported data
     * neither counts nor fires: a null Usage, a half-reported Usage (one bucket
     * null) and a fully-default Usage each reset the row mid-sequence, and the
     * fire waits for three REAL both-zero reports after every such reset.
     */
    public function testUnreportedUsageResetsTheRowWithoutFiring(): void
    {
        $breakpoints = new CacheBreakpoints();
        $zero = Usage::new(100, 0.1, 90, 10, 0, 0);

        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth(null));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertSame(self::ZERO_REPORT_FIRE, $breakpoints->observeCacheHealth($zero));

        self::assertNull($breakpoints->observeCacheHealth(Usage::new(100, 0.1, 90, 10, null, 0)));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth(Usage::new(100, 0.1, 90, 10, 0, null)));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertNull($breakpoints->observeCacheHealth($zero));
        self::assertSame(self::ZERO_REPORT_FIRE, $breakpoints->observeCacheHealth($zero));

        $unreported = Usage::new();
        self::assertNull($breakpoints->observeCacheHealth($unreported));
        self::assertNull($breakpoints->observeCacheHealth($unreported));
        self::assertNull($breakpoints->observeCacheHealth($unreported));
        self::assertNull($breakpoints->observeCacheHealth($unreported));
    }

    // -------------------------------------------------------------------------
    // Measurement helpers (over the OUTPUT, not the implementation)
    // -------------------------------------------------------------------------

    /**
     * @param array{tools?: array<array-key, array<string, mixed>>, messages?: array<array-key, array<string, mixed>>} $out
     * @return array{ephemeral: int, automatic: int, total: int}
     */
    private function census(array $out): array
    {
        $marks = [];

        foreach (($out['tools'] ?? []) as $tool) {
            if (isset($tool['cache_control'])) {
                $marks[] = $tool['cache_control'];
            }
        }

        foreach (($out['messages'] ?? []) as $turn) {
            if (isset($turn['cache_control'])) {
                $marks[] = $turn['cache_control'];
            }

            foreach ($turn['content'] ?? [] as $block) {
                if (is_array($block) && isset($block['cache_control'])) {
                    $marks[] = $block['cache_control'];
                }
            }
        }

        $ephemeral = 0;
        foreach ($marks as $mark) {
            if (($mark['type'] ?? null) === 'ephemeral') {
                $ephemeral++;
            }
        }

        return ['ephemeral' => $ephemeral, 'automatic' => count($marks) - $ephemeral, 'total' => count($marks)];
    }

    /**
     * Flattened content-block indexes of every ephemeral-marked block, in wire
     * order — the coordinate system §4.15's lookback window counts in.
     *
     * @param array<array-key, array<string, mixed>> $messages
     * @return list<int>
     */
    private function markedPositions(array $messages): array
    {
        $positions = [];
        $index = -1;

        foreach ($messages as $turn) {
            foreach ($turn['content'] ?? [] as $block) {
                $index++;
                if (is_array($block) && ($block['cache_control']['type'] ?? null) === 'ephemeral') {
                    $positions[] = $index;
                }
            }
        }

        return $positions;
    }
}
