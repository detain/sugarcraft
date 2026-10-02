<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

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

    // =========================================================================
    // argumentsError (audit A11)
    // =========================================================================

    public function testAWellFormedCallCarriesNoArgumentsError(): void
    {
        $this->assertNull((new ToolCall('c', 'Read', ['path' => 'a']))->argumentsError());
        $this->assertNull(ToolCall::fromArray(['id' => 'c', 'name' => 'Read'])->argumentsError());
    }

    public function testWithArgumentsErrorReturnsANewInstanceAndLeavesTheOriginal(): void
    {
        $original = new ToolCall('c', 'Read', []);
        $marked = $original->withArgumentsError('arguments were not valid JSON (Syntax error): {');

        $this->assertNotSame($original, $marked);
        $this->assertNull($original->argumentsError());
        $this->assertSame('arguments were not valid JSON (Syntax error): {', $marked->argumentsError());
        $this->assertSame('c', $marked->id());
        $this->assertSame('Read', $marked->name());
        $this->assertNull($marked->withArgumentsError(null)->argumentsError());
    }

    public function testToArrayFromArrayRoundTripKeepsTheArgumentsError(): void
    {
        $marked = (new ToolCall('c', 'Read', []))->withArgumentsError('arguments were not valid JSON (Syntax error): {"path": "a');

        $array = $marked->toArray();
        $this->assertSame('arguments were not valid JSON (Syntax error): {"path": "a', $array['argumentsError']);

        $restored = ToolCall::fromArray($array);
        $this->assertSame($marked->argumentsError(), $restored->argumentsError());
        $this->assertSame($array, $restored->toArray());
    }

    public function testFromArrayIgnoresANonStringArgumentsError(): void
    {
        $this->assertNull(ToolCall::fromArray(['id' => 'c', 'name' => 'Read', 'argumentsError' => ['x']])->argumentsError());
    }

    /**
     * serialize() is the transcript format of
     * {@see \SugarCraft\Crush\Agents\SuspendedDelegations}, the one place a
     * ToolCall object crosses a process boundary whole.
     */
    public function testSerializeRoundTripKeepsTheArgumentsError(): void
    {
        $marked = (new ToolCall('c', 'Read', []))->withArgumentsError('arguments decoded to int, not a JSON object: 12');

        $restored = unserialize(serialize($marked), ['allowed_classes' => [ToolCall::class]]);

        $this->assertInstanceOf(ToolCall::class, $restored);
        $this->assertSame('arguments decoded to int, not a JSON object: 12', $restored->argumentsError());
    }

    public function testACallSerializedBeforeTheFieldExistedReadsAsNoError(): void
    {
        // The pre-A11 three-property shape, as a suspended transcript on disk holds it.
        $legacy = sprintf(
            'O:%d:"%s":3:{s:%d:"%s";s:1:"c";s:%d:"%s";s:4:"Read";s:%d:"%s";a:0:{}}',
            strlen(ToolCall::class),
            ToolCall::class,
            strlen("\0" . ToolCall::class . "\0id"),
            "\0" . ToolCall::class . "\0id",
            strlen("\0" . ToolCall::class . "\0name"),
            "\0" . ToolCall::class . "\0name",
            strlen("\0" . ToolCall::class . "\0arguments"),
            "\0" . ToolCall::class . "\0arguments",
        );

        $restored = unserialize($legacy, ['allowed_classes' => [ToolCall::class]]);

        $this->assertInstanceOf(ToolCall::class, $restored);
        $this->assertSame('Read', $restored->name());
        $this->assertNull($restored->argumentsError());
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function rawArgumentPayloads(): array
    {
        return [
            'decoded array' => [['path' => 'a'], null],
            'absent' => [null, null],
            'blank string' => ['  ', null],
            'json object' => ['{"path":"a"}', null],
            'empty object' => ['{}', null],
            'json null' => ['null', null],
            'truncated object' => ['{"path": "a', 'arguments were not valid JSON (Control character error, possibly incorrectly encoded): {"path": "a'],
            'closed but invalid' => ['{"path": }', 'arguments were not valid JSON (Syntax error): {"path": }'],
            'json scalar' => ['12', 'arguments decoded to int, not a JSON object: 12'],
            'json string' => ['"text"', 'arguments decoded to string, not a JSON object: "text"'],
            'non-string wire value' => [7, 'arguments were int, not a JSON object'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rawArgumentPayloads')]
    public function testArgumentsErrorForClassifiesTheWirePayload(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, ToolCall::argumentsErrorFor($raw));
    }

    public function testArgumentsErrorForBoundsTheQuotedExcerptWithoutSplittingUtf8(): void
    {
        $raw = '{"content": "' . str_repeat('é', 400);

        $error = (string) ToolCall::argumentsErrorFor($raw);

        $this->assertStringEndsWith(' [...]', $error);
        $this->assertLessThan(300, strlen($error));
        $this->assertTrue(mb_check_encoding($error, 'UTF-8'));
    }
}
