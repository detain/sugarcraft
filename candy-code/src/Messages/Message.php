<?php

declare(strict_types=1);

namespace SugarCraft\Code\Messages;

interface Message
{
    public function role(): string;

    public function content(): string;

    public function toArray(): array;
}
