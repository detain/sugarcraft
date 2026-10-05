<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Acp;

use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * What a session's events and rows look like as Agent Client Protocol
 * `session/update` notifications (roadmap 5.9-1, Appendix O §6.12).
 *
 * The `update` objects this builds are ACP's, field for field:
 * `agent_message_chunk` / `agent_thought_chunk` / `user_message_chunk`
 * (`{content: ContentBlock}`), `tool_call` and `tool_call_update`
 * (`{toolCallId, title, kind, status, content, locations, rawInput}`) and
 * `plan` (`{entries: [{content, priority, status}]}`).
 *
 * TWO SOURCES, ONE SHAPE. A live turn is mapped from its {@see SessionEvent}s
 * ({@see fromEvent()}): the deltas as they stream, a tool's start and finish.
 * A finished row — a slash command's output, or a whole transcript replayed
 * for `session/load` — is mapped from the {@see Message} itself
 * ({@see fromRow()}), with the same kinds, titles and content, so a session
 * looks the same to an editor whether it watched the turn or loaded it later.
 *
 * Stateless apart from the project root it resolves tool paths against: ACP
 * locations are absolute paths an editor can open.
 */
final class AcpUpdateMapper
{
    public const AGENT_MESSAGE_CHUNK = 'agent_message_chunk';
    public const AGENT_THOUGHT_CHUNK = 'agent_thought_chunk';
    public const USER_MESSAGE_CHUNK = 'user_message_chunk';
    public const TOOL_CALL = 'tool_call';
    public const TOOL_CALL_UPDATE = 'tool_call_update';
    public const PLAN = 'plan';
    public const CURRENT_MODE_UPDATE = 'current_mode_update';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * ACP's tool kinds by built-in tool name; anything else (an MCP tool, a
     * custom one) is `other`. `think` is ACP's kind for work the agent does
     * on its own reasoning — delegation and planning.
     */
    private const KINDS = [
        'Read' => 'read',
        'Edit' => 'edit',
        'Write' => 'edit',
        'ApplyPatch' => 'edit',
        'Bash' => 'execute',
        'Grep' => 'search',
        'Glob' => 'search',
        'RepoMap' => 'search',
        'Recall' => 'search',
        'Lsp' => 'search',
        'WebFetch' => 'fetch',
        'WebSearch' => 'fetch',
        'Task' => 'think',
        'Team' => 'think',
        'Workflow' => 'think',
        'Todo' => 'think',
    ];

    /** The argument keys a tool names a file or directory under. */
    private const PATH_KEYS = ['file_path', 'path'];

    private function __construct(private readonly ?string $root)
    {
    }

    /** A mapper resolving relative tool paths against $root (null: as given). */
    public static function new(?string $root = null): self
    {
        return new self($root === null || $root === '' ? null : rtrim($root, '/'));
    }

    /** A text content block. */
    public static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** An `agent_message_chunk` carrying $text. */
    public static function messageChunk(string $text): array
    {
        return ['sessionUpdate' => self::AGENT_MESSAGE_CHUNK, 'content' => self::text($text)];
    }

    /** An `agent_thought_chunk` carrying $text. */
    public static function thoughtChunk(string $text): array
    {
        return ['sessionUpdate' => self::AGENT_THOUGHT_CHUNK, 'content' => self::text($text)];
    }

    /** ACP's tool kind for $tool. */
    public static function kind(string $tool): string
    {
        return self::KINDS[$tool] ?? 'other';
    }

    /**
     * The one-line title of a call: the model's own `description` when it
     * wrote one, else the tool and its arguments — the line the TUI shows
     * ({@see Message::describeToolCall()}).
     *
     * @param array<string, mixed> $arguments
     */
    public static function title(string $tool, array $arguments): string
    {
        return Message::describeToolCall(new ToolCall($tool, $arguments));
    }

    /**
     * The files a call names, as ACP locations an editor can follow.
     *
     * @param array<string, mixed> $arguments
     * @return list<array{path: string, line?: int}>
     */
    public function locations(array $arguments): array
    {
        foreach (self::PATH_KEYS as $key) {
            $path = $arguments[$key] ?? null;
            if (!\is_string($path) || trim($path) === '') {
                continue;
            }
            $location = ['path' => $this->absolute($path)];
            $line = $arguments['offset'] ?? null;
            if (\is_int($line) && $line > 0) {
                $location['line'] = $line;
            }

            return [$location];
        }

        return [];
    }

    /**
     * A `tool_call` announcing $tool's call $toolCallId.
     *
     * @param array<string, mixed> $arguments
     */
    public function toolCall(string $toolCallId, string $tool, array $arguments, string $status = self::STATUS_IN_PROGRESS): array
    {
        return array_filter([
            'sessionUpdate' => self::TOOL_CALL,
            'toolCallId' => $toolCallId,
            'title' => self::title($tool, $arguments),
            'kind' => self::kind($tool),
            'status' => $status,
            'locations' => $this->locations($arguments) ?: null,
            'rawInput' => $arguments === [] ? null : $arguments,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The `session/update` payloads one live event maps to — none for an
     * event an editor has no use for (the step tick, the bill, the durable
     * bookkeeping), and none for the permission events, which the
     * {@see AcpPermissionBridge} turns into requests instead.
     *
     * @return list<array<string, mixed>>
     */
    public function fromEvent(SessionEvent $event): array
    {
        $data = $event->data;

        return match ($event->type) {
            SessionEvent::ASSISTANT_DELTA => self::chunkIf(self::messageChunk(...), $data['text'] ?? null),
            SessionEvent::REASONING_DELTA => self::chunkIf(self::thoughtChunk(...), $data['text'] ?? null),
            SessionEvent::TOOL_STARTED => [$this->toolCall(
                (string) ($data['toolCallId'] ?? ''),
                (string) ($data['name'] ?? ''),
                \is_array($data['arguments'] ?? null) ? $data['arguments'] : [],
            )],
            SessionEvent::TOOL_FINISHED => [$this->toolFinished($data)],
            SessionEvent::TODO_UPDATED => [self::plan(\is_array($data['items'] ?? null) ? $data['items'] : [])],
            default => [],
        };
    }

    /**
     * The updates a settled row replays as: what the user typed, what the
     * agent said and thought, a tool call with its outcome, a notice the
     * transcript shows. A row the transcript hides (a harness reminder, the
     * hidden step row a tool result pairs with) replays as nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function fromRow(Message $row): array
    {
        if (!$row->userVisible || $row->pendingToolCallId !== null) {
            return [];
        }

        if ($row->role === Role::User) {
            return $row->content === '' ? [] : [['sessionUpdate' => self::USER_MESSAGE_CHUNK, 'content' => self::text($row->content)]];
        }

        $updates = [];
        if ($row->role === Role::Assistant && trim((string) $row->reasoning) !== '') {
            $updates[] = self::thoughtChunk((string) $row->reasoning);
        }
        if ($row->toolResults !== []) {
            foreach ($row->toolResults as $result) {
                $id = $result->id ?? ('replay_' . substr(hash('sha256', serialize([$row->createdAt, $result->name, $result->arguments])), 0, 12));
                $updates[] = $this->toolCall($id, $result->name, $result->arguments, $result->isError() ? self::STATUS_FAILED : self::STATUS_COMPLETED)
                    + ['content' => $this->resultContent($result->isError() ? (string) $result->error : $result->result, $result->diff)];
            }

            return $updates;
        }
        if ($row->content !== '') {
            $updates[] = self::messageChunk($row->content);
        }

        return $updates;
    }

    /**
     * A `plan` from a todo list's items (`{content, status}`). ACP has no
     * `cancelled`: a dropped item is shown done, which is what it is to a
     * reader of the plan.
     *
     * @param array<int, mixed> $items
     */
    public static function plan(array $items): array
    {
        $entries = [];
        foreach ($items as $item) {
            if (!\is_array($item) || !\is_string($item['content'] ?? null)) {
                continue;
            }
            $entries[] = [
                'content' => $item['content'],
                'priority' => 'medium',
                'status' => match ($item['status'] ?? null) {
                    'in_progress' => self::STATUS_IN_PROGRESS,
                    'completed', 'cancelled' => self::STATUS_COMPLETED,
                    default => self::STATUS_PENDING,
                },
            ];
        }

        return ['sessionUpdate' => self::PLAN, 'entries' => $entries];
    }

    /**
     * The `tool_call_update` a `tool.finished` event becomes.
     *
     * @param array<string, mixed> $data
     */
    private function toolFinished(array $data): array
    {
        $failed = ($data['isError'] ?? false) === true;
        $content = \is_string($data['content'] ?? null) ? $data['content'] : '';

        return [
            'sessionUpdate' => self::TOOL_CALL_UPDATE,
            'toolCallId' => (string) ($data['toolCallId'] ?? ''),
            'status' => $failed ? self::STATUS_FAILED : self::STATUS_COMPLETED,
            'content' => $this->resultContent($content, \is_string($data['diff'] ?? null) ? $data['diff'] : null),
        ];
    }

    /**
     * A finished call's content: its output as text.
     *
     * @return list<array<string, mixed>>
     */
    private function resultContent(string $output, ?string $diff): array
    {
        return $output === '' ? [] : [['type' => 'content', 'content' => self::text($output)]];
    }

    /**
     * @param \Closure(string): array<string, mixed> $chunk
     * @return list<array<string, mixed>>
     */
    private static function chunkIf(\Closure $chunk, mixed $text): array
    {
        return \is_string($text) && $text !== '' ? [$chunk($text)] : [];
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/') || $this->root === null) {
            return $path;
        }

        return $this->root . '/' . (string) preg_replace('#^(?:\./)+#', '', $path);
    }
}
