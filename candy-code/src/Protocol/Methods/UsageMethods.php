<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Host\Commands\UsageHostCommand;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;

/**
 * `usage.rollup` (C-SES-8.a): the durable spend ledger as structure — the
 * same fold `/usage` prints, so the web panel and the terminal page can
 * never disagree about the same ledger at the same instant.
 *
 * ONE SNAPSHOT (the C-SES-9.a law): the answer is built from a single
 * {@see UsageHostCommand::rollupFacts()} read, and the totals are derived
 * from that read's own per-model fold — the same derivation
 * {@see \SugarCraft\Code\Commands\UsageCommand::compose()} makes its totals
 * line from. A client that wants the grid and the model table together
 * gets them from one transaction, not two reads that may straddle a settle.
 *
 * THE `≥` LAW IT CARRIES (SES-1): when any folded row was written by an
 * unpriced call, `unpriced` is true and the dollar figures are a LOWER
 * BOUND — the flag travels with the answer so no surface can print a bare
 * "$" for a bound. A panel renders it exactly as `/usage` does.
 *
 * WHY THE REASON IS DATA: a host without an enhanced store — a plain
 * SessionStore, or a launch whose ledger failed to open — does not answer
 * with an empty grid pretending nothing was spent. It answers with empty
 * folds AND a stated reason, because "nothing was spent" and "nothing can
 * be seen" are different answers (the rule UsageHostCommand states for the
 * terminal page, restated on the wire).
 *
 * RETENTION RIDES THE ANSWER READ-ONLY: `retentionDays` is the launch's
 * effective {@see Bootstrap::sessionRetentionDays()} — the setting the
 * panel links to Settings for, never writes. This method opens no write
 * path (the design's law for the panel applies to the RPC too).
 */
final class UsageMethods
{
    /** OBS-3: the windows the ledger answers for — the same pair UsageHostCommand parses. */
    private const WINDOWS = [7 => 7, 30 => 30];

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('usage.rollup', Scope::Read, 'The durable usage ledger folded by day and model — one snapshot of the same read `/usage` prints, with derived totals, the unpriced lower-bound flag, and this launch\'s session-retention setting (read-only). scope "session" narrows to sessionId; scope "all" spans every session the ledger holds; days trims to the last 7 or 30 whole UTC days.', self::rollup(...)));
    }

    /** @return array<string, mixed> */
    private static function rollup(CallContext $call, Params $params): array
    {
        $scope = $params->enum('scope', ['session', 'all']);
        $sessionId = $scope === 'session' ? SessionMethods::sessionId($params) : null;
        $windowDays = null;
        if ($params->has('days')) {
            $asked = $params->int('days');
            if (!isset(self::WINDOWS[$asked])) {
                throw RpcError::invalidParams('days must be 7 or 30 — anything else is refused, not folded into the whole ledger');
            }
            $windowDays = self::WINDOWS[$asked];
        }

        $store = $call->server->store();
        $retentionDays = Bootstrap::sessionRetentionDays();
        if ($store === null) {
            return self::answer([], [], false, 'no durable ledger store is open here', $retentionDays);
        }

        $facts = UsageHostCommand::rollupFacts($store, $sessionId, $windowDays, $scope === 'all');
        if ($facts['days'] === null || $facts['models'] === null) {
            return self::answer([], [], false, (string) $facts['reason'], $retentionDays);
        }

        /** @var list<array<string, mixed>> $days */
        $days = $facts['days'];
        /** @var list<array<string, mixed>> $models */
        $models = $facts['models'];
        $bound = self::anyUnpriced($days) || self::anyUnpriced($models);

        return self::answer($days, $models, $bound, '', $retentionDays);
    }

    /**
     * The published shape: folds as-is (they carry their own per-row
     * `unpriced` bit), totals derived from the per-model fold — one source,
     * so totals and rows cannot disagree by construction — plus the flag,
     * the retention setting and the reason.
     *
     * @param list<array<string, mixed>> $days
     * @param list<array<string, mixed>> $models
     * @return array<string, mixed>
     */
    private static function answer(array $days, array $models, bool $unpriced, string $reason, int $retentionDays): array
    {
        $calls = 0;
        $tokens = 0;
        $cost = 0.0;
        foreach ($models as $row) {
            $calls += (int) $row['calls'];
            $tokens += (int) $row['totalTokens'];
            $cost += (float) $row['costUsd'];
        }

        return [
            'days' => $days,
            'models' => $models,
            'totals' => ['calls' => $calls, 'totalTokens' => $tokens, 'costUsd' => $cost],
            'unpriced' => $unpriced,
            'retentionDays' => $retentionDays,
            'reason' => $reason,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function anyUnpriced(array $rows): bool
    {
        foreach ($rows as $row) {
            if ((bool) $row['unpriced']) {
                return true;
            }
        }

        return false;
    }
}
