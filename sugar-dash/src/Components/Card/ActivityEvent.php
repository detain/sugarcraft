<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

/**
 * An event in an activity feed.
 */
final readonly class ActivityEvent
{
    public function __construct(
        public string $actor,
        public string $action,
        public string $target,
        public string $type = 'default',
        public ?string $timestamp = null,
    ) {}
}
