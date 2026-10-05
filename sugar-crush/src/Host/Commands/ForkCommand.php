<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Session\SessionKind;

/**
 * `/fork <prompt>` — clone this conversation and run $prompt against the clone
 * in a background session (moved out of `Chat` in roadmap O-2h).
 *
 * The copy is the store's `forkSession()` — transcript, checkpoints, blobs and
 * meta in one transaction, the call `/branch` makes — but the session in front
 * of the user stays put: `/branch` MOVES onto the copy, `/fork` sends it away
 * to work (Claude Code's split). The daemon loads the forked copy as its
 * history and saves its reply back into it (X-30), so the background session
 * continues the transcript rather than starting from the bare prompt. The copy
 * is recorded as a {@see SessionKind::Background} session, so the picker lists
 * it with the `⧗ bg` badge.
 */
final class ForkCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        if ($context->backgroundSupervisor === null) {
            return CommandResult::reply($text, BackgroundCommand::notConfigured());
        }

        $prompt = CommandText::argument($text);
        if ($prompt === '') {
            return CommandResult::reply($text, Lang::t('host.fork.usage'));
        }

        $store = $context->sessionStore;
        if ($store === null) {
            return CommandResult::reply($text, Lang::t('host.fork.no_store'));
        }

        if ($context->sessionId === null) {
            return CommandResult::reply($text, Lang::t('host.rename.no_session'));
        }

        // The fork copies what is stored (audit R2): write any debounced change first.
        $context->transcripts->flush();

        try {
            $forked = $store->forkSession($context->sessionId, SessionKind::Background);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, Lang::t('host.rename.error', ['error' => $e->getMessage()]));
        }

        return CommandResult::echo($text)->withEffect(
            BackgroundCommand::spawnEffect($context, '/fork', BackgroundCommand::sessionName($prompt), $prompt, $forked),
        );
    }
}
