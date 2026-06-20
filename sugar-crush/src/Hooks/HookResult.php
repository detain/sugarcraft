<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

final readonly class HookResult
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const MODIFY = 'modify';

    public function __construct(
        public string $action,
        public string $message,
        public ?string $modifiedInput = null,
    ) {}

    public static function allow(string $message = ''): self
    {
        return new self(self::ALLOW, $message);
    }

    public static function deny(string $message): self
    {
        return new self(self::DENY, $message);
    }

    public static function modify(string $newInput, string $message = ''): self
    {
        return new self(self::MODIFY, $message, $newInput);
    }

    public function isAllowed(): bool
    {
        return $this->action === self::ALLOW;
    }

    public function isDenied(): bool
    {
        return $this->action === self::DENY;
    }

    public function isModified(): bool
    {
        return $this->action === self::MODIFY;
    }
}
