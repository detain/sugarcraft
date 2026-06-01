<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

/**
 * A node in a timeline.
 */
readonly class TimelineNode
{
    public function __construct(
        public string $label,
        public ?string $description = null,
    ) {}

    /**
     * Create a new timeline node.
     */
    public static function create(string $label, ?string $description = null): self
    {
        return new self(
            label: $label,
            description: $description,
        );
    }

    /**
     * Create a node with a multiline description.
     */
    public static function withMultiLineDescription(string $label, array $descriptionLines): self
    {
        return new self(
            label: $label,
            description: implode("\n", $descriptionLines),
        );
    }
}
