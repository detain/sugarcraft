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
    /**
     * OBS-3: the windows `/usage` answers for besides the whole ledger.
     * Whole UTC days inclusive of today (the store states the boundary);
     * anything else is a usage error, NOT a silent fall-back to `all` —
     * a typo'd window must not print a bigger number than was asked for.
     */
    private const WINDOWS = [7 => 7, 30 => 30];

    public function run(CommandContext $context, string $text): CommandResult
    {
        // CommandText::argument() is the house strip of the leading command
        // word — the run($context, $text) contract hands the WHOLE line.
        $argument = strtolower(trim(CommandText::argument($text)));
        $scopeAll = false;
        $windowDays = null;
        foreach (preg_split('/\s+/', $argument, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($token === 'all') {
                $scopeAll = true;
            } elseif (isset(self::WINDOWS[(int) $token]) && ctype_digit($token)) {
                $windowDays = self::WINDOWS[(int) $token];
            } else {
                return CommandResult::reply($text, Lang::t('cmd.usage.usage'));
            }
        }

        return CommandResult::reply($text, UsageCommand::compose($this->facts($context, $scopeAll, $windowDays)));
    }

    /**
     * @return array<string, mixed> the fact map {@see UsageCommand::compose()} parses
     */
    private function facts(CommandContext $context, bool $scopeAll, ?int $windowDays): array
    {
        $store = $context->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            return ['scopeAll' => $scopeAll, 'windowDays' => $windowDays, 'days' => null, 'models' => null, 'reason' => 'no durable ledger store is open here'];
        }

        $sessionId = $scopeAll ? null : $context->sessionId;
        if ($sessionId === null && !$scopeAll) {
            return ['scopeAll' => false, 'windowDays' => $windowDays, 'days' => null, 'models' => null, 'reason' => 'this session has no id yet; `/usage all` spans the whole ledger'];
        }

        return self::rollupFacts($store, $sessionId, $windowDays, $scopeAll);
    }

    /**
     * THE SHARED READ (OBS-3-P2): one snapshot fold of the durable ledger,
     * shaped into the fact map {@see UsageCommand::compose()} parses. The
     * `/usage` act above, the usage RPC (C-SES-8.a), the CLI verb
     * (C-SES-8.e) and insights (C-SES-8.c) all answer through this one
     * method, so no two surfaces can print different grids, different
     * totals, or different reasons for the same ledger at the same instant.
     *
     * @return array<string, mixed>
     */
    public static function rollupFacts(EnhancedSessionStore $store, ?string $sessionId, ?int $windowDays, bool $scopeAll): array
    {
        try {
            $rollups = $store->usageRollups($sessionId, $windowDays);

            return [
                'scopeAll' => $scopeAll,
                'windowDays' => $windowDays,
                'days' => $rollups['days'],
                'models' => $rollups['models'],
                'reason' => '',
            ];
        } catch (\Throwable $e) {
            return ['scopeAll' => $scopeAll, 'windowDays' => $windowDays, 'days' => null, 'models' => null, 'reason' => $e->getMessage()];
        }
    }
}
