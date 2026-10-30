<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;

/**
 * Implements the `/usage` command — the durable spend ledger folded by day
 * and model, or (C-SES-8.b) by settle-arm source with its advice block.
 *
 * Usage:
 *   /usage       — this session's rows
 *   /usage all   — every session the ledger holds
 *   /usage 7     — the last seven whole UTC days, this session
 *   /usage 30 all — the same window across every session (order-free:
 *                  `/usage all 30` is the same page; OBS-3)
 *   /usage sources — the same ledger folded by settle-arm SOURCE instead of
 *                    by day (C-SES-8.b): which arm of the loop spent what,
 *                    with the delegated-share column the per-model fold has
 *                    no room for, plus an advice block naming what the
 *                    session's own context costs per request.
 *
 * OBS-3: the windows land because 7-of-30-day produce-errors with NO written
 * judgment was the audit's finding — an argument the ledger could answer was
 * rejected at the parser. A junk argument still gets the usage line: a typo'd
 * window must not silently print the whole history.
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
    private const REQUIRED_FACTS = ['scopeAll', 'windowDays', 'days', 'models', 'reason'];

    /** Column widths shared by the header and the rows — one sprintf, so the two cannot misalign. */
    private const ROW_FORMAT = '  %-11s %-22s %6s %9s %11s';

    /**
     * The `/usage sources` grid (C-SES-8.b): the day column's 11 cells make
     * room for a 22-cell source name and a fifth numeric column, because a
     * fold that attributes spend to settle arms must show the delegated
     * share beside the tokens it rode — the per-model grid has no column for
     * it and must not grow one (its byte shape is pinned history).
     */
    private const SOURCES_ROW_FORMAT = '  %-22s %6s %9s %9s %11s';

    /**
     * The page built from GIVEN folds rather than live stores — pure, so the
     * line-budget formula and the `≥` law can be pinned against exactly the
     * shapes the rollups produce.
     *
     * @param array<string, mixed> $facts
     *                                    - scopeAll: bool — all sessions or this one
     *                                    - windowDays: ?int — OBS-3: the last N whole
     *                                      UTC days, null spans the whole ledger
     *                                    - days: ?list<array{day: string, model: ?string, calls: int, totalTokens: int, costUsd: float, unpriced: bool}>
     *                                    - models: ?list<array{model: ?string, calls: int, totalTokens: int, costUsd: float, unpriced: bool}>
     *                                    - reason: string — the stated cause when days is null
     *                                    - view: string — optional (C-SES-8.b): 'ledger'
     *                                      (default — the day and model grids keep their
     *                                      exact historical bytes) or 'sources'
     *                                    - sources: list<array{source: ?string, calls: int, totalTokens: int, delegatedTokens: int, costUsd: float, unpriced: bool}> — REQUIRED only by the 'sources' view: the same snapshot's fold by settle-arm attribution, null source being a legacy or headless row the ledger never labelled
     *                                    - advice: list<string> — REQUIRED only by the 'sources' view: the pre-wrapped sentences naming what the session's own context costs per request (the host derives them from {@see ContextSources}'s public API; compose only prints them)
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

        // C-SES-8.b: the sources view reads the SAME snapshot's third fold;
        // the ledger page never touches the new keys, so its bytes (and their
        // pins) ride on untouched. An unreadable ledger answers with the
        // unavailable page for either view — "the ledger could not be read"
        // is the answer before any fold is printed.
        if ((string) ($facts['view'] ?? 'ledger') === 'sources') {
            foreach (['sources', 'advice'] as $key) {
                if (!\array_key_exists($key, $facts)) {
                    throw new \LogicException('UsageCommand::compose() is missing the ' . $key . ' fact — the sources view prints every section always, so every fact must be passed.');
                }
            }

            return self::composeSources($facts);
        }

        $days = $facts['days'];
        $models = $facts['models'];
        $anyUnpriced = self::anyUnpriced($days) || self::anyUnpriced($models);

        $lines = [
            Lang::t('cmd.usage.title'),
            Lang::t('cmd.usage.subtitle'),
            '',
            Lang::t('cmd.usage.scope', ['scope' => Lang::t($facts['scopeAll'] ? 'cmd.usage.scope.all' : 'cmd.usage.scope.session')]),
            // OBS-3: the window prints on its own line only when one was
            // asked for — the no-window page keeps its exact old bytes.
            ...(null === $facts['windowDays'] ? [] : [Lang::t('cmd.usage.window', ['days' => (string) $facts['windowDays']])]),
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
     * The `/usage sources` page (C-SES-8.b): the same header, scope, window
     * and totals lines the ledger page prints — the totals are the scope's,
     * not a fold's, so both views must state them identically — then the
     * attribution grid and the advice block. The completeness law rides
     * unchanged: both headers print ALWAYS, so the line budget stays a
     * formula: 10 fixed lines (title, subtitle, blank, scope, totals, blank,
     * source header, source columns, blank, advice header) plus a window
     * line, plus max(rows, none) per section, plus the bound footer.
     *
     * @param array<string, mixed> $facts the ledger branch has already vetted
     */
    private static function composeSources(array $facts): string
    {
        $sources = $facts['sources'];
        $advice = $facts['advice'];
        $models = $facts['models'];
        // A blind row anywhere in the scope blinds the page's dollars, in
        // either grid — the same law, now with the source fold as a third
        // witness beside day and model.
        $anyUnpriced = self::anyUnpriced($models) || self::anyUnpriced($sources);

        $lines = [
            Lang::t('cmd.usage.title'),
            Lang::t('cmd.usage.subtitle'),
            '',
            Lang::t('cmd.usage.scope', ['scope' => Lang::t($facts['scopeAll'] ? 'cmd.usage.scope.all' : 'cmd.usage.scope.session')]),
            ...(null === $facts['windowDays'] ? [] : [Lang::t('cmd.usage.window', ['days' => (string) $facts['windowDays']])]),
            self::totalsLine($models),
            '',
            Lang::t('cmd.usage.source', ['count' => (string) count($sources)]),
            self::sourceColumns(),
        ];
        if ($sources === []) {
            $lines[] = '  ' . Lang::t('cmd.usage.none');
        }
        foreach ($sources as $row) {
            $lines[] = self::sourcesRow($row);
        }

        $lines[] = '';
        $lines[] = Lang::t('cmd.usage.advice.header', ['count' => (string) count($advice)]);
        if ($advice === []) {
            $lines[] = '  ' . Lang::t('cmd.usage.none');
        }
        foreach ($advice as $note) {
            // The advice sentences name configured servers — foreign text
            // like every other string here, so each rides reportField()'s
            // choke on its way to the transcript: an MCP server renamed with
            // a newline cannot forge a section header either.
            $lines[] = '  - ' . Chat::reportField((string) $note);
        }

        if ($anyUnpriced) {
            $lines[] = Lang::t('cmd.usage.bound');
        }

        return implode("\n", $lines);
    }

    /**
     * One attribution line. A null source prints the same `(unrecorded)`
     * sentinel an old model-less row uses — the ledger's own history predates
     * attribution, and inventing a label for it would be a fabrication.
     *
     * @param array<string, mixed> $row
     */
    private static function sourcesRow(array $row): string
    {
        $source = $row['source'] ?? null;

        return \sprintf(
            self::SOURCES_ROW_FORMAT,
            Chat::reportField($source === null || $source === ''
                ? Lang::t('cmd.usage.unrecorded')
                : mb_strimwidth((string) $source, 0, 22, '…')),
            number_format((int) $row['calls']),
            number_format((int) $row['totalTokens']),
            number_format((int) $row['delegatedTokens']),
            self::amount((float) $row['costUsd'], (bool) $row['unpriced']),
        );
    }

    private static function sourceColumns(): string
    {
        return \sprintf(
            self::SOURCES_ROW_FORMAT,
            Lang::t('cmd.usage.col.source'),
            Lang::t('cmd.usage.col.calls'),
            Lang::t('cmd.usage.col.tokens'),
            Lang::t('cmd.usage.col.delegated'),
            Lang::t('cmd.usage.col.spend'),
        );
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
