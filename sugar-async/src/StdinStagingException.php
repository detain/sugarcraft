<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * ChildRun could not stage the stdin payload to a temp file, so no child was
 * started. Only the Windows runtime stages a payload ({@see ChildRun::withStdin()}),
 * and the cause is the temp directory: no file could be created there, or the
 * payload could not be written to it.
 *
 * A RuntimeException like every other refused start, so a caller that treats any
 * refusal alike keeps working. Its own type lets a caller tell this apart from a
 * spawn the system refused: a full or unwritable temp directory is not a process
 * limit, and must not be reported as one.
 */
final class StdinStagingException extends \RuntimeException
{
    /**
     * The constructor is exempt from signature-compatibility rules in PHP, so the
     * temp directory rides after the inherited three (message, code, previous).
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?string $tempDir = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** The directory the payload was to be staged in, or null when unknown. */
    public function tempDir(): ?string
    {
        return $this->tempDir;
    }
}
