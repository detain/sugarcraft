<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\EventType;
use SugarCraft\Crush\Protocol\Schema\Definitions;
use SugarCraft\Crush\Protocol\Schema\EventSchemas;
use SugarCraft\Crush\Protocol\Schema\MethodSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Protocol\Schema\Schema;
use SugarCraft\Crush\Protocol\Schema\SchemaValidator;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap O-3c: `docs/protocol/sugarcrush.v1.schema.json` and the tables of
 * `docs/SERVER.md` are what `scripts/gen-protocol-schema.php` makes of the
 * code — and the schema tells the truth about the wire: every method and
 * event has one, every `$ref` resolves, the fixtures a client is built from
 * fit it, and the answers and events a real session produces fit it too.
 */
final class ProtocolSchemaDriftTest extends TestCase
{
    private const PACKAGE = __DIR__ . '/../..';

    public function testTheSchemaFileAndTheServerDocTablesAreCurrent(): void
    {
        $schema = ProtocolSchema::new();
        $doc = (string) \file_get_contents(self::PACKAGE . '/' . ProtocolSchema::SERVER_DOC);

        self::assertSame(
            $schema->json(),
            (string) @\file_get_contents(self::PACKAGE . '/' . ProtocolSchema::SCHEMA_FILE),
            'the protocol schema is stale — run `php scripts/gen-protocol-schema.php --write` from sugar-crush/',
        );
        self::assertSame($schema->renderServerDoc($doc), $doc, 'the SERVER.md protocol tables are stale — run `php scripts/gen-protocol-schema.php --write`');
    }

    public function testEveryMethodAndEventHasASchemaAndNothingElseDoes(): void
    {
        self::assertSame(Dispatcher::methods()->names(), \array_keys(MethodSchemas::all()));
        self::assertSame(EventType::all(), \array_keys(EventSchemas::all()));
    }

    public function testEveryReferenceResolves(): void
    {
        $document = ProtocolSchema::new()->document();
        $refs = [];
        \array_walk_recursive($document, static function (mixed $value, string|int $key) use (&$refs): void {
            if ($key === '$ref') {
                $refs[] = (string) $value;
            }
        });

        self::assertNotEmpty($refs);
        foreach (\array_unique($refs) as $ref) {
            self::assertArrayHasKey(\substr($ref, \strlen('#/$defs/')), $document['$defs'], $ref);
        }
    }

    public function testTheClientFixturesFitTheSchema(): void
    {
        $files = \glob(self::PACKAGE . '/tests/fixtures/protocol/*.json') ?: [];
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $fixture = \json_decode((string) \file_get_contents($file), true, 64, \JSON_THROW_ON_ERROR);
            $name = \basename($file);
            if (isset($fixture['method'])) {
                self::assertSame([], ProtocolSchema::paramErrors($fixture['method'], $fixture['params']), $name . ' params');
                $result = MethodSchemas::result($fixture['method']);
                self::assertNotNull($result, $name);
                self::assertSame([], ProtocolSchema::errors($fixture['result'], $result), $name . ' result');
            }
            if (isset($fixture['event'])) {
                $this->assertEventFits($fixture['event'], $name);
            }
            if (isset($fixture['error'])) {
                self::assertSame([], ProtocolSchema::errors($fixture['error'], Schema::ref(Definitions::ERROR)), $name);
            }
        }
    }

    public function testParamsThatBreakTheSchemaAreRefusedNamingTheField(): void
    {
        $fixture = ProtocolFixture::new();
        try {
            $client = $fixture->client();
            $reply = $client->request('session.send', ['sessionId' => 'abc', 'text' => 42]);

            self::assertSame(-32602, $reply['error']['code']);
            self::assertSame('invalid_params', $reply['error']['data']['kind']);
            self::assertSame(['params.text: must be string'], $reply['error']['data']['errors']);
            self::assertSame(['params.resume.*: must be integer'], ProtocolSchema::paramErrors('server.hello', ['resume' => ['a-secret-key' => 'x']]), 'a map key the client chose is not echoed');
        } finally {
            $fixture->tearDown();
        }
    }

    public function testTheValidatorReadsTheSubsetItWritesExactly(): void
    {
        $validator = SchemaValidator::new(['N' => Schema::integer(1)->toArray()]);
        $schema = Schema::object([
            'a' => Schema::string(3),
            'b' => Schema::ref('N')->nullable(),
            'c' => Schema::arrayOf(Schema::enum(['x', 'y']), 2),
            'd' => Schema::oneOf(Schema::boolean(), Schema::number()),
        ], ['a'], false)->toArray();

        self::assertSame([], $validator->errors(['a' => 'abc', 'b' => null, 'c' => ['x'], 'd' => 1.5], $schema));
        self::assertSame([], $validator->errors(['a' => 'ok', 'c' => []], $schema), 'an empty JSON array decodes to []');
        self::assertSame(['$.a: is required'], $validator->errors([], $schema));
        self::assertSame(['$.a: longer than 3', '$.b: below 1', '$.c: more than 2 items', '$.c[2]: must be one of x, y'], $validator->errors(['a' => 'abcd', 'b' => 0, 'c' => ['x', 'y', 'z']], $schema));
        self::assertSame(['$: has a property it does not allow'], $validator->errors(['a' => 'a', 'zzz' => 1], $schema));
        self::assertSame(['$.d: must be boolean'], $validator->errors(['a' => 'a', 'd' => 'no'], $schema));
    }

    public function testWhatARealSessionAnswersAndSendsFitsTheSchema(): void
    {
        $fixture = ProtocolFixture::new();
        try {
            $client = WireClient::open($fixture->dispatcher);
            $this->checked($client, 'server.hello', ['minProtocol' => 1, 'maxProtocol' => 1, 'client' => ['name' => 't']]);
            $sessionId = (string) $this->checked($client, 'session.create', ['name' => 'schema'])['id'];
            $this->checked($client, 'session.subscribe', ['sessionId' => $sessionId]);
            $fixture->run();
            $this->checked($client, 'session.send', ['sessionId' => $sessionId, 'text' => 'first']);
            $this->checked($client, 'session.send', ['sessionId' => $sessionId, 'text' => 'second']);
            $this->checked($client, 'session.send', ['sessionId' => $sessionId, 'text' => 'steer', 'delivery' => 'steer']);
            $this->checked($client, 'session.queue', ['sessionId' => $sessionId]);
            $fixture->backend->token('Hi');
            $fixture->backend->emit(new ToolStarted('c1', 'Bash', ['command' => 'ls']));
            $fixture->backend->emit(new ToolFinished('c1', 'Bash', new EngineToolResult('c1', 'a.txt')));
            $ask = $fixture->backend->ask('c2', 'Write', ['file_path' => 'a.txt']);
            $fixture->run(0.08);
            $this->checked($client, 'permission.pending', ['sessionId' => $sessionId]);
            $this->checked($client, 'session.get', ['sessionId' => $sessionId]);
            $this->checked($client, 'permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'always']);
            $this->checked($client, 'permission.rules', ['sessionId' => $sessionId]);
            $fixture->run(0.08);
            $fixture->backend->settle(Message::assistant('Hi there'));
            $fixture->run(0.08);
            $this->checked($client, 'tool.output', ['sessionId' => $sessionId, 'toolCallId' => 'c1']);
            $this->checked($client, 'session.cancel', ['sessionId' => $sessionId, 'mode' => 'soft']);
            $this->checked($client, 'session.list');
            $this->checked($client, 'session.rename', ['sessionId' => $sessionId, 'name' => 'renamed']);
            $this->checked($client, 'session.export', ['sessionId' => $sessionId, 'format' => 'json']);
            $this->checked($client, 'session.setMode', ['sessionId' => $sessionId, 'permissionMode' => 'plan']);
            $this->checked($client, 'server.health');
            $this->checked($client, 'server.info');
            $this->checked($client, 'command.list');
            $this->checked($client, 'settings.schema');
            $this->checked($client, 'settings.get', ['scope' => 'effective']);
            $this->checked($client, 'agents.list');
            $this->checked($client, 'agents.subtree', ['sessionId' => $sessionId]);
            $this->checked($client, 'client.viewing', ['sessionIds' => [$sessionId], 'foreground' => $sessionId]);
            $fork = (string) $this->checked($client, 'session.fork', ['sessionId' => $sessionId])['id'];
            $this->checked($client, 'session.delete', ['sessionId' => $fork]);
            $fixture->backend->settle(Message::assistant('done'));
            $fixture->run(0.08);
            $this->checked($client, 'session.unsubscribe', ['sessionId' => $sessionId]);
            $this->checked($client, 'session.close', ['sessionId' => $sessionId, 'force' => true]);

            $types = [];
            foreach ($client->events() as $event) {
                $this->assertEventFits($event, $event['type']);
                $types[$event['type']] = true;
            }
            foreach (['message.created', 'session.status', 'turn.started', 'turn.queued', 'turn.steered', 'turn.dequeued', 'assistant.delta', 'tool.started', 'tool.finished', 'permission.requested', 'permission.resolved', 'assistant.completed', 'turn.completed', 'session.created', 'session.updated', 'session.deleted'] as $type) {
                self::assertArrayHasKey($type, $types, $type . ' was not produced, so it was not checked');
            }
        } finally {
            $fixture->tearDown();
        }
    }

    /**
     * Call $method and assert its result fits the method's result schema.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checked(WireClient $client, string $method, array $params = []): array
    {
        $result = $client->call($method, $params);
        $schema = MethodSchemas::result($method);
        self::assertNotNull($schema, $method);
        self::assertSame([], ProtocolSchema::errors($result, $schema, $method), $method . ' answered outside its schema');

        return $result;
    }

    /** @param array<string, mixed> $event */
    private function assertEventFits(array $event, string $what): void
    {
        self::assertSame([], ProtocolSchema::errors($event, Schema::ref(Definitions::ENVELOPE)), $what . ' envelope');
        $data = EventSchemas::data((string) $event['type']);
        self::assertNotNull($data, $what . ' has no data schema');
        self::assertSame([], ProtocolSchema::errors($event['data'], $data, $event['type']), $what . ' data');
        self::assertSame(EventType::isDurable((string) $event['type']), $event['durable'], $what . ' durability');
    }
}
