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
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Util\Exporter;

/**
 * `session.*` lifecycle (Appendix O §6.3): list, create, open and follow,
 * rename, fork, close, delete, export, serve and revoke (share pages), and
 * the session's permission mode.
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
        $registry->add(MethodSpec::new('session.export', Scope::Read, 'A session\'s transcript as markdown, json or text.', self::export(...)));
        $registry->add(MethodSpec::new('session.setMode', Scope::Write, 'The permission mode the session\'s next turns run in.', self::setMode(...), true));
        $registry->add(MethodSpec::new('session.share', Scope::Write, 'Publish an HTML snapshot of a session as a self-hosted share page at /s/{token} (SES-3).', self::share(...), true));
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

        $hub = $call->server->hub();
        if ($query === null) {
            $page = $hub->list($limit, $cursor);
        } else {
            // Filtered before paging, so a page is $limit MATCHES.
            $page = ['sessions' => [], 'nextCursor' => null];
            $after = $cursor;
            do {
                $chunk = $hub->list(200, $after);
                foreach ($chunk['sessions'] as $row) {
                    $haystack = \strtolower((string) ($row['name'] ?? '') . ' ' . (string) ($row['id'] ?? '') . ' ' . (string) ($row['last_preview'] ?? ''));
                    if (\str_contains($haystack, \strtolower($query))) {
                        if (\count($page['sessions']) === $limit) {
                            $page['nextCursor'] = (string) $page['sessions'][$limit - 1]['id'];
                            break 2;
                        }
                        $page['sessions'][] = $row;
                    }
                }
                $after = $chunk['nextCursor'];
            } while ($after !== null);
        }

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

        $host = $call->server->hub()->create(null, $name);
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
        $host = $call->host(self::sessionId($params));
        $afterSeq = $params->optionalInt('afterSeq', 0);
        $mode = $params->enum('mode', [SessionFeed::MODE_FULL, SessionFeed::MODE_NARRATION], SessionFeed::MODE_FULL);
        $snapshotLimit = $params->optionalInt('snapshotLimit', 1, SessionSnapshot::MAX_PAGE_ROWS);

        return $call->feed($host)->subscribe($call->client, $afterSeq, $mode, $snapshotLimit);
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
        if ($store === null || $store->getSession($sessionId) === null) {
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
        if ($store === null || $store->getSession($sessionId) === null) {
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
        if ($store === null || $store->getSession($sessionId) === null) {
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

    /** @return array<string, mixed> */
    private static function export(CallContext $call, Params $params): array
    {
        $sessionId = self::sessionId($params);
        $format = $params->enum('format', ['markdown', 'json', 'text'], 'markdown');
        $host = $call->server->hub()->get($sessionId);
        if ($host !== null) {
            $rows = $host->history();
        } else {
            $workspace = $call->server->hub()->workspace();
            if ($workspace->sessionStore === null || $workspace->sessionStore->getSession($sessionId) === null) {
                throw RpcError::notFound(\sprintf('no session %s', $sessionId), 'session_not_found');
            }
            $rows = SessionHost::transcriptsFor($workspace)->load($sessionId);
        }
        $rows = \array_values(\array_filter($rows, static fn (Message $row): bool => $row->userVisible));

        return ['format' => $format, 'content' => match ($format) {
            'json' => Exporter::toJson($rows),
            'text' => Exporter::toText($rows),
            default => Exporter::toMarkdown($rows),
        }];
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
            if ($workspace->sessionStore === null || $workspace->sessionStore->getSession($sessionId) === null) {
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
