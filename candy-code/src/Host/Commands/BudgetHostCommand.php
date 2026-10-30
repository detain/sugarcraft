<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Util\TokenTracker;

/**
 * `/budget` — where this session stands, read-only (C-WEB-2.e).
 *
 * The bare form answers {@see SpendLedger::statusLine()} over the session's
 * own tracker against the launch cap, the same two inputs the TUI's status
 * half reads: {@see CommandContext::$spendTracker} is the tracker the host
 * accounts turns into, and {@see CommandContext::$spendCap} carries the
 * `--max-cost` this server launched with (`SessionHost::commandContext()`
 * threads both). The lower-bound law (SES-1) rides statusLine itself: a
 * session that used an unpriced model reads `≥$…`, never a bare figure.
 *
 * The SETTING forms refuse out loud. `/budget 5` and `/budget off` are
 * per-session decisions on the TUI, where each Chat owns its cap; on a headless
 * host the cap is one number on the workspace, shared by every session the
 * server holds — let one client re-thread it would silently move the money
 * ceiling under the others' feet (the web≥-unpriced class of defect: a control
 * the two sides mean differently). So an amount or a clear answers
 * `host.budget.server_cap` and the cap is left exactly where it was; a
 * launch-time tighten-per-session override is deliberately out of scope here.
 *
 * No tracker on the context is not a failure: a fresh empty
 * {@see TokenTracker} answers, and statusLine says "not reported" — the same
 * honest shape an offline TUI session prints.
 */
final class BudgetHostCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $argument = CommandText::argument($text);
        $tracker = $context->spendTracker ?? new TokenTracker();
        $ledger = $this->ledger($context);

        if ($argument === '') {
            return CommandResult::reply($text, $ledger->statusLine($tracker, $context->spendCap));
        }

        $clears = in_array(strtolower($argument), ['off', 'none', 'clear'], true);
        $amount = ltrim($argument, '$');
        $sets = is_numeric($amount) && SpendLedger::isUsableCap((float) $amount);

        if ($clears || $sets) {
            return CommandResult::reply($text, Lang::t('host.budget.server_cap', ['requested' => $argument]));
        }

        return CommandResult::reply($text, Lang::t('host.budget.usage'));
    }

    /** The workspace's ledger when it registered one, else a default — the same choice `SessionHost::ledger()` makes. */
    private function ledger(CommandContext $context): SpendLedger
    {
        $service = $context->workspace?->service(SpendLedger::class);

        return $service instanceof SpendLedger ? $service : SpendLedger::new();
    }
}
