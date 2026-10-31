<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\HooksCommand;
use SugarCraft\Code\Commands\TranscriptTable;
use SugarCraft\Code\Hooks\HookLog;
use SugarCraft\Code\Lang;

/**
 * `/hooks`: the launch's hook chain, by event (roadmap VIS-3a, C-EXT-7).
 * Read-only and local: it reads the manager ALREADY BUILT for the launch —
 * `$context->workspace?->hooks`, the same {@see \SugarCraft\Code\Hooks\HookManager}
 * the session registers through — via {@see \SugarCraft\Code\Hooks\HookManager::inspect()},
 * and sends nothing. The report itself is {@see HooksCommand}'s; this is its
 * session half.
 *
 * `/hooks log [n]` (C-EXT-6.b) is the same read-only promise on the OTHER
 * hook surface: the newest runs recorded in {@see \SugarCraft\Code\Hooks\HookLog}.
 * It deliberately short-circuits BEFORE the chain check — the log exists
 * whether or not this session carries a built chain, and it records runs
 * that happened in forked children the carried manager could not report even
 * if reporting runs were this command's job. The session tail filters by
 * `$context->sessionId`; a host carrying no session id sees the newest rows
 * for any session (there is nothing to filter BY, and inventing a filter
 * that silently empties the table would read as "no hooks ran").
 *
 * The command deliberately does NOT go through the Bootstrap builder any more:
 * building a chain is not a pure read — it re-runs configuration and carries
 * launch side effects (the secret-env allowlist, the LSP client, the team
 * manager's launch-hook handover). A read-only panel must never be able to
 * mint or mutate session state, so it rides the chain that exists or says so.
 * A context with no workspace chain (a host that never carried one) reports
 * "not measured" with a plain no-chain row — it rebuilds nothing to prove it.
 *
 * Arguments are no longer ignored: `CommandText::words()` routes the first
 * positional word — `log` to the tail above, anything else to the usage row.
 * The registry dispatch of the bare form is unchanged.
 */
final class HooksHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $words = CommandText::words($text);
        $word = $words[0] ?? '';

        if ($word === 'log') {
            return $this->runLog($context, $text, $words);
        }

        if ($word !== '') {
            return CommandResult::reply($text, Lang::t('cmd.hooks.unknown', [
                'word' => Chat::reportField($word),
            ]));
        }

        $manager = $context->workspace?->hooks;

        if ($manager === null) {
            $report = HooksCommand::compose(null, TranscriptTable::paneWidth($context))
                . "\n" . Lang::t('cmd.hooks.unmeasured.nochain');

            return CommandResult::reply($text, $report);
        }

        $report = HooksCommand::compose($manager->inspect(), TranscriptTable::paneWidth($context));

        return CommandResult::reply($text, $report);
    }

    /**
     * `/hooks log [n]`: read the tail, render it. The reader clamps `n` into
     * {@see HookLog::MAX_ROWS}; this half refuses a non-numeric or
     * out-of-range `n` loudly (a silently clamped 5000 would show 200 rows
     * the user did not ask about and cannot ask again for).
     *
     * @param list<string> $words the positional words, `$words[0] === 'log'`
     */
    private function runLog(CommandContext $context, string $text, array $words): CommandResult
    {
        $asked = $words[1] ?? null;
        if ($asked === null) {
            $limit = HookLog::DEFAULT_ROWS;
        } elseif (ctype_digit($asked) && (int) $asked >= 1 && (int) $asked <= HookLog::MAX_ROWS) {
            $limit = (int) $asked;
        } else {
            return CommandResult::reply($text, Lang::t('cmd.hooks.log.usage', [
                'max' => (string) HookLog::MAX_ROWS,
            ]));
        }

        $tail = HookLog::tail($limit, $context->sessionId);
        $report = HooksCommand::composeLog($tail['rows'], TranscriptTable::paneWidth($context));

        if ($tail['unreadable']) {
            // The rows shown so far are real; the missing half is announced.
            // Rendering a blocked read as "no entries" would teach the user
            // their hooks did not run when the truth is nobody looked.
            $report .= "\n" . Lang::t('cmd.hooks.log.unreadable');
        }

        return CommandResult::reply($text, $report);
    }
}
