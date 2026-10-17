<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * @internal
 */
final class AddComponentMsg implements ComponentAddressedMsg
{
    public function __construct(
        public readonly string $id,
        public readonly Component $component,
    ) {
    }

    public function componentId(): string
    {
        return $this->id;
    }
}
