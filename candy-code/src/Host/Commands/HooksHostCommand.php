<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\HooksCommand;
use SugarCraft\Code\Commands\TranscriptTable;
use SugarCraft\Code\Lang;

/**
 * `/hooks`: the launch's hook chain, by event (roadmap VIS-3a, C-EXT-7).
 * Read-only and local: it reads the manager ALREADY BUILT for the launch —
 * `$context->workspace?->hooks`, the same {@see \SugarCraft\Code\Hooks\HookManager}
 * the session registers through — via {@see \SugarCraft\Code\Hooks\HookManager::inspect()},
 * and sends nothing. The report itself is {@see HooksCommand}'s; this is its
 * session half.
 *
 * The command deliberately does NOT go through the Bootstrap builder any more:
 * building a chain is not a pure read — it re-runs configuration and carries
 * launch side effects (the secret-env allowlist, the LSP client, the team
 * manager's launch-hook handover). A read-only panel must never be able to
 * mint or mutate session state, so it rides the chain that exists or says so.
 * A context with no workspace chain (a host that never carried one) reports
 * "not measured" with a plain no-chain row — it rebuilds nothing to prove it.
 */
final class HooksHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $manager = $context->workspace?->hooks;

        if ($manager === null) {
            $report = HooksCommand::compose(null, TranscriptTable::paneWidth($context))
                . "\n" . Lang::t('cmd.hooks.unmeasured.nochain');

            return CommandResult::reply($text, $report);
        }

        $report = HooksCommand::compose($manager->inspect(), TranscriptTable::paneWidth($context));

        return CommandResult::reply($text, $report);
    }
}
