<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\UsageCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * `/usage` (OBS-3): the durable `usage_records` ledger (SES-1), folded by
 * day and model. {@see UsageCommand} owns the page and its escaping; this is
 * its session half — it decides the scope from the argument and asks the
 * store's own rollups, so the grid and the tables the store answers from can
 * never disagree.
 *
 * A host without an enhanced store — a plain SessionStore, or no session id
 * yet — does not print an empty grid: the ledger is UNAVAILABLE there, and
 * the page says so with its reason, because "nothing was spent" and "nothing
 * can be seen" are different answers (the same rule `/status` states for
 * every other row).
 */
final class UsageHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        // CommandText::argument() is the house strip of the leading command
        // word — the run($context, $text) contract hands the WHOLE line.
        $argument = strtolower(trim(CommandText::argument($text)));
        if ($argument !== '' && $argument !== 'all') {
            return CommandResult::reply($text, Lang::t('cmd.usage.usage'));
        }
        $scopeAll = $argument === 'all';

        return CommandResult::reply($text, UsageCommand::compose($this->facts($context, $scopeAll)));
    }

    /**
     * @return array<string, mixed> the fact map {@see UsageCommand::compose()} parses
     */
    private function facts(CommandContext $context, bool $scopeAll): array
    {
        $store = $context->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            return ['scopeAll' => $scopeAll, 'days' => null, 'models' => null, 'reason' => 'no durable ledger store is open here'];
        }

        $sessionId = $scopeAll ? null : $context->sessionId;
        if ($sessionId === null && !$scopeAll) {
            return ['scopeAll' => false, 'days' => null, 'models' => null, 'reason' => 'this session has no id yet; `/usage all` spans the whole ledger'];
        }

        try {
            return [
                'scopeAll' => $scopeAll,
                'days' => $store->usageDayModelRollups($sessionId),
                'models' => $store->usageModelRollups($sessionId),
                'reason' => '',
            ];
        } catch (\Throwable $e) {
            return ['scopeAll' => $scopeAll, 'days' => null, 'models' => null, 'reason' => $e->getMessage()];
        }
    }
}
