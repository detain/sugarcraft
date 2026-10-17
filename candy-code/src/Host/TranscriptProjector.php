<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Events\ReasoningDelta;
use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\StepStarted;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Events\TokenDelta;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Events\UsageUpdated;
use SugarCraft\Code\Media\MediaKind;
use SugarCraft\Code\Media\Png;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tools\ToolCall as EngineToolCall;

/**
 * What a turn's tool-lifecycle events become: transcript rows, and the wire
 * events that name them (roadmap O-2f, Appendix O §4.2 "Tool-event projection
 * to transcript rows → `Host\TranscriptProjector`").
 *
 * EXTRACTED FROM CHAT, NOT BESIDE IT. The running placeholder a
 * {@see ToolStarted} appends, the replace-by-id a {@see ToolFinished} does,
 * and the finished-row shape both pipelines write used to be
 * `Chat::appendToolRunningPlaceholder()`, `::replaceToolRunningPlaceholder()`
 * and `::toolResultMessage()`. A host with no screen needs exactly those rows —
 * they are what it persists and what a client's transcript shows — so they
 * live here and Chat folds through them; the TUI and a server cannot drift on
 * what a finished tool row looks like.
 *
 * PURE. Every method maps its input to a new history or a new
 * {@see SessionEvent}; nothing here writes a store or touches a clock beyond
 * the event's own timestamp. The writing — the durable log and the listeners —
 * is {@see TurnRunner}'s, which hands the projector the row identities
 * {@see TranscriptStore::identify()} allocated.
 */
final class TranscriptProjector
{
    /**
     * The largest tool output a `tool.finished` event carries inline
     * (Appendix O §6.5: ≤256 KiB, flagged `truncated`). The row itself keeps
     * all of it; only the event is clipped.
     */
    public const MAX_EVENT_CONTENT_BYTES = 262144;

    /**
     * The largest `media` list a `tool.finished` event carries (W6.1,
     * Appendix C-7). Past the cap the ordered head is kept and the event
     * says so with `mediaTruncated` — the same honest-truncation ethic as
     * {@see MAX_EVENT_CONTENT_BYTES}: a client that sees the flag knows to
     * ask the media store for the rest instead of assuming the batch ended.
     */
    public const MAX_EVENT_MEDIA_REFS = 32;

    /**
     * Extension (lower-case, no dot) → media type for a ref's `mime`.
     * An unknown extension projects as `application/octet-stream`; the
     * ref still carries its path, so nothing is lost but the label.
     */
    private const MEDIA_MIME_BY_EXTENSION = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
    ];

    /** Extensions whose refs project as {@see MediaKind::Video}; everything else is image. */
    private const MEDIA_VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'mkv', 'avi'];

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * The "running" placeholder an engine-dispatched call shows until its
     * result arrives — the first half of `Chat::beginToolCalls()`, driven by
     * a {@see ToolStarted} instead of a Message's own `$toolCalls`.
     *
     * The event's engine-side identity is converted through
     * {@see ToolCall::fromEngineCall()} rather than by hand so the
     * placeholder's `pendingToolCallId` keys exactly the way the rest of the
     * Chat-side pipeline keys (W2.S1b). $reasoning is the step's thinking,
     * parked on the placeholder so it becomes the finished row's collapsible
     * thought rather than being dropped.
     */
    public function placeholder(ToolStarted $event, string $reasoning = ''): Message
    {
        $call = ToolCall::fromEngineCall(
            new EngineToolCall($event->toolCallId, $event->toolName, $event->arguments),
        );
        $placeholder = Message::toolRunning($call);
        if (trim($reasoning) !== '') {
            $placeholder = $placeholder->withReasoning($reasoning);
        }

        return $placeholder;
    }

    /**
     * $history with $event's placeholder appended.
     *
     * @param list<Message> $history
     * @return list<Message>
     */
    public function started(array $history, ToolStarted $event, string $reasoning = ''): array
    {
        return [...$history, $this->placeholder($event, $reasoning)];
    }

    /**
     * $history with the placeholder $event finishes replaced by its real
     * result, and the two rows involved.
     *
     * Correlation is on {@see ToolFinished::$toolCallId}, NOT on the adapted
     * result's own `id`: a tool never sees its own call id, so built-ins
     * routinely return an invented one and only the event carries the id the
     * placeholder was keyed with (see {@see ToolFinished::fromResult()}).
     *
     * The search runs NEWEST FIRST (audit 15b-02). Call ids are not unique
     * across a session — the DSML and MiniMax parsers restart at
     * `dsml_call_0` on every response — so a top-down walk resolved the
     * OLDEST row with the id, which is a previous turn's whenever one
     * survived, and left this call's own placeholder spinning. The call this
     * event finishes is the most recent one started under its id.
     *
     * An unmatched result is appended rather than dropped — losing a tool's
     * output entirely is worse than showing it without a preceding
     * placeholder — and then no placeholder is returned.
     *
     * @param list<Message> $history
     * @return array{0: list<Message>, 1: Message, 2: ?Message} the new
     *         history, the finished row, and the placeholder it replaced
     */
    public function finish(array $history, ToolFinished $event): array
    {
        $result = ToolResult::fromEngineResult($event->result, $event->toolName);

        $newHistory = array_values($history);
        for ($i = \count($newHistory) - 1; $i >= 0; $i--) {
            $historyMessage = $newHistory[$i];
            if ($historyMessage->pendingToolCallId !== $event->toolCallId) {
                continue;
            }

            // The placeholder content is Message::describeToolCall()'s
            // one-liner, and ToolFinished carries no arguments, so this is
            // the only point at which the finished row can learn WHAT ran
            // (crush_feat.md §3 E2). The thought that led to this call rides
            // along from the placeholder, so the finished row keeps its
            // collapsible "💭 Thought" above it.
            $row = self::resultRow(
                $result
                    ->withDescription($historyMessage->content)
                    ->withArguments($historyMessage->pendingToolArguments),
                $historyMessage->reasoning,
            );
            $newHistory[$i] = $row;

            return [$newHistory, $row, $historyMessage];
        }

        $row = self::resultRow($result);
        $newHistory[] = $row;

        return [$newHistory, $row, null];
    }

    /**
     * $history with $event applied: {@see started()} for a start,
     * {@see finish()} for a finish. Every other event leaves the rows alone —
     * they are status, not transcript.
     *
     * @param list<Message> $history
     * @return list<Message>
     */
    public function apply(array $history, object $event, string $reasoning = ''): array
    {
        return match (true) {
            $event instanceof ToolStarted => $this->started($history, $event, $reasoning),
            $event instanceof ToolFinished => $this->finish($history, $event)[0],
            default => array_values($history),
        };
    }

    /**
     * The finished-tool-call row, in the shape both pipelines write — the
     * engine's events here and `Chat::finishToolCalls()` for a Chat-native
     * tool — so `Renderer::renderToolResults()` renders both identically
     * (including the W1.F1 diff and the image bytes that ride along on
     * {@see ToolResult}).
     *
     * $reasoning is display-only: `EngineBackend::toTypedMessages()` replays
     * a tool row as its result alone — paired with its call once the settled
     * turn stamps the row with its step (roadmap 1.B-2), as prose before that
     * — so a thought parked on a tool row never reaches the model from here.
     * The step's hidden assistant row carries it.
     */
    public static function resultRow(ToolResult $result, ?string $reasoning = null): Message
    {
        return Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result, reasoning: $reasoning)
            ->withToolResults([$result]);
    }

    // ── the wire events ────────────────────────────────────────────────

    /**
     * `tool.started`, naming the placeholder row when it already has an
     * identity. A placeholder is transient — its finish replaces it with a
     * new row — so it is named only if a save already gave it one, rather
     * than spending a ref on a row that will not survive the turn; the
     * `toolCallId` is what pairs the start with its finish.
     *
     * @param array{0: string, 1: int}|null $identity
     */
    public function toolStartedEvent(ToolStarted $event, ?array $identity, ?string $sessionId, ?string $turnId): SessionEvent
    {
        return SessionEvent::new(SessionEvent::TOOL_STARTED, [
            'toolCallId' => $event->toolCallId,
            'name' => $event->toolName,
            'arguments' => $event->arguments,
            ...self::identityData($identity),
        ], $sessionId, $turnId);
    }

    /**
     * `tool.finished`, naming the finished row ($identity) and the
     * placeholder it replaced when that one had an identity.
     *
     * @param array{0: string, 1: int}|null $identity
     * @param array{0: string, 1: int}|null $replaced
     */
    public function toolFinishedEvent(
        ToolFinished $event,
        Message $row,
        ?array $identity,
        ?array $replaced,
        ?string $sessionId,
        ?string $turnId,
    ): SessionEvent {
        $result = $row->toolResults[0] ?? ToolResult::fromEngineResult($event->result, $event->toolName);
        $content = $result->isError() ? (string) $result->error : $result->result;
        $truncated = \strlen($content) > self::MAX_EVENT_CONTENT_BYTES;
        if ($truncated) {
            $content = mb_strcut($content, 0, self::MAX_EVENT_CONTENT_BYTES, 'UTF-8');
        }
        $mediaTruncated = false;
        $media = self::mediaRefs($result, $mediaTruncated);

        return SessionEvent::new(SessionEvent::TOOL_FINISHED, array_filter([
            'toolCallId' => $event->toolCallId,
            'name' => $event->toolName,
            'isError' => $result->isError(),
            'durationMs' => $result->durationMs,
            'content' => $content,
            'truncated' => $truncated ?: null,
            'diff' => $result->diff,
            'denial' => $result->denial === null ? null : ['kind' => $result->denial->name, 'reason' => (string) $result->error],
            'media' => $media,
            'mediaTruncated' => $mediaTruncated ?: null,
            ...self::identityData($identity),
            'replaces' => $replaced === null ? null : $replaced[0],
        ], static fn (mixed $value): bool => $value !== null), $sessionId, $turnId);
    }

    /**
     * The `media` list a finished tool result projects to (W6.1).
     *
     * C-7 law: the journal carries artifact references — the MediaStore id,
     * the path, sizes, mime — never image bytes. Production surfaces name at
     * most one artifact on the result today (the head image's `imagePath`),
     * so this is the single-ref fold of {@see mediaRefsForPaths()}; the
     * ordered-list contract is exercised there directly.
     *
     * @param-out ?bool $truncated
     *
     * @return list<array<string, mixed>>|null
     */
    public static function mediaRefs(ToolResult $result, ?bool &$truncated = null): ?array
    {
        return self::mediaRefsForPaths([$result->imagePath], $result->imageBytes, $truncated);
    }

    /**
     * Ordered media refs for a list of stored artifact paths, cut off at
     * {@see MAX_EVENT_MEDIA_REFS} with the truncation flag raised for the
     * caller to stamp onto the event. `$headBytes` — the bytes the result
     * carried for the artifact at index 0 of the list — sizes and digests
     * only that entry, and rides nowhere else (a head slot without a path
     * means the bytes describe nothing on this event); other path-only refs
     * report `bytes: 0` and no digest. Empty (or entirely
     * unpathed) input projects to null so the event key stays absent for
     * old-client compatibility.
     *
     * @param list<string|null> $paths
     * @param-out ?bool $truncated
     *
     * @return list<array<string, mixed>>|null
     */
    public static function mediaRefsForPaths(array $paths, ?string $headBytes = null, ?bool &$truncated = null): ?array
    {
        $refs = [];
        foreach ($paths as $index => $path) {
            if (!\is_string($path) || $path === '') {
                continue;
            }
            $refs[] = self::mediaRef($path, $index === 0 ? $headBytes : null);
        }

        $truncated = \count($refs) > self::MAX_EVENT_MEDIA_REFS;
        if ($refs === []) {
            return null;
        }

        return \array_slice($refs, 0, self::MAX_EVENT_MEDIA_REFS);
    }

    /**
     * One artifact ref: identity and path only (C-7). `artifactId` follows
     * MediaStore's own id rule so a client can fetch by the id `list()`
     * would report; `infotextDigest` rides only when the delivered bytes
     * parse as a PNG carrying infotext. Public because the serve RPC reads
     * the same single formatter: `generate.status` builds its artifact list
     * and `media.fetch` its mime/kind row from here, never a re-derivation
     * (plan W6.2).
     *
     * @return array<string, mixed>
     */
    public static function mediaRef(string $path, ?string $bytes): array
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return array_filter([
            'artifactId' => MediaStore::idOf(basename($path)),
            'kind' => \in_array($extension, self::MEDIA_VIDEO_EXTENSIONS, true)
                ? MediaKind::Video->value
                : MediaKind::Image->value,
            'path' => $path,
            'bytes' => $bytes === null ? 0 : \strlen($bytes),
            'mime' => self::MEDIA_MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream',
            'infotextDigest' => self::infotextDigest($bytes),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** SHA-256 of the PNG infotext, when the delivered bytes carry one. */
    private static function infotextDigest(?string $bytes): ?string
    {
        if ($bytes === null || !Png::isPng($bytes)) {
            return null;
        }
        $infotext = Png::readText($bytes);
        if ($infotext === null || trim($infotext) === '') {
            return null;
        }

        return hash('sha256', $infotext);
    }

    /**
     * The event a non-row backend event projects to: a permission question
     * and its settlement, a delegated run's beat, the step and bill frames,
     * the spend cap, and the streaming deltas. Null for anything else
     * (including the tool events, which need their rows —
     * {@see toolStartedEvent()} / {@see toolFinishedEvent()}).
     */
    public function backendEvent(object $event, ?string $sessionId, ?string $turnId): ?SessionEvent
    {
        return match (true) {
            $event instanceof PermissionAsked => SessionEvent::new(
                SessionEvent::PERMISSION_REQUESTED,
                self::askData($event->ask),
                $sessionId,
                $turnId,
            ),
            $event instanceof PermissionResolved => SessionEvent::new(SessionEvent::PERMISSION_RESOLVED, array_filter([
                'askId' => $event->askId,
                'reply' => $event->reply?->value,
                'note' => $event->note !== '' ? $event->note : null,
                'cancelled' => $event->cancelled ?: null,
            ], static fn (mixed $value): bool => $value !== null), $sessionId, $turnId),
            $event instanceof SubAgentActivity => SessionEvent::new(
                match ($event->op) {
                    SubAgentActivity::OP_STARTED => SessionEvent::SUBAGENT_STARTED,
                    SubAgentActivity::OP_FINISHED => SessionEvent::SUBAGENT_FINISHED,
                    default => SessionEvent::SUBAGENT_PROGRESS,
                },
                $event->toArray(),
                $sessionId,
                $turnId,
            ),
            $event instanceof StepStarted => SessionEvent::new(SessionEvent::TURN_STEP, $event->toArray(), $sessionId, $turnId),
            $event instanceof UsageUpdated => SessionEvent::new(SessionEvent::USAGE_UPDATED, $event->toArray(), $sessionId, $turnId),
            $event instanceof SpendCapBreached => SessionEvent::new(SessionEvent::SPEND_CAP_BREACHED, [
                'calls' => $event->completedCalls,
                'spent' => $event->spentUsd,
                'cap' => $event->capUsd,
            ], $sessionId, $turnId),
            $event instanceof TokenDelta => SessionEvent::new(SessionEvent::ASSISTANT_DELTA, ['text' => $event->text], $sessionId, $turnId),
            $event instanceof ReasoningDelta => SessionEvent::new(SessionEvent::REASONING_DELTA, ['text' => $event->text], $sessionId, $turnId),
            default => null,
        };
    }

    /**
     * `assistant.completed`: the settled reply, under the identity of the row
     * it lands as.
     *
     * @param array{0: string, 1: int}|null $identity
     */
    public function assistantCompletedEvent(Message $reply, ?array $identity, ?string $sessionId, ?string $turnId): SessionEvent
    {
        return SessionEvent::new(SessionEvent::ASSISTANT_COMPLETED, array_filter([
            ...self::identityData($identity),
            'content' => $reply->content,
            'reasoning' => $reply->reasoning !== null && $reply->reasoning !== '' ? $reply->reasoning : null,
            'lengthStopped' => $reply->lengthStopped,
            'stepsTruncated' => $reply->stepsTruncated,
            'usage' => $reply->usage?->toArray(),
        ], static fn (mixed $value): bool => $value !== null), $sessionId, $turnId);
    }

    /**
     * The `stopReason` a settled reply ended on (Appendix O §6.5).
     */
    public static function stopReason(Message $reply, bool $softCancelled, bool $spendCapped): string
    {
        return match (true) {
            $spendCapped => SessionEvent::STOP_SPEND_CAP,
            $reply->lengthStopped => SessionEvent::STOP_LENGTH,
            $reply->stepsTruncated => SessionEvent::STOP_MAX_STEPS,
            $softCancelled => SessionEvent::STOP_CANCELLED,
            default => SessionEvent::STOP_END_TURN,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function askData(PendingAsk $ask): array
    {
        return [
            'askId' => $ask->askId,
            'toolCallId' => $ask->toolCallId,
            'tool' => $ask->tool,
            'arguments' => $ask->arguments,
            'reason' => $ask->reason,
            'source' => $ask->source,
            'mode' => $ask->mode,
            'options' => $ask->suggestions,
            'alwaysScope' => $ask->alwaysScope,
            // P-E2: the delegated run that asked, when a sub-agent's question
            // was relayed up; absent for the turn's own.
        ] + ($ask->origin === null ? [] : ['origin' => $ask->origin->toArray()]);
    }

    /**
     * @param array{0: string, 1: int}|null $identity
     * @return array<string, string|int>
     */
    private static function identityData(?array $identity): array
    {
        return $identity === null ? [] : ['messageId' => $identity[0], 'ref' => $identity[1]];
    }
}
