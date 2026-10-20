<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Goal\GoalJudge;
use SugarCraft\Code\Goal\GoalMode;
use SugarCraft\Code\Goal\GoalState;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;

/**
 * `/goal <condition>` (and, through {@see GrindHostCommand}, `/grind`) on a
 * served session (WEB-2) — the same decision table the terminal's
 * `Chat::handleGoalCommand()` runs, against the same transcript-marker state
 * ({@see GoalState::fromHistory()}), reusing the same `chat.goal.*` sentences
 * so both surfaces say the identical thing.
 *
 * What crosses and what does not:
 *  - Status, set and clear cross whole: the goal lives in its marker row, so
 *    a resumed session resumes it, and `/clear` still drops it.
 *  - The FIRST round crosses: the set rows land and
 *    {@see CommandEffect::submitPrompt()} sends the kickoff prompt as a real
 *    turn (spend cap and blocking tiers refuse it through the submit route's
 *    own checks, and the refusal reason lands beside the marker).
 *  - The CONTINUATION ROUNDS cross too (W7a-3): the served host runs the
 *    judge on its own turn settle — the shared {@see \SugarCraft\Code\Goal\GoalLoop}
 *    decision table, so the round budget and every stop condition are the
 *    terminal's, byte for byte. A goal set on a served session keeps sending
 *    the agent back to work until the judge says met, the budget is spent, or
 *    a stop fires (no judge model, spend cap, an unusable verdict); the rows
 *    it lands say which.
 */
final class GoalHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $tokens = CommandText::tokens($text);
        $mode = GoalMode::tryFrom(ltrim($tokens[0] ?? '', '/')) ?? GoalMode::Goal;
        $condition = CommandText::argument($text);
        $current = GoalState::fromHistory($context->history);
        $live = $current !== null && $current->isActive() ? $current : null;

        if ($condition === '') {
            return CommandResult::reply($text, $live === null
                ? Lang::t('chat.goal.none_set', ['command' => $mode->command(), 'rounds' => $mode->maxRounds()])
                : Lang::t('chat.goal.status', [
                    'command' => $live->mode->command(),
                    'round' => $live->round,
                    'rounds' => $live->mode->maxRounds(),
                    'condition' => $live->condition,
                ]));
        }

        if ($context->readOnly) {
            // Listing stays available (it writes nothing beyond the echo the
            // caller renders); every other arm mutates the transcript.
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => $mode->command()]));
        }

        if (\in_array(strtolower($condition), ['clear', 'off', 'stop'], true)) {
            return CommandResult::new(Message::user($text)->withUiOnly())->withRows(...($live === null
                ? [Message::assistant(Lang::t('chat.goal.nothing_to_clear'))->withUiOnly()]
                : [
                    Message::notice(Lang::t('chat.goal.cleared', ['condition' => $live->condition])),
                    $live->withStatus(GoalState::CLEARED)->toMessage(),
                ]));
        }

        if ($context->titleBackend === null) {
            return CommandResult::reply($text, Lang::t('chat.goal.needs_title_model', ['command' => $mode->command()]));
        }

        $goal = GoalState::new($mode, $condition);

        return CommandResult::new(
            Message::user($text)->withUiOnly(),
            Message::notice(Lang::t('chat.goal.set', [
                'condition' => $goal->condition,
                'rounds' => $mode->maxRounds(),
                'command' => $mode->command(),
            ])),
            $goal->toMessage(),
        )->withEffect(CommandEffect::submitPrompt(GoalJudge::kickoffPrompt($goal)));
    }
}
