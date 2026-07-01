<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

/**
 * Thrown by {@see MultiBackend} when multiple child backends fail
 * during a fanout operation in continue-on-error mode.
 *
 * Use {@see getErrors()} to retrieve the full list of failures.
 */
final class MultiBackendException extends \RuntimeException
{
    /**
     * @param array<\Throwable> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = '',
    ) {
        if ($message === '') {
            $messages = array_map(fn(\Throwable $e) => $e->getMessage(), $this->errors);
            $message = 'MultiBackend: ' . count($this->errors) . ' child backend(s) failed. Errors: ' . implode('; ', $messages);
        }
        parent::__construct($message, previous: $this->errors[0] ?? null);
    }

    /**
     * Returns all errors collected during the fanout.
     *
     * @return array<\Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
