<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Protocol\SessionFeed;

/**
 * Permission questions over the wire (Appendix O §6.7).
 *
 * A question is an EVENT (`permission.requested`, durable), so every client
 * following the session sees it, and an answer is a REQUEST any of them may
 * send. THE FIRST VALID ANSWER WINS: a later one is refused `-32009` with
 * `data.kind = "already_resolved"` and the winning settlement in
 * `data.resolved`. The reply reaches exactly the call that was asked about —
 * the `askId` is a hash of the call id, tool and (rewritten) arguments.
 *
 * - `always` is remembered for the session (every later turn's gate), and
 *   covers the other questions already open for the same tool, which are
 *   answered `once` on its strength (listed in `cascaded`);
 * - `reject` with `cascade: true` rejects every other open question of the
 *   session and soft-cancels the turn ("Reject & stop");
 * - `remember: "project"` is refused — permission rules are user-tier only
 *   — and `remember: "user"`, a write to the user's config, is not offered
 *   over the wire yet.
 */
final class PermissionMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('permission.respond', Scope::Approve, 'Answer an open permission question; the first answer wins.', self::respond(...), true));
        $registry->add(MethodSpec::new('permission.pending', Scope::Read, 'The questions still open, for one session or all open sessions.', self::pending(...)));
        $registry->add(MethodSpec::new('permission.rules', Scope::Read, 'The effective permission mode and rules, read-only.', self::rules(...)));
    }

    /** @return array<string, mixed> */
    private static function respond(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $askId = $params->string('askId', 64);
        $reply = PermissionReply::from($params->enum('reply', \array_map(static fn (PermissionReply $r): string => $r->value, PermissionReply::cases())));
        $note = $params->optionalString('note', 2048);
        $remember = $params->has('remember') ? $params->enum('remember', ['session', 'project', 'user']) : null;

        if ($remember === 'project') {
            throw RpcError::of(ErrorCode::Forbidden, 'permission rules are user-tier only; a project cannot be told to allow a tool', 'remember_refused');
        }
        if ($remember === 'user') {
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'remembering an answer in your config is not offered over the wire; answer always for this session, or add the rule to ~/.sugar-crush/settings.json', 'remember_user_unavailable');
        }
        if ($remember === 'session' && $reply === PermissionReply::Once) {
            $reply = PermissionReply::Always;
        }

        $host = $call->server->hub()->get($sessionId)
            ?? throw RpcError::notFound(\sprintf('session %s is not open, so no question of it is open', $sessionId), 'ask_not_found');
        $call->feed($host);

        $asked = null;
        foreach ($host->pendingAsks() as $ask) {
            if ($ask->askId === $askId) {
                $asked = $ask;
            }
        }

        $resolution = $host->answer($askId, $reply, $note);
        if ($resolution === null) {
            $previous = $host->resolution($askId);
            if ($previous !== null) {
                throw RpcError::of(ErrorCode::Conflict, 'this question was already answered', 'already_resolved', ['resolved' => \array_filter([
                    'askId' => $previous->askId,
                    'reply' => $previous->reply?->value,
                    'note' => $previous->note !== '' ? $previous->note : null,
                    'cancelled' => $previous->cancelled ?: null,
                ], static fn (mixed $value): bool => $value !== null)]);
            }

            throw RpcError::notFound(\sprintf('no open question %s', $askId), 'ask_not_found');
        }

        $cascaded = [];
        if ($reply === PermissionReply::Reject && $params->bool('cascade')) {
            foreach ($host->pendingAsks() as $other) {
                if ($host->answer($other->askId, PermissionReply::Reject, 'rejected together with an earlier question') !== null) {
                    $cascaded[] = $other->askId;
                }
            }
            $host->cancelSoft();
        } elseif ($resolution->reply === PermissionReply::Always && $asked !== null) {
            foreach ($host->pendingAsks() as $other) {
                if ($other->tool === $asked->tool && $other->offers(PermissionReply::Always)
                    && $host->answer($other->askId, PermissionReply::Once) !== null) {
                    $cascaded[] = $other->askId;
                }
            }
        }

        return [
            'applied' => true,
            'askId' => $askId,
            'reply' => $resolution->reply?->value,
            'cascaded' => $cascaded,
        ];
    }

    /** @return array<string, mixed> */
    private static function pending(CallContext $call, Params $params): array
    {
        $hub = $call->server->hub();
        $sessionIds = $params->has('sessionId') ? [SessionMethods::sessionId($params)] : $hub->openSessionIds();

        $items = [];
        foreach ($sessionIds as $sessionId) {
            foreach ($hub->get($sessionId)?->pendingAsks() ?? [] as $ask) {
                $items[] = ['sessionId' => $sessionId, ...SessionFeed::askSummary($ask)];
            }
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    private static function rules(CallContext $call, Params $params): array
    {
        $workspace = $call->server->hub()->workspace();
        $backend = $workspace->backend;
        $gate = $backend instanceof EngineBackend ? $backend->permissionGate() : $workspace->permissionGate;

        $host = $params->has('sessionId') ? $call->server->hub()->get(SessionMethods::sessionId($params)) : null;

        return [
            'mode' => ($host?->permissionMode() ?? $gate?->mode())?->value,
            'modeSource' => $gate?->modeSource(),
            'rules' => \array_map(static fn (PermissionRule $rule): array => [
                'pattern' => $rule->pattern,
                'action' => $rule->action->value,
            ], $gate?->rules() ?? []),
            'sessionGrants' => $host?->grants()->patterns() ?? [],
        ];
    }
}
