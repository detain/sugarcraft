<?php

declare(strict_types=1);

namespace SugarCraft\Core\Exception;

use SugarCraft\Core\Lang;

/**
 * SugarCraft-flavored `\InvalidArgumentException` with i18n construction.
 *
 * Mirrors \InvalidArgumentException and extends it directly (sc-22 /
 * finding sugar-core-042): callers legitimately write
 * `catch (\InvalidArgumentException)` around programming-error faults;
 * descending from the domain `\Exception` base hid this class from
 * that idiom. SPL parenting follows the `SemaphoreClosedException`
 * law (see {@see RuntimeException} for the census disclosure).
 */
final class InvalidArgumentException extends \InvalidArgumentException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create from a translatable key and parameters.
     *
     * @param string $key   Translation key in Lang format (e.g., 'color.invalid_hex')
     * @param array<string, mixed> $params Key-value pairs for placeholder substitution
     */
    public static function fromKey(string $key, array $params = []): self
    {
        return new self(Lang::t($key, $params));
    }
}
