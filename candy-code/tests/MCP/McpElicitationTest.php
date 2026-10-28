<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpElicitation;

/**
 * C-MCP-3: the elicitation/create mapping core — schema in, questions
 * out; answers in, validated protocol result out.
 *
 * Everything here is pure (no pipe, no UI): the approver is the injected
 * seam a real UI rides in C-MCP-3.b, and the law this class exists to
 * enforce is that no answer reaches the wire unless it conforms to the
 * schema the server itself declared — malformed schemas and violating
 * answers are REFUSED (the caller maps the throw to a JSON-RPC error),
 * never patched into shape.
 */
final class McpElicitationTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function schema(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    public function testQuestionsMapsEveryFlatPrimitiveFieldInDeclarationOrder(): void
    {
        $questions = McpElicitation::questions(self::schema(
            [
                'name' => ['type' => 'string', 'description' => 'Who are you?'],
                'mode' => ['type' => 'string', 'enum' => ['fast', 'slow'], 'title' => 'Mode'],
                'count' => ['type' => 'integer'],
                'ratio' => ['type' => 'number'],
                'agree' => ['type' => 'boolean', 'description' => 'Proceed?'],
            ],
            ['name', 'agree'],
        ));

        self::assertSame(['name', 'mode', 'count', 'ratio', 'agree'], array_column($questions, 'key'));
        self::assertSame('Who are you?', $questions[0]['message']);
        self::assertSame('Mode', $questions[1]['message'], 'title is the fallback voice before the key');
        self::assertSame('count', $questions[2]['message'], 'a bare field falls back to its key');
        self::assertSame(['fast', 'slow'], $questions[1]['enum'], 'a string enum becomes a choice question');
        self::assertNull($questions[0]['enum'], 'a plain string stays free input');
        self::assertSame('integer', $questions[2]['type']);
        self::assertSame(['name', 'agree'], [
            $questions[0]['key'],
            $questions[4]['key'],
        ]);
        self::assertTrue($questions[0]['required']);
        self::assertFalse($questions[1]['required']);
        self::assertTrue($questions[4]['required']);
    }

    public function testNonObjectAndUnpropertyedSchemasAreRefused(): void
    {
        foreach ([
            'not-an-array' => 'nonsense',
            'wrong-type' => ['type' => 'array', 'properties' => ['a' => ['type' => 'string']]],
            'no-properties' => ['type' => 'object'],
            'empty-properties' => ['type' => 'object', 'properties' => []],
            'list-shaped-properties' => ['type' => 'object', 'properties' => [['type' => 'string']]],
        ] as $why => $bad) {
            try {
                McpElicitation::questions($bad);
                self::fail('schema refused to be refused: ' . $why);
            } catch (\InvalidArgumentException $refused) {
                self::assertNotSame('', $refused->getMessage());
            }
        }
    }

    public function testUnsupportedFieldShapesAreRefusedNotGuessed(): void
    {
        foreach ([
            'nested-object' => ['type' => 'object', 'properties' => ['inner' => ['type' => 'object', 'properties' => []]]],
            'array-field' => self::schema(['xs' => ['type' => 'array']]),
            'untyped-field' => self::schema(['ghost' => []]),
            'enum-on-integer' => self::schema(['n' => ['type' => 'integer', 'enum' => [1, 2]]]),
            'empty-enum' => self::schema(['s' => ['type' => 'string', 'enum' => []]]),
            'non-string-enum-members' => self::schema(['s' => ['type' => 'string', 'enum' => ['a', 2]]]),
            'non-array-required' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => 'a'],
        ] as $why => $bad) {
            try {
                McpElicitation::questions($bad);
                self::fail('field shape slipped through: ' . $why);
            } catch (\InvalidArgumentException $refused) {
                self::assertNotSame('', $refused->getMessage(), 'the refusal carries a reason (' . $why . ')');
            }
        }
    }

    public function testNobodyToAskIsACancelNeverAGuess(): void
    {
        $questions = McpElicitation::questions(self::schema(['a' => ['type' => 'string']], ['a']));

        self::assertSame(['action' => 'cancel'], McpElicitation::result($questions, null));
    }

    public function testSentinelsRoundTripToDeclineAndCancel(): void
    {
        $questions = McpElicitation::questions(self::schema(['a' => ['type' => 'string']]));

        self::assertSame(
            ['action' => 'decline'],
            McpElicitation::result($questions, static fn (array $q): string => McpElicitation::DECLINE),
        );
        self::assertSame(
            ['action' => 'cancel'],
            McpElicitation::result($questions, static fn (array $q): string => McpElicitation::CANCEL),
        );
        self::assertSame(
            ['action' => 'cancel'],
            McpElicitation::result($questions, static fn (array $q): null => null),
            'a null from the approver means walked away, same as the sentinel',
        );
    }

    public function testAcceptCarriesOnlyValidatedDeclaredAnswers(): void
    {
        $questions = McpElicitation::questions(self::schema(
            [
                'city' => ['type' => 'string'],
                'count' => ['type' => 'integer'],
                'ratio' => ['type' => 'number'],
                'agree' => ['type' => 'boolean'],
                'mode' => ['type' => 'string', 'enum' => ['a', 'b']],
            ],
        ));

        $answer = McpElicitation::result($questions, static fn (array $q): array => [
            'city' => 'Lima',
            'count' => 3,
            'ratio' => 1.5,
            'agree' => false,
            'mode' => 'b',
        ]);

        self::assertSame([
            'action' => 'accept',
            'content' => ['city' => 'Lima', 'count' => 3, 'ratio' => 1.5, 'agree' => false, 'mode' => 'b'],
        ], $answer, 'an integer may answer number; false is a real boolean answer');
    }

    public function testIntegerFieldRejectsFloatAndStringLookalikes(): void
    {
        $questions = McpElicitation::questions(self::schema(['n' => ['type' => 'integer']], ['n']));

        foreach ([3.5, 'twelve', '3'] as $bad) {
            try {
                McpElicitation::result($questions, static fn (array $q): array => ['n' => $bad]);
                self::fail('an integer field accepted ' . get_debug_type($bad));
            } catch (\InvalidArgumentException $refused) {
                self::assertStringContainsString('integer', $refused->getMessage());
            }
        }
    }

    public function testMissingRequiredAnswerIsRefused(): void
    {
        $questions = McpElicitation::questions(self::schema(
            ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
            ['a', 'b'],
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required field "b"');

        McpElicitation::result($questions, static fn (array $q): array => ['a' => 'only']);
    }

    public function testOptionalFieldsMayStayUnansweredAndContentOmitsThem(): void
    {
        $questions = McpElicitation::questions(self::schema(
            ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
            ['a'],
        ));

        self::assertSame(
            ['action' => 'accept', 'content' => ['a' => 'x']],
            McpElicitation::result($questions, static fn (array $q): array => ['a' => 'x']),
            'an omitted optional is omitted from content, never invented',
        );
    }

    public function testUnknownInventedFieldIsRefusedWholesale(): void
    {
        $questions = McpElicitation::questions(self::schema(['a' => ['type' => 'string']]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown field');

        McpElicitation::result($questions, static fn (array $q): array => ['a' => 'x', 'smuggled' => 'y']);
    }

    public function testEnumAnswerOutsideItsOptionsIsRefused(): void
    {
        $questions = McpElicitation::questions(self::schema(['m' => ['type' => 'string', 'enum' => ['x', 'y']]]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside its enum');

        McpElicitation::result($questions, static fn (array $q): array => ['m' => 'z']);
    }

    public function testNonArrayApproverReturnIsRefused(): void
    {
        $questions = McpElicitation::questions(self::schema(['a' => ['type' => 'string']]));

        $this->expectException(\InvalidArgumentException::class);

        McpElicitation::result($questions, static fn (array $q): int => 7);
    }

    public function testRespondRunsTheWholeRoundTripFromWireParams(): void
    {
        $params = [
            'message' => 'Name yourself',
            'requestedSchema' => self::schema(['name' => ['type' => 'string']], ['name']),
        ];

        self::assertSame(
            ['action' => 'accept', 'content' => ['name' => 'Ada']],
            McpElicitation::respond($params, static fn (array $q): array => ['name' => 'Ada']),
        );

        self::assertSame(['action' => 'cancel'], McpElicitation::respond($params, null));
    }

    public function testRespondOnAMissingSchemaThrowsForTheCallersInvalidParamsLeg(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        McpElicitation::respond(['message' => 'nothing to answer'], static fn (array $q): array => []);
    }
}
