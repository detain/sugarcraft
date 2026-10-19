<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;

/**
 * `/compact [focus]` on a served session (WEB-2) — the fold every driver
 * already shares through {@see \SugarCraft\Code\Host\CompactionService},
 * asked of the session rather than of `Chat`.
 *
 * The body decides only WHAT happens (a typed fold, carrying the focus the
 * command was given); the driver performs it through its own compactor,
 * breaker and transcript, so there is no second implementation of the fold
 * to drift ({@see CommandEffectKind::CompactTranscript}). Two divergences
 * from the terminal route are by construction, and stated here because a
 * web user sees them:
 *
 *  - The served fold is SYNCHRONOUS. It spends no provider call: a landed
 *    ahead-of-need model summary is spliced in when its fingerprint still
 *    matches (the idle watch and automatic tier still earn those), and an
 *    unlanded history folds on the heuristic exactly as a terminal session
 *    with no summary model does. `/compact` never parks a served turn on a
 *    summarisation call — the TUI's pending-latch machinery has no state to
 *    cancel from here.
 *  - The CTX-4 typed-HOLD shape survives the crossing: a HOLD verdict in the
 *    held slot REFUSES this fold outright ({@see \SugarCraft\Code\Host\CompactionService::safeguardHoldRefusal()}),
 *    the same verdict the terminal's typed route reaches, rather than
 *    degrading to the heuristic fold the automatic tier settles for.
 *
 * The `--self` form is the one refusal: it previews the turn's OWN model
 * writing the summary, a route that exists only inside the terminal's submit
 * loop, and a served session answers instead of silently doing something else.
 */
final class CompactHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        if ($context->readOnly) {
            return CommandResult::refused(Lang::t('host.command.read_only', ['name' => '/compact']));
        }

        if (strtolower(CommandText::argument($text)) === '--self') {
            return CommandResult::reply($text, Lang::t('host.compact.self_client_only'));
        }

        // No echo row: the shared engine writes the command's echo and its
        // report INSIDE the folded history, so the transcript order — fold,
        // echo, measurement — matches the terminal's byte for byte.
        return CommandResult::new()->withEffect(CommandEffect::compactTranscript($text));
    }
}
