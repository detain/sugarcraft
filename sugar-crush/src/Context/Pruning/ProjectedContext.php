<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\ContextPressure;
use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * What {@see ContextProjector::project()} answers: the conversation as the
 * next request sends it.
 */
final readonly class ProjectedContext
{
    /** @param list<TypedMessage> $messages */
    public function __construct(public array $messages)
    {
    }

    /**
     * The projected messages' estimate, on the same per-row rule the step
     * pressure check uses ({@see ContextPressure::ofMessages()}).
     */
    public function tokens(): int
    {
        return ContextPressure::ofMessages($this->messages);
    }
}
