<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\StructuredOutputTool;

/** P0-2: the per-run answer channel's validation and encoding, without an engine. */
final class StructuredOutputToolTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['ok' => ['type' => 'boolean'], 'items' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/item']]],
        'required' => ['ok'],
        '$defs' => ['item' => ['type' => 'string']],
    ];

    public function testAnObjectSchemaIsTheInputSchemaVerbatim(): void
    {
        $tool = new StructuredOutputTool(self::SCHEMA);

        self::assertSame(StructuredOutputTool::NAME, $tool->name());
        self::assertSame(self::SCHEMA, $tool->inputSchema());
        self::assertStringNotContainsString(StructuredOutputTool::WRAP_KEY, $tool->description());
    }

    public function testAMismatchIsAnErrorResultAndRecordsNothing(): void
    {
        $tool = new StructuredOutputTool(self::SCHEMA);

        $result = $tool->execute(['items' => ['a', 3]]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('$.ok: is required', $result->content());
        self::assertStringContainsString('$.items[1]: must be string', $result->content(), '$ref resolves into the schema\'s own $defs');
        self::assertFalse($tool->accepted());
        self::assertNull($tool->answer());
    }

    public function testAMatchIsAcceptedAsItsJsonDocument(): void
    {
        $tool = new StructuredOutputTool(self::SCHEMA);

        $result = $tool->execute(['ok' => true, 'items' => ['x/y']]);

        self::assertFalse($result->isError());
        self::assertTrue($tool->accepted());
        self::assertSame('{"ok":true,"items":["x/y"]}', $tool->answer());
    }

    public function testAnEmptyObjectAnswerIsWrittenAsAnObject(): void
    {
        $tool = new StructuredOutputTool(['type' => 'object']);

        $tool->execute([]);

        self::assertSame('{}', $tool->answer());
    }

    public function testANonObjectSchemaIsWrappedAndUnwrapped(): void
    {
        $tool = new StructuredOutputTool(['type' => 'integer']);

        self::assertSame(['type' => 'object', 'properties' => ['value' => ['type' => 'integer']], 'required' => ['value'], 'additionalProperties' => false], $tool->inputSchema());
        self::assertStringContainsString('"value"', $tool->description());
        self::assertTrue($tool->execute(['value' => 'seven'])->isError());
        self::assertFalse($tool->execute(['value' => 7])->isError());
        self::assertSame('7', $tool->answer());
    }

    public function testOnlyAReplyThatIsExactlyAValidDocumentCountsAsAnAnswer(): void
    {
        $tool = new StructuredOutputTool(self::SCHEMA);

        self::assertSame('{"ok":false}', $tool->answerFromText(' {"ok": false} '));
        self::assertSame('{"ok":true}', $tool->answerFromText("```json\n{\"ok\":true}\n```"));
        self::assertNull($tool->answerFromText('Here you go: {"ok": true}'), 'prose around the document is prose');
        self::assertNull($tool->answerFromText('{"items": []}'), 'a document that misses the schema is not an answer');
        self::assertNull($tool->answerFromText(''));
        self::assertFalse($tool->accepted(), 'reading text never records an accepted call');
    }

    public function testTheErrorsAccessorReportsAgainstTheInputSchema(): void
    {
        self::assertSame([], (new StructuredOutputTool(self::SCHEMA))->errors(['ok' => true]));
        self::assertSame(['$.value: is required'], (new StructuredOutputTool(['type' => 'string']))->errors([]));
    }
}
