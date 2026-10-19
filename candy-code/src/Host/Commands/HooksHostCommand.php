<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\HooksCommand;
use SugarCraft\Code\Commands\TranscriptTable;

/**
 * `/hooks`: the launch's hook chain, by event (roadmap VIS-3a). Read-only and
 * local: it asks {@see \SugarCraft\Code\Cli\Bootstrap::hooks()} for the same
 * manager the session registers — built-ins, configured post-edit guards and
 * the loaded hook files — reads it through {@see \SugarCraft\Code\Hooks\HookManager::inspect()},
 * and sends nothing. The report itself is {@see HooksCommand}'s; this is its
 * session half.
 *
 * Building the manager re-runs its configuration reads (all memoized at the
 * Bootstrap layer); it never RUNS a hook — registration constructs ScriptHook
 * objects without invoking them, which is the whole reason a read-only panel
 * may call the launch's own builder instead of a new discovery path. A chain
 * that cannot be built (a malformed, loudly-refused hooks.yaml) reports "not
 * measured" with the reason rather than the empty table it never proved.
 */
final class HooksHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        try {
            $inspect = \SugarCraft\Code\Cli\Bootstrap::hooks($context->permissionGate, $context->root)->inspect();
        } catch (\Throwable $e) {
            $inspect = null;
            $reason = $e->getMessage();
        }

        $report = HooksCommand::compose($inspect, TranscriptTable::paneWidth($context));
        if ($inspect === null && isset($reason) && $reason !== '') {
            $report .= "\n" . \SugarCraft\Code\Lang::t('cmd.hooks.unmeasured.reason', ['reason' => \SugarCraft\Code\Chat::reportField($reason)]);
        }

        return CommandResult::reply($text, $report);
    }
}
