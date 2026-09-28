<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * A {@see EngineBackend::completeTranscript()} turn that failed part-way, with
 * the transcript up to its last COMPLETED step — what a resume continues from.
 * The original failure is the previous exception, and its message is this
 * one's, so a caller that only reports the text reports the real cause.
 */
final class TurnInterrupted extends \RuntimeException
{
    /**
     * @param list<TypedMessage> $transcript
     */
    public function __construct(
        public readonly array $transcript,
        \Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
