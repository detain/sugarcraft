<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Json;

final class JsonTest extends TestCase
{
    public function testDecodesJsonListToArray(): void
    {
        $this->assertSame([1, 2, 3], Json::decodeArray('[1, 2, 3]'));
    }

    public function testDecodesJsonObjectToAssocArray(): void
    {
        $this->assertSame(['a' => 1, 'b' => 'two'], Json::decodeArray('{"a": 1, "b": "two"}'));
    }

    public function testDecodesEmptyArray(): void
    {
        $this->assertSame([], Json::decodeArray('[]'));
        $this->assertSame([], Json::decodeArray('{}'));
    }

    public function testPreservesNestedArrays(): void
    {
        $json = '{"outer": {"inner": [1, {"deep": true}]}, "list": [[1], [2, 3]]}';

        $this->assertSame(
            ['outer' => ['inner' => [1, ['deep' => true]]], 'list' => [[1], [2, 3]]],
            Json::decodeArray($json),
        );
    }

    /**
     * @dataProvider nonArrayTopLevelProvider
     */
    public function testNonArrayTopLevelThrowsRuntimeException(string $json, string $expectedType): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedType);

        Json::decodeArray($json);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonArrayTopLevelProvider(): array
    {
        return [
            'int'    => ['5', 'int'],
            'float'  => ['3.14', 'float'],
            'string' => ['"hello"', 'string'],
            'true'   => ['true', 'bool'],
            'false'  => ['false', 'bool'],
            'null'   => ['null', 'null'],
        ];
    }

    public function testMalformedJsonThrowsJsonException(): void
    {
        $this->expectException(\JsonException::class);

        Json::decodeArray('{not valid json');
    }

    public function testTruncatedJsonThrowsJsonException(): void
    {
        $this->expectException(\JsonException::class);

        Json::decodeArray('[1, 2,');
    }
}
