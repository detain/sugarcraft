<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

/**
 * `/branch` — fork the current session and move onto the copy (roadmap O-2h
 * moved it out of `Chat::handleBranchCommand()`).
 *
 * The copy is the STORED transcript, so any debounced change is written
 * first ({@see \SugarCraft\Crush\Host\TranscriptStore::flush()}), or the branch
 * starts behind the screen (audit R2). The move is
 * {@see CommandEffect::switchSession()}: the TUI writes to the branch from the
 * next keystroke — out of a read-only window too, which is why the reply says
 * so there (audit SES-3(b)) — while a headless host, whose session id is fixed
 * for life, leaves the branch for a client to open.
 */
final class BranchCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $store = $context->sessionStore;
        if ($store === null) {
            return CommandResult::reply($text, 'Session store not configured. Set a SessionStore to use /branch and /rename commands.');
        }

        if ($context->sessionId === null) {
            return CommandResult::reply($text, 'No active session. Start a new conversation first.');
        }

        if (CommandText::argument($text) !== '') {
            return CommandResult::reply($text, 'Usage: /branch (takes no arguments)');
        }

        $context->transcripts->flush();

        try {
            $branch = $store->forkSession($context->sessionId);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, "Error: {$e->getMessage()}");
        }

        $response = "Branch created: {$branch}";
        if ($context->readOnly) {
            // The window's lock moves onto the branch (audit SES-3(b)), so from
            // the next keystroke it writes again — to the fork, never to the
            // session the other window has.
            $response .= ' — this window now writes to the branch; the original stays with the other sugarcrush.';
        }

        return CommandResult::reply($text, $response)->withEffect(CommandEffect::switchSession($branch));
    }
}
