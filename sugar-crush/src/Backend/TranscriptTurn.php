<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\Message as TypedMessage;

/**
 * One finished {@see EngineBackend::completeTranscript()} turn: the reply the
 * caller reads, and the whole typed conversation that produced it — the input
 * history followed by every assistant step (tool calls included) and every
 * tool result — so the turn can be continued later.
 */
final readonly class TranscriptTurn
{
    /**
     * @param list<TypedMessage> $transcript
     */
    public function __construct(
        public Message $reply,
        public array $transcript,
    ) {}
}
