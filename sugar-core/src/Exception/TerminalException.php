<?php

declare(strict_types=1);

namespace SugarCraft\Core\Exception;

use SugarCraft\Core\Lang;

/**
 * Exception carrying a terminal-layer fault.
 *
 * This type travels as an {@see \SugarCraft\Core\Msg\ExceptionMsg}
 * payload (`InputReader`'s incomplete-sequence overflow, `Program`'s
 * bounded screen-stack refusal) rather than being thrown across the
 * dispatch boundary, so it stays a domain exception under the
 * family base — SPL-parenting law (sc-22) applies to types callers
 * catch directly, not to payload discriminators matched via
 * `instanceof`.
 */
final class TerminalException extends Exception
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
