<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * A step in a wizard.
 */
final readonly class WizardStep
{
    public function __construct(
        public string $title,
        public ?string $description = null,
    ) {}

    /**
     * Create a new wizard step.
     */
    public static function create(string $title, ?string $description = null): self
    {
        return new self(
            title: $title,
            description: $description,
        );
    }
}
