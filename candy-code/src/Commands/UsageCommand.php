<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;

/**
 * Implements the `/usage` command — the durable spend ledger folded by day
 * and model.
 *
 * Usage:
 *   /usage       — this session's rows
 *   /usage all   — every session the ledger holds
 *
 * WHAT `/budget` IS NOT. `/budget` reads the in-process tracker: what THIS
 * launch has reported, reset by every restart. `/usage` reads the
 * `usage_records` table (SES-1): what every launch that wrote to this
 * database accounted, still there after the tracker is gone. The two are
 * different questions and print different numbers by design; a resumed
 * session shows the launch's tracker beside the whole history (the
 * tracker's reseed keeps even those from silently disagreeing on dollars).
 *
 * THE COMPLETENESS LAW IT RIDES (from `/notices`, whose test template this
 * mirrors): every section header prints ALWAYS — a count, a `none`, or a
 * stated `unavailable` with its reason — so the page's line budget is a
 * formula, and silence is never an answer.
 *
 * THE `≥` LAW IT INHERITS (SES-1): a fold whose rows include one unpriced
 * call is a fold whose dollars are a LOWER BOUND, and every dollar figure
 * for it says so — per group on the grid, per group in the totals, with one
 * footer sentence for the whole page. Day and model names enter through
 * {@see Chat::reportField()} like every other foreign string here: a
 * directory-named model on an old row must not be able to fabricate a
 * section header.
 */
final class UsageCommand
{
    /**
     * days/models null means "the ledger could not be read at all" and the
     * reason names it; the empty-LEDGER case is both lists present and
     * empty, which renders the grid with `none` sections, not an apology.
     */
    private const REQUIRED_FACTS = ['scopeAll', 'days', 'models', 'reason'];

    /** Column widths shared by the header and the rows — one sprintf, so the two cannot misalign. */
    private const ROW_FORMAT = '  %-11s %-22s %6s %9s %11s';

    /**
     * The page built from GIVEN folds rather than live stores — pure, so the
     * line-budget formula and the `≥` law can be pinned against exactly the
     * shapes the rollups produce.
     *
     * @param array<string, mixed> $facts
     *                                    - scopeAll: bool — all sessions or this one
     *                                    - days: ?list<array{day: string, model: ?string, calls: int, totalTokens: int, costUsd: float, unpriced: bool}>
     *                                    - models: ?list<array{model: ?string, calls: int, totalTokens: int, costUsd: float, unpriced: bool}>
     *                                    - reason: string — the stated cause when days is null
     */
    public static function compose(array $facts): string
    {
        foreach (self::REQUIRED_FACTS as $key) {
            if (!\array_key_exists($key, $facts)) {
                throw new \LogicException('UsageCommand::compose() is missing the ' . $key . ' fact — every section prints always, so every fact must be passed.');
            }
        }

        if ($facts['days'] === null || $facts['models'] === null) {
            return implode("\n", [
                Lang::t('cmd.usage.title'),
                Lang::t('cmd.usage.subtitle'),
                '',
                Lang::t('cmd.usage.unavailable', ['reason' => Chat::reportField((string) $facts['reason'])]),
            ]);
        }

        $days = $facts['days'];
        $models = $facts['models'];
        $anyUnpriced = self::anyUnpriced($days) || self::anyUnpriced($models);

        $lines = [
            Lang::t('cmd.usage.title'),
            Lang::t('cmd.usage.subtitle'),
            '',
            Lang::t('cmd.usage.scope', ['scope' => Lang::t($facts['scopeAll'] ? 'cmd.usage.scope.all' : 'cmd.usage.scope.session')]),
            self::totalsLine($models),
            '',
            Lang::t('cmd.usage.day', ['count' => (string) count($days)]),
            self::columns(),
        ];
        if ($days === []) {
            $lines[] = '  ' . Lang::t('cmd.usage.none');
        }
        foreach ($days as $row) {
            $lines[] = self::gridRow((string) $row['day'], (string) ($row['model'] ?? ''), $row);
        }

        $lines[] = '';
        $lines[] = Lang::t('cmd.usage.model', ['count' => (string) count($models)]);
        $lines[] = self::columns();
        if ($models === []) {
            $lines[] = '  ' . Lang::t('cmd.usage.none');
        }
        foreach ($models as $row) {
            $lines[] = self::gridRow('', (string) ($row['model'] ?? ''), $row);
        }

        if ($anyUnpriced) {
            $lines[] = Lang::t('cmd.usage.bound');
        }

        return implode("\n", $lines);
    }

    /**
     * The scope's whole ledger, in the same units the grid prints. Derived
     * from the per-model fold — one source, so the totals and the rows
     * cannot disagree by construction.
     */
    private static function totalsLine(array $models): string
    {
        $calls = 0;
        $tokens = 0;
        $cost = 0.0;
        $bound = false;
        foreach ($models as $row) {
            $calls += (int) $row['calls'];
            $tokens += (int) $row['totalTokens'];
            $cost += (float) $row['costUsd'];
            $bound = $bound || (bool) $row['unpriced'];
        }

        return Lang::t('cmd.usage.totals', [
            'calls' => number_format($calls),
            'tokens' => number_format($tokens),
            'amount' => self::amount($cost, $bound),
        ]);
    }

    /**
     * One grid line. The day column doubles as the model column's home on
     * the per-model fold (whose rows have no day), so both grids share the
     * exact alignment of the header.
     */
    private static function gridRow(string $day, string $model, array $row): string
    {
        return \sprintf(
            self::ROW_FORMAT,
            Chat::reportField($day),
            Chat::reportField($model === '' ? Lang::t('cmd.usage.unrecorded') : mb_strimwidth($model, 0, 22, '…')),
            number_format((int) $row['calls']),
            number_format((int) $row['totalTokens']),
            self::amount((float) $row['costUsd'], (bool) $row['unpriced']),
        );
    }

    /** A dollar figure with its honesty prefix: `≥` for a bound, `$` for a measurement. */
    private static function amount(float $cost, bool $bound): string
    {
        return ($bound ? '≥$' : '$') . number_format($cost, 4);
    }

    private static function columns(): string
    {
        return \sprintf(
            self::ROW_FORMAT,
            Lang::t('cmd.usage.col.day'),
            Lang::t('cmd.usage.col.model'),
            Lang::t('cmd.usage.col.calls'),
            Lang::t('cmd.usage.col.tokens'),
            Lang::t('cmd.usage.col.spend'),
        );
    }

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
