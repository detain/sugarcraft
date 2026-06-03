<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * @see ToolCall
 */
final class ToolCallTest extends TestCase
{
    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function testCanBeCreatedWithIdNameAndArguments(): void
    {
        $id = 'call_123';
        $name = 'Read';
        $arguments = ['file_path' => '/tmp/test.txt'];

        $toolCall = new ToolCall($id, $name, $arguments);

        $this->assertSame($id, $toolCall->id());
        $this->assertSame($name, $toolCall->name());
        $this->assertSame($arguments, $toolCall->arguments());
    }

    public function testCanBeCreatedWithEmptyArguments(): void
    {
        $toolCall = new ToolCall('call_1', 'Bash', []);

        $this->assertSame('call_1', $toolCall->id());
        $this->assertSame('Bash', $toolCall->name());
        $this->assertSame([], $toolCall->arguments());
    }

    public function testCanBeCreatedWithComplexArguments(): void
    {
        $arguments = [
            'file_path' => '/path/to/file',
            'options' => ['verbose' => true, 'count' => 10],
            'nested' => ['a' => ['b' => 'c']],
        ];

        $toolCall = new ToolCall('call_complex', 'Edit', $arguments);

        $this->assertSame($arguments, $toolCall->arguments());
    }

    // =========================================================================
    // fromArray Tests
    // =========================================================================

    public function testFromArrayParsesCompleteData(): void
    {
        $data = [
            'id' => 'call_456',
            'name' => 'Grep',
            'arguments' => ['pattern' => 'test', 'path' => '/src'],
        ];

        $toolCall = ToolCall::fromArray($data);

        $this->assertSame('call_456', $toolCall->id());
        $this->assertSame('Grep', $toolCall->name());
        $this->assertSame(['pattern' => 'test', 'path' => '/src'], $toolCall->arguments());
    }

    public function testFromArrayWithMissingIdDefaultsToEmptyString(): void
    {
        $data = [
            'name' => 'Glob',
            'arguments' => ['pattern' => '*.php'],
        ];

        $toolCall = ToolCall::fromArray($data);

        $this->assertSame('', $toolCall->id());
        $this->assertSame('Glob', $toolCall->name());
    }

    public function testFromArrayWithMissingNameDefaultsToEmptyString(): void
    {
        $data = [
            'id' => 'call_789',
            'arguments' => ['url' => 'https://example.com'],
        ];

        $toolCall = ToolCall::fromArray($data);

        $this->assertSame('call_789', $toolCall->id());
        $this->assertSame('', $toolCall->name());
    }

    public function testFromArrayWithMissingArgumentsDefaultsToEmptyArray(): void
    {
        $data = [
            'id' => 'call_empty',
            'name' => 'Bash',
        ];

        $toolCall = ToolCall::fromArray($data);

        $this->assertSame([], $toolCall->arguments());
    }

    public function testFromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $toolCall = ToolCall::fromArray([]);

        $this->assertSame('', $toolCall->id());
        $this->assertSame('', $toolCall->name());
        $this->assertSame([], $toolCall->arguments());
    }

    // =========================================================================
    // toArray Tests
    // =========================================================================

    public function testToArrayReturnsCorrectStructure(): void
    {
        $id = 'call_test';
        $name = 'Read';
        $arguments = ['file_path' => '/etc/hosts'];

        $toolCall = new ToolCall($id, $name, $arguments);
        $array = $toolCall->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('arguments', $array);
        $this->assertSame($id, $array['id']);
        $this->assertSame($name, $array['name']);
        $this->assertSame($arguments, $array['arguments']);
    }

    public function testToArrayReturnsExactlyThreeKeys(): void
    {
        $toolCall = new ToolCall('call_1', 'Bash', ['command' => 'ls']);
        $array = $toolCall->toArray();

        $this->assertCount(3, $array);
    }

    public function testToArrayWithEmptyArguments(): void
    {
        $toolCall = new ToolCall('call_empty', 'Test', []);
        $array = $toolCall->toArray();

        $this->assertSame([], $array['arguments']);
    }

    public function testToArrayRoundTripsWithFromArray(): void
    {
        $original = [
            'id' => 'call_roundtrip',
            'name' => 'Edit',
            'arguments' => ['file_path' => '/tmp/x', 'old_string' => 'a', 'new_string' => 'b'],
        ];

        $toolCall = ToolCall::fromArray($original);
        $result = $toolCall->toArray();

        $this->assertSame($original, $result);
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testImmutability(): void
    {
        $a = new ToolCall('call_1', 'Read', ['file_path' => '/a']);
        $b = new ToolCall('call_2', 'Bash', ['command' => 'ls']);

        $this->assertNotSame($a, $b);
        $this->assertSame('call_1', $a->id());
        $this->assertSame('call_2', $b->id());
    }

    public function testArgumentsArrayIsNotModifiedByCaller(): void
    {
        $originalArgs = ['file_path' => '/test'];
        $toolCall = new ToolCall('call_1', 'Read', $originalArgs);

        $originalArgs['file_path'] = '/modified';
        $this->assertSame('/test', $toolCall->arguments()['file_path']);
    }
}
