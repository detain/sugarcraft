<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\AgentMessage;
use SugarCraft\Crush\Agents\Live\AgentTranscriptTail;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Host\AgentResume;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Host\SessionHost;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * `agents.*` (Appendix O §6.3, roadmap O-6c): the agent roster, the live tree
 * of the sub-agents a session's turns delegated to, and the Agent View's two
 * halves for a browser — a run's own transcript, and talking to it.
 *
 * WHAT A RUN IS HERE. Every delegated run announces itself on the session's
 * `subagent.*` events (the P-B v2 beat, {@see SubAgentActivity::toArray()}).
 * {@see runs()} folds them into one row per run: what the attached feed has
 * heard live, over what the session's durable log still holds — so a run that
 * finished before this server started (or before its feed was attached) is
 * still in the tree, and can still be read and continued. A beat names its
 * `task` and `description` only on `started`; the fold keeps them.
 *
 * TALKING TO A RUN (Appendix P §5.3/§5.5, P-D). A run that is still going
 * reads its mailbox at its next step boundary, so `agents.message` and
 * `agents.control` write a `from: user` line signed with this launch's key
 * ({@see \SugarCraft\Crush\Host\WorkspaceContext::agentInbox()}) — the same
 * route the TUI's Agent View composer takes, and the one that reaches every
 * run alike: a lone Task in the turn's child, a parallel member in its own
 * process, a detached follow-up. A run that has FINISHED has nobody left to
 * read a mailbox: a message, or `resume`, continues it instead
 * ({@see AgentResume}, "cold resume"), and its beats come back as this
 * session's `subagent.*` events.
 */
final class AgentsMethods
{
    /** Bytes of a run's transcript log one `agents.transcript` page reads at most. */
    public const TRANSCRIPT_PAGE_BYTES = AgentTranscriptTail::MAX_BYTES_PER_READ;

    /** Durable events {@see runs()} reads back at most, newest pages kept. */
    private const LOG_SCAN_LIMIT = 20_000;

    /** Bytes of one message to a run. */
    private const MAX_MESSAGE_BYTES = 65_536;

    /**
     * The `agents.control` verbs on the wire: the inbox's control verbs plus
     * {@see AgentInbox::BACKGROUND_VERB} — the TUI Agent View's `Ctrl+X b`
     * (roadmap P-E3), which promotes the running run to a background session.
     * The inbox keeps that verb out of its own list; the wire offers it here,
     * and the schema's enum is this list.
     */
    public const CONTROL_VERBS = [...AgentInbox::CONTROL_VERBS, AgentInbox::BACKGROUND_VERB];

    /** The fields a transcript item may carry over the wire, by type. */
    private const ITEM_FIELDS = ['text', 'callId', 'tool', 'args', 'ok', 'content', 'truncated', 'status', 'outcome', 'error', 'from', 'mode', 'msgId', 'step'];

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('agents.list', Scope::Read, 'The agents a turn can delegate to.', self::list(...)));
        $registry->add(MethodSpec::new('agents.subtree', Scope::Read, 'The sub-agents a session\'s turns have delegated to, with their latest activity.', self::subtree(...)));
        $registry->add(MethodSpec::new('agents.transcript', Scope::Read, 'A delegated run\'s own transcript, read from a byte offset.', self::transcript(...)));
        $registry->add(MethodSpec::new('agents.message', Scope::Write, 'Message a delegated run: into its mailbox while it runs, as a follow-up once it finished.', self::message(...), true));
        $registry->add(MethodSpec::new('agents.control', Scope::Write, 'Cancel, pause, resume or background a delegated run.', self::control(...), true));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $agents = $call->server->hub()->workspace()->agentManager?->all() ?? [];

        return ['items' => \array_values(\array_map(static fn (Agent $agent): array => [
            'name' => $agent->name,
            'description' => $agent->description,
            'model' => $agent->model,
            'provider' => $agent->provider,
            'permissionMode' => $agent->permissionMode->value,
            'active' => $agent->isActive,
        ], $agents))];
    }

    /** @return array<string, mixed> */
    private static function subtree(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));

        return ['items' => \array_values(self::runs($call, $host))];
    }

    /** @return array<string, mixed> */
    private static function transcript(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);
        $agentId = self::agentId($params);
        $offset = $params->int('offset', 0, 0);
        $run = self::runs($call, $host)[$agentId] ?? null;

        $path = self::logPath($sessionId, $agentId, $run);
        if ($path === null) {
            throw RpcError::notFound(\sprintf('no transcript for %s', $agentId), 'transcript_not_found');
        }

        $limit = $params->int('limit', self::TRANSCRIPT_PAGE_BYTES, 1024, self::TRANSCRIPT_PAGE_BYTES);
        [$items, $next, $size] = self::readPage($path, $offset, $limit);

        return [
            'agentId' => $agentId,
            'offset' => $next,
            'items' => $items,
            'more' => $next < $size,
            'finished' => self::isFinished($run),
        ];
    }

    /** @return array<string, mixed> */
    private static function message(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);
        $agentId = self::agentId($params);
        $text = \trim($params->string('text', self::MAX_MESSAGE_BYTES));
        if ($text === '') {
            throw RpcError::invalidParams('text must not be empty', 'empty_message');
        }
        $run = self::runs($call, $host)[$agentId]
            ?? throw RpcError::notFound(\sprintf('%s is not a delegated run of this session', $agentId), 'agent_not_found');

        if (self::isFinished($run)) {
            return self::followUp($host, $run, $text);
        }

        $message = self::inbox($host)->send($agentId, AgentMessage::fromUser($text));

        return ['agentId' => $agentId, 'status' => 'queued', 'msgId' => $message->msgId];
    }

    /** @return array<string, mixed> */
    private static function control(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);
        $agentId = self::agentId($params);
        $verb = $params->enum('verb', self::CONTROL_VERBS);
        $run = self::runs($call, $host)[$agentId]
            ?? throw RpcError::notFound(\sprintf('%s is not a delegated run of this session', $agentId), 'agent_not_found');

        if (self::isFinished($run)) {
            if ($verb === 'resume') {
                return self::followUp($host, $run, \trim((string) ($params->optionalString('text', self::MAX_MESSAGE_BYTES) ?? '')));
            }

            throw RpcError::of(ErrorCode::Conflict, \sprintf('%s has already finished', self::name($run)), 'agent_finished');
        }

        // As in the TUI (P-E3): a nested run's Task call belongs to the run
        // that made it, so only a run the conversation delegated is handed on.
        if ($verb === AgentInbox::BACKGROUND_VERB && \is_string($run['parentAgentId'] ?? null) && $run['parentAgentId'] !== '') {
            throw RpcError::of(ErrorCode::Conflict, \sprintf('%s is a nested run; only a run this conversation delegated can move to the background', self::name($run)), 'agent_nested');
        }

        $message = self::inbox($host)->control($agentId, $verb);

        return ['agentId' => $agentId, 'status' => 'queued', 'msgId' => $message->msgId];
    }

    // ── runs ───────────────────────────────────────────────────────────

    /**
     * Every delegated run of $host's session, by id: the latest beat of each,
     * from the session's durable log and then what the feed heard live.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function runs(CallContext $call, SessionHost $host): array
    {
        $runs = [];
        $log = $host->events();
        if ($log !== null) {
            $sessionId = $host->sessionId();
            $after = \max(0, $log->latestSeq($sessionId) - self::LOG_SCAN_LIMIT);
            try {
                while (true) {
                    $page = $log->since($sessionId, $after);
                    if ($page === []) {
                        break;
                    }
                    foreach ($page as $row) {
                        if (\in_array($row['type'], [SessionEvent::SUBAGENT_STARTED, SessionEvent::SUBAGENT_FINISHED], true)) {
                            $runs = self::fold($runs, $row['payload']);
                        }
                        $after = $row['seq'];
                    }
                }
            } catch (\Throwable) {
                // An unreadable log leaves the live half.
            }
        }
        foreach ($call->feed($host)->subagents() as $beat) {
            if (\is_array($beat)) {
                $runs = self::fold($runs, $beat);
            }
        }

        return $runs;
    }

    /**
     * $runs with $beat folded in. Beats are folded in the order they were
     * heard, so the later one wins — but the fields only `started` carries
     * survive a later beat that leaves them empty.
     *
     * @param array<string, array<string, mixed>> $runs
     * @param array<string, mixed> $beat
     * @return array<string, array<string, mixed>>
     */
    public static function fold(array $runs, array $beat): array
    {
        $id = $beat['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            return $runs;
        }
        unset($beat['turnId']);
        $previous = $runs[$id] ?? null;
        if ($previous !== null) {
            foreach (['task', 'description', 'name', 'parentCallId', 'model'] as $field) {
                if (($beat[$field] ?? '') === '' && ($previous[$field] ?? '') !== '') {
                    $beat[$field] = $previous[$field];
                }
            }
        }
        $runs[$id] = $beat;

        return $runs;
    }

    /** @param array<string, mixed>|null $run */
    private static function isFinished(?array $run): bool
    {
        return ($run['op'] ?? null) === SubAgentActivity::OP_FINISHED;
    }

    /** @param array<string, mixed> $run */
    private static function name(array $run): string
    {
        $name = $run['name'] ?? '';

        return \is_string($name) && $name !== '' ? $name : (string) ($run['id'] ?? '');
    }

    private static function agentId(Params $params): string
    {
        $agentId = $params->string('agentId', 128);
        if (\preg_match('/^[A-Za-z0-9._-]{1,128}$/', $agentId) !== 1) {
            throw RpcError::invalidParams('agentId is a run id: letters, digits, dot, underscore, dash', 'invalid_agent_id');
        }

        return $agentId;
    }

    private static function inbox(SessionHost $host): AgentInbox
    {
        return $host->workspace()->agentInbox($host->sessionId())
            ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this server keeps no agent mailboxes (no owned home directory)', 'inbox_unavailable');
    }

    /**
     * The log of run $agentId: the path its beats announced when it is one
     * this class would have written, else where a run of this session writes
     * one — whichever exists.
     *
     * @param array<string, mixed>|null $run
     */
    private static function logPath(string $sessionId, string $agentId, ?array $run): ?string
    {
        $announced = $run['transcriptLog'] ?? null;
        if (\is_string($announced) && SubAgentTranscriptLog::isLogPath($announced) && \is_file($announced)) {
            return $announced;
        }

        try {
            $path = SubAgentTranscriptLog::forRun($sessionId, $agentId)->path();
        } catch (\RuntimeException) {
            return null;
        }

        return \is_file($path) ? $path : null;
    }

    /**
     * The items of the complete lines of the log at $path from byte $offset,
     * at most $limit bytes of it, and the offset just past the last whole
     * line read — where the next page starts. A line the writer has not
     * finished is read again next time, never parsed half-written; an offset
     * that is not a line start (a client's guess) costs only the partial line,
     * which does not decode. A window holding no newline at all is skipped
     * once it is wider than any line the writer produces.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int} items, next offset, file size
     */
    private static function readPage(string $path, int $offset, int $limit): array
    {
        // The run may still be appending: never a size PHP's stat cache kept.
        \clearstatcache(true, $path);
        $size = @\filesize($path);
        $size = \is_int($size) ? $size : 0;
        $offset = \min($offset, $size);
        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            return [[], $offset, $size];
        }
        try {
            $chunk = \fseek($handle, $offset) === 0 ? \fread($handle, $limit) : false;
        } finally {
            \fclose($handle);
        }
        if (!\is_string($chunk) || $chunk === '') {
            return [[], $offset, $size];
        }
        $end = \strrpos($chunk, "\n");
        if ($end === false) {
            $skip = \strlen($chunk) >= $limit && $limit > SubAgentTranscriptLog::MAX_LINE_BYTES;

            return [[], $skip ? $offset + \strlen($chunk) : $offset, $size];
        }

        $items = [];
        foreach (\explode("\n", \substr($chunk, 0, $end)) as $line) {
            try {
                $item = \json_decode($line, true, 64, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (\is_array($item) && \in_array($item['t'] ?? null, SubAgentTranscriptLog::TYPES, true)) {
                $items[] = self::wireItem($item);
            }
        }

        return [$items, $offset + $end + 1, $size];
    }

    /**
     * One log item as the wire carries it: its type, time and known fields,
     * every string scrubbed of terminal escapes, control bytes and
     * Private-Use codepoints — a model and its tools wrote all of it.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function wireItem(array $item): array
    {
        $wire = ['t' => (string) $item['t']];
        if (\is_int($item['ts'] ?? null) || \is_float($item['ts'] ?? null)) {
            $wire['ts'] = (int) $item['ts'];
        }
        foreach (self::ITEM_FIELDS as $field) {
            if (\array_key_exists($field, $item) && $item[$field] !== null) {
                $wire[$field] = self::scrub($item[$field]);
            }
        }

        return $wire;
    }

    private static function scrub(mixed $value): mixed
    {
        if (\is_string($value)) {
            $value = \mb_scrub($value, 'UTF-8');
            $value = \preg_replace('/\x1b\[[\x30-\x3f]*[\x20-\x2f]*[\x40-\x7e]?|\x1b\][^\x07\x1b]*(?:\x07|\x1b\x5c)?|\x1b/', '', $value) ?? '';

            return \preg_replace('/[\x00-\x08\x0b-\x1f\x7f]|[\x{80}-\x{9f}]|[\x{E000}-\x{F8FF}]/u', '', $value) ?? '';
        }
        if (\is_array($value)) {
            $clean = [];
            foreach ($value as $key => $inner) {
                $clean[\is_string($key) ? (string) self::scrub($key) : $key] = self::scrub($inner);
            }

            return $clean;
        }

        return \is_scalar($value) ? $value : null;
    }

    /**
     * Continue a finished run with $text ({@see AgentResume}): detached from
     * any turn, its beats recorded as this session's `subagent.*` events (and
     * mirrored into the agent manager, which stores a finished run as a child
     * session), the way the TUI's follow-up lands them.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private static function followUp(SessionHost $host, array $run, string $text): array
    {
        $agentId = (string) $run['id'];
        $resumeId = $run['resumeId'] ?? null;
        if (!\is_string($resumeId) || $resumeId === '') {
            throw RpcError::of(ErrorCode::Conflict, \sprintf('%s cannot be continued: it kept no resume id', self::name($run)), 'not_resumable');
        }
        $backend = $host->workspace()->backend;
        if (!$backend instanceof EngineBackend) {
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'continuing a run needs the engine backend', 'resume_unavailable');
        }
        $resume = AgentResume::new($backend->withSessionId($host->sessionId()));
        if (!$resume->available()) {
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'this session has no Task tool to continue the run with', 'resume_unavailable');
        }

        $manager = $host->workspace()->agentManager;
        $sessionId = $host->sessionId();
        $heard = new \ArrayObject();
        $onActivity = static function (SubAgentActivity $activity) use ($host, $manager, $sessionId, $heard): void {
            $heard['beat'] = true;
            $activity = $manager?->projectRemoteSubAgent($activity) ?? $activity;
            $host->announce(SessionEvent::new(match ($activity->op) {
                SubAgentActivity::OP_STARTED => SessionEvent::SUBAGENT_STARTED,
                SubAgentActivity::OP_FINISHED => SessionEvent::SUBAGENT_FINISHED,
                default => SessionEvent::SUBAGENT_PROGRESS,
            }, $activity->toArray(), $sessionId));
        };
        $description = \is_string($run['description'] ?? null) ? $run['description'] : '';

        $resume->run(self::name($run), $resumeId, $text, $description, $onActivity)
            ->then(static function (ToolResult $result) use ($host, $sessionId, $run, $heard): void {
                // A follow-up that failed before its run said anything (no
                // resume record left, no Task tool in the child) would leave
                // the tree showing the old run as it was: say it failed.
                if ($result->isError() && !isset($heard['beat'])) {
                    $why = \trim(\strtok($result->content(), "\n") ?: '');
                    $host->announce(SessionEvent::new(SessionEvent::SUBAGENT_FINISHED, [
                        ...$run,
                        'op' => SubAgentActivity::OP_FINISHED,
                        'seq' => (int) ($run['seq'] ?? 0) + 1,
                        'outcome' => SubAgentActivity::OUTCOME_FAILED,
                        'error' => $why === '' ? 'the follow-up failed' : $why,
                    ], $sessionId));
                }
            });

        return ['agentId' => $agentId, 'status' => 'resuming'];
    }
}
