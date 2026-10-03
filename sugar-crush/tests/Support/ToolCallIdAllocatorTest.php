<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ToolCallIdAllocator;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Step 0.2: the per-turn tool-call id ledger. A real, portable, first-seen
 * server id is kept; an empty, parser-positional, non-portable or repeated
 * one is rewritten to `tc_<nonce>_<seq>`.
 */
final class ToolCallIdAllocatorTest extends TestCase
{
    public function testARealPortableServerIdIsKeptVerbatim(): void
    {
        $call = new ToolCall('call_9fA-x_1', 'Read', ['path' => 'a']);

        [$out] = (new ToolCallIdAllocator('n1'))->assign([$call]);

        $this->assertSame($call, $out, 'an untouched call is the same instance');
    }

    public function testEmptyAndParserPositionalIdsAreRewritten(): void
    {
        $out = (new ToolCallIdAllocator('n1'))->assign([
            new ToolCall('', 'Read', []),
            new ToolCall('dsml_call_0', 'Read', []),
            new ToolCall('minimax_xml_call_3', 'Read', []),
        ]);

        $this->assertSame(['tc_n1_0', 'tc_n1_1', 'tc_n1_2'], array_map(static fn(ToolCall $c): string => $c->id(), $out));
    }

    public function testAnIdOutsideThePortableShapeIsRewritten(): void
    {
        $out = (new ToolCallIdAllocator('n1'))->assign([
            new ToolCall('tool.abc', 'Read', []),
            new ToolCall(str_repeat('a', 65), 'Read', []),
        ]);

        $this->assertSame(['tc_n1_0', 'tc_n1_1'], array_map(static fn(ToolCall $c): string => $c->id(), $out));
    }

    public function testAnIdRepeatedWithinTheTurnIsRewrittenAcrossSteps(): void
    {
        $ids = new ToolCallIdAllocator('n1');

        [$first] = $ids->assign([new ToolCall('call_0', 'Read', [])]);
        [$second] = $ids->assign([new ToolCall('call_0', 'Read', [])]);
        $sameStep = $ids->assign([new ToolCall('x', 'Read', []), new ToolCall('x', 'Read', [])]);

        $this->assertSame('call_0', $first->id());
        $this->assertSame('tc_n1_0', $second->id());
        $this->assertSame(['x', 'tc_n1_1'], array_map(static fn(ToolCall $c): string => $c->id(), $sameStep));
    }

    public function testObservedIdsAreNeverReusedAndMintingSkipsThem(): void
    {
        $ids = new ToolCallIdAllocator('n1');
        $ids->observe(['call_7', 'tc_n1_0', '']);

        $out = $ids->assign([new ToolCall('call_7', 'Read', []), new ToolCall('', 'Read', [])]);

        $this->assertSame(['tc_n1_1', 'tc_n1_2'], array_map(static fn(ToolCall $c): string => $c->id(), $out));
    }

    public function testRewritingKeepsEveryOtherFieldOfTheCall(): void
    {
        $call = new ToolCall('', 'Edit', ['opts' => []], 'broken', '{"opts":[]}');

        [$out] = (new ToolCallIdAllocator('n1'))->assign([$call]);

        $this->assertSame('tc_n1_0', $out->id());
        $this->assertSame('Edit', $out->name());
        $this->assertSame(['opts' => []], $out->arguments());
        $this->assertSame('broken', $out->argumentsError());
        $this->assertSame('{"opts":[]}', $out->rawArguments());
    }

    public function testANonToolCallEntryPassesThroughUntouched(): void
    {
        $shaped = ['id' => 'dsml_call_0', 'type' => 'function'];

        $this->assertSame([$shaped], (new ToolCallIdAllocator('n1'))->assign([$shaped]));
    }

    public function testTheDefaultNonceIsRandomAndEveryMintedIdIsPortable(): void
    {
        $a = ToolCallIdAllocator::new();
        $b = ToolCallIdAllocator::new();

        $this->assertNotSame($a->nonce(), $b->nonce());
        [$call] = $a->assign([new ToolCall('', 'Read', [])]);
        $this->assertMatchesRegularExpression('/^tc_[0-9a-f]{8}_0$/', $call->id());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $call->id());
    }

    public function testANonceThatWouldMintANonPortableIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ToolCallIdAllocator('a.b');
    }
}
