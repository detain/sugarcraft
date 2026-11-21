<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge\Support;

use SugarCraft\Plugins\Bridge\TextGuard;

/** A TextGuard that marks fenced text, and scrubs one named secret from outbound text. */
final class FakeTextGuard implements TextGuard
{
    public function __construct(private readonly string $secret = '')
    {
    }

    public function fence(string $untrusted): string
    {
        return '<fenced>' . $untrusted . '</fenced>';
    }

    public function outbound(string $text): string
    {
        return $this->secret === '' ? $text : \str_replace($this->secret, '[redacted]', $text);
    }
}
