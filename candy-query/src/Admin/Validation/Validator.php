<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Validation;

/**
 * Base validator for admin page prerequisites.
 */
abstract class Validator
{
    protected string $errorMessage = '';

    public function __construct(
        protected readonly \SugarCraft\Query\Admin\ServerContextInterface $context,
    ) {}

    /**
     * Run validation and return true if passes.
     */
    abstract public function isValid(): bool;

    /**
     * Get the error message if validation failed.
     */
    public function error(): string
    {
        return $this->errorMessage;
    }

    protected function setError(string $message): void
    {
        $this->errorMessage = $message;
    }
}
