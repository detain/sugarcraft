<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Share\ShareSession;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Host\SessionSnapshot;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\JsonRpc;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Protocol\TrustAsks;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\Import\ImportFormat;
use SugarCraft\Code\Session\Import\ImportRefusedException;
use SugarCraft\Code\Session\Import\SessionImportLedger;
use SugarCraft\Code\Session\Import\SessionImporter;
use SugarCraft\Code\Util\Exporter;

/**
 * `session.*` lifecycle (Appendix O §6.3): list, create, open and follow,
 * rename, fork, close, delete, export, serve, list and revoke (share pages),
 * and the session's permission mode.
 * Prompting is {@see TurnMethods}.
 *
 * A SESSION'S PERMISSION MODE is the client's to choose — within
 * `ServerConfig::admitsPermissionMode()`: `bypass-permissions` and `dont-ask`
 * answer every question without asking, so over the wire they are refused
 * (`-32020`) unless the operator started the server with `--allow-bypass`.
 */
final class SessionMethods
{
    public const SESSION_ID_MAX = 128;

    /** How many leading hex chars of a token `session.shares` rows echo for display. */
    public const SHARE_TOKEN_PREFIX_CHARS = 8;

    /** Smallest `session.export` page a client may ask for (one 64 KiB block). */
    public const EXPORT_MIN_PAGE_BYTES = 65_536;

    /** Largest `session.export` page a client may ask for (the `tool.output` slice bound). */
    public const EXPORT_MAX_PAGE_BYTES = 1_048_576;

    /**
     * Bytes a paged export keeps off the frame cap: the JSON-RPC envelope,
     * and the headroom one raw control byte needs after escaping (a page of
     * them inflates about six-fold, which would otherwise trip the
     * dispatcher's `too_large` bound again on the shrink pass).
     */
    public const EXPORT_FRAME_HEADROOM_BYTES = 65_536;

    /** A paging revision is one xxh128 hex string; nothing longer is read. */
    private const EXPORT_REVISION_MAX_CHARS = 64;

    /**
     * The byte ceiling for `session.import` content (C-SES-7.e).
     *
     * The server reads an inbound WS frame up to
     * {@see ServerConfig::MAX_CLIENT_FRAME_BYTES} before dispatch (that is
     * the frame cap the `Ws\MessageBuffer` enforces, verified in
     * `src/Server/Ws/`, NOT the 16 MiB outbox watermark in `Outbox.php:40`).
     * A JSON-RPC envelope rides inside that same frame, so the largest
     * transcript body that can arrive is the frame cap less the envelope and
     * one escaped control byte's headroom. The import bound mirrors it: a
     * larger `content` never reaches a method body: the published schema
     * guard (`maxLength`) refuses it with `invalid_params` naming the
     * oversized param before dispatch, and {@see self::import()} re-checks
     * the same ceiling in-body for callers off that path.
     */
    public const IMPORT_MAX_CONTENT_BYTES = ServerConfig::MAX_CLIENT_FRAME_BYTES - self::EXPORT_FRAME_HEADROOM_BYTES;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('session.list', Scope::Read, 'The workspace\'s sessions, newest activity first, paged.', self::list(...)));
        $registry->add(MethodSpec::new('session.create', Scope::Write, 'Create a session and open it.', self::create(...), true));
        $registry->add(MethodSpec::new('session.get', Scope::Read, 'A session\'s snapshot: rows, status, queue, open questions, usage; limit/beforeIndex page the rows.', self::get(...)));
        $registry->add(MethodSpec::new('session.subscribe', Scope::Read, 'Follow a session\'s events from a cursor (replay) or from a snapshot; snapshotLimit pages the reset snapshot.', self::subscribe(...)));
        $registry->add(MethodSpec::new('session.unsubscribe', Scope::Read, 'Stop following a session.', self::unsubscribe(...)));
        $registry->add(MethodSpec::new('session.rename', Scope::Write, 'Rename a session.', self::rename(...), true));
        $registry->add(MethodSpec::new('session.fork', Scope::Write, 'Branch a session into a new one carrying its transcript.', self::fork(...), true));
        $registry->add(MethodSpec::new('session.close', Scope::Write, 'Release a session (refused while a turn runs unless force).', self::close(...), true));
        $registry->add(MethodSpec::new('session.delete', Scope::Write, 'Delete a session and its history.', self::delete(...), true));
        $registry->add(MethodSpec::new('session.export', Scope::Read, 'A session\'s transcript as markdown, json or text; limit/offset page it and revision guards the walk.', self::export(...)));
        $registry->add(MethodSpec::new('session.import', Scope::Write, 'Import an external agent transcript carried in the request; byte-identical re-sends land in the same session.', self::import(...), true));
        $registry->add(MethodSpec::new('session.archive', Scope::Write, 'Move a session to the archive tray; it leaves the default listing.', self::archive(...), true));
        $registry->add(MethodSpec::new('session.unarchive', Scope::Write, 'Restore an archived session to the live listing.', self::unarchive(...), true));
        $registry->add(MethodSpec::new('session.setMode', Scope::Write, 'The permission mode the session\'s next turns run in.', self::setMode(...), true));
        $registry->add(MethodSpec::new('session.share', Scope::Write, 'Publish an HTML snapshot of a session as a self-hosted share page at /s/{token}.', self::share(...), true));
        $registry->add(MethodSpec::new('session.shares', Scope::Read, 'The live share pages of one session, or of every session when sessionId is absent.', self::shares(...)));
        $registry->add(MethodSpec::new('session.unshare', Scope::Write, 'Revoke a share page by token; it 404s from its next request.', self::unshare(...), true));
    }

    public static function sessionId(Params $params): string
    {
        $id = $params->string('sessionId', self::SESSION_ID_MAX);
        if (\preg_match('/^[A-Za-z0-9._-]+$/', $id) !== 1) {
            throw RpcError::invalidParams('sessionId may hold letters, digits, ".", "_" and "-" only');
        }

        return $id;
    }

    /**
     * A session as `session.list` and the `session.*` events describe it.
     *
     * @param array<string, mixed> $row a `sessions` row
     * @return array<string, mixed>
     */
    public static function summary(CallContext $call, array $row): array
    {
        return $call->server->summary($row);
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $limit = $params->int('limit', 20, 1, 200);
        $cursor = $params->optionalString('cursor', self::SESSION_ID_MAX);
        $query = $params->optionalString('query', 256);

        // C-SES-7.e: `archived` is a tri-state. Absent (null) and false both
        // hide the archive (the historical law); true shows ONLY archived
        // rows. The hub applies it on both the FTS and the meta path.
        $archived = $params->has('archived') ? $params->bool('archived') : null;

        // C-SES-2.c: $query reaches FTS through the hub — bodies ranked,
        // snippets attached, LIKE as the degrade — instead of str_contains
        // over what the first 200-row chunk happened to hold. Both paths
        // page $limit MATCHES, so the cursor law is one law.
        $page = $call->server->hub()->list($limit, $cursor, $query, $archived);

        return [
            'items' => \array_map(static fn (array $row): array => self::summary($call, $row), $page['sessions']),
            'nextCursor' => $page['nextCursor'],
        ];
    }

    /** @return array<string, mixed> */
    private static function create(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }
        $name = $params->optionalString('name', 256);
        $mode = self::admittedMode($call, $params);
        // C-SES-7.e: an ephemeral ("Incognito") session opens a live host
        // with NO store row — TranscriptStore saves, the usage-record sink
        // and the settle passes (memory/title) are gated off inside
        // SessionHost, and the hub drops the host on close.
        $ephemeral = $params->bool('ephemeral');

        $host = $call->server->hub()->create(null, $name, ephemeral: $ephemeral);
        $call->server->feedFor($host);
        if ($mode !== null) {
            $host->setPermissionMode($mode);
        }

        return self::announce($call, $host->sessionId(), EventType::SESSION_CREATED);
    }

    /** @return array<string, mixed> */
    private static function get(CallContext $call, Params $params): array
    {
        $host = $call->host(self::sessionId($params));

        return $call->feed($host)->snapshot(
            $params->optionalInt('limit', 1, SessionSnapshot::MAX_PAGE_ROWS),
            $params->optionalInt('beforeIndex', 0),
        );
    }

    /** @return array<string, mixed> */
    private static function subscribe(CallContext $call, Params $params): array
    {
        $sessionId = self::sessionId($params);
        $host = $call->host($sessionId);
        // C-SEC-4: the trust prompt must reach a client before it opens the
        // Context panel — subscribing is the moment a client says it is
        // watching this session, so the same once-per-root discovery
        // `context.sources` runs rides here first, off the cheap launch
        // verdict, not the full measurement.
        self::discoverTrustOnSubscribe($call, $sessionId, $host);
        $afterSeq = $params->optionalInt('afterSeq', 0);
        $mode = $params->enum('mode', [SessionFeed::MODE_FULL, SessionFeed::MODE_NARRATION], SessionFeed::MODE_FULL);
        $snapshotLimit = $params->optionalInt('snapshotLimit', 1, SessionSnapshot::MAX_PAGE_ROWS);

        return $call->feed($host)->subscribe($call->client, $afterSeq, $mode, $snapshotLimit);
    }

    /**
     * Mint (once per root, per target) the project-trust prompts the launch
     * census says are pending, announcing each exactly as
     * {@see ContextMethods::sources()} does; nothing else is read.
     * C-SEC-5.b: this is the widened census from 5.a, not just the MCP
     * verdict — a root with an untrusted `.mcp.json` plus untrusted hooks and
     * settings files opens three prompts on subscribe, and answering one
     * leaves the others open.
     */
    private static function discoverTrustOnSubscribe(CallContext $call, string $sessionId, SessionHost $host): void
    {
        $root = $host->workspace()->root;

        TrustAsks::discoverPending($root, static function (array $record) use ($call, $sessionId): void {
            $call->server->broadcast(EventEnvelope::server(EventType::PERMISSION_ASKED, ['sessionId' => $sessionId] + $record));
        });
    }

    /** @return array<string, mixed> */
    private static function unsubscribe(CallContext $call, Params $params): array
    {
        $feed = $call->server->feed(self::sessionId($params));

        return ['unsubscribed' => $feed !== null && $feed->unsubscribe($call->client)];
    }

    /** @return array<string, mixed> */
    private static function rename(CallContext $call, Params $params): array
    {
        $sessionId = self::sessionId($params);
        $name = \trim($params->string('name', 256));
        $store = $call->server->hub()->workspace()->sessionStore;
        if ($store === null || $store->session($sessionId) === null) {
            throw RpcError::notFound(\sprintf('no session %s', $sessionId), 'session_not_found');
        }
        if ($name === '' || !$store->renameSession($sessionId, $name)) {
            throw RpcError::invalidParams('name was refused');
        }

        return self::announce($call, $sessionId, EventType::SESSION_UPDATED);
    }

    /** @return array<string, mixed> */
    private static function fork(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }
        $sessionId = self::sessionId($params);
        $store = $call->server->hub()->workspace()->sessionStore;
        if ($store === null || $store->session($sessionId) === null) {
            throw RpcError::notFound(\sprintf('no session %s', $sessionId), 'session_not_found');
        }
        // The fork copies what is SAVED: fold whatever the running turn
        // reported first, so the branch is not behind its parent's screen.
        $call->server->hub()->get($sessionId)?->pump();
        $forkId = $store->forkSession($sessionId);

        return self::announce($call, $forkId, EventType::SESSION_CREATED);
    }

    /** @return array<string, mixed> */
    private static function close(CallContext $call, Params $params): array
    {
        $sessionId = self::sessionId($params);
        $force = $params->bool('force');
        $hub = $call->server->hub();
        if (!$hub->isOpen($sessionId)) {
            return ['closed' => false];
        }
        if (!$hub->close($sessionId, $force)) {
            throw RpcError::of(ErrorCode::Busy, 'a turn is running; cancel it or close with force', 'turn_running');
        }
        $call->server->dropFeed($sessionId);

        return ['closed' => true];
    }

    /** @return array<string, mixed> */
    private static function delete(CallContext $call, Params $params): array
    {
        $sessionId = self::sessionId($params);
        $hub = $call->server->hub();
        $store = $hub->workspace()->sessionStore;
        if ($store === null || $store->session($sessionId) === null) {
            throw RpcError::notFound(\sprintf('no session %s', $sessionId), 'session_not_found');
        }
        if ($hub->isOpen($sessionId) && !$hub->close($sessionId, $params->bool('force'))) {
            throw RpcError::of(ErrorCode::Busy, 'a turn is running; cancel it or delete with force', 'turn_running');
        }
        $call->server->dropFeed($sessionId);
        $deleted = $store->deleteSession($sessionId);
        $call->server->broadcast(EventEnvelope::server(EventType::SESSION_DELETED, ['id' => $sessionId]));

        return ['deleted' => $deleted];
    }

    /**
     * `session.export` — the transcript in one of three renderings, in one
     * answer or paged (plan_comparison C-WEB-7.c, source WEB-1-P2).
     *
     * Without `limit` the answer is the historical unpaged `{format, content}`
     * byte-for-byte (the ISS-058 rule {@see \SugarCraft\Code\Tests\Server\SnapshotPagingTest}
     * pins for snapshots), and a transcript over the frame cap still answers
     * the dispatcher's `too_large` — exactly as before, because nothing here
     * changed that path.
     *
     * With `limit` the answer pages by BYTE offset into the rendered text,
     * the way `tool.output` pages a tool's output ({@see ToolMethods::output}),
     * plus the revision guard that transcript paging needs and slice paging
     * does not: a running turn or a compaction can reshape the rendering
     * mid-walk, and JSON pages cut from two different renders splice into
     * garbage, so a page 2+ carrying a stale `revision` is refused with
     * Conflict/`export_changed` and the client restarts from offset 0.
     * The server computes `nextOffset` from the bytes IT cut, so a client
     * never has to byte-count text that `JSON_INVALID_UTF8_SUBSTITUTE` may
     * have rewritten in transit.
     *
     * @return array<string, mixed>
     */
    private static function export(CallContext $call, Params $params): array
    {
        $sessionId = self::sessionId($params);
        $format = $params->enum('format', ['markdown', 'json', 'text'], 'markdown');
        $offset = $params->optionalInt('offset', 0) ?? 0;
        $limit = $params->optionalInt('limit', self::EXPORT_MIN_PAGE_BYTES, self::EXPORT_MAX_PAGE_BYTES);
        $revision = $params->optionalString('revision', self::EXPORT_REVISION_MAX_CHARS);
        $host = $call->server->hub()->get($sessionId);
        if ($host !== null) {
            $rows = $host->history();
        } else {
            $workspace = $call->server->hub()->workspace();
            if ($workspace->sessionStore === null || $workspace->sessionStore->session($sessionId) === null) {
                throw RpcError::notFound(\sprintf('no session %s', $sessionId), 'session_not_found');
            }
            $rows = SessionHost::transcriptsFor($workspace)->load($sessionId);
        }
        $rows = \array_values(\array_filter($rows, static fn (Message $row): bool => $row->userVisible));
        $text = match ($format) {
            'json' => Exporter::toJson($rows),
            'text' => Exporter::toText($rows),
            default => Exporter::toMarkdown($rows),
        };
        if ($limit === null) {
            return ['format' => $format, 'content' => $text];
        }

        $full = \hash('xxh128', $text);
        if ($revision !== null && $revision !== $full) {
            throw RpcError::of(ErrorCode::Conflict, 'the session changed since the export began; start again', 'export_changed');
        }

        $total = \strlen($text);
        $start = self::backToLeadByte($text, \min($offset, $total));
        // Cut at most the requested page, then shrink while the ESCAPED
        // payload would not fit the frame: a page of control bytes escapes
        // about six-fold. The wrapper key in the probe encode is constant
        // overhead far inside the headroom; the escaping — the six-fold part
        // — is exactly what `JsonRpc::encode` puts on the wire.
        $budget = Dispatcher::MAX_SERVER_FRAME_BYTES - self::EXPORT_FRAME_HEADROOM_BYTES;
        $slice = \mb_strcut($text, $start, \min($limit, $total - $start), 'UTF-8');
        while ($slice !== '' && \strlen(JsonRpc::encode(['c' => $slice])) > $budget) {
            $slice = \mb_strcut($text, $start, \intdiv(\strlen($slice), 2), 'UTF-8');
        }
        $next = $start + \strlen($slice);

        return [
            'format' => $format,
            'content' => $slice,
            'offset' => $start,
            'nextOffset' => $next,
            'total' => $total,
            'more' => $next < $total,
            'revision' => $full,
        ];
    }

    /**
     * $offset backed up to (or left at) a UTF-8 lead byte, so a page never
     * opens with the tail of a codepoint the previous page already carried
     * its head of. A byte at an offset past the text needs no backing.
     */
    private static function backToLeadByte(string $text, int $offset): int
    {
        while ($offset > 0 && $offset < \strlen($text) && (\ord($text[$offset]) & 0xC0) === 0x80) {
            --$offset;
        }

        return $offset;
    }

    /**
     * `session.share` — the RPC twin of `/share serve` (SES-3, COMPARISON
     * §6.5): the row stores the HTML rendered AT THIS CALL, never a session
     * reference, so publishing is an explicit act and a later transcript
     * edit cannot reach a page that is already shareable.
     *
     * @return array<string, mixed>
     */
    private static function share(CallContext $call, Params $params): array
    {
        $sessionId = self::sessionId($params);
        $days = $params->optionalInt('days', 1, ShareStore::MAX_EXPIRY_DAYS) ?? ShareSession::DEFAULT_EXPIRY_DAYS;
        $hub = $call->server->hub();
        $host = $hub->get($sessionId);
        if ($host !== null) {
            $rows = $host->history();
        } else {
            $workspace = $hub->workspace();
            if ($workspace->sessionStore === null || $workspace->sessionStore->session($sessionId) === null) {
                throw RpcError::notFound(\sprintf('no session %s', $sessionId), 'session_not_found');
            }
            $rows = SessionHost::transcriptsFor($workspace)->load($sessionId);
        }

        $snapshot = new ShareSession($rows, ShareSession::FORMAT_HTML);
        if ($snapshot->messageCount() === 0) {
            throw RpcError::invalidParams('nothing to share: the session has no visible messages to snapshot');
        }

        $record = self::shareStore($call)->publish($sessionId, $snapshot->format(), $snapshot->serialize(), $days);

        return [
            'token' => $record->token,
            'path' => $record->path(),
            'format' => $record->format,
            'expiresAt' => $record->expiresAt,
        ];
    }

    /**
     * `session.unshare` — the RPC twin of `/share revoke`. Unknown, expired
     * and already-revoked tokens all answer `revoked: false` without
     * distinguishing, matching the page's no-oracle 404.
     *
     * @return array<string, mixed>
     */
    private static function unshare(CallContext $call, Params $params): array
    {
        $token = $params->string('token', ShareStore::TOKEN_BYTES * 2);

        return ['revoked' => self::shareStore($call)->revoke($token)];
    }

    /**
     * `session.import` — run the C-SES-7.a importer in-process over a
     * uploaded transcript body (C-SES-7.e).
     *
     * The idempotency law is the importer's own digest keying, not the
     * dispatcher's `idempotencyKey`: the source key is `rpc:` plus the
     * sha256 of the body's FIRST LINE, so a byte-identical resend of the
     * same transcript lands on the same session with `appended` 0, a grown
     * resend appends only its delta, and a reshaped head is honestly a
     * different source. The event type follows the ledger:
     * `session.created` when the source key was unknown before the call,
     * `session.updated` when it already had a session. A `content` beyond {@see IMPORT_MAX_CONTENT_BYTES} never
     * reaches this body over the wire — the published schema guard refuses
     * it with `invalid_params` at the dispatcher (one ceiling, two
     * chokepoints) — and an importer refusal, malformed shape, unknown
     * format, a shrunken resend, is translated to the typed
     * `import_refused` rather than leaking as an internal error.
     *
     * @return array<string, mixed>
     */
    private static function import(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }

        $store = $call->server->hub()->workspace()->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            throw RpcError::of(ErrorCode::Internal, 'the workspace has no session store', 'no_store');
        }

        try {
            $content = $params->string('content', self::IMPORT_MAX_CONTENT_BYTES);
        } catch (RpcError $tooLong) {
            // Defense-in-depth off the wire: on the WS path the published
            // schema guard (maxLength) refuses the same bound before the
            // method is ever dispatched, so this throw answers the direct
            // callers only — one ceiling, two chokepoints.
            throw RpcError::invalidParams($tooLong->getMessage(), 'import_too_large');
        }

        $format = null;
        if (($formatName = $params->optionalString('format', 32)) !== null) {
            $format = ImportFormat::tryFrom($formatName);
            if ($format === null) {
                throw RpcError::invalidParams(\sprintf(
                    'format must be one of: %s',
                    \implode(', ', \array_map(static fn (ImportFormat $case): string => $case->value, ImportFormat::cases())),
                ));
            }
        }

        /*
         * The sourceKey must be stable across RESENDS of a growing
         * transcript — the ledger's own law turns a changed digest under a
         * known key into a delta append, and a shrunken one into a typed
         * refusal. Hashing the whole body instead (the naive key) would make
         * every resend a new key, and an append indistinguishable from a
         * brand-new import. So the identity is the digest of the FIRST LINE:
         * a growing log preserves its head, a rewritten one does not. The
         * `rpc:` prefix namespaces the key away from file imports.
         */
        $identity = \substr(\hash('sha256', \strtok($content, "\n") ?: $content), 0, 32);
        $sourceKey = 'rpc:' . $identity;

        // The ledger is read BEFORE the import: it answers which session (if
        // any) this source already produced, which is what makes the created
        // vs updated announcement the truth rather than a guess.
        $ledger = SessionImportLedger::atPath($store->path());
        $known = $ledger->lookup($sourceKey);

        try {
            $imported = SessionImporter::new()
                ->withBounds(null, self::IMPORT_MAX_CONTENT_BYTES)
                ->importString($store, $content, $format, $params->optionalString('name', 256), $sourceKey);
        } catch (ImportRefusedException $refused) {
            throw RpcError::invalidParams($refused->getMessage(), 'import_refused');
        }

        if ($known === null) {
            // A fresh import is a session the live server has never hosted;
            // open it through the hub exactly as `session.create` does, so
            // the answered row is interactive and honestly `open`, and the
            // per-session lock is taken through the hub's own lease path.
            $host = $call->server->hub()->open($imported->sessionId);
            $call->server->feedFor($host);
        } elseif ($call->server->hub()->isOpen($imported->sessionId)) {
            // The append rewrote the session's stored transcript underneath
            // any live host. Close and re-open so the in-memory history
            // matches the store — an open host left behind the ledger would
            // clobber the appended rows at its next save.
            $call->server->hub()->close($imported->sessionId);
            $call->server->feedFor($call->server->hub()->open($imported->sessionId));
        }

        return self::announce(
            $call,
            $imported->sessionId,
            $known !== null ? EventType::SESSION_UPDATED : EventType::SESSION_CREATED,
        );
    }

    /** @return array<string, mixed> */
    private static function archive(CallContext $call, Params $params): array
    {
        return self::setArchived($call, $params, true);
    }

    /** @return array<string, mixed> */
    private static function unarchive(CallContext $call, Params $params): array
    {
        return self::setArchived($call, $params, false);
    }

    /**
     * `session.archive` / `session.unarchive` — flag one stored session
     * out of, or back into, the default listing (C-SES-7.e, wrapping the
     * existing `EnhancedSessionStore::archive()`/`unarchive()`). Re-asking
     * for the state a session already holds is a no-op answered with the
     * fresh summary, never a refusal: the flag write is `WHERE`-guarded in
     * the store, so the repeat costs nothing and the announce keeps every
     * follower honest.
     *
     * @return array<string, mixed>
     */
    private static function setArchived(CallContext $call, Params $params, bool $archived): array
    {
        $sessionId = self::sessionId($params);
        $store = $call->server->hub()->workspace()->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            throw RpcError::of(ErrorCode::Internal, 'the workspace has no session store', 'no_store');
        }

        if ($store->session($sessionId) === null) {
            throw RpcError::notFound(\sprintf('no session %s', $sessionId), 'session_not_found');
        }

        if ($archived) {
            $store->archive($sessionId);
        } else {
            $store->unarchive($sessionId);
        }

        return self::announce($call, $sessionId, EventType::SESSION_UPDATED);
    }

    /**
     * `session.shares` — the read twin of `session.share` / `session.unshare`
     * (C-SES-3, for the web Share/Revoke menu): the LIVE shares of one
     * session, or of every session when `sessionId` is absent. Rows carry the
     * full token exactly as `session.share`'s answer does — the method is
     * token-scoped like the page it lists, not a public register — beside a
     * short display prefix, the page path, expiry, format, and the owning
     * session's id and name.
     *
     * A `sessionId` no session owns answers the empty list, not not-found:
     * the listing states what the shares table holds, and a deleted session
     * has its live shares revoked with it, so an honest empty IS the whole
     * truth about it.
     *
     * @return array<string, mixed>
     */
    private static function shares(CallContext $call, Params $params): array
    {
        $sessionId = $params->has('sessionId') ? self::sessionId($params) : null;

        $rows = self::shareStore($call)->liveAll();
        if ($sessionId !== null) {
            $rows = \array_values(\array_filter(
                $rows,
                static fn (array $row): bool => $row['record']->sessionId === $sessionId,
            ));
        }

        return ['items' => \array_map(static fn (array $row): array => [
            'sessionId' => $row['record']->sessionId,
            'sessionName' => $row['sessionName'],
            'token' => $row['record']->token,
            'tokenPrefix' => \substr($row['record']->token, 0, self::SHARE_TOKEN_PREFIX_CHARS),
            'path' => $row['record']->path(),
            'format' => $row['record']->format,
            'expiresAt' => $row['record']->expiresAt,
        ], $rows)];
    }

    /**
     * The `shares` table lives in the workspace's own session database, so
     * a share published over the wire is readable by the `serve` process
     * that serves `/s/...` without any second store to keep in step.
     */
    private static function shareStore(CallContext $call): ShareStore
    {
        $store = $call->server->hub()->workspace()->sessionStore;
        if ($store === null) {
            throw RpcError::of(ErrorCode::Internal, 'this server has no session database to publish into', 'share_store_unavailable');
        }

        return ShareStore::atPath($store->path());
    }

    /** @return array<string, mixed> */
    private static function setMode(CallContext $call, Params $params): array
    {
        $host = $call->host(self::sessionId($params));
        $mode = self::admittedMode($call, $params) ?? throw RpcError::invalidParams('permissionMode is required');
        $host->setPermissionMode($mode);
        self::announce($call, $host->sessionId(), EventType::SESSION_UPDATED);

        return ['permissionMode' => $mode->value];
    }

    /**
     * The `permissionMode` param, refused (`-32020`) when the server does not
     * admit it over the wire.
     */
    private static function admittedMode(CallContext $call, Params $params): ?PermissionMode
    {
        $raw = $params->optionalString('permissionMode', 64);
        if ($raw === null) {
            return null;
        }
        $mode = PermissionMode::tryFrom($raw) ?? throw RpcError::invalidParams(\sprintf(
            'permissionMode must be one of: %s',
            \implode(', ', \array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases())),
        ));
        if (!$call->server->config()->admitsPermissionMode($mode)) {
            throw RpcError::of(
                ErrorCode::PermissionModeRefused,
                \sprintf('%s answers every permission question without asking; this server was not started with --allow-bypass', $mode->value),
                'permission_mode_refused',
                ['permissionMode' => $mode->value],
            );
        }

        return $mode;
    }

    /**
     * Tell every client about $sessionId and return its summary.
     *
     * @return array<string, mixed>
     */
    private static function announce(CallContext $call, string $sessionId, string $type): array
    {
        $summary = $call->server->summaryOf($sessionId);
        $call->server->broadcast(EventEnvelope::server($type, $summary));

        return $summary;
    }
}
