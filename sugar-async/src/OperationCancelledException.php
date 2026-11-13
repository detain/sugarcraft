<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Thrown when an async retry or operation is cancelled via a
 * CancellationToken before it could complete, or when a wrapper's wait is
 * abandoned.
 *
 * Like its sibling {@see TimeoutException} it carries the operation's label
 * as a structured field when the wrapper received one, so a caller catching
 * several wrapped operations branches on data, not message archaeology.
 *
 * Extends \RuntimeException so existing callers that catch
 * \RuntimeException continue to catch cancellations unchanged (BC-safe).
 */
final class OperationCancelledException extends \RuntimeException
{
    /**
     * The constructor is exempt from signature-compatibility rules in PHP, so
     * the structured label rides after the inherited trio (message, code,
     * previous); every existing single-argument construction keeps working.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?string $label = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** The operation label passed to the wrapper, or null when unlabelled. */
    public function label(): ?string
    {
        return $this->label;
    }
}
