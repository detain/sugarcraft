<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\ArgumentShape;

/**
 * Audit MCP-9: every row is judged by the JSON the shaped arguments encode
 * to, because the wire bytes are the whole point — `[]` versus `{}`.
 */
final class ArgumentShapeTest extends TestCase
{
    /**
     * @param array<array-key,mixed> $args
     * @param array<array-key,mixed> $schema
     */
    private static function wire(array $args, array $schema): string
    {
        return (string) json_encode(ArgumentShape::conform($args, $schema));
    }

    public function testANestedEmptyObjectBecomesAnObject(): void
    {
        $schema = ['type' => 'object', 'properties' => ['filter' => ['type' => 'object']]];

        self::assertSame('{"filter":{}}', self::wire(['filter' => []], $schema));
    }

    public function testASchemaDeclaredEmptyArrayStaysAnArray(): void
    {
        $schema = ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string']]]];

        self::assertSame('{"tags":[]}', self::wire(['tags' => []], $schema));
    }

    public function testObjectsInsideArrayItemsAreShapedPerElement(): void
    {
        $schema = ['type' => 'object', 'properties' => ['rules' => [
            'type' => 'array',
            'items' => ['type' => 'object', 'properties' => ['match' => ['type' => 'object'], 'ids' => ['type' => 'array']]],
        ]]];

        self::assertSame(
            '{"rules":[{"match":{},"ids":[]},{}]}',
            self::wire(['rules' => [['match' => [], 'ids' => []], []]], $schema),
        );
    }

    public function testTupleItemsAndPrefixItemsAreShapedByPosition(): void
    {
        $prefix = ['type' => 'object', 'properties' => ['pair' => [
            'type' => 'array',
            'prefixItems' => [['type' => 'array'], ['type' => 'object']],
        ]]];
        $draft4 = ['type' => 'object', 'properties' => ['pair' => [
            'type' => 'array',
            'items' => [['type' => 'array'], ['type' => 'object']],
            'additionalItems' => ['type' => 'object'],
        ]]];

        self::assertSame('{"pair":[[],{}]}', self::wire(['pair' => [[], []]], $prefix));
        self::assertSame('{"pair":[[],{},{}]}', self::wire(['pair' => [[], [], []]], $draft4));
    }

    public function testAdditionalPropertiesMapsAreShapedForEveryKey(): void
    {
        $schema = ['type' => 'object', 'properties' => ['env' => [
            'type' => 'object',
            'additionalProperties' => ['type' => 'object'],
        ]]];

        self::assertSame('{"env":{}}', self::wire(['env' => []], $schema));
        self::assertSame('{"env":{"a":{},"b":{"x":1}}}', self::wire(['env' => ['a' => [], 'b' => ['x' => 1]]], $schema));
    }

    public function testPatternPropertiesAreConsulted(): void
    {
        $schema = ['type' => 'object', 'patternProperties' => ['^opt_' => ['type' => 'object']]];

        self::assertSame('{"opt_a":{},"tags":[]}', self::wire(['opt_a' => [], 'tags' => []], $schema));
    }

    public function testWithoutAnySchemaEmptyArraysAreLeftAlone(): void
    {
        self::assertSame('{"filter":[],"deep":{"x":[]}}', self::wire(['filter' => [], 'deep' => ['x' => []]], []));
        self::assertSame(
            '{"anything":[]}',
            self::wire(['anything' => []], ['type' => 'object', 'properties' => ['anything' => new \stdClass()]]),
        );
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function unionRows(): iterable
    {
        yield 'type list with null' => [['type' => ['object', 'null']], '{"v":{}}'];
        yield 'type list object and array is ambiguous' => [['type' => ['object', 'array']], '{"v":[]}'];
        yield 'anyOf object or null' => [['anyOf' => [['type' => 'object'], ['type' => 'null']]], '{"v":{}}'];
        yield 'oneOf object or array is ambiguous' => [['oneOf' => [['type' => 'object'], ['type' => 'array']]], '{"v":[]}'];
        yield 'anyOf with an untyped branch says nothing' => [['anyOf' => [['type' => 'object'], ['description' => 'x']]], '{"v":[]}'];
        yield 'allOf object' => [['allOf' => [['type' => 'object'], ['description' => 'x']]], '{"v":{}}'];
        yield 'untyped with properties' => [['properties' => ['a' => ['type' => 'string']]], '{"v":{}}'];
        yield 'untyped with items' => [['items' => ['type' => 'string']], '{"v":[]}'];
        yield 'string type keeps the decoded value' => [['type' => 'string'], '{"v":[]}'];
        yield 'boolean true schema' => [true, '{"v":[]}'];
    }

    #[DataProvider('unionRows')]
    public function testUnionAndUntypedSchemas(mixed $propertySchema, string $expected): void
    {
        self::assertSame($expected, self::wire(['v' => []], ['type' => 'object', 'properties' => ['v' => $propertySchema]]));
    }

    public function testLocalRefsAndThePydanticOptionalModelShapeResolve(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'filter' => ['anyOf' => [['$ref' => '#/$defs/Filter'], ['type' => 'null']], 'default' => null],
                'legacy' => ['$ref' => '#/definitions/Legacy'],
            ],
            '$defs' => ['Filter' => ['type' => 'object', 'properties' => ['where' => ['type' => 'object']]]],
            'definitions' => ['Legacy' => ['type' => 'object']],
        ];

        self::assertSame('{"filter":{},"legacy":{}}', self::wire(['filter' => [], 'legacy' => []], $schema));
        self::assertSame('{"filter":{"where":{}}}', self::wire(['filter' => ['where' => []]], $schema));
    }

    public function testACyclicSchemaTerminates(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['node' => ['$ref' => '#/$defs/Node']],
            '$defs' => ['Node' => [
                'anyOf' => [['$ref' => '#/$defs/Node'], ['$ref' => '#/$defs/Node'], ['$ref' => '#']],
            ]],
        ];

        self::assertSame('{"node":[]}', self::wire(['node' => []], $schema));
    }

    public function testNonEmptyValuesAndScalarsAreUnchanged(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'filter' => ['type' => 'object'],
            'n' => ['type' => 'object'],
        ]];
        $args = ['filter' => ['status' => 'open', 'ids' => [1, 2]], 'n' => 3, 'free' => ['x', null, true]];

        self::assertSame($args, ArgumentShape::conform($args, $schema));
    }

    public function testACallerBuiltObjectIsPreservedAsIs(): void
    {
        $object = new \stdClass();
        $object->inner = [];
        $schema = ['type' => 'object', 'properties' => ['filter' => [
            'type' => 'object',
            'properties' => ['inner' => ['type' => 'object']],
        ]]];

        $shaped = ArgumentShape::conform(['filter' => $object], $schema);

        self::assertSame($object, $shaped['filter']);
        self::assertSame('{"filter":{"inner":[]}}', (string) json_encode($shaped));
    }

    public function testANonEmptyListUnderAnObjectSchemaIsNotReinterpreted(): void
    {
        // ["a"] and {"0":"a"} decode alike; turning the model's list into an
        // object would make an invalid call silently validate.
        $schema = ['type' => 'object', 'properties' => ['filter' => ['type' => 'object']]];

        self::assertSame('{"filter":["a"]}', self::wire(['filter' => ['a']], $schema));
    }

    public function testEmptyArgumentsStayAnEmptyArray(): void
    {
        self::assertSame([], ArgumentShape::conform([], ['type' => 'object']));
    }
}
