<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tools\BuiltIn\Doctor;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Regression guard for a defect that made the agent unable to send ANY
 * message to an SGLang-backed model.
 *
 * PHP cannot tell an empty map from an empty list — both are `[]` — so a
 * parameter-less tool declaring `'properties' => []` encodes as the JSON
 * array `"properties": []` where JSON Schema requires the object `{}`.
 * SGLang validates tool schemas and rejects the ENTIRE `chat/completions`
 * request over one bad tool, with:
 *
 *     Tool 6 function has invalid 'parameters' schema: [] is not of type 'object'
 *
 * The `doctor` built-in (added in W1.G2) takes no parameters, so every
 * request carrying the built-in tool set 400'd.
 */
final class ToolSchemaEncodingTest extends TestCase
{
    /** The declaration site must be correct on its own. */
    public function testDoctorDeclaresPropertiesAsAnObjectNotAnArray(): void
    {
        $schema = (new Doctor())->inputSchema();

        $this->assertIsObject($schema['properties']);
        $this->assertSame('{}', json_encode($schema['properties']));
    }

    /**
     * And the wire boundary must correct it regardless, so a tool written
     * later with the natural `'properties' => []` cannot break every request.
     */
    public function testProviderEncodesEmptyPropertiesAsAJsonObject(): void
    {
        $params = $this->buildParams([new EmptyPropsToolStub()]);
        $json = json_encode($params);

        $this->assertStringContainsString('"properties":{}', (string) $json);
        $this->assertStringNotContainsString('"properties":[]', (string) $json);
    }

    /** A tool that DOES take parameters must be untouched. */
    public function testPopulatedPropertiesAreLeftAlone(): void
    {
        $params = $this->buildParams([new Doctor(), new RealPropsToolStub()]);
        $decoded = json_decode((string) json_encode($params), true);

        $byName = [];
        foreach ($decoded['tools'] as $t) {
            $byName[$t['function']['name']] = $t['function']['parameters'];
        }

        $this->assertSame([], $byName['doctor']['properties'], 'doctor round-trips as an empty OBJECT, decoded to an empty array');
        $this->assertArrayHasKey('path', $byName['real']['properties']);
        $this->assertSame('string', $byName['real']['properties']['path']['type']);
    }

    /** `required` is genuinely a JSON array — it must NOT be objectified. */
    public function testRequiredStaysAnArray(): void
    {
        $json = (string) json_encode($this->buildParams([new EmptyPropsToolStub()]));

        $this->assertStringContainsString('"required":[]', $json);
    }


    /**
     * Regression guard for the SECOND 400: any turn that actually CALLED a
     * tool died on the follow-up request reporting the results back.
     *
     * Tools\ToolCall keeps its state in PRIVATE properties behind bare
     * accessors, so json_encode() -- which serializes only public ones --
     * rendered each call as `{}`. SGLang answered one
     * "'msg': 'Field required'" per call for the missing `function` key.
     */
    public function testAssistantToolCallsSerializeToTheOpenAiWireShape(): void
    {
        $params = $this->buildParamsForMessages([
            new \SugarCraft\Crush\Messages\AssistantMessage('', [
                new \SugarCraft\Crush\Tools\ToolCall('call_1', 'Bash', ['command' => 'uname -a']),
            ]),
        ]);

        $json = (string) json_encode($params);
        $this->assertStringNotContainsString('"tool_calls":[{}]', $json, 'tool calls must not encode as empty objects');

        $call = json_decode($json, true)['messages'][0]['tool_calls'][0];
        $this->assertSame('call_1', $call['id']);
        $this->assertSame('function', $call['type']);
        $this->assertSame('Bash', $call['function']['name']);
        $this->assertIsString($call['function']['arguments'], 'OpenAI requires arguments to be a JSON STRING');
        $this->assertSame(['command' => 'uname -a'], json_decode($call['function']['arguments'], true));
    }

    /** An argument-less call must still send `{}`, not `[]`. */
    public function testArgumentLessToolCallEncodesArgumentsAsAnObject(): void
    {
        $params = $this->buildParamsForMessages([
            new \SugarCraft\Crush\Messages\AssistantMessage('', [
                new \SugarCraft\Crush\Tools\ToolCall('call_2', 'doctor', []),
            ]),
        ]);

        $call = json_decode((string) json_encode($params), true)['messages'][0]['tool_calls'][0];
        $this->assertSame('{}', $call['function']['arguments']);
    }

    /** @param list<mixed> $messages */
    private function buildParamsForMessages(array $messages): array
    {
        $provider = SglangProvider::openAiCompatible('https://example.invalid/v1', 'test-model');
        $method = new \ReflectionMethod(SglangProvider::class, 'buildParams');
        $method->setAccessible(true);

        return $method->invoke($provider, new CompleteRequest(
            model: 'test-model',
            messages: $messages,
        ));
    }

    /** @param list<Tool> $tools */
    private function buildParams(array $tools): array
    {
        $provider = SglangProvider::openAiCompatible('https://example.invalid/v1', 'test-model');
        $method = new \ReflectionMethod(SglangProvider::class, 'buildParams');
        $method->setAccessible(true);

        return $method->invoke($provider, new CompleteRequest(
            model: 'test-model',
            messages: [],
            tools: $tools,
        ));
    }
}

/** Declares no parameters the natural PHP way — the shape that broke it. */
final class EmptyPropsToolStub implements Tool
{
    public function name(): string { return 'empty'; }
    public function description(): string { return 'takes nothing'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
    public function execute(array $args): ToolResult
    {
        return ToolResult::ok('ok');
    }
}

/** Declares real parameters, to prove normalization is narrowly scoped. */
final class RealPropsToolStub implements Tool
{
    public function name(): string { return 'real'; }
    public function description(): string { return 'takes a path'; }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'a path']],
            'required' => ['path'],
        ];
    }
    public function execute(array $args): ToolResult
    {
        return ToolResult::ok('ok');
    }
}
