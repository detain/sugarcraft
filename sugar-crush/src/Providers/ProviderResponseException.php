<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * A provider call that ended in an `isError` {@see CompleteResponse} which the
 * retry seam in {@see \SugarCraft\Crush\Runtime} could not (or was not allowed
 * to) recover from (audit 15a A1).
 *
 * WHY THIS EXISTS
 * ---------------
 * {@see CustomProvider} (and therefore the `anthropic` provider type) and
 * {@see VertexProvider} report a failed call as an error RESPONSE instead of
 * throwing. Runtime used to stop retrying such a response and then yield it
 * onward as an ordinary assistant message - whose content is `''` - so a 401
 * "Incorrect API key", a 404 for a bad model or a blocked prompt reached the
 * user as a blank reply and `CompleteResponse::$errorMessage` was never read.
 * Throwing this instead lets {@see \SugarCraft\Crush\Backend\EngineBackend}
 * carry the provider's own text to the user the same way it already carries a
 * thrown provider failure.
 *
 * WHY NOT {@see ProviderException}
 * --------------------------------
 * That class is a SUBPROCESS failure with exit-code structure, and its
 * docblock records that {@see TransientFailure} may one day learn to retry
 * parts of it. This exception is thrown only AFTER Runtime's retry decision
 * is final, so it must never be classified transient - sharing a type with a
 * class whose verdict is still open would let an outer retry loop re-run a
 * failure the inner one already gave up on.
 *
 * WHY IT EXTENDS \RuntimeException, AND WHY IT IS NEVER TRANSIENT
 * ---------------------------------------------------------------
 * Callers around the providers catch `\RuntimeException` broadly. It carries
 * no HTTP status (no `getStatusCode()`), no `previous` and none of the network
 * exception types, so {@see TransientFailure::isTransient()} walks its chain
 * and answers false. That is the point: by the time it is thrown, either the
 * response was not transient or the retries were exhausted.
 */
final class ProviderResponseException extends \RuntimeException
{
    /** Used when the provider flagged an error but supplied no text for it. */
    public const FALLBACK_MESSAGE = 'The provider returned an error response without a message.';

    /**
     * Whether the response said the prompt did not fit the context window
     * (roadmap 2.7-1a), decided from the response by
     * {@see ContextOverflow::matches()} when the exception is built — the one
     * permanent failure a smaller retry can fix, carried as a verdict because
     * this exception has no status to decide it from later.
     */
    public readonly bool $contextOverflow;

    public function __construct(
        string $message,
        public readonly CompleteResponse $response,
    ) {
        parent::__construct($message);
        $this->contextOverflow = ContextOverflow::matches($response);
    }

    /**
     * Build the exception from the error response itself, so the provider's
     * own wording - not a paraphrase - is what reaches the user.
     */
    public static function fromResponse(CompleteResponse $response): self
    {
        $message = $response->errorMessage;
        if ($message === null || trim($message) === '') {
            $message = self::FALLBACK_MESSAGE;
        }

        return new self($message, $response);
    }
}
