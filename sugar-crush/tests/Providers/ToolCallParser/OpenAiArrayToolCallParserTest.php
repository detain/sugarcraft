<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers\ToolCallParser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * W1.A5 (crush_feat.md §12 D6): the default `message.tool_calls[]` strategy.
 */
final class OpenAiArrayToolCallParserTest extends TestCase
{
    public function testNewReturnsInstanceOfTheInterface(): void
    {
        $this->assertInstanceOf(ToolCallParserInterface::class, OpenAiArrayToolCallParser::new());
    }

    public function testParseReturnsNullWhenMessageHasNoToolCallsKey(): void
    {
        $this->assertNull(OpenAiArrayToolCallParser::new()->parse(['content' => 'hello']));
    }

    public function testParseReturnsNullWhenToolCallsIsNotAnArray(): void
    {
        $this->assertNull(OpenAiArrayToolCallParser::new()->parse(['tool_calls' => null]));
    }

    public function testParseReturnsEmptyArrayForAnEmptyToolCallsArray(): void
    {
        // Distinguishable from "no tool calls at all", which is null.
        $this->assertSame([], OpenAiArrayToolCallParser::new()->parse(['tool_calls' => []]));
    }

    public function testParseMapsIdNameAndDecodedArguments(): void
    {
        $calls = OpenAiArrayToolCallParser::new()->parse([
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'function' => ['name' => 'read', 'arguments' => '{"path":"/tmp/a"}'],
                ],
            ],
        ]);

        $this->assertNotNull($calls);
        $this->assertCount(1, $calls);
        $this->assertInstanceOf(ToolCall::class, $calls[0]);
        $this->assertSame('call_1', $calls[0]->id());
        $this->assertSame('read', $calls[0]->name());
        $this->assertSame(['path' => '/tmp/a'], $calls[0]->arguments());
    }

    public function testParsePreservesOrderOfMultipleCalls(): void
    {
        $calls = OpenAiArrayToolCallParser::new()->parse([
            'tool_calls' => [
                ['id' => 'a', 'function' => ['name' => 'first', 'arguments' => '{}']],
                ['id' => 'b', 'function' => ['name' => 'second', 'arguments' => '{}']],
            ],
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(['first', 'second'], array_map(static fn (ToolCall $c): string => $c->name(), $calls));
    }

    public function testParseAcceptsAlreadyDecodedArgumentArrays(): void
    {
        $calls = OpenAiArrayToolCallParser::new()->parse([
            'tool_calls' => [
                ['id' => 'x', 'function' => ['name' => 'ls', 'arguments' => ['dir' => '/etc']]],
            ],
        ]);

        $this->assertNotNull($calls);
        $this->assertSame(['dir' => '/etc'], $calls[0]->arguments());
    }

    public function testParseTreatsBlankArgumentsAsAZeroArgumentCall(): void
    {
        $calls = OpenAiArrayToolCallParser::new()->parse([
            'tool_calls' => [
                ['id' => 'x', 'function' => ['name' => 'now', 'arguments' => '   ']],
            ],
        ]);

        $this->assertNotNull($calls);
        $this->assertSame([], $calls[0]->arguments());
    }

    public function testParseToleratesMissingIdAndName(): void
    {
        $calls = OpenAiArrayToolCallParser::new()->parse(['tool_calls' => [[]]]);

        $this->assertNotNull($calls);
        $this->assertSame('', $calls[0]->id());
        $this->assertSame('', $calls[0]->name());
        $this->assertSame([], $calls[0]->arguments());
    }

    public function testParseSkipsNonArrayEntries(): void
    {
        $calls = OpenAiArrayToolCallParser::new()->parse([
            'tool_calls' => ['garbage', ['id' => 'x', 'function' => ['name' => 'ok', 'arguments' => '{}']]],
        ]);

        $this->assertNotNull($calls);
        $this->assertCount(1, $calls);
        $this->assertSame('ok', $calls[0]->name());
    }

    public function testInjectedArgumentDecoderReceivesRawPayloadAndToolName(): void
    {
        // The injection point exists so a provider can keep its own diagnostics
        // (e.g. SglangProvider's §12 D5 truncation warning) instead of this
        // class silently re-implementing a quieter decode.
        $seen = [];

        $parser = OpenAiArrayToolCallParser::new(
            function (mixed $raw, string $name) use (&$seen): array {
                $seen[] = [$raw, $name];

                return ['decoded' => true];
            },
        );

        $calls = $parser->parse([
            'tool_calls' => [
                ['id' => 'x', 'function' => ['name' => 'edit', 'arguments' => '{"broken":']],
            ],
        ]);

        $this->assertSame([['{"broken":', 'edit']], $seen);
        $this->assertNotNull($calls);
        $this->assertSame(['decoded' => true], $calls[0]->arguments());
    }

    public function testMalformedArgumentsDegradeToNoArgumentsWithoutTheInjectedDecoder(): void
    {
        $calls = OpenAiArrayToolCallParser::new()->parse([
            'tool_calls' => [
                ['id' => 'x', 'function' => ['name' => 'edit', 'arguments' => '{"path":"/a/b</parameter>']],
            ],
        ]);

        $this->assertNotNull($calls);
        $this->assertSame([], $calls[0]->arguments());
    }
}
