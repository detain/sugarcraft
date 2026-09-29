<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * @internal
 */
final class RemoveComponentMsg implements ComponentAddressedMsg
{
    public function __construct(public readonly string $id)
    {
    }

    public function componentId(): string
    {
        return $this->id;
    }
}
