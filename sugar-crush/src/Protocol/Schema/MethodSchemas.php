<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Schema;

use SugarCraft\Crush\Protocol\Schema\Definitions as D;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * The params and result of every `sugarcrush.v1` method (roadmap O-3c).
 *
 * Load-bearing, not documentation only: the {@see \SugarCraft\Crush\Protocol\Dispatcher}
 * checks every request's params against {@see params()} before the method
 * runs, and `ProtocolSchemaDriftTest` checks the answers and events a real
 * session produces against {@see result()} and the event schemas. A method
 * registered without a row here, or a row without a method, reds that test.
 */
final class MethodSchemas
{
    /** @var array<string, array{0: Schema, 1: Schema}>|null built once: pure data */
    private static ?array $all = null;

    private function __construct()
    {
    }

    /**
     * method => [params, result]
     *
     * @return array<string, array{0: Schema, 1: Schema}>
     */
    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    /** @return array<string, array{0: Schema, 1: Schema}> */
    private static function build(): array
    {
        $sid = ['sessionId' => Schema::ref(D::SESSION_ID)];
        $sessionOnly = Schema::object($sid, ['sessionId']);
        $empty = Schema::object();
        $items = static fn (Schema $item): Schema => Schema::object(['items' => Schema::arrayOf($item)], ['items']);
        $page = static fn (Schema $item): Schema => Schema::object(['items' => Schema::arrayOf($item), 'nextCursor' => Schema::string()->nullable()], ['items', 'nextCursor']);
        $summary = Schema::ref(D::SESSION_SUMMARY);
        $memoryScope = Schema::enum(['user', 'project', 'agent']);

        $schemas = [
            'server.hello' => [
                Schema::object([
                    'minProtocol' => Schema::integer(0),
                    'maxProtocol' => Schema::integer(0),
                    'client' => Schema::object(['name' => Schema::string(), 'version' => Schema::string(), 'instanceId' => Schema::string()]),
                    'caps' => Schema::arrayOf(Schema::string(), 64),
                    'resume' => Schema::map(Schema::integer(0))->describe('sessionId => the last seq the client holds'),
                ]),
                Schema::object([
                    'protocol' => Schema::integer(1),
                    'server' => Schema::object([
                        'version' => Schema::string(),
                        'connectionId' => Schema::string(),
                        'root' => Schema::string()->nullable(),
                        'pid' => Schema::integer()->nullable(),
                    ], ['version', 'connectionId']),
                    'features' => Schema::object([
                        'methods' => Schema::arrayOf(Schema::string()),
                        'events' => Schema::arrayOf(Schema::string()),
                    ], ['methods', 'events']),
                    'limits' => Schema::map(Schema::integer(0)),
                    'principal' => Schema::object([
                        'kind' => Schema::string(),
                        'via' => Schema::string(),
                        'scopes' => Schema::arrayOf(Schema::enum(['read', 'write', 'approve', 'admin'])),
                    ], ['kind', 'scopes']),
                    'defaults' => Schema::object(['permissionMode' => Schema::ref(D::PERMISSION_MODE)]),
                    'resumed' => Schema::map(Schema::oneOf(
                        Schema::ref(D::SUBSCRIPTION),
                        Schema::object(['error' => Schema::string()], ['error'], false),
                    )),
                ], ['protocol', 'server', 'features', 'limits', 'principal', 'defaults', 'resumed']),
            ],
            'server.health' => [$empty, Schema::object([
                'ok' => Schema::boolean(),
                'version' => Schema::string(),
                'uptimeS' => Schema::integer(0),
                'sessionsOpen' => Schema::integer(0),
                'turnsRunning' => Schema::integer(0),
                'draining' => Schema::boolean(),
            ], ['ok', 'version', 'uptimeS', 'sessionsOpen', 'turnsRunning', 'draining'])],
            'server.info' => [$empty, Schema::object([
                'version' => Schema::string(),
                'protocol' => Schema::integer(1),
                'root' => Schema::string()->nullable(),
                'providers' => Schema::arrayOf(Schema::string()),
                'agents' => Schema::arrayOf(Schema::object(['name' => Schema::string(), 'description' => Schema::string()], ['name'])),
                'tools' => Schema::arrayOf(Schema::string()),
                'commands' => Schema::arrayOf(Schema::string()),
                'permissionModes' => Schema::arrayOf(Schema::ref(D::PERMISSION_MODE)),
                'defaultPermissionMode' => Schema::ref(D::PERMISSION_MODE),
                'limits' => Schema::map(Schema::number(0)),
            ], ['version', 'protocol', 'permissionModes', 'defaultPermissionMode'])],
            'server.shutdown' => [
                Schema::object(['drainSeconds' => Schema::number(0)]),
                Schema::object(['accepted' => Schema::boolean()], ['accepted']),
            ],
            'client.viewing' => [
                Schema::object([
                    'sessionIds' => Schema::arrayOf(Schema::ref(D::SESSION_ID), 50),
                    'foreground' => Schema::ref(D::SESSION_ID)->nullable(),
                ]),
                $empty,
            ],

            'session.list' => [
                Schema::object(['limit' => Schema::integer(1, 200), 'cursor' => Schema::ref(D::SESSION_ID), 'query' => Schema::string(256)]),
                $page($summary),
            ],
            'session.create' => [
                Schema::object(['name' => Schema::string(256), 'permissionMode' => Schema::ref(D::PERMISSION_MODE)]),
                $summary,
            ],
            'session.get' => [$sessionOnly, Schema::ref(D::SNAPSHOT)],
            'session.subscribe' => [
                Schema::object([...$sid, 'afterSeq' => Schema::integer(0), 'mode' => Schema::enum(['full', 'narration'])], ['sessionId']),
                Schema::ref(D::SUBSCRIPTION),
            ],
            'session.unsubscribe' => [$sessionOnly, Schema::object(['unsubscribed' => Schema::boolean()], ['unsubscribed'])],
            'session.rename' => [Schema::object([...$sid, 'name' => Schema::string(256)], ['sessionId', 'name']), $summary],
            'session.fork' => [$sessionOnly, $summary],
            'session.close' => [
                Schema::object([...$sid, 'force' => Schema::boolean()], ['sessionId']),
                Schema::object(['closed' => Schema::boolean()], ['closed']),
            ],
            'session.delete' => [
                Schema::object([...$sid, 'force' => Schema::boolean()], ['sessionId']),
                Schema::object(['deleted' => Schema::arrayOf(Schema::ref(D::SESSION_ID))], ['deleted']),
            ],
            'session.export' => [
                Schema::object([...$sid, 'format' => Schema::enum(['markdown', 'json', 'text'])], ['sessionId']),
                Schema::object(['format' => Schema::enum(['markdown', 'json', 'text']), 'content' => Schema::string()], ['format', 'content']),
            ],
            'session.setMode' => [
                Schema::object([...$sid, 'permissionMode' => Schema::ref(D::PERMISSION_MODE)], ['sessionId', 'permissionMode']),
                Schema::object(['permissionMode' => Schema::ref(D::PERMISSION_MODE)], ['permissionMode']),
            ],

            'session.send' => [
                Schema::object([
                    ...$sid,
                    'text' => Schema::string(ServerConfig::MAX_CLIENT_MESSAGE_BYTES),
                    'delivery' => Schema::enum(['queue', 'steer', 'interrupt']),
                ], ['sessionId', 'text']),
                Schema::ref(D::ADMISSION),
            ],
            'session.cancel' => [
                Schema::object([...$sid, 'turnId' => Schema::string(64), 'mode' => Schema::enum(['hard', 'soft']), 'clearQueue' => Schema::boolean()], ['sessionId']),
                Schema::object(['cancelled' => Schema::boolean(), 'mode' => Schema::enum(['hard', 'soft']), 'cleared' => Schema::integer(0)], ['cancelled', 'mode', 'cleared']),
            ],
            'session.queue' => [$sessionOnly, $items(Schema::ref(D::QUEUE_ENTRY))],
            'session.dequeue' => [
                Schema::object([...$sid, 'queueId' => Schema::string(32)], ['sessionId', 'queueId']),
                Schema::object(['dequeued' => Schema::boolean()], ['dequeued']),
            ],

            'permission.respond' => [
                Schema::object([
                    ...$sid,
                    'askId' => Schema::string(64),
                    'reply' => Schema::enum(['once', 'always', 'reject']),
                    'note' => Schema::string(2048),
                    'cascade' => Schema::boolean(),
                    'remember' => Schema::enum(['session', 'project', 'user']),
                ], ['sessionId', 'askId', 'reply']),
                Schema::object([
                    'applied' => Schema::boolean(),
                    'askId' => Schema::string(),
                    'reply' => Schema::enum(['once', 'always', 'reject'])->nullable(),
                    'cascaded' => Schema::arrayOf(Schema::string()),
                ], ['applied', 'askId', 'cascaded']),
            ],
            'permission.pending' => [
                Schema::object($sid),
                $items(Schema::ref(D::PENDING_ASK)->describe('Each also carries its sessionId.')),
            ],
            'permission.rules' => [
                Schema::object($sid),
                Schema::object([
                    'mode' => Schema::ref(D::PERMISSION_MODE)->nullable(),
                    'modeSource' => Schema::string()->nullable(),
                    'rules' => Schema::arrayOf(Schema::object(['pattern' => Schema::string(), 'action' => Schema::string()], ['pattern', 'action'])),
                    'sessionGrants' => Schema::arrayOf(Schema::string()),
                ], ['mode', 'rules', 'sessionGrants']),
            ],

            'command.list' => [Schema::object($sid), $items(Schema::object([
                'name' => Schema::string(),
                'description' => Schema::string(),
                'argumentHint' => Schema::string()->nullable(),
                'source' => Schema::enum(['builtin', 'file']),
                'runsIn' => Schema::enum(['server', 'client']),
            ], ['name', 'source', 'runsIn']))],
            'command.exec' => [
                Schema::object([...$sid, 'name' => Schema::string(128), 'args' => Schema::string(65_536)], ['sessionId', 'name']),
                Schema::oneOf(
                    Schema::ref(D::ADMISSION)->describe('A command file: admitted like session.send, plus empty `rows` and `effects`.'),
                    Schema::object([
                        'rows' => Schema::arrayOf(Schema::object([
                            'role' => Schema::string(),
                            'content' => Schema::string(),
                            'uiOnly' => Schema::boolean(),
                        ], ['role', 'content', 'uiOnly'])),
                        'effects' => Schema::arrayOf(Schema::string()),
                    ], ['rows', 'effects'], open: false)->describe('A built-in run on the server: the rows it appended and the kinds of the effects it applied.'),
                ),
            ],

            'settings.schema' => [$empty, $items(Schema::object([
                'key' => Schema::string(),
                'type' => Schema::string(),
                'default' => Schema::any(),
                'group' => Schema::string(),
                'label' => Schema::string(),
                'help' => Schema::string(),
                'enum' => Schema::arrayOf(Schema::any()),
                'min' => Schema::number(),
                'max' => Schema::number(),
                'riskClass' => Schema::string(),
                'applies' => Schema::string(),
                'projectSettable' => Schema::boolean(),
                'sensitive' => Schema::boolean(),
                'writableRemotely' => Schema::boolean(),
            ], ['key', 'type', 'group', 'label', 'riskClass', 'applies', 'sensitive', 'writableRemotely']))],
            'settings.get' => [
                Schema::object(['scope' => Schema::enum(['effective', 'user', 'project'])]),
                Schema::object(['scope' => Schema::enum(['effective', 'user', 'project']), 'values' => Schema::map(Schema::any())], ['scope', 'values']),
            ],
            'settings.set' => [
                Schema::object(['key' => Schema::string(128), 'value' => Schema::any(), 'scope' => Schema::enum(['user', 'project']), 'reset' => Schema::boolean()], ['key']),
                Schema::object(['key' => Schema::string(), 'scope' => Schema::string(), 'written' => Schema::string(), 'applies' => Schema::string()], ['key', 'scope', 'written']),
            ],

            'memory.list' => [Schema::object(['scope' => $memoryScope]), $items(Schema::ref(D::MEMORY_ENTRY))],
            'memory.search' => [Schema::object(['query' => Schema::string(1024), 'limit' => Schema::integer(1, 500)], ['query']), $items(Schema::ref(D::MEMORY_ENTRY))],
            'memory.add' => [
                Schema::object(['content' => Schema::string(65_536), 'scope' => $memoryScope, 'tags' => Schema::arrayOf(Schema::string(), 32)], ['content']),
                Schema::object(['id' => Schema::string()], ['id']),
            ],
            'memory.edit' => [
                Schema::object(['id' => Schema::string(128), 'content' => Schema::string(65_536)], ['id', 'content']),
                Schema::object(['id' => Schema::string()], ['id']),
            ],
            'memory.delete' => [Schema::object(['id' => Schema::string(128)], ['id']), Schema::object(['deleted' => Schema::boolean()], ['deleted'])],

            'agents.list' => [$empty, $items(Schema::object([
                'name' => Schema::string(),
                'description' => Schema::string(),
                'model' => Schema::string(),
                'provider' => Schema::string(),
                'permissionMode' => Schema::ref(D::PERMISSION_MODE),
                'active' => Schema::boolean(),
            ], ['name']))],
            'agents.subtree' => [$sessionOnly, $items(Schema::map(Schema::any())->describe('A sub-agent\'s latest `subagent.*` event data.'))],

            'bg.list' => [$empty, $items(Schema::ref(D::BACKGROUND_SESSION))],
            'bg.spawn' => [
                Schema::object(['task' => Schema::string(ServerConfig::MAX_CLIENT_MESSAGE_BYTES), 'agent' => Schema::string(128), 'name' => Schema::string(256)], ['task']),
                Schema::ref(D::BACKGROUND_SESSION),
            ],
            'bg.inject' => [
                Schema::object([...$sid, 'bgId' => Schema::string(128), 'delivery' => Schema::enum(['queue', 'steer', 'interrupt'])], ['bgId', 'sessionId']),
                Schema::object([
                    'bgId' => Schema::string(),
                    'sessionId' => Schema::ref(D::SESSION_ID),
                    'admitted' => Schema::enum(['started', 'queued', 'steered', 'pending']),
                    'turnId' => Schema::string(),
                    'messageId' => Schema::string(),
                    'steerId' => Schema::string(),
                    'queuePosition' => Schema::integer(1),
                    'queueId' => Schema::string(32),
                ], ['bgId', 'sessionId', 'admitted']),
            ],
            'bg.output' => [
                Schema::object(['bgId' => Schema::string(128), 'offset' => Schema::integer(0), 'limit' => Schema::integer(1)], ['bgId']),
                Schema::object(['bgId' => Schema::string(), 'offset' => Schema::integer(0), 'text' => Schema::string(), 'total' => Schema::integer(0), 'status' => Schema::string()], ['bgId', 'offset', 'text', 'total', 'status']),
            ],
            'bg.stop' => [
                Schema::object(['bgId' => Schema::string(128)], ['bgId']),
                Schema::object(['bgId' => Schema::string(), 'outcome' => Schema::string()], ['bgId', 'outcome']),
            ],

            'files.diff' => [
                Schema::object(['ref' => Schema::string(128)]),
                Schema::object(['ref' => Schema::string(), 'diff' => Schema::string(), 'truncated' => Schema::boolean()], ['ref', 'diff', 'truncated']),
            ],
            'files.changed' => [$empty, Schema::object([
                'items' => Schema::arrayOf(Schema::object(['path' => Schema::string(), 'status' => Schema::string(), 'from' => Schema::string()->nullable()], ['path', 'status'])),
                'truncated' => Schema::boolean(),
            ], ['items', 'truncated'])],
            'files.read' => [
                Schema::object(['path' => Schema::string(4096), 'offset' => Schema::integer(0), 'limit' => Schema::integer(1)], ['path']),
                Schema::object([
                    'path' => Schema::string(),
                    'size' => Schema::integer(0),
                    'offset' => Schema::integer(0),
                    'binary' => Schema::boolean(),
                    'content' => Schema::string(),
                    'truncated' => Schema::boolean(),
                ], ['path', 'size', 'offset', 'binary', 'content', 'truncated']),
            ],

            'tool.output' => [
                Schema::object([...$sid, 'toolCallId' => Schema::string(256), 'offset' => Schema::integer(0), 'limit' => Schema::integer(1)], ['sessionId', 'toolCallId']),
                Schema::object([
                    'toolCallId' => Schema::string(),
                    'offset' => Schema::integer(0),
                    'content' => Schema::string(),
                    'total' => Schema::integer(0),
                    'isError' => Schema::boolean(),
                    'more' => Schema::boolean(),
                ], ['toolCallId', 'offset', 'content', 'total', 'isError', 'more']),
            ],
            'todo.get' => [$sessionOnly, Schema::object(['items' => Schema::arrayOf(Schema::object(['content' => Schema::string(), 'status' => Schema::enum(\SugarCraft\Crush\Todo\TodoStatus::values())], ['content', 'status']))], ['items'])],
        ];
        \ksort($schemas);

        return $schemas;
    }

    public static function params(string $method): ?Schema
    {
        return self::all()[$method][0] ?? null;
    }

    public static function result(string $method): ?Schema
    {
        return self::all()[$method][1] ?? null;
    }
}
