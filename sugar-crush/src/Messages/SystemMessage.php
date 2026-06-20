<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Messages;

final readonly class SystemMessage implements Message
{
    public function __construct(
        private string $content,
    ) {}

    public function role(): string
    {
        return 'system';
    }

    public function content(): string
    {
        return $this->content;
    }

    public function toArray(): array
    {
        return ['role' => 'system', 'content' => $this->content];
    }
}
