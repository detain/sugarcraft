<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * An event in a timeline.
 */
final readonly class TimelineEvent
{
    public function __construct(
        public string $time,
        public string $title,
        public ?string $description = null,
        public string $type = 'default',
    ) {}
}
