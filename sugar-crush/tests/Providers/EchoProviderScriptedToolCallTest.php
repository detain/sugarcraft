<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * The offline provider's scripted tool-call mode (roadmap O-5b): a user turn
 * of `::tool <Name> <json>` lines becomes real tool calls, and once their
 * results are in the next step reports them, so the web UI's end-to-end tests
 * can drive tool cards, diffs and permission questions with no model.
 *
 * @see EchoProvider
 */
final class EchoProviderScriptedToolCallTest extends TestCase
{
    public function testAToolLineBecomesOneToolCall(): void
    {
        $response = self::complete([new UserMessage('::tool Bash {"command":"git status"}')]);

        $this->assertSame('', $response->content);
        $this->assertCount(1, $response->toolCalls ?? []);
        $call = $response->toolCalls[0];
        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame('echo_call_1', $call->id());
        $this->assertSame('Bash', $call->name());
        $this->assertSame(['command' => 'git status'], $call->arguments());
        $this->assertNull($call->argumentsError());
    }

    public function testEveryToolLineIsOneCallAndProseIsIgnored(): void
    {
        $response = self::complete([new UserMessage(
            "run these:\n::tool Read {\"file_path\":\"a.txt\"}\n  ::tool Write {\"file_path\":\"b.txt\",\"content\":\"x\"}\nthanks",
        )]);

        $calls = $response->toolCalls ?? [];
        $this->assertSame(['Read', 'Write'], array_map(static fn (ToolCall $c): string => $c->name(), $calls));
        $this->assertSame(['echo_call_1', 'echo_call_2'], array_map(static fn (ToolCall $c): string => $c->id(), $calls));
        $this->assertSame(['file_path' => 'b.txt', 'content' => 'x'], $calls[1]->arguments());
    }

    public function testAToolLineWithoutArgumentsIsAZeroArgumentCall(): void
    {
        $call = (self::complete([new UserMessage('::tool Doctor')])->toolCalls ?? [])[0] ?? null;

        $this->assertInstanceOf(ToolCall::class, $call);
        $this->assertSame([], $call->arguments());
        $this->assertNull($call->argumentsError());
    }

    public function testArgumentsThatAreNotAJsonObjectReachTheEngineAsAnUndecodableCall(): void
    {
        foreach (['{broken', '[1, 2]', '"text"'] as $json) {
            $call = (self::complete([new UserMessage('::tool Bash ' . $json)])->toolCalls ?? [])[0] ?? null;

            $this->assertInstanceOf(ToolCall::class, $call, $json);
            $this->assertSame([], $call->arguments(), $json);
            $this->assertNotNull($call->argumentsError(), $json);
            $this->assertStringContainsString($json, (string) $call->argumentsError());
        }
    }

    public function testTheStreamYieldsTheCallsAsOneChunk(): void
    {
        $chunks = iterator_to_array((new EchoProvider())->completeStream(new CompleteRequest(
            model: 'echo',
            messages: [new UserMessage('::tool Bash {"command":"ls"}')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertSame('', $chunks[0]->content);
        $this->assertSame('Bash', ($chunks[0]->toolCalls ?? [])[0]?->name());
    }

    public function testOnceTheResultsAreInTheReplyReportsThemAndCallsNothing(): void
    {
        $messages = [
            new UserMessage('::tool Bash {"command":"ls"}' . "\n" . '::tool Read {"file_path":"x"}'),
            new AssistantMessage('', [
                new ToolCall('call_a', 'Bash', ['command' => 'ls']),
                new ToolCall('call_b', 'Read', ['file_path' => 'x']),
            ]),
            new ToolResultMessage('call_a', "a.txt\nb.txt\n<ctx-ref r=\"3\"/>"),
            new ToolResultMessage('call_b', 'no such file', true),
        ];

        $response = self::complete($messages);
        $this->assertNull($response->toolCalls);
        $this->assertSame(
            "Tool `Bash` returned:\n\n> a.txt\n> b.txt\n\nTool `Read` failed:\n\n> no such file",
            $response->content,
        );

        $streamed = '';
        foreach ((new EchoProvider())->completeStream(new CompleteRequest(model: 'echo', messages: $messages)) as $chunk) {
            $this->assertNull($chunk->toolCalls);
            $streamed .= $chunk->content;
        }
        $this->assertSame($response->content, $streamed);
    }

    public function testAnEmptyResultIsSaidToBeEmpty(): void
    {
        $response = self::complete([
            new UserMessage('::tool Bash {"command":"true"}'),
            new AssistantMessage('', [new ToolCall('c1', 'Bash', ['command' => 'true'])]),
            new ToolResultMessage('c1', ''),
        ]);

        $this->assertSame("Tool `Bash` returned:\n\n> (no output)", $response->content);
    }

    public function testANewScriptedTurnCallsAgainEvenAfterAnEarlierTurnsResults(): void
    {
        $response = self::complete([
            new UserMessage('::tool Bash {"command":"ls"}'),
            new AssistantMessage('', [new ToolCall('c1', 'Bash', ['command' => 'ls'])]),
            new ToolResultMessage('c1', 'a.txt'),
            new AssistantMessage('Tool `Bash` returned: a.txt'),
            new UserMessage('::tool Bash {"command":"pwd"}'),
        ]);

        $this->assertSame(['command' => 'pwd'], ($response->toolCalls ?? [])[0]?->arguments());
    }

    public function testAnOrdinaryPromptStillEchoes(): void
    {
        $response = self::complete([new UserMessage('hello')]);

        $this->assertNull($response->toolCalls);
        $this->assertSame("You said:\n\n> hello", $response->content);
    }

    public function testABangPrefixIsNotTheMarker(): void
    {
        // `!…` is the user's own shell command and never reaches a provider;
        // the marker must not depend on it.
        $response = self::complete([new UserMessage('!tool Bash {"command":"ls"}')]);

        $this->assertNull($response->toolCalls);
        $this->assertStringStartsWith('You said:', $response->content);
    }

    public function testFunctionCallingIsStillNotAdvertised(): void
    {
        // The user names the tool; nothing here reads a request's tool schema.
        $this->assertFalse((new EchoProvider())->supportsFunctionCalling());
    }

    /**
     * @param list<Message> $messages
     */
    private static function complete(array $messages): CompleteResponse
    {
        return (new EchoProvider())->complete(new CompleteRequest(model: 'echo', messages: $messages));
    }
}
