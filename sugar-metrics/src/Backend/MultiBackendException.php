<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Backend;

use SugarCraft\Metrics\Lang;
use SugarCraft\Metrics\Util;

/**
 * Thrown by {@see MultiBackend} when multiple child backends fail
 * during a fanout operation in continue-on-error mode.
 *
 * Use {@see errors()} to retrieve the full list of failures.
 */
final class MultiBackendException extends \RuntimeException
{
    /**
     * @param array<\Throwable> $errors
     */
    /**
     * House-idiom factory (sugar-metrics-014): `MultiBackendException::new($errors)`.
     */
    public static function new(array $errors, string $message = ''): self
    {
        return new self($errors, $message);
    }

    public function __construct(
        private readonly array $errors,
        string $message = '',
    ) {
        if ($message === '') {
            // Child messages can carry untrusted bytes (a third-party
            // backend may interpolate a raw metric name or path). The
            // aggregate joins them into one displayable string — sweep it.
            $messages = array_map(fn(\Throwable $e) => $e->getMessage(), $this->errors);
            $message = Lang::t('multibackend.aggregate', [
                'count' => count($this->errors),
                'errors' => Util::displaySafe(implode('; ', $messages)),
            ]);
        }
        parent::__construct($message, previous: $this->errors[0] ?? null);
    }

    /**
     * Returns all errors collected during the fanout.
     *
     * @return array<\Throwable>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
