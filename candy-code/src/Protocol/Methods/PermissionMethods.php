<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Hooks\BuiltIn\AuditLogReader;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SavedRules;
use SugarCraft\Code\Permissions\SessionPermissionMemo;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\SessionFeed;

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
 *   covers the other questions already open that the remembered grant
 *   covers (the pattern, or the identical call), which are answered `once`
 *   on its strength (listed in `cascaded`);
 * - `reject` with `cascade: true` rejects every other open question of the
 *   session and soft-cancels the turn ("Reject & stop");
 * - `remember: "project"` is refused — permission rules are user-tier only;
 *   `remember: "user"` answers `always` and saves the question's patterns as
 *   allow-rules in the user config through {@see SavedRules} (the TUI `p`
 *   set, effective next launch), and its notice rides back in `saved`;
 * - since ISS-004 a `sessionId` that names no open session is tried as a
 *   RELAYED BACKGROUND QUESTION (`bg.*` sessions answer through the
 *   supervisor's answer file); the same first-answer-wins law applies,
 *   `cascade` on one is refused `cascade_unavailable`, and `remember: "user"`
 *   on one is refused `remember_user_unavailable` — a relayed answer carries
 *   no session grant to persist, so there is nothing to save.
 *
 * `permission.audit` (C-SEC-6.d) answers a different question — what has
 * ALREADY been decided — by reading {@see \SugarCraft\Code\Hooks\BuiltIn\AuditHook}'s
 * redacted log through {@see AuditLogReader}. It sits at `Scope::Approve`,
 * deliberately tighter than the gap's original Read: the log spans every
 * session and every project root of this user, so a viewer who cannot answer
 * one permission question should not read its whole history either.
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
        $registry->add(MethodSpec::new('permission.rules', Scope::Read, 'The effective permission mode and rules, read-only; saved user-tier rows carry their tier and 1-based saved index.', self::rules(...)));
        $registry->add(MethodSpec::new('permission.forget', Scope::Approve, 'Remove one saved permission rule from the user config, addressed by its pattern or by its 1-based saved index.', self::forget(...), true));
        $registry->add(MethodSpec::new('permission.audit', Scope::Approve, 'Recent tool-call decisions from this user\'s redacted audit log tail; a bounded read, every session unless one is named.', self::audit(...)));
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
        $rememberUser = $remember === 'user';
        if (($remember === 'session' || $rememberUser) && $reply === PermissionReply::Once) {
            // A remember that reaches past this single call implies the
            // session grant: `remember: "user"` answers `always` first (the
            // C-SEC-6.c wire verdict "Always (save)") and persists on top.
            $reply = PermissionReply::Always;
        }

        $host = $call->server->hub()->get($sessionId);
        if ($host === null) {
            // ISS-004: not a hosted session — it may be a background daemon
            // whose question this server is relaying. Those live outside the
            // hub, on the supervisor's ask/answer files; a sessionId that is
            // neither stays the same `ask_not_found` it always was.
            return self::respondRelayed($call, $sessionId, $askId, $reply, $note, $params, $rememberUser);
        }
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
            // Only what the grant just remembered covers — the pattern
            // (`Bash(git status *)`) or the identical call — never the tool as
            // a whole: `always` on `git status` must not answer an open
            // `git push`.
            $grants = $host->grants();
            foreach ($host->pendingAsks() as $other) {
                if ($other->offers(PermissionReply::Always)
                    && $grants->allows($other->tool, $other->arguments, $host->workspace()->root)
                    && $host->answer($other->askId, PermissionReply::Once) !== null) {
                    $cascaded[] = $other->askId;
                }
            }
        }

        $saved = null;
        if ($rememberUser && $resolution->reply === PermissionReply::Always && $asked !== null) {
            $saved = self::saveAnsweredGrant($asked, $host);
        }

        return [
            'applied' => true,
            'askId' => $askId,
            'reply' => $resolution->reply?->value,
            'cascaded' => $cascaded,
            'saved' => $saved,
        ];
    }

    /**
     * The persistence behind `remember: "user"` on a hosted question.
     *
     * Exactly the TUI `p` set: the same {@see SessionPermissionMemo::patternsFor()}
     * derivation, gated the same way — only a question whose `always` was
     * offered has a grant to persist, and the patterns are saved only when a
     * configured rule shape reproduces the grant they generalise (an
     * untranslatable one saves nothing rather than saving a lie). The notice
     * {@see SavedRules::append()} answers with rides back to the client in
     * `saved`; a question with nothing to save answers `saved: null` rather
     * than a persisted note for zero patterns.
     */
    private static function saveAnsweredGrant(PendingAsk $asked, mixed $host): ?string
    {
        if (!$asked->offers(PermissionReply::Always)) {
            return null;
        }

        $root = $host->workspace()->root;
        $patterns = SessionPermissionMemo::patternsFor($asked->tool, $asked->arguments, $root);
        if ($patterns === []) {
            return null;
        }
        $full = new ToolCall($asked->tool, $asked->arguments);
        $translatable = false;
        foreach ($patterns as $pattern) {
            if ((new PermissionRule($pattern, PermissionAction::Allow))->matches($full, true, $root)) {
                $translatable = true;
                break;
            }
        }
        if (!$translatable) {
            return null;
        }

        $writer = $host->workspace()->service(SettingsWriter::class);

        return (new SavedRules($writer instanceof SettingsWriter ? $writer : null))->append($patterns);
    }

    /**
     * Answer to a question relayed by a background daemon (ISS-004).
     *
     * The narrow edges, stated rather than papered over: `cascade` is refused
     * (a daemon holds one question at a time and the server keeps no roster
     * of its siblings' opens to reject alongside), and `always` is accepted
     * and lands once-strength — the daemon's approver is a bool, and the
     * relayed event already names its options as `["once","reject"]`. The
     * echo tells the truth about that (F5): the row written, the settled
     * event and this result all carry `once` for a relayed `always`, because
     * `once` is the strength that was applied. That includes the shape where
     * `remember: "session"` coerced the client's `once` up to `always`
     * before routing — coercion is meaningless on a one-question record, so
     * the answer comes back at the strength the daemon granted. `remember:
     * "user"` is refused `remember_user_unavailable`: there is no grant on a
     * relayed question for a saved rule to reproduce.
     *
     * @return array<string, mixed>
     */
    private static function respondRelayed(CallContext $call, string $sessionId, string $askId, PermissionReply $reply, ?string $note, Params $params, bool $rememberUser): array
    {
        $events = $call->server->background();
        if ($events === null) {
            throw RpcError::notFound(\sprintf('session %s is not open, so no question of it is open', $sessionId), 'ask_not_found');
        }
        if ($reply === PermissionReply::Reject && $params->bool('cascade')) {
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'a relayed background question is answered one at a time; a background session holds no siblings to reject with it', 'cascade_unavailable');
        }
        if ($rememberUser) {
            // The daemon's approver is a bool — the answer lands once-strength
            // and records no session grant, so there is nothing here that a
            // saved rule could reproduce. Refuse loudly; the question stays open.
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'a relayed background question is answered by its daemon, not by a grant; remembering it in your config is not offered for one', 'remember_user_unavailable');
        }

        $outcome = $events->answerAsk($sessionId, $askId, $reply->value, $note ?? '');
        if ($outcome['status'] === 'unknown') {
            throw RpcError::notFound(\sprintf('no open question %s', $askId), 'ask_not_found');
        }
        if ($outcome['status'] === 'already') {
            throw RpcError::of(ErrorCode::Conflict, 'this question was already answered', 'already_resolved', ['resolved' => [
                'askId' => $askId,
                'reply' => $outcome['reply'] ?? null,
            ]]);
        }

        return [
            'applied' => true,
            'askId' => $askId,
            'reply' => $outcome['reply'] ?? $reply->value,
            'cascaded' => [],
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

        // ISS-004: relayed background questions are open questions of this
        // server too — a client polling `permission.pending` sees them beside
        // the hosted sessions', each named by its background id.
        $background = $call->server->background();
        if ($background !== null) {
            foreach ($background->pendingAsks() as $ask) {
                if (!$params->has('sessionId') || $ask['sessionId'] === SessionMethods::sessionId($params)) {
                    $items[] = $ask;
                }
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

        // C-SEC-6.c: a rule the user SAVED through `p` or `remember: "user"`
        // is told apart from a hand-edited config row, and numbered with the
        // SAME 1-based count `permission.forget` and `/permissions saved`
        // address by. A saved file that cannot be read degrades every row to
        // `config` rather than sinking this read-only audit view — the launch
        // already refuses a corrupt file with its own warning.
        $savedIndex = [];
        try {
            $writer = $workspace->service(SettingsWriter::class);
            if ($writer instanceof SettingsWriter) {
                foreach ((new SavedRules($writer))->list() as $position => $row) {
                    if ($row['action'] === PermissionAction::Allow->value && !isset($savedIndex[$row['pattern']])) {
                        $savedIndex[$row['pattern']] = $position + 1;
                    }
                }
            }
        } catch (\Throwable) {
            $savedIndex = [];
        }

        return [
            'mode' => ($host?->permissionMode() ?? $gate?->mode())?->value,
            'modeSource' => $gate?->modeSource(),
            'rules' => \array_map(static function (PermissionRule $rule) use ($savedIndex): array {
                $user = $rule->action === PermissionAction::Allow && isset($savedIndex[$rule->pattern]);

                return [
                    'pattern' => $rule->pattern,
                    'action' => $rule->action->value,
                    'tier' => $user ? 'user' : 'config',
                    'savedIndex' => $user ? $savedIndex[$rule->pattern] : null,
                ];
            }, $gate?->rules() ?? []),
            'sessionGrants' => $host?->grants()->patterns() ?? [],
        ];
    }

    /**
     * Take one saved rule back out (C-SEC-6.c, the wire twin of
     * `/permissions forget`). `Scope::Approve`: removing a saved allow-rule
     * narrows what the agent may do without asking — strictly the safe
     * direction — but it edits the operator's config, and only an approver
     * may do that from the wire. Unlike the kinder append, a failed removal
     * REFUSES (a half-reported forget is the lie the permission screen exists
     * to prevent); a pattern or number that matches nothing is an honest
     * empty `removed` that wrote nothing.
     *
     * @return array<string, mixed>
     */
    private static function forget(CallContext $call, Params $params): array
    {
        $pattern = $params->optionalString('pattern', 512);
        $index = $params->optionalInt('index', 1);
        if (($pattern === null) === ($index === null)) {
            throw RpcError::of(ErrorCode::InvalidParams, 'permission.forget takes exactly one of pattern or index', 'forget_target');
        }

        $writer = $call->server->hub()->workspace()->service(SettingsWriter::class);
        if (!$writer instanceof SettingsWriter) {
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'this server registers no settings write door; the saved rules cannot be edited over the wire', 'no_writer');
        }

        $rules = new SavedRules($writer);
        try {
            $gone = $rules->remove($index ?? $pattern);
        } catch (\Throwable $e) {
            throw RpcError::of(ErrorCode::Internal, \sprintf('forgetting the saved rule failed: %s', $e->getMessage()), 'forget_failed');
        }

        return [
            'removed' => \array_map(static fn (array $row): array => [
                'pattern' => (string) ($row['pattern'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
            ], $gone),
            'path' => (string) $rules->path(),
        ];
    }

    /**
     * The recent decisions, newest last (C-SEC-6.d — the web panel's data
     * arm). Deliberately NOT tied to a hosted session: the audit log is
     * this process's own per-user trail, and a server may be asked about it
     * before (or without) any session. `unreadable` answers only when the
     * named log exists but refused to open, so "no decisions yet" and "the
     * trail is there and I could not read it" stay two different sentences;
     * a missing file is the honest empty list the class note promises.
     *
     * @return array<string, mixed>
     */
    private static function audit(CallContext $call, Params $params): array
    {
        $sessionId = $params->has('sessionId') ? SessionMethods::sessionId($params) : null;
        $limit = $params->optionalInt('limit', 1, AuditLogReader::MAX_ROWS) ?? AuditLogReader::DEFAULT_ROWS;

        return AuditLogReader::tail($limit, null, $sessionId);
    }
}
