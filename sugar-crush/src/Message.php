<?php

declare(strict_types=1);

namespace CandyCore\Crush;

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
    public function __construct(
        public readonly Role  $role,
        public readonly string $content,
        public readonly int   $createdAt,
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

    /**
     * Wire-format dict used by every HTTP backend adapter. Caller
     * decides whether to filter system messages out (some APIs
     * don't accept them in the messages list).
     *
     * @return array{role:string,content:string}
     */
    public function toWire(): array
    {
        return ['role' => $this->role->value, 'content' => $this->content];
    }
}
