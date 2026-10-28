<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\StreamJsonEmitter;
use SugarCraft\Code\Events\MediaProgress;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\Schema\EventSchemas;
use SugarCraft\Code\Tools\ToolResult;

/**
 * AUT-3 byte-shape and vocabulary pins for the stream-json emitter.
 *
 * The two laws this class exists to keep: EVERY line is a protocol-shaped
 * envelope whose type and required data keys come from the same vocabulary
 * the server's `event` notifications speak (never a third one), and the
 * terminal schema-1 document rides out LAST, after every event line.
 */
final class StreamJsonEmitterTest extends TestCase
{
    /** @var resource */
    private $out;

    protected function setUp(): void
    {
        $this->out = \fopen('php://memory', 'w+b');
        $this->assertNotFalse($this->out);
    }

    protected function tearDown(): void
    {
        \fclose($this->out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        \rewind($this->out);
        $raw = (string) \stream_get_contents($this->out);
        if ($raw === '') {
            return [];
        }

        $this->assertStringEndsWith("\n", $raw, 'every NDJSON line is newline-terminated');
        $rows = [];
        foreach (\explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = \json_decode($line, true);
            $this->assertIsArray($decoded, 'every line is ONE decodable JSON document: ' . $line);
            $rows[] = $decoded;
        }

        return $rows;
    }

    public function testADeltaLineIsTheProtocolEnvelopeEndToEnd(): void
    {
        $emitter = new StreamJsonEmitter($this->out);
        $emitter->onToken('hel');

        $rows = $this->lines();
        $this->assertCount(1, $rows);
        $this->assertSame(
            ['sessionId', 'type', 'ts', 'durable', 'data'],
            \array_keys($rows[0]),
            'the envelope is EventEnvelope::server() verbatim: null sessionId, no seq, durable false',
        );
        $this->assertNull($rows[0]['sessionId']);
        $this->assertSame(SessionEvent::ASSISTANT_DELTA, $rows[0]['type']);
        $this->assertFalse($rows[0]['durable']);
        $this->assertIsInt($rows[0]['ts']);
        $this->assertSame(['text' => 'hel', 'partId' => 'answer', 'offset' => 0], $rows[0]['data']);
    }

    public function testEveryEmittedTypeAndDataSetConformToTheProtocolSchemas(): void
    {
        $emitter = new StreamJsonEmitter($this->out);
        $emitter->onToken('a');
        $emitter->onReasoning('think');
        $emitter->onEvent(new ToolStarted('call-1', 'Bash', ['command' => 'ls']));
        $emitter->onEvent(new ToolFinished('call-1', 'Bash', new ToolResult('call-1', 'out', isError: false)));
        $emitter->onEvent(new ToolProgress('call-1', 'Bash', 'tail', 2, true, 4096));
        $emitter->onEvent(new SpendCapBreached(3, 1.25, 1.0));
        $emitter->onEvent(new SubAgentActivity('started', 'agent-9', 'worker', 'do it', 0, ''));

        $rows = $this->lines();
        $this->assertCount(7, $rows);
        foreach ($rows as $row) {
            $schema = EventSchemas::data((string) $row['type']);
            $this->assertNotNull(
                $schema,
                'the emitter invented a type outside the protocol vocabulary: ' . $row['type'],
            );
            $required = $schema->toArray()['required'] ?? [];
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $row['data'], "data for {$row['type']} must carry required key {$key}");
            }
        }
    }

    public function testOffsetsAccumulateInBytesPerPart(): void
    {
        $emitter = new StreamJsonEmitter($this->out);
        $emitter->onToken('ab');
        $emitter->onToken('🎉');
        $emitter->onReasoning('x');
        $emitter->onToken('');

        $rows = $this->lines();
        $this->assertCount(3, $rows, 'an empty delta writes no line');
        $this->assertSame(0, $rows[0]['data']['offset']);
        $this->assertSame(2, $rows[1]['data']['offset'], 'offset counts BYTES into the part');
        $this->assertSame('answer', $rows[1]['data']['partId']);
        $this->assertSame('reasoning', $rows[2]['data']['partId'], 'reasoning keeps its own part and offset');
        $this->assertSame(0, $rows[2]['data']['offset']);
    }

    public function testTheSpendCapRowSpeaksTheSchemaKeysNotTheEventKeys(): void
    {
        $emitter = new StreamJsonEmitter($this->out);
        $emitter->onEvent(new SpendCapBreached(3, 1.25, 2.5));

        $rows = $this->lines();
        $this->assertSame(SessionEvent::SPEND_CAP_BREACHED, $rows[0]['type']);
        $this->assertSame(['calls' => 3, 'spent' => 1.25, 'cap' => 2.5], $rows[0]['data']);
    }

    public function testSubAgentBeatsRouteToTheirTypeByOp(): void
    {
        $emitter = new StreamJsonEmitter($this->out);
        $emitter->onEvent(new SubAgentActivity('started', 'id-1', 'n', 't', 0, ''));
        $emitter->onEvent(new SubAgentActivity('progress', 'id-1', 'n', 't', 1, 'tail'));
        $emitter->onEvent(new SubAgentActivity('finished', 'id-1', 'n', 't', 2, 'tail'));

        $rows = $this->lines();
        $this->assertSame(
            [SessionEvent::SUBAGENT_STARTED, SessionEvent::SUBAGENT_PROGRESS, SessionEvent::SUBAGENT_FINISHED],
            \array_column($rows, 'type'),
        );
        $this->assertSame('progress', $rows[1]['data']['op'], 'the v1 beat carries its op verbatim');
    }

    public function testEventClassesWithoutAWireTypeWriteNoBytes(): void
    {
        $emitter = new StreamJsonEmitter($this->out);
        $emitter->onEvent(new MediaProgress('call-1', null, 0.5, 3.0));
        $emitter->onEvent(new \stdClass());

        $this->assertSame([], $this->lines(), 'no vocabulary, no line — and never an invented type');
    }

    public function testEmitLineWritesTheTerminalDocumentVerbatimLast(): void
    {
        $emitter = new StreamJsonEmitter($this->out);
        $emitter->onToken('partial answer');
        $document = NonInteractive::encodeDocument(['schema' => NonInteractive::SCHEMA, 'result' => 'partial answer']);
        $emitter->emitLine($document);

        $rows = $this->lines();
        $this->assertCount(2, $rows);
        $this->assertSame(SessionEvent::ASSISTANT_DELTA, $rows[0]['type']);
        $this->assertSame(
            ['schema' => NonInteractive::SCHEMA, 'result' => 'partial answer'],
            $rows[1],
            'the terminal line IS the json-format success document, byte for byte',
        );
    }

    /**
     * C-TOOL-7.d: the emitter inherits the encoder's invisibility post-pass.
     * A provider that answers with a bidi override inside its text must see
     * it arrive on EVERY line as an escape — raw, those bytes let the payload
     * spoof what the consumer's terminal shows — while decoding stays exact.
     */
    public function testABidiOverridePayloadArrivesEscapedOnEveryLine(): void
    {
        $payload = "flip\u{202E}flop";
        $emitter = new StreamJsonEmitter($this->out);
        $emitter->onToken($payload);
        $emitter->emitLine(NonInteractive::encodeDocument(['schema' => NonInteractive::SCHEMA, 'result' => $payload]));

        \rewind($this->out);
        $raw = (string) \stream_get_contents($this->out);
        $this->assertStringNotContainsString("\u{202E}", $raw, 'a raw U+202E reached the stream-json lines');
        $this->assertStringContainsString('\u202e', $raw, 'the override arrives in the escaped spelling');

        $rows = $this->lines();
        $this->assertCount(2, $rows);
        $this->assertSame($payload, $rows[0]['data']['text'], 'the delta row decodes back to the exact payload');
        $this->assertSame($payload, $rows[1]['result'], 'the terminal row decodes back to the exact payload');
    }
}
