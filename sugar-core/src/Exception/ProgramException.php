<?php

declare(strict_types=1);

namespace SugarCraft\Core\Exception;

use SugarCraft\Core\Lang;

/**
 * Exception thrown for Program runtime errors.
 *
 * Covers init failures, cmd execution issues, and other Program
 * lifecycle faults that are runtime (not logic) errors. Extends
 * `\RuntimeException` on the `SemaphoreClosedException` law (sc-22 /
 * findings sugar-core-042, sugar-core-023): its first wired site is
 * the `proc_open` failure in `Program`'s command runner, which threw
 * a bare `\RuntimeException` before — a named type that left the
 * runtime catch lineage would have broken every caller already
 * catching that site.
 */
final class ProgramException extends \RuntimeException
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
     * @param string $key   Translation key in Lang format
     * @param array<string, mixed> $params Key-value pairs for placeholder substitution
     */
    public static function fromKey(string $key, array $params = []): self
    {
        return new self(Lang::t($key, $params));
    }
}
