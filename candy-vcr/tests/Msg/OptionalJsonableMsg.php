<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use SugarCraft\Core\Msg;

/**
 * Test fixture: every constructor parameter is optional, so an empty
 * `data` envelope is legal under the arg mirror.
 */
final class OptionalJsonableMsg implements Msg, \JsonSerializable
{
    public function __construct(
        public readonly string $note = 'default',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['note' => $this->note];
    }
}
