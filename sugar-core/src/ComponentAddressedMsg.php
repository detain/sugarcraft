<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Messages that carry a component id for routing within a Composite.
 */
interface ComponentAddressedMsg extends Msg
{
    public function componentId(): ?string;
}
