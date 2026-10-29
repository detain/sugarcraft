<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Schema;

use SugarCraft\Code\Hooks\BuiltIn\AuditLogReader;
use SugarCraft\Code\Host\SessionSnapshot;
use SugarCraft\Code\Memory\MemoryHistory;
use SugarCraft\Code\Protocol\Methods\FsMethods;
use SugarCraft\Code\Protocol\Methods\SessionMethods;
use SugarCraft\Code\Protocol\Methods\TurnMethods;
use SugarCraft\Code\Protocol\Schema\Definitions as D;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\Import\ImportFormat;
use SugarCraft\Code\Share\ShareSession;
use SugarCraft\Code\Share\ShareStore;

/**
 * The params and result of every `candy-code.v1` method (roadmap O-3c).
 *
 * Load-bearing, not documentation only: the {@see \SugarCraft\Code\Protocol\Dispatcher}
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
        $root = Schema::string(4096)->describe('Another project root: the serve gateway routes the call to that root\'s workspace host (roadmap O-7).');
        $workspace = Schema::object([
            'root' => Schema::string(),
            'primary' => Schema::boolean(),
            'running' => Schema::boolean(),
            'pid' => Schema::integer()->nullable(),
        ], ['root', 'primary', 'running']);

        $agentId = Schema::string(128)->describe('A delegated run\'s id, as its `subagent.*` events name it.');
        $agentSent = Schema::object([
            'agentId' => Schema::string(),
            'status' => Schema::enum(['queued', 'resuming'])->describe('queued: in the running run\'s mailbox, read at its next step; resuming: a finished run is being continued.'),
            'msgId' => Schema::string(),
        ], ['agentId', 'status']);
        $commandRun = Schema::object([
            'rows' => Schema::arrayOf(Schema::object([
                'role' => Schema::string(),
                'content' => Schema::string(),
                'uiOnly' => Schema::boolean(),
            ], ['role', 'content', 'uiOnly'])),
            'effects' => Schema::arrayOf(Schema::string()),
        ], ['rows', 'effects'], open: false);
        $workflowStatus = Schema::object(['workflowId' => Schema::string(), 'status' => Schema::enum(\array_map(static fn (\SugarCraft\Code\Workflows\WorkflowStatus $s): string => $s->value, \SugarCraft\Code\Workflows\WorkflowStatus::cases()))], ['workflowId', 'status']);

        $schemas = [
            'server.hello' => [
                Schema::object([
                    'minProtocol' => Schema::integer(0),
                    'maxProtocol' => Schema::integer(0),
                    'client' => Schema::object(['name' => Schema::string(), 'version' => Schema::string(), 'instanceId' => Schema::string()]),
                    'caps' => Schema::arrayOf(Schema::string(), 64),
                    'resume' => Schema::map(Schema::integer(0))->describe('sessionId => the last seq the client holds'),
                    'snapshotLimit' => Schema::integer(1, SessionSnapshot::MAX_PAGE_ROWS)->describe('Page a resumed session\'s reset snapshot to this many newest rows.'),
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
                        'dirBrowse' => Schema::object([
                            'enabled' => Schema::boolean(),
                            'root' => Schema::string()->nullable(),
                        ], ['enabled', 'root'])->describe('Whether `fs.listDirs` and a browse-rooted `workspace.open` answer (serve --allow-dir-browse), and the directory they are confined to.'),
                        'media' => Schema::object([
                            'image' => Schema::boolean(),
                            'video' => Schema::boolean(),
                            'baseUrlHostOnly' => Schema::string()->nullable(),
                        ], ['image', 'video', 'baseUrlHostOnly'])->describe('Whether this server may offer image/video generation, derived at boot from config intersected with probed capability without dialing the server; `baseUrlHostOnly` is a sha256 prefix of the SD host — never the URL or hostname in the clear, null when none is configured.'),
                    ], ['methods', 'events']),
                    'limits' => Schema::map(Schema::integer(0)),
                    'principal' => Schema::object([
                        'kind' => Schema::string(),
                        'via' => Schema::string(),
                        'scopes' => Schema::arrayOf(Schema::enum(['read', 'write', 'approve', 'admin'])),
                    ], ['kind', 'scopes']),
                    'defaults' => Schema::object([
                        'permissionMode' => Schema::ref(D::PERMISSION_MODE),
                        'delivery' => Schema::enum(['queue', 'steer', 'interrupt'])->describe('The delivery a client should offer first for a prompt sent while a turn runs: the `queueMode` setting, `steer` unless set. A `session.send` that names none still queues.'),
                    ]),
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
                    'narrate' => Schema::boolean()->describe('Narrate every followed session that is not in front, instead of streaming it.'),
                ]),
                Schema::object([
                    'narrated' => Schema::arrayOf(Schema::ref(D::SESSION_ID))->describe('The followed sessions this client now hears as narration.'),
                ], ['narrated']),
            ],

            'session.list' => [
                Schema::object([
                    'limit' => Schema::integer(1, 200),
                    'cursor' => Schema::ref(D::SESSION_ID),
                    'query' => Schema::string(256),
                    'archived' => Schema::boolean()->describe('true lists ONLY archived sessions; omitted or false lists the unarchived ones (the historical law).'),
                    'root' => $root,
                ]),
                $page($summary),
            ],
            'session.create' => [
                Schema::object([
                    'name' => Schema::string(256),
                    'permissionMode' => Schema::ref(D::PERMISSION_MODE),
                    'ephemeral' => Schema::boolean()->describe('Incognito: a live session that persists nothing — no store row, no transcript saves, no usage records, no title or memory passes — and is dropped by the hub on close.'),
                    'root' => $root,
                ]),
                $summary,
            ],
            'session.get' => [Schema::object([...$sid, 'limit' => Schema::integer(1, SessionSnapshot::MAX_PAGE_ROWS), 'beforeIndex' => Schema::integer(0)], ['sessionId']), Schema::ref(D::SNAPSHOT)],
            'session.subscribe' => [
                Schema::object([...$sid, 'afterSeq' => Schema::integer(0), 'mode' => Schema::enum(['full', 'narration']), 'snapshotLimit' => Schema::integer(1, SessionSnapshot::MAX_PAGE_ROWS)], ['sessionId']),
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
                Schema::object([
                    ...$sid,
                    'format' => Schema::enum(['markdown', 'json', 'text']),
                    'offset' => Schema::integer(0)->describe('Byte offset into the rendered transcript; the page backs up to a UTF-8 lead byte. Only read with limit.'),
                    'limit' => Schema::integer(SessionMethods::EXPORT_MIN_PAGE_BYTES, SessionMethods::EXPORT_MAX_PAGE_BYTES)->describe('Ask for a page instead of the whole transcript; the answer shrinks further if the page would not fit the frame. Omitted: one unpaged answer.'),
                    'revision' => Schema::string(64)->describe('The revision a previous page reported; a page 2+ whose session re-rendered is refused export_changed so pages from two renders can never splice.'),
                ], ['sessionId']),
                Schema::object([
                    'format' => Schema::enum(['markdown', 'json', 'text']),
                    'content' => Schema::string(),
                    'offset' => Schema::integer(0)->describe('The lead-byte-aligned byte this page starts at.'),
                    'nextOffset' => Schema::integer(0)->describe('Where the next page begins; the offset to send back (computed by the server, never by re-encoding the text).'),
                    'total' => Schema::integer(0)->describe('Total bytes of the rendered transcript this revision covers.'),
                    'more' => Schema::boolean()->describe('False on the last page; join the contents seen so far.'),
                    'revision' => Schema::string(64)->describe('xxh128 of the whole rendered transcript; echo it on the next page.'),
                ], ['format', 'content']),
            ],
            'session.import' => [
                Schema::object([
                    'content' => Schema::string(SessionMethods::IMPORT_MAX_CONTENT_BYTES)->describe('The transcript body to import, up to the verified inbound WS frame ceiling (ServerConfig::MAX_CLIENT_FRAME_BYTES less envelope headroom); a longer body is refused as an invalid param before dispatch, a malformed one as import_refused.'),
                    'format' => Schema::enum(\array_map(static fn (ImportFormat $case): string => $case->value, ImportFormat::cases()))->describe('How to read the body; omitted, the importer detects it from the leading structure.'),
                    'name' => Schema::string(256),
                    'root' => $root,
                ], ['content']),
                $summary,
            ],
            'session.archive' => [$sessionOnly, $summary],
            'session.unarchive' => [$sessionOnly, $summary],
            'session.share' => [
                Schema::object([...$sid, 'days' => Schema::integer(1, ShareStore::MAX_EXPIRY_DAYS)], ['sessionId']),
                Schema::object([
                    'token' => Schema::string(ShareStore::TOKEN_BYTES * 2),
                    'path' => Schema::string(),
                    'format' => Schema::enum([ShareSession::FORMAT_HTML]),
                    'expiresAt' => Schema::integer(0),
                ], ['token', 'path', 'format', 'expiresAt']),
            ],
            'session.unshare' => [
                Schema::object(['token' => Schema::string(ShareStore::TOKEN_BYTES * 2)], ['token']),
                Schema::object(['revoked' => Schema::boolean()], ['revoked']),
            ],
            'session.shares' => [
                Schema::object(['sessionId' => Schema::ref(D::SESSION_ID)]),
                $items(Schema::object([
                    'sessionId' => Schema::ref(D::SESSION_ID),
                    'sessionName' => Schema::string()->nullable(),
                    'token' => Schema::string(ShareStore::TOKEN_BYTES * 2),
                    'tokenPrefix' => Schema::string(SessionMethods::SHARE_TOKEN_PREFIX_CHARS),
                    'path' => Schema::string(),
                    'format' => Schema::enum([ShareSession::FORMAT_HTML]),
                    'expiresAt' => Schema::integer(0),
                ], ['sessionId', 'sessionName', 'token', 'tokenPrefix', 'path', 'format', 'expiresAt'])),
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
                    // WEB-5b: pasted images, base64. `data` is strict base64 and
                    // `mime` a claim — the handler sniffs the bytes and refuses
                    // any mismatch; the caps here bound the frame, the handler
                    // bounds the decoded budget.
                    'images' => Schema::arrayOf(Schema::object([
                        'name' => Schema::string(256),
                        'mime' => Schema::enum(TurnMethods::PASTED_IMAGE_MIMES),
                        'data' => Schema::string(TurnMethods::MAX_IMAGE_B64_CHARS),
                    ], ['mime', 'data']), TurnMethods::MAX_IMAGES),
                    // C-WEB-4.e: uploaded documents, base64 — same law, PDF
                    // bytes only, and the `data` cap is the exact base64
                    // length of the images' and documents' SHARED decoded
                    // budget, the largest lone upload that still rides a
                    // frame. The handler sums both legs into it.
                    'documents' => Schema::arrayOf(Schema::object([
                        'name' => Schema::string(256),
                        'mime' => Schema::enum(TurnMethods::PASTED_DOCUMENT_MIMES),
                        'data' => Schema::string(TurnMethods::MAX_DOCUMENT_B64_CHARS),
                    ], ['mime', 'data']), TurnMethods::MAX_DOCUMENTS),
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
                    'saved' => Schema::string(2048)->nullable()->describe('With remember "user" on a hosted question: the notice naming where the answer\'s patterns were saved (or why they were not); null when the question had nothing generalisable to persist.'),
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
                    'rules' => Schema::arrayOf(Schema::object([
                        'pattern' => Schema::string(),
                        'action' => Schema::string(),
                        'tier' => Schema::enum(['user', 'config'])->describe('C-SEC-6.c: `user` for a rule the user saved through `p` / `remember: "user"` (numbered by savedIndex, the same count permission.forget and `/permissions saved` address by); `config` for every other configured row.'),
                        'savedIndex' => Schema::integer(1)->nullable(),
                    ], ['pattern', 'action', 'tier'])),
                    'sessionGrants' => Schema::arrayOf(Schema::string()),
                ], ['mode', 'rules', 'sessionGrants']),
            ],
            'permission.forget' => [
                Schema::object([
                    'pattern' => Schema::string(512),
                    'index' => Schema::integer(1)->describe('The 1-based number the saved list shows; exactly one of pattern or index.'),
                ]),
                Schema::object([
                    'removed' => Schema::arrayOf(Schema::object(['pattern' => Schema::string(), 'action' => Schema::string()], ['pattern', 'action'])),
                    'path' => Schema::string(),
                ], ['removed', 'path']),
            ],
            'permission.audit' => [
                Schema::object([...$sid, 'limit' => Schema::integer(1, AuditLogReader::MAX_ROWS)->describe('Rows wanted, newest last; the server caps the read at ' . AuditLogReader::MAX_ROWS . '.')]),
                Schema::object([
                    'rows' => Schema::arrayOf(Schema::object([
                        'time' => Schema::string(19),
                        'session' => Schema::string(),
                        'tool' => Schema::string(),
                        'verdict' => Schema::enum(AuditLogReader::verdicts()),
                        'kind' => Schema::string(512)->nullable(),
                        'reason' => Schema::string()->nullable(),
                    ], ['time', 'session', 'tool', 'verdict']), AuditLogReader::MAX_ROWS),
                    'unreadable' => Schema::boolean(),
                ], ['rows', 'unreadable']),
            ],

            'command.list' => [$sessionOnly, $items(Schema::object([
                'name' => Schema::string(),
                'description' => Schema::string(),
                'argumentHint' => Schema::string()->nullable(),
                'source' => Schema::enum(['builtin', 'file', 'mcp']),
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

            'mcp.resources' => [
                $sessionOnly,
                Schema::object([
                    'status' => Schema::enum(['started', 'not-started']),
                    'servers' => Schema::arrayOf(Schema::object([
                        'server' => Schema::string(200),
                        'resources' => Schema::arrayOf(Schema::object([
                            'uri' => Schema::string(512),
                            'name' => Schema::string(200),
                            'mimeType' => Schema::string(128)->nullable(),
                            'description' => Schema::string(400)->nullable(),
                        ], ['uri', 'name'])),
                    ], ['server', 'resources'])),
                ], ['status', 'servers']),
            ],

            'settings.schema' => [$empty, Schema::object([
                'items' => Schema::arrayOf(Schema::object([
                    'key' => Schema::string(),
                    'type' => Schema::string(),
                    'default' => Schema::any(),
                    'defaultText' => Schema::string(),
                    'group' => Schema::string(),
                    'label' => Schema::string(),
                    'help' => Schema::string(),
                    'enum' => Schema::arrayOf(Schema::any()),
                    'options' => Schema::arrayOf(Schema::string())->describe('The choices a pick-one field offers (the enum, or the launch\'s list: themes, providers…).'),
                    'min' => Schema::number(),
                    'max' => Schema::number(),
                    'riskClass' => Schema::string(),
                    'applies' => Schema::enum(['live', 'next-turn', 'reload', 'restart', 'frozen']),
                    'appliesLabel' => Schema::string(),
                    'ui' => Schema::enum(['easy', 'list', 'complex', 'read-only', 'hidden']),
                    'layered' => Schema::boolean(),
                    'projectSettable' => Schema::boolean(),
                    'envVar' => Schema::string()->describe('The environment variable that, when set, locks the key.'),
                    'cliFlag' => Schema::string(),
                    'sensitive' => Schema::boolean(),
                    'writableRemotely' => Schema::boolean(),
                    'remoteRefusal' => Schema::string()->describe('Why a client may not write the key; absent when it may.'),
                ], ['key', 'type', 'group', 'label', 'riskClass', 'applies', 'sensitive', 'writableRemotely'])),
                'tiers' => Schema::arrayOf(Schema::object([
                    'scope' => Schema::enum(['user', 'project', 'project-shared']),
                    'label' => Schema::string(),
                    'path' => Schema::string(),
                    'writable' => Schema::boolean(),
                    'refusal' => Schema::string(),
                ], ['scope', 'label', 'writable']))->describe('The tiers a save can target, and whether each can be written now.'),
            ], ['items'])],
            'settings.get' => [
                Schema::object(['scope' => Schema::enum(['effective', 'user', 'project', 'project-shared'])]),
                Schema::object([
                    'scope' => Schema::enum(['effective', 'user', 'project', 'project-shared']),
                    'values' => Schema::map(Schema::any())->describe('Effective: key => {value, source, sourceLabel, sourcePath, shadowed, locked, lockReason}. A tier: the file\'s own object.'),
                    'files' => Schema::arrayOf(Schema::object([
                        'role' => Schema::string(),
                        'path' => Schema::string(),
                        'status' => Schema::string(),
                        'note' => Schema::string(),
                    ], ['role', 'path', 'status', 'note']))->describe('Effective only: the files behind the layers, and whether each was read.'),
                ], ['scope', 'values']),
            ],
            'settings.preview' => [
                Schema::object([
                    'scope' => Schema::enum(['user', 'project', 'project-shared']),
                    'set' => Schema::map(Schema::any()),
                    'unset' => Schema::arrayOf(Schema::string(128), 200),
                    'key' => Schema::string(128),
                    'value' => Schema::any(),
                    'reset' => Schema::boolean(),
                ]),
                Schema::object([
                    'scope' => Schema::string(),
                    'path' => Schema::string()->nullable(),
                    'canSave' => Schema::boolean(),
                    'refusals' => Schema::map(Schema::string())->describe('key (`*` for the tier) => why the save is refused'),
                    'changes' => Schema::arrayOf(Schema::object([
                        'key' => Schema::string(),
                        'action' => Schema::enum(['set', 'reset']),
                        'value' => Schema::any(),
                        'applies' => Schema::string(),
                        'appliesLabel' => Schema::string(),
                    ], ['key', 'action', 'applies', 'appliesLabel'])),
                    'applySummary' => Schema::string(),
                    'notes' => Schema::arrayOf(Schema::string()),
                    'diff' => Schema::string()->describe('The target file\'s JSON, before and after, as unified-diff hunks; secrets masked.'),
                ], ['scope', 'path', 'canSave', 'refusals', 'changes', 'applySummary', 'notes', 'diff']),
            ],
            'settings.set' => [
                Schema::object([
                    'key' => Schema::string(128),
                    'value' => Schema::any(),
                    'reset' => Schema::boolean(),
                    'set' => Schema::map(Schema::any()),
                    'unset' => Schema::arrayOf(Schema::string(128), 200),
                    'scope' => Schema::enum(['user', 'project', 'project-shared']),
                ]),
                Schema::object([
                    'key' => Schema::string(),
                    'scope' => Schema::string(),
                    'written' => Schema::string(),
                    'applies' => Schema::string(),
                    'changed' => Schema::arrayOf(Schema::string()),
                    'appliesByKey' => Schema::map(Schema::string()),
                ], ['scope', 'written', 'changed']),
            ],

            'memory.list' => [Schema::object(['scope' => $memoryScope]), $items(Schema::ref(D::MEMORY_ENTRY))],
            'memory.search' => [Schema::object(['query' => Schema::string(1024), 'limit' => Schema::integer(1, 500)], ['query']), $items(Schema::ref(D::MEMORY_ENTRY))],
            'memory.add' => [
                Schema::object(['content' => Schema::string(65_536)->describe('Empty text and project notes over the store bound (ProjectMemoryWriter::MAX_CONTENT_BYTES, setting memory.projectNoteMaxBytes) are refused as invalid_params.'), 'scope' => $memoryScope, 'tags' => Schema::arrayOf(Schema::string(), 32)], ['content']),
                Schema::object([
                    'id' => Schema::string(),
                    'source' => Schema::enum(['home', 'repo']),
                    'fellBackToHome' => Schema::boolean()->describe('True when a project note could not land in the repository store and went to the home directory instead (never silent).'),
                ], ['id', 'source']),
            ],
            'memory.edit' => [
                Schema::object(['id' => Schema::string(128), 'content' => Schema::string(65_536)], ['id', 'content']),
                Schema::object(['id' => Schema::string()], ['id']),
            ],
            'memory.delete' => [Schema::object(['id' => Schema::string(128)], ['id']), Schema::object(['deleted' => Schema::boolean()], ['deleted'])],
            'memory.log' => [
                Schema::object(['limit' => Schema::integer(1, MemoryHistory::MAX_LOG_ENTRIES)->describe('Newest revisions to list (default memory.log\'s own default). Like /memory log, this commits pending changes first so the list ends at what is on disk now.')]),
                $items(Schema::object([
                    'sha' => Schema::string(),
                    'shortSha' => Schema::string(),
                    'committedAt' => Schema::string()->describe('ISO-8601 (DATE_ATOM) commit time.'),
                    'subject' => Schema::string(),
                ], ['sha', 'shortSha', 'committedAt', 'subject'])),
            ],
            'memory.undo' => [
                Schema::object(['revision' => Schema::string(64)->describe('Hex commit id as memory.log lists; omitted means the state before the newest commit.')]),
                Schema::object([
                    'restored' => Schema::string()->describe('The revision the store was restored to.'),
                    'commit' => Schema::string()->nullable()->describe('New commit id, or null when the restored tree equalled the current one.'),
                ], ['restored']),
            ],

            'context.sources' => [
                $sessionOnly,
                Schema::ref(D::CONTEXT_SOURCES),
            ],

            'context.trust' => [
                Schema::object([
                    ...$sid,
                    'askId' => Schema::string(16)->describe('The trust prompt\'s id, as permission.asked (kind "trust") carried it — one answer settles that prompt\'s target only; the root\'s other prompts stay open.'),
                    'decision' => Schema::enum(['grant', 'reject']),
                    'remember' => Schema::enum(['session', 'user', 'project'])->describe('`user` writes the root into your own config under the answered prompt\'s target list — `trustedProjectMcp` for an `mcp` or `mcp-pin` row, the matching `trustedProject*` key for the others (effective next launch, like the TUI grant) — and refuses `trust_write_failed`, leaving the prompt open, when that write does not land; `session` — or omitted — settles for this server only; `project` is refused: a project cannot grant itself trust (the permission.respond posture).'),
                ], ['sessionId', 'askId', 'decision']),
                Schema::object([
                    'askId' => Schema::string(16),
                    'status' => Schema::enum(['granted', 'rejected']),
                    'persisted' => Schema::boolean()->describe('True only when the user-tier write landed.'),
                ], ['askId', 'status', 'persisted']),
            ],

            'agents.list' => [$empty, $items(Schema::object([
                'name' => Schema::string(),
                'description' => Schema::string(),
                'model' => Schema::string(),
                'provider' => Schema::string(),
                'permissionMode' => Schema::ref(D::PERMISSION_MODE),
                'active' => Schema::boolean(),
                // C-AGT-4.c: the preset's declaring `mode:` frontmatter,
                // through the same leniency law the TUI's primary cycle
                // follows (Chat::presentsAsPrimary): a preset naming no mode
                // — or naming one the enum does not hold — answers `all`.
                // `subagent` presets are what a web primary-agent picker must
                // not offer; `all`/`primary` rows may be.
                'mode' => Schema::enum(['primary', 'subagent', 'all'])->describe('The preset\'s declaring frontmatter `mode:`; absent or unreadable answers `all`.'),
                'color' => Schema::string(7)->describe('The preset\'s `color:` vocabulary resolved through the same AgentColor law the TUI strip follows, as a `#rrggbb` hex against the server\'s default theme; omitted when the preset names no colour or names one that does not resolve legibly.'),
            ], ['name']))],
            'agents.subtree' => [$sessionOnly, $items(Schema::map(Schema::any())->describe('A delegated run\'s latest `subagent.*` event data, from the session\'s log and the live feed.'))],
            'agents.transcript' => [
                Schema::object([...$sid, 'agentId' => $agentId, 'offset' => Schema::integer(0), 'limit' => Schema::integer(1024, 65_536)], ['sessionId', 'agentId']),
                Schema::object([
                    'agentId' => Schema::string(),
                    'offset' => Schema::integer(0)->describe('Where the next page starts: just past the last whole line read.'),
                    'items' => Schema::arrayOf(Schema::object([
                        't' => Schema::enum(\SugarCraft\Code\Agents\Live\SubAgentTranscriptLog::TYPES),
                        'ts' => Schema::integer(0),
                        'text' => Schema::string(),
                        'callId' => Schema::string(),
                        'tool' => Schema::string(),
                        'args' => Schema::map(Schema::any()),
                        'ok' => Schema::boolean(),
                        'content' => Schema::string(),
                        'truncated' => Schema::boolean(),
                        'status' => Schema::string(),
                        'outcome' => Schema::string(),
                        'error' => Schema::string(),
                        'from' => Schema::string(),
                        'mode' => Schema::string(),
                        'msgId' => Schema::string(),
                        'step' => Schema::integer(0),
                    ], ['t'])),
                    'more' => Schema::boolean(),
                    'finished' => Schema::boolean(),
                ], ['agentId', 'offset', 'items', 'more', 'finished']),
            ],
            'agents.message' => [
                Schema::object([...$sid, 'agentId' => $agentId, 'text' => Schema::string(65_536), 'mode' => Schema::enum(\SugarCraft\Code\Protocol\Methods\AgentsMethods::MESSAGE_MODES)->describe('steer (the default): delivered at the run\'s next step boundary, the current step\'s tool calls all running first; interrupt: delivered there too, but the step\'s sequential calls that have not started are skipped, so the run reads the message before doing more work on a plan it may change. Omitted on a FINISHED run — its follow-up conversation carries no step to steer.')], ['sessionId', 'agentId', 'text']),
                $agentSent,
            ],
            'agents.control' => [
                Schema::object([
                    ...$sid,
                    'agentId' => $agentId,
                    'verb' => Schema::enum(\SugarCraft\Code\Protocol\Methods\AgentsMethods::CONTROL_VERBS),
                    'text' => Schema::string(65_536)->describe('What a resumed FINISHED run is told; the default asks it to continue.'),
                ], ['sessionId', 'agentId', 'verb']),
                $agentSent,
            ],

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
                    'admitted' => Schema::enum(['started', 'queued', 'steered', 'pending', 'handled']),
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

            'shells.list' => [$sessionOnly, $items(Schema::ref(D::BACKGROUND_SHELL))],
            'shells.output' => [
                Schema::object([
                    ...$sid,
                    'shellId' => Schema::string(128),
                    'cursor' => Schema::oneOf(Schema::integer(0), Schema::string(64))->describe('The "out.err" token a previous read answered, or a byte count; omitted (or 0) reads fresh — a fresh window tail-wins, and skippedBytes says how much fell off the head.'),
                ], ['sessionId', 'shellId']),
                Schema::object([
                    'shellId' => Schema::string(),
                    'stdout' => Schema::string(),
                    'stderr' => Schema::string(),
                    'cursor' => Schema::string(),
                    'skippedBytes' => Schema::integer(0),
                    'bytesAvailable' => Schema::integer(0),
                    'state' => Schema::enum(['running', 'finished', 'unknown']),
                    'exitCode' => Schema::integer()->nullable(),
                    'exitNote' => Schema::string()->nullable(),
                    'expired' => Schema::boolean(),
                ], ['shellId', 'stdout', 'stderr', 'cursor', 'skippedBytes', 'bytesAvailable', 'state', 'expired']),
            ],
            'shells.stop' => [
                Schema::object([...$sid, 'shellId' => Schema::string(128)], ['sessionId', 'shellId']),
                Schema::object([
                    'shellId' => Schema::string(),
                    'found' => Schema::boolean()->describe('False for an unknown id AND for another session\'s shell — the two answers are identical by design.'),
                    'wasRunning' => Schema::boolean(),
                    'confirmed' => Schema::boolean(),
                    'grouped' => Schema::boolean(),
                    'exitCode' => Schema::integer()->nullable(),
                    'expired' => Schema::boolean(),
                ], ['shellId', 'found', 'wasRunning', 'confirmed', 'grouped', 'expired']),
            ],

            'workflow.list' => [$empty, Schema::object([
                'available' => Schema::boolean(),
                'items' => Schema::arrayOf(Schema::object(['name' => Schema::string()], ['name'])),
            ], ['available', 'items'])],
            'workflow.run' => [
                Schema::object([...$sid, 'name' => Schema::string(128), 'vars' => Schema::map(Schema::any())->describe('Context values for the run; each a string, number or boolean with no whitespace.')], ['sessionId', 'name']),
                $commandRun,
            ],
            'workflow.pause' => [Schema::object(['workflowId' => Schema::string(256)], ['workflowId']), $workflowStatus],
            'workflow.resume' => [Schema::object([...$sid, 'workflowId' => Schema::string(256)], ['sessionId', 'workflowId']), $commandRun],
            'workflow.status' => [Schema::object(['workflowId' => Schema::string(256)], ['workflowId']), $workflowStatus],
            'workflow.runs' => [$sessionOnly, $items(Schema::object([
                'source' => Schema::enum(['command', 'tool']),
                'name' => Schema::string(),
                'status' => Schema::string(),
                'running' => Schema::boolean(),
                'workflowId' => Schema::string(),
                'toolCallId' => Schema::string(),
                'report' => Schema::string(),
            ], ['source', 'name', 'status', 'running']))],

            'workspace.list' => [$empty, $items($workspace)],
            'workspace.open' => [Schema::object([
                'root' => Schema::string(4096),
                'browse' => Schema::boolean()->describe('The root was picked with `fs.listDirs`: refused unless directory browsing is on and the root is inside the browse root.'),
            ], ['root']), $workspace],
            'workspace.close' => [
                Schema::object(['root' => Schema::string(4096), 'force' => Schema::boolean()], ['root']),
                Schema::object(['closed' => Schema::boolean()], ['closed']),
            ],

            'fs.listDirs' => [
                Schema::object([
                    'path' => Schema::string(4096)->describe('Absolute, `~`-relative, or relative to the browse root; omitted = the browse root.'),
                    'showHidden' => Schema::boolean()->describe('Also list dot-directories.'),
                ]),
                Schema::object([
                    'root' => Schema::string()->describe('The browse root.'),
                    'path' => Schema::string()->describe('The listed directory, resolved.'),
                    'parent' => Schema::string()->nullable()->describe('Its parent; null at the browse root.'),
                    'entries' => Schema::arrayOf(Schema::object([
                        'name' => Schema::string(),
                        'path' => Schema::string(),
                        'project' => Schema::boolean()->describe('Holds .git, composer.json, package.json or .candy-code.'),
                        'readable' => Schema::boolean(),
                    ], ['name', 'path', 'project', 'readable'], false)),
                    'truncated' => Schema::boolean(),
                    'readable' => Schema::boolean()->describe('False when the directory itself could not be read (entries is then empty).'),
                    'project' => Schema::boolean(),
                ], ['root', 'path', 'parent', 'entries', 'truncated', 'readable', 'project'], false),
            ],
            'fs.search' => [
                Schema::object([
                    'query' => Schema::string(4096)->describe('The partial typed after `@`; empty lists the front of the project.'),
                    'limit' => Schema::integer(1, FsMethods::SEARCH_MAX_LIMIT)->describe('Rows to return, best-ranked first; capped at '.FsMethods::SEARCH_MAX_LIMIT.'.'),
                    'showHidden' => Schema::boolean()->describe('Consider dot-files in the non-git fallback walk (a git listing is whatever git tracks, hidden included).'),
                ]),
                Schema::object([
                    'root' => Schema::string()->describe('The workspace root the listing is jailed to.'),
                    'matches' => Schema::arrayOf(Schema::string())->describe('Workspace-relative file paths, ranked; insert one after `@`.'),
                    'truncated' => Schema::boolean()->describe('False when the whole ranked list fit in `limit`.'),
                ], ['root', 'matches', 'truncated'], false),
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
            'todo.get' => [$sessionOnly, Schema::object(['items' => Schema::arrayOf(Schema::object(['content' => Schema::string(), 'status' => Schema::enum(\SugarCraft\Code\Todo\TodoStatus::values())], ['content', 'status']))], ['items'])],

            // Plan W6.2, widened by C-WEB-3.f: the submit params embed
            // MediaRequest's own v1 vocabulary map — the same static the
            // GenerateImage tool schema renders — plus the seven keys only
            // the GenerateVideo tool names, selected by `kind`. The model
            // doors and the RPC door cannot drift; MediaMethodsTest pins
            // both equalities.
            'generate.submit' => [
                Schema::object([
                    ...$sid,
                    'kind' => Schema::enum(['image', 'video']),
                    ...\SugarCraft\Code\Media\MediaRequest::paramSchema(),
                    'num_frames' => Schema::integer(1),
                    'duration_seconds' => Schema::number(0),
                    'fps' => Schema::integer(1),
                    'cfg' => Schema::number(0),
                    'cfg2' => Schema::number(0),
                    'motion' => Schema::number(0),
                    'first_frame_image' => Schema::string(4096),
                    'idempotencyKey' => Schema::string(64),
                ], ['sessionId', 'prompt']),
                Schema::object(['jobId' => Schema::string(64)], ['jobId']),
            ],
            'generate.status' => [
                Schema::object([...$sid, 'jobId' => Schema::string(64)], ['sessionId', 'jobId']),
                Schema::object([
                    'state' => Schema::enum(\array_map(
                        static fn (\SugarCraft\Code\Media\MediaJobStatus $status): string => $status->value,
                        \SugarCraft\Code\Media\MediaJobStatus::cases(),
                    )),
                    'progress01' => Schema::number(0),
                    'etaSeconds' => Schema::number(0),
                    'error' => Schema::string(2048),
                    'artifacts' => Schema::arrayOf(Schema::ref(D::MEDIA_REF), 32),
                ], ['state', 'progress01']),
            ],
            'media.fetch' => [
                Schema::object([
                    ...$sid,
                    'artifactId' => Schema::string(128),
                    'path' => Schema::string(4096),
                    'offset' => Schema::integer(0),
                    'limit' => Schema::integer(1, \SugarCraft\Code\Protocol\Methods\MediaMethods::FETCH_MAX_BYTES),
                ], ['sessionId']),
                Schema::object([
                    'artifactId' => Schema::string(128),
                    'offset' => Schema::integer(0),
                    'bytes' => Schema::string(),
                    'totalBytes' => Schema::integer(0),
                    'eof' => Schema::boolean(),
                    'mime' => Schema::string(128),
                ], ['artifactId', 'offset', 'bytes', 'totalBytes', 'eof']),
            ],
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
