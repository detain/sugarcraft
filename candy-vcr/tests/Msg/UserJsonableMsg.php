<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use SugarCraft\Core\Msg;

/**
 * Test fixture: a user Msg that round-trips via JsonableSerializer.
 * Constructor parameter names match the keys returned by jsonSerialize(),
 * so named-arg unpacking on decode just works.
 */
final class UserJsonableMsg implements Msg, \JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly int $count,
    ) {}

    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'count' => $this->count];
    }
}
