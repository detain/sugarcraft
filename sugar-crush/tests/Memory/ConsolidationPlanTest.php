<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\ConsolidationOp;
use SugarCraft\Crush\Memory\ConsolidationPlan;

/**
 * Roadmap 5.2: the consolidation reply is model output, so the plan parser
 * validates every field and turns anything malformed into a reasoned skip.
 */
final class ConsolidationPlanTest extends TestCase
{
    public function testAWellFormedReplyParsesIntoOperationsAndSkips(): void
    {
        $plan = ConsolidationPlan::parse(<<<'JSON'
            <think>let me look</think>
            ```json
            {"operations":[
              {"op":"add","scope":"user","type":"preference","content":"Prefers tabs.","tags":["Style Guide","indent!",42]},
              {"op":"update","id":"abc","content":"New text"},
              {"op":"delete","id":"old"}
            ],
             "skipped":[{"reason":"transient","detail":"build status"},{"reason":"made-up","detail":"x"}]}
            ```
            JSON);

        $mutations = $plan->mutations();
        self::assertCount(3, $mutations);
        self::assertSame([ConsolidationOp::ADD, 'user', 'preference', 'Prefers tabs.', ['style-guide', 'indent']], [
            $mutations[0]->kind, $mutations[0]->scope, $mutations[0]->type, $mutations[0]->content, $mutations[0]->tags,
        ]);
        self::assertSame([ConsolidationOp::UPDATE, 'abc', 'New text'], [$mutations[1]->kind, $mutations[1]->id, $mutations[1]->content]);
        self::assertSame([ConsolidationOp::DELETE, 'old'], [$mutations[2]->kind, $mutations[2]->id]);

        self::assertSame(['transient', 'unsupported'], array_map(static fn(ConsolidationOp $op): string => $op->reason, $plan->skips()));
        self::assertFalse($plan->changesNothing());
    }

    public function testUnknownScopeAndTypeFallBackToTheDefaults(): void
    {
        $op = ConsolidationPlan::parse('{"operations":[{"op":"add","scope":"agent","type":"gossip","content":"x"}]}')->mutations()[0];

        self::assertSame(['project', 'pattern'], [$op->scope, $op->type]);
    }

    public function testAnEmptyOperationListChangesNothing(): void
    {
        $plan = ConsolidationPlan::parse('{"operations":[],"skipped":[{"reason":"duplicate","detail":"known"}]}');

        self::assertTrue($plan->changesNothing());
        self::assertSame('duplicate', $plan->skips()[0]->reason);
    }

    /** @return iterable<string, array{0: string}> */
    public static function garbage(): iterable
    {
        yield 'prose' => ['Nothing worth saving here.'];
        yield 'broken json' => ['{"operations": [ }'];
        yield 'json list' => ['[1, 2]'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('garbage')]
    public function testAnUnusableReplyIsOneInvalidSkip(string $reply): void
    {
        $plan = ConsolidationPlan::parse($reply);

        self::assertTrue($plan->changesNothing());
        self::assertSame('invalid', $plan->skips()[0]->reason);
    }

    public function testMalformedOperationsBecomeInvalidSkips(): void
    {
        $plan = ConsolidationPlan::parse('{"operations":[{"op":"add"},{"op":"update","id":"x"},{"op":"delete"},{"op":"rewrite_all"},"nope",{"op":"noop","reason":"in_progress"}]}');

        self::assertTrue($plan->changesNothing());
        self::assertSame(
            ['invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'in_progress'],
            array_map(static fn(ConsolidationOp $op): string => $op->reason, $plan->skips()),
        );
    }

    public function testOperationsPastTheCapAreQuotaGuardSkips(): void
    {
        $ops = [];
        for ($i = 0; $i < ConsolidationPlan::MAX_OPS + 3; $i++) {
            $ops[] = ['op' => 'add', 'content' => "fact {$i}"];
        }
        $plan = ConsolidationPlan::parse(json_encode(['operations' => $ops], \JSON_THROW_ON_ERROR));

        self::assertCount(ConsolidationPlan::MAX_OPS, $plan->mutations());
        self::assertSame(['quota_guard', 'quota_guard', 'quota_guard'], array_map(static fn(ConsolidationOp $op): string => $op->reason, $plan->skips()));
    }
}
