<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Commands\UsageCommand;
use SugarCraft\Code\Host\ContextSources;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * `/usage` (OBS-3): the durable `usage_records` ledger (SES-1), folded by
 * day and model. {@see UsageCommand} owns the page and its escaping; this is
 * its session half — it decides the scope from the argument and asks the
 * store's own rollups, so the grid and the tables the store answers from can
 * never disagree.
 *
 * C-SES-8.b adds the `sources` operand: the same snapshot folded by
 * settle-arm attribution, beside an advice block built from
 * {@see ContextSources}'s PUBLIC measurement API — what the session's own
 * configuration costs every request it shapes. The advice is a reading of
 * the live context, not a ledger fold: it never touches the store, and it
 * prices only the tool-schema half it can see (instruction files would need
 * a disk read the ledger page has no licence to make here — `/context`
 * remains the page that itemises them).
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

    /**
     * C-SES-8.b: the advice block's budget. A server under the floor is not
     * worth a line — the page is a ledger readout, not a nag — and three
     * notes cap the block so it cannot outshout the grid it annotates.
     * Measured in prompt-schema tokens, which is what the advice prices.
     */
    private const ADVICE_MIN_TOKENS = 1000;
    private const ADVICE_MAX_NOTES = 3;

    public function run(CommandContext $context, string $text): CommandResult
    {
        // CommandText::argument() is the house strip of the leading command
        // word — the run($context, $text) contract hands the WHOLE line.
        $argument = strtolower(trim(CommandText::argument($text)));
        $scopeAll = false;
        $windowDays = null;
        $view = 'ledger';
        foreach (preg_split('/\s+/', $argument, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($token === 'all') {
                $scopeAll = true;
            } elseif ($token === 'sources') {
                // C-SES-8.b: order-free like the windows — `/usage sources 7`
                // and `/usage 7 sources` are the same page; anything else a
                // junk token still gets the usage line, not a wider page.
                $view = 'sources';
            } elseif (isset(self::WINDOWS[(int) $token]) && ctype_digit($token)) {
                $windowDays = self::WINDOWS[(int) $token];
            } else {
                return CommandResult::reply($text, Lang::t('cmd.usage.usage'));
            }
        }

        return CommandResult::reply($text, UsageCommand::compose($this->facts($context, $scopeAll, $windowDays, $view)));
    }

    /**
     * @return array<string, mixed> the fact map {@see UsageCommand::compose()} parses
     */
    private function facts(CommandContext $context, bool $scopeAll, ?int $windowDays, string $view = 'ledger'): array
    {
        $store = $context->sessionStore;
        if (!$store instanceof EnhancedSessionStore) {
            return ['scopeAll' => $scopeAll, 'windowDays' => $windowDays, 'days' => null, 'models' => null, 'reason' => 'no durable ledger store is open here'];
        }

        $sessionId = $scopeAll ? null : $context->sessionId;
        if ($sessionId === null && !$scopeAll) {
            return ['scopeAll' => false, 'windowDays' => $windowDays, 'days' => null, 'models' => null, 'reason' => 'this session has no id yet; `/usage all` spans the whole ledger'];
        }

        $facts = self::rollupFacts($store, $sessionId, $windowDays, $scopeAll);
        if ($view === 'sources') {
            // The advice rides the LIVE context, not the snapshot: it prices
            // what the NEXT request will carry, which is the question the
            // note answers ("disable it" is worthless if the cost was only
            // historical). An unreadable ledger keeps days/models null and
            // compose() answers with the unavailable page before ever
            // reading these two keys.
            $facts['view'] = 'sources';
            $facts['advice'] = self::advice($context);
        }

        return $facts;
    }

    /**
     * C-SES-8.b: one note per expensive MCP server, the loudest first, in
     * {@see self::ADVICE_MAX_NOTES} at most. `ContextSources::measure()` with
     * a null instruction-file census is deliberate: the advice prices only
     * the tool-schema half, so the sources page never adds a disk read the
     * ledger read did not already make, and a context with no engine backend
     * simply has nothing to price — a server-less session earns an empty
     * block, printed as `none`, never a fabricated note.
     *
     * @return list<string> finished sentences; {@see UsageCommand} escapes and prints them
     */
    private static function advice(CommandContext $context): array
    {
        $servers = ContextSources::measure($context, null)->mcpServers ?? [];
        usort($servers, static fn (array $a, array $b): int => (int) $b['tokens'] <=> (int) $a['tokens']);

        $notes = [];
        foreach ($servers as $server) {
            if (count($notes) >= self::ADVICE_MAX_NOTES) {
                break;
            }
            $tokens = (int) $server['tokens'];
            if ($tokens < self::ADVICE_MIN_TOKENS) {
                continue;
            }
            $notes[] = Lang::t('cmd.usage.advice.mcp', [
                'server' => (string) $server['name'],
                'tokens' => '~' . number_format(max(1, (int) round($tokens / 1000))) . 'k',
            ]);
        }

        return $notes;
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
                // C-SES-8.b: the third fold of the SAME snapshot (the store's
                // single-read law, C-SES-9.a). The ledger page ignores it;
                // the CLI JSON grows it honestly — machine consumers read a
                // wider document, never a different one.
                'sources' => $rollups['sources'],
            ];
        } catch (\Throwable $e) {
            return ['scopeAll' => $scopeAll, 'windowDays' => $windowDays, 'days' => null, 'models' => null, 'reason' => $e->getMessage()];
        }
    }
}
