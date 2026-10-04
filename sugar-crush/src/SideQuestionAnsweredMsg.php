<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * The answer to a `/btw` side question (roadmap 5.14b), built by
 * {@see Host\Commands\BtwHostCommand} and landed by `Chat` as one UI-only row:
 * shown under the question, never sent to the agent.
 *
 * Stamped with the session it was asked in, so an answer that lands after a
 * session switch is not written into the wrong transcript. NOT stamped with a
 * turn generation: a side question is not part of a turn, so a turn settling,
 * starting or being cancelled while it runs leaves the answer as wanted as
 * before. The usage is accounted whatever became of the answer.
 */
final class SideQuestionAnsweredMsg implements Msg
{
    public function __construct(
        public readonly ?string $sessionId,
        public readonly string $answer,
        public readonly ?Usage $usage = null,
    ) {
    }
}
