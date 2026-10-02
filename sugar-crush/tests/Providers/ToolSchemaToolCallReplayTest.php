<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Regression guard for audit A7: replaying an assistant turn's tool calls must
 * reproduce the arguments the model actually sent.
 *
 * `formatToolCalls()` used JSON_FORCE_OBJECT to keep an argument-less call from
 * going out as `[]`. That flag is RECURSIVE, so every list nested inside the
 * arguments was rewritten too: `{"paths":["a.php","b.php"]}` replayed as
 * `{"paths":{"0":"a.php","1":"b.php"}}`. The model then sees itself having
 * called the tool with an object where its schema says array — a transcript
 * that contradicts the tool contract on every follow-up turn.
 *
 * The `?: '{}'` fallback was a second silent rewrite: one invalid UTF-8 byte
 * (a filename, a pasted log line) made json_encode() return false, and the call
 * was replayed as if it had taken no arguments at all.
 *
 * The trait is driven directly AND through OpenAIProvider's real message
 * formatter, so a provider that stopped delegating to the trait would still be
 * caught. Sglang/Custom share the same trait method.
 */
final class ToolSchemaToolCallReplayTest extends TestCase
{
    public function testListArgumentReplaysAsJsonArray(): void
    {
        $args = $this->replay(['paths' => ['a.php', 'b.php']]);

        $this->assertStringContainsString('["a.php","b.php"]', $args);
        $this->assertStringNotContainsString('{"0":', $args);
        $this->assertSame(['paths' => ['a.php', 'b.php']], json_decode($args, true));
    }

    /** The original reason for forcing objects must still hold: `{}`, never `[]`. */
    public function testEmptyArgumentsReplayAsEmptyObject(): void
    {
        $this->assertSame('{}', $this->replay([]));
    }

    public function testNestedAssociativeArgumentStaysAnObject(): void
    {
        $args = $this->replay(['opts' => ['depth' => 2, 'filter' => ['ext' => 'php']]]);

        $this->assertSame('{"opts":{"depth":2,"filter":{"ext":"php"}}}', $args);
    }

    /**
     * `function.arguments` must decode to a JSON object at the TOP level even if
     * the decoded arguments happen to be a list — only nested values keep their
     * list shape.
     */
    public function testTopLevelListArgumentsStillEncodeAsAnObject(): void
    {
        $args = $this->replay(['a.php', 'b.php']);

        $this->assertSame('{"0":"a.php","1":"b.php"}', $args);
    }

    /** A bad byte must not erase every other argument the call carried. */
    public function testInvalidUtf8DoesNotCollapseArgumentsToEmptyObject(): void
    {
        $args = $this->replay(['path' => "bad\xB1name.php", 'mode' => 'read']);

        $this->assertNotSame('{}', $args);
        $decoded = json_decode($args, true);
        $this->assertIsArray($decoded);
        $this->assertSame('read', $decoded['mode']);
        $this->assertStringStartsWith('bad', $decoded['path']);
        $this->assertStringEndsWith('name.php', $decoded['path']);
    }

    /** Non-ASCII text is sent as itself rather than inflated to \u escapes. */
    public function testUnicodeArgumentIsNotEscaped(): void
    {
        $this->assertSame('{"query":"café"}', $this->replay(['query' => 'café']));
    }

    /** The same fix reaches the wire through a real provider's formatter. */
    public function testOpenAIProviderReplaysListArgumentAsJsonArray(): void
    {
        $provider = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');
        $formatter = (new \ReflectionClass($provider))->getMethod('formatMessages');

        $messages = $formatter->invoke($provider, [
            new AssistantMessage('', [new ToolCall('c1', 'mcp__git__git_add', ['paths' => ['a.php', 'b.php']])]),
        ]);

        $this->assertSame(
            '{"paths":["a.php","b.php"]}',
            $messages[0]['tool_calls'][0]['function']['arguments'],
        );
    }

    /**
     * @param array<mixed> $arguments
     */
    private function replay(array $arguments): string
    {
        // An anonymous host keeps the test on the trait itself; formatToolCalls()
        // is private, so the host re-exposes it.
        $host = new class () {
            use ToolSchema;

            /**
             * @param array<mixed> $calls
             * @return array<mixed>
             */
            public function format(array $calls): array
            {
                return $this->formatToolCalls($calls);
            }
        };

        $wire = $host->format([new ToolCall('call_1', 'tool', $arguments)]);

        $this->assertIsString($wire[0]['function']['arguments'], 'OpenAI requires arguments to be a JSON STRING');

        return $wire[0]['function']['arguments'];
    }
}
