<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\ReviewCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;

/**
 * The server-side body of `/review [<tree-ish>]` and `/security-review [<ref>]`
 * (C-WEB-2, GAP-GIT-10): one class for both spellings, read apart by the
 * command word of the draft — the two spec rows in `builtin-commands/` name
 * the same body and nothing else tells them apart.
 *
 * The shape is {@see InitHostCommand}'s, and for the same reason: a headless
 * driver cannot type into an input buffer, so the canned prompt rides a
 * {@see CommandEffect::submitPrompt()} that lands as the session's next turn
 * once the command's echo row has. The `@diff` close is the whole technique —
 * the host's own submit resolves mentions, so the change set attaches through
 * the one diff-reading path the scanner already guards, never as text the
 * command assembles itself.
 *
 * A named ref goes through {@see ReviewCommand::refError()} — the C-AGT-2
 * validator (the scanner's trim round trip plus a bounded pre-resolution) —
 * BEFORE the prompt is built, so a typo answers with the `cmd.review.bad-ref`
 * row and no turn is billed against a diff that could never attach. The
 * refusal needs no new wording: it is the sentence the TUI's handler already
 * prints.
 *
 * NOT carried here: the `--pr <n>` form (C-AGT-6.d) stays a terminal
 * dispatch. The design for this body pins the `prompt($ref, $security)`
 * shape; on a served session `--pr …` fails the ref validator and refuses
 * loudly — an honest no-turn answer, not a silently-different turn.
 *
 * A read-only session refuses: both spellings start a turn, whatever the
 * review's plan-clamp says about the turn itself.
 */
final class ReviewHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $name = CommandText::tokens($text)[0];

        if ($context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => $name]));
        }

        $ref = CommandText::argument($text);
        $refusal = ReviewCommand::refError($ref, $context->projectRoot());
        if ($refusal !== null) {
            return CommandResult::refused($refusal);
        }

        return CommandResult::new(Message::user($text)->withUiOnly())
            ->withEffect(CommandEffect::submitPrompt(
                ReviewCommand::prompt($ref, $name === '/security-review'),
            ));
    }
}
