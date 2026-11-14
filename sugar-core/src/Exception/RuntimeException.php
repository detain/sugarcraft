<?php

declare(strict_types=1);

namespace SugarCraft\Core\Exception;

use SugarCraft\Core\Lang;

/**
 * SugarCraft-flavored `\RuntimeException` with i18n construction.
 *
 * Mirrors \RuntimeException and extends it directly (sc-22 / finding
 * sugar-core-042): the docblock claimed "mirrors" while the class
 * descended from the domain base, so `catch (\RuntimeException)` —
 * the idiom every caller of a runtime-faulting sugar-core call is
 * entitled to write — missed it. `SemaphoreClosedException` is the
 * precedent for the SPL parenting; this class now follows the same
 * law. Consumer census at fix time: zero in-repo references to
 * `SugarCraft\Core\Exception` outside sugar-core, so the re-parenting
 * was free today when made.
 */
final class RuntimeException extends \RuntimeException
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
