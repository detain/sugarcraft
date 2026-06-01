<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Modal\Msg;

/**
 * Represents an edit action on an item (used by ListModal, MultiselectModal).
 */
readonly class AnsweredEditMsg extends Msg
{
    public function __construct(
        public readonly mixed $item,
        public readonly int $index,
    ) {}
}
