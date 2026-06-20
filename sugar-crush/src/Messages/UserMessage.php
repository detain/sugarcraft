<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Messages;

use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;

final readonly class UserMessage implements Message
{
    /**
     * @param list<Attachment> $attachments
     */
    public function __construct(
        private string $content,
        private array $attachments = [],
    ) {}

    public function role(): string
    {
        return 'user';
    }

    public function content(): string
    {
        return $this->content;
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    public function withAttachment(Attachment $attachment): self
    {
        return new self($this->content, [...$this->attachments, $attachment]);
    }

    public function withFile(string $path): self
    {
        return $this->withAttachment(new Attachment($path, AttachmentType::File));
    }

    public function withImage(string $path): self
    {
        return $this->withAttachment(new Attachment($path, AttachmentType::Image));
    }

    public function toArray(): array
    {
        $array = ['role' => 'user', 'content' => $this->content];

        // Only surface attachments when present so the common text-only case
        // stays a clean two-key {role, content} payload.
        if ($this->attachments !== []) {
            $array['attachments'] = array_map(
                static fn (Attachment $a): array => ['type' => $a->type->name, 'path' => $a->path],
                $this->attachments,
            );
        }

        return $array;
    }
}
