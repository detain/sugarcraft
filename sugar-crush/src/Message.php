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
     */
    public function __construct(
        public readonly Role  $role,
        public readonly string $content,
        public readonly int   $createdAt,
        public readonly array $attachments = [],
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
        );
    }

    public function attachImage(string $path): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: [...$this->attachments, new Attachment($path, AttachmentType::Image)],
        );
    }

    /**
     * Wire-format dict used by every HTTP backend adapter. Caller
     * decides whether to filter system messages out (some APIs
     * don't accept them in the messages list).
     *
     * @return array{role:string,content:string,attachments?:list<array{type:string,path:string}>}
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
        return $wire;
    }
}
