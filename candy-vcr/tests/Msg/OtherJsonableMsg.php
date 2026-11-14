<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use SugarCraft\Core\Msg;

/**
 * Test fixture: a second allow-listed user Msg, so allowedClasses()
 * ordering is observable.
 */
final class OtherJsonableMsg implements Msg, \JsonSerializable
{
    public function __construct(
        public readonly string $label,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['label' => $this->label];
    }
}
