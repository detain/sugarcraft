<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * One turn in a chat conversation. Immutable, role-tagged,
 * timestamped. The chat history is a `list<Message>` carried
 * on the {@see Chat} model.
 *
 * Content stays as a plain string here — Markdown is rendered
 * lazily at view time via CandyShine. That keeps `Message`
 * cheap to build (every keystroke updates the in-flight user
 * message) and keeps the backend adapter API ASCII-only.
 */
final class Message
{
    /**
     * @param list<Attachment> $attachments
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(
        public readonly Role  $role,
        public readonly string $content,
        public readonly int   $createdAt,
        public readonly array $attachments = [],
        public readonly array $toolCalls = [],
    ) {}

    public static function user(string $content, ?int $now = null): self
    {
        return new self(Role::User, $content, $now ?? time());
    }

    public static function assistant(string $content, ?int $now = null): self
    {
        return new self(Role::Assistant, $content, $now ?? time());
    }

    public static function system(string $content, ?int $now = null): self
    {
        return new self(Role::System, $content, $now ?? time());
    }

    public function attachFile(string $path): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: [...$this->attachments, new Attachment($path, AttachmentType::File)],
            toolCalls: $this->toolCalls,
        );
    }

    public function attachImage(string $path): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: [...$this->attachments, new Attachment($path, AttachmentType::Image)],
            toolCalls: $this->toolCalls,
        );
    }

    /**
     * Create a message with tool calls (for assistant responses that invoke tools).
     *
     * @param list<ToolCall> $toolCalls
     */
    public function withToolCalls(array $toolCalls): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: $this->attachments,
            toolCalls: $toolCalls,
        );
    }

    /**
     * Create a message from a tool result.
     * Tool results are treated as assistant messages with the result as content.
     *
     * @param list<ToolResult> $toolResults
     */
    public function withToolResults(array $toolResults): self
    {
        // For tool results, we create an assistant message with empty content
        // that carries the tool results. The actual result content is in the
        // separate messages added to history after tool execution.
        return new self(
            role: Role::Assistant,
            content: '',
            createdAt: $this->createdAt,
            attachments: [],
            toolCalls: [],
        );
    }

    /**
     * Wire-format dict used by every HTTP backend adapter. Caller
     * decides whether to filter system messages out (some APIs
     * don't accept them in the messages list).
     *
     * @return array{role:string,content:string,attachments?:list<array{type:string,path:string}>,tool_calls?:list<array{name:string,arguments:array<string,mixed>,id?:string}>}
     */
    public function toWire(): array
    {
        $wire = ['role' => $this->role->value, 'content' => $this->content];
        if ($this->attachments !== []) {
            $wire['attachments'] = array_map(
                static fn(Attachment $a) => ['type' => $a->type->name, 'path' => $a->path],
                $this->attachments,
            );
        }
        if ($this->toolCalls !== []) {
            $wire['tool_calls'] = array_map(
                static fn(ToolCall $tc) => $tc->toArray(),
                $this->toolCalls,
            );
        }
        return $wire;
    }
}
