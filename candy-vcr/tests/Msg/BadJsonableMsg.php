<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use SugarCraft\Core\Msg;

/**
 * Test fixture: jsonSerialize returns a non-array — exercises
 * JsonableSerializer's defensive type check.
 */
final class BadJsonableMsg implements Msg, \JsonSerializable
{
    public function jsonSerialize(): string
    {
        return 'not-an-array';
    }
}
