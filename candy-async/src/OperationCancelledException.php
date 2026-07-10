<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Thrown when an async retry or operation is cancelled via a
 * CancellationToken before it could complete.
 *
 * Extends \RuntimeException so existing callers that catch
 * \RuntimeException continue to catch cancellations unchanged (BC-safe).
 */
final class OperationCancelledException extends \RuntimeException
{
}
