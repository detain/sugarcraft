<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\App\SideQuestionAnsweredMsg;

/**
 * `/recap` — where did this session leave off and what is next, answered by a
 * tool-less side call on the title backend over the seam `/btw` opened
 * ({@see BtwHostCommand::call()}): the fixed question rides `request()`'s
 * question slot, the recap's own voice rides its framing parameter. No new
 * message type and no Chat arm — the call resolves to a
 * {@see SideQuestionAnsweredMsg} the existing fold already lands
 * ({@see \SugarCraft\Code\Chat\Handlers\HandlesTurnCompletion}) — on the
 * headless host the row rides this class' mapper; the Chat fold lands it
 * in the seam's default btw voice (it applies Async effects without the
 * describe), so a recap-voiced TUI row is a C-TUI-3.g seam. This class
 * fixes the prompt and gives the host row the recap's heading.
 *
 * Both the echo and the answer are UI-only rows like `/btw`'s: the recap is
 * for the human stepping away and back, not material the agent reads into
 * history. ONLY EVER THE TITLE BACKEND, by the rule every side call follows
 * (audit 15b-12): no title model, no recap. The call is the same title-backend
 * request `/btw` makes, so nothing adds a total timeout to it (E646).
 */
final class RecapHostCommand implements HostCommand
{
    /**
     * The fixed question: `/recap` takes no argument — whatever trails the
     * name is ignored and this is asked of the transcript as it stands.
     */
    public const PROMPT = 'in one or two sentences, where did this session leave off and what is next';

    /** The recap's framing — `/btw`'s template ({@see BtwHostCommand::PROMPT}) in the recap's voice. */
    public const FRAMING = 'You write a brief recap of a conversation between a user and an AI coding agent. '
        . 'The recap is not part of that conversation and the agent will not see it. You cannot run '
        . 'tools; answer from the conversation shown, in one or two sentences.';

    public function run(CommandContext $context, string $text): CommandResult
    {
        $backend = $context->titleBackend;
        if ($backend === null) {
            return CommandResult::errorReply($text, Lang::t('host.recap.needs_title_model'))->holdingTurn();
        }

        return CommandResult::new(Message::user($text)->withUiOnly())
            ->withEffect(CommandEffect::async(
                BtwHostCommand::call(
                    $backend,
                    self::PROMPT,
                    $context->history,
                    $context->sessionId,
                    framing: self::FRAMING,
                    failureKey: 'host.recap.failed',
                ),
                self::rowMessage(...),
            ))
            ->holdingTurn();
    }

    /**
     * The settled recap as a row: the shared side-answer mapper with the
     * recap's heading and empty-answer voice. Not an error row, for the same
     * disclosed reason `/btw`'s answer is not
     * ({@see BtwHostCommand::answerRowMessage()}).
     */
    public static function rowMessage(mixed $msg): ?Message
    {
        return BtwHostCommand::answerRowMessage(
            $msg,
            'host.recap.answer_heading',
            'host.recap.no_answer',
        );
    }
}
