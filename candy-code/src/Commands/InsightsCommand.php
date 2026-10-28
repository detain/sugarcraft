<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;

/**
 * Implements the insights report core (C-SES-8.c, GAP-OBS-06) — the spend
 * rollups folded by model AND by day, the sessions/turns-per-day roster, the
 * busiest projects, the kind split, the unpriced share and the imported
 * share, rendered as a text page and a self-contained HTML file.
 *
 * THE FACT MAP IS THE CONTRACT. {@see \SugarCraft\Code\Host\Commands\InsightsHostCommand}
 * gathers facts through public store reads and passes them here; this class
 * touches no store, makes no model call, and writes nothing. Both faces —
 * `compose()` for the transcript, `composeHtml()` for the exported file —
 * read ONE fact map and state the same figures, so the reply and the
 * artifact cannot disagree by construction.
 *
 * IT INHERITS TWO LAWS FROM `/usage`:
 *  - THE COMPLETENESS LAW: every section header prints ALWAYS — a count, a
 *    `none`, or a stated `unavailable` with its reason — so the page's line
 *    budget is the formula {@see lineBudget()} states, and silence is never
 *    an answer. An unreadable ledger collapses BOTH spend grids into one
 *    stated line; the session-shaped sections keep their counts because the
 *    roster the fold was asked about was readable.
 *  - THE `≥` LAW: a fold that saw any unpriced row is a fold whose dollars
 *    are a LOWER BOUND — every dollar figure for it carries `≥`, the totals
 *    line carries it, and one footer sentence closes the page. The blind
 *    ledger path prints it too: an unseeable ledger must never read as
 *    "nothing spent".
 *
 * THE GRID HEADINGS ARE STRUCTURE, NOT CONTENT. The two spend grids share
 * `/usage`'s exact column format; the roster headings print from the locale
 * catalogue with the store's COUNTS interpolated, and every store-derived
 * value — day, model, cwd, kind spelling, ledger reason — enters through
 * {@see Chat::reportField()}, so a forged directory name cannot add a line
 * or a header to the page. The HTML face escapes first instead: the file
 * outlives the terminal and a browser WILL execute what survives into it,
 * so every dynamic string passes through `ENT_QUOTES | ENT_SUBSTITUTE`
 * (hostile markup lands as text, malformed UTF-8 lands as the replacement
 * character, never as a silently empty cell).
 */
final class InsightsCommand
{
    /**
     * windowDays:int — the whole-UTC-day window asked for.
     * sessions:list<array{date:string, kind:string, cwd:string, turns:int,
     *            unpriced:bool, imported:bool}> — the roster, window applied.
     * spendDays/spendModels:null — the ledger could not be read at all;
     *            otherwise the same row shapes `/usage` folds print.
     * spendReason:string — the stated cause when a grid is null.
     */
    private const REQUIRED_FACTS = ['windowDays', 'sessions', 'spendDays', 'spendModels', 'spendReason'];

    /** The shared grid format — same widths as `UsageCommand::ROW_FORMAT`, one sprintf for header and rows. */
    private const GRID_FORMAT = '  %-11s %-22s %6s %9s %11s';

    /** Fixed constants by law: a heading the store can influence is a heading a store row can forge. */
    private const COLS_SPEND = '  Date        Model                    Calls    Tokens       Spend';

    private const COLS_PER_DAY = '  Date        Sessions   Turns';

    private const COLS_PROJECT = '  Project                                     Sessions   Turns';

    private const PER_DAY_FORMAT = '  %-11s %8s %7s';

    private const PROJECT_FORMAT = '  %-42s %8s %7s';

    private const KIND_FORMAT = '  %-14s %5s';

    /**
     * The four session kinds, in `SessionKind` enum-value order — the labels
     * the page always prints. A store row whose kind spelling is none of
     * these (data from before the enum, or from an import) keeps its own
     * extra row rather than silently landing in one of the four.
     */
    private const KIND_ROWS = ['main', 'branch', 'subagent', 'background'];

    /** Frame lines that print whatever the data says (title, subtitle, blank, window, totals). */
    private const FRAME_LINES = 5;

    /**
     * The readable Spend block's furniture: `blank + heading + columns` for
     * each of the two grids; the blind variant prints `blank + unavailable`
     * in their place. The `none` sentinel law belongs to the roster grids.
     */
    private const SPEND_LINES = 6;

    private const BLIND_SPEND_LINES = 2;

    /** `blank + heading + columns` — each roster grid's fixed furniture. */
    private const ROSTER_GRID_LINES = 3;

    /** `blank + heading` over the always-four kind rows (+ any foreign rows). */
    private const KINDS_LINES = 6;

    /** `blank + unpriced share + imported share`. */
    private const SHARE_LINES = 3;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $facts
     */
    public static function compose(array $facts): string
    {
        self::guardFacts('compose()', $facts);

        $blind = null === $facts['spendDays'] || null === $facts['spendModels'];
        $unreadable = $blind ? Chat::reportField((string) $facts['spendReason']) : '';
        $fold = self::foldRoster(is_array($facts['sessions']) ? $facts['sessions'] : []);

        $lines = [
            Lang::t('cmd.insights.title'),
            Lang::t('cmd.insights.subtitle'),
            '',
            Lang::t('cmd.insights.scope', ['days' => (string) $facts['windowDays']]),
            self::totalsLine($facts, $fold, $blind, $unreadable),
            '',
            $blind
                ? Lang::t('cmd.insights.unavailable', ['reason' => $unreadable])
                : Lang::t('cmd.insights.spend-model', ['count' => (string) count($facts['spendModels'])]),
        ];

        if (!$blind) {
            $lines[] = self::COLS_SPEND;
            foreach ($facts['spendModels'] as $row) {
                $lines[] = self::spendRow('', $row);
            }

            $lines[] = '';
            $lines[] = Lang::t('cmd.insights.spend-day', ['count' => (string) count($facts['spendDays'])]);
            $lines[] = self::COLS_SPEND;
            foreach ($facts['spendDays'] as $row) {
                $lines[] = self::spendRow((string) ($row['day'] ?? ''), $row);
            }
        }

        $lines[] = '';
        $lines[] = Lang::t('cmd.insights.per-day', ['count' => (string) $fold['dayCounts']]);
        $lines[] = self::COLS_PER_DAY;
        if ($fold['dayCounts'] === 0) {
            $lines[] = '  ' . Lang::t('cmd.insights.none');
        }
        foreach ($fold['perDay'] as $day => $cell) {
            $lines[] = sprintf(self::PER_DAY_FORMAT, Chat::reportField((string) $day), number_format($cell[0]), number_format($cell[1]));
        }

        $lines[] = '';
        $lines[] = Lang::t('cmd.insights.projects', ['count' => (string) $fold['projectCounts']]);
        $lines[] = self::COLS_PROJECT;
        if ($fold['projectCounts'] === 0) {
            $lines[] = '  ' . Lang::t('cmd.insights.none');
        }
        foreach ($fold['projects'] as $cwd => $cell) {
            $lines[] = sprintf(
                self::PROJECT_FORMAT,
                Chat::reportField(mb_strimwidth((string) $cwd, 0, 42, '…')),
                number_format($cell[0]),
                number_format($cell[1])
            );
        }

        $lines[] = '';
        $lines[] = Lang::t('cmd.insights.kinds');
        foreach (self::KIND_ROWS as $label) {
            $lines[] = sprintf(self::KIND_FORMAT, $label, number_format($fold['kinds'][$label] ?? 0));
        }
        foreach ($fold['foreignKinds'] as $label => $count) {
            $lines[] = sprintf(self::KIND_FORMAT, Chat::reportField((string) $label), number_format($count));
        }

        $lines[] = '';
        $lines[] = Lang::t('cmd.insights.unpriced-share', [
            'count' => (string) $fold['unpriced'],
            'share' => self::share($fold['unpriced'], $fold['sessions']),
        ]);
        $lines[] = Lang::t('cmd.insights.imported-share', [
            'count' => (string) $fold['imported'],
            'share' => self::share($fold['imported'], $fold['sessions']),
        ]);

        if (self::pageIsBound($facts, $fold, $blind)) {
            $lines[] = Lang::t('cmd.insights.bound');
        }

        return implode("\n", $lines);
    }

    /**
     * The same facts as a self-contained HTML page: inline style, no
     * external asset, every dynamic string escaped. This is the file that
     * lands on the exports shelf — the report's other face.
     *
     * @param array<string, mixed> $facts
     */
    public static function composeHtml(array $facts): string
    {
        self::guardFacts('composeHtml()', $facts);

        $blind = null === $facts['spendDays'] || null === $facts['spendModels'];
        $fold = self::foldRoster(is_array($facts['sessions']) ? $facts['sessions'] : []);
        $reason = (string) $facts['spendReason'];

        $out = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . self::escape(Lang::t('cmd.insights.title')) . "</title>\n"
            . "<style>\n"
            . "body{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;margin:1.5rem;background:#fff;color:#111}\n"
            . "h1{font-size:1.25rem;margin:0 0 .25rem}\n"
            . "p.sub{margin:0 0 1rem;color:#555}\n"
            . "p.meta{margin:.25rem 0}\n"
            . "h2{font-size:1rem;margin:1.25rem 0 .25rem;border-bottom:1px solid #ccc}\n"
            . "table{border-collapse:collapse;font-size:.85rem}\n"
            . "th,td{border:1px solid #ddd;padding:.2rem .5rem;text-align:right;white-space:pre-wrap;overflow-wrap:anywhere}\n"
            . "th{background:#f4f4f4}\n"
            . "td.l,th.l{text-align:left}\n"
            . "tr.none td{color:#777;font-style:italic}\n"
            . "p.unavailable{color:#a00;margin:.5rem 0}\n"
            . "footer{margin-top:1.25rem;color:#666;font-size:.8rem}\n"
            . "</style>\n</head>\n<body>\n"
            . '<h1>' . self::escape(Lang::t('cmd.insights.title')) . "</h1>\n"
            . '<p class="sub">' . self::escape(Lang::t('cmd.insights.subtitle')) . "</p>\n"
            . '<p class="meta">' . self::escape(Lang::t('cmd.insights.scope', ['days' => (string) $facts['windowDays']])) . "</p>\n"
            . '<p class="meta">' . self::escape(self::totalsLine($facts, $fold, $blind, $reason)) . "</p>\n"
            . '<h2>' . self::escape(Lang::t('cmd.insights.spend-heading')) . "</h2>\n";

        if ($blind) {
            $out .= '<p class="unavailable">' . self::escape(Lang::t('cmd.insights.unavailable', ['reason' => $reason])) . "</p>\n";
        } else {
            $out .= self::spendTable('cmd.insights.spend-model', $facts['spendModels'], false);
            $out .= self::spendTable('cmd.insights.spend-day', $facts['spendDays'], true);
        }

        $out .= '<h2>' . self::escape(Lang::t('cmd.insights.per-day', ['count' => (string) $fold['dayCounts']])) . "</h2>\n";
        $out .= self::table(self::htmlColumns3(Lang::t('cmd.insights.col.day')), self::perDayRows($fold), $fold['dayCounts']);

        $out .= '<h2>' . self::escape(Lang::t('cmd.insights.projects', ['count' => (string) $fold['projectCounts']])) . "</h2>\n";
        $out .= self::table(self::htmlColumns3(Lang::t('cmd.insights.col.project')), self::projectRows($fold), $fold['projectCounts']);

        $out .= '<h2>' . self::escape(Lang::t('cmd.insights.kinds')) . "</h2>\n"
            . self::table(
                ['<th class="l">' . self::escape(Lang::t('cmd.insights.col.kind')) . '</th>', '<th>' . self::escape(Lang::t('cmd.insights.col.count')) . '</th>'],
                self::kindRows($fold),
                count(self::KIND_ROWS) + count($fold['foreignKinds'])
            );

        // The two share lines are the same locale sentences the text page
        // prints, interpolated and escaped — one wording, two faces.
        $out .= '<table>' . "\n" . "<tbody>\n"
            . '<tr><td class="l" colspan="2">' . self::escape(Lang::t('cmd.insights.unpriced-share', [
                'count' => (string) $fold['unpriced'],
                'share' => self::share($fold['unpriced'], $fold['sessions']),
            ])) . "</td></tr>\n"
            . '<tr><td class="l" colspan="2">' . self::escape(Lang::t('cmd.insights.imported-share', [
                'count' => (string) $fold['imported'],
                'share' => self::share($fold['imported'], $fold['sessions']),
            ])) . "</td></tr>\n"
            . "</tbody>\n</table>\n";

        if (self::pageIsBound($facts, $fold, $blind)) {
            $out .= '<footer>' . self::escape(Lang::t('cmd.insights.bound')) . "</footer>\n";
        }

        return $out . "</body>\n</html>\n";
    }

    /**
     * The completeness law as code: frame lines, one line per group (or a
     * `none`/unavailable sentinel where a group would be silent), the four
     * kind rows plus any foreign spelling, the two share lines, and the
     * bound footer when the page carries any lower bound.
     *
     * @param array<string, mixed> $facts
     */
    public static function lineBudget(array $facts): int
    {
        self::guardFacts('lineBudget()', $facts);

        $blind = null === $facts['spendDays'] || null === $facts['spendModels'];
        $fold = self::foldRoster(is_array($facts['sessions']) ? $facts['sessions'] : []);

        // Readable: `blank + heading + columns` per grid plus that grid's
        // rows. Blind: `blank + unavailable`. The roster grids keep the
        // `none` sentinel law (max(1,count)); the spend grids do not —
        // their column lines already stand beside an empty fold.
        $spend = $blind
            ? self::BLIND_SPEND_LINES
            : self::SPEND_LINES + count($facts['spendModels']) + count($facts['spendDays']);

        return self::FRAME_LINES
            + $spend
            + 2 * self::ROSTER_GRID_LINES
            + max(1, $fold['dayCounts'])
            + max(1, $fold['projectCounts'])
            + self::KINDS_LINES + count($fold['foreignKinds'])
            + self::SHARE_LINES
            + (self::pageIsBound($facts, $fold, $blind) ? 1 : 0);
    }

    // ── shared assembly ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $facts
     */
    private static function guardFacts(string $method, array $facts): void
    {
        foreach (self::REQUIRED_FACTS as $key) {
            if (!\array_key_exists($key, $facts)) {
                throw new \LogicException('InsightsCommand::' . $method . ' is missing the ' . $key . ' fact — every section prints always, so every fact must be passed.');
            }
        }
    }

    /**
     * @param list<array{date:string, kind:string, cwd:string, turns:int, unpriced:bool, imported:bool}> $roster
     *
     * @return array{sessions:int, turns:int, dayCounts:int, projectCounts:int, unpriced:int, imported:int,
     *     perDay:array<string,array{int,int}>, projects:array<string,array{int,int}>,
     *     kinds:array<string,int>, foreignKinds:array<string,int>}
     */
    private static function foldRoster(array $roster): array
    {
        $known = array_flip(self::KIND_ROWS);
        $perDay = [];
        $projects = [];
        $kinds = array_fill_keys(self::KIND_ROWS, 0);
        $foreign = [];
        $unpriced = 0;
        $imported = 0;
        $turns = 0;

        foreach ($roster as $row) {
            $date = (string) ($row['date'] ?? '');
            $perDay[$date][0] = ($perDay[$date][0] ?? 0) + 1;
            $perDay[$date][1] = ($perDay[$date][1] ?? 0) + (int) ($row['turns'] ?? 0);

            $cwd = (string) ($row['cwd'] ?? '');
            $projects[$cwd][0] = ($projects[$cwd][0] ?? 0) + 1;
            $projects[$cwd][1] = ($projects[$cwd][1] ?? 0) + (int) ($row['turns'] ?? 0);

            $kind = (string) ($row['kind'] ?? '');
            if (isset($known[$kind])) {
                ++$kinds[$kind];
            } else {
                $foreign[$kind] = ($foreign[$kind] ?? 0) + 1;
            }

            if (($row['unpriced'] ?? false)) {
                ++$unpriced;
            }

            if (($row['imported'] ?? false)) {
                ++$imported;
            }

            $turns += (int) ($row['turns'] ?? 0);
        }

        krsort($perDay);
        arsort($projects);

        return [
            'sessions' => count($roster),
            'turns' => $turns,
            'dayCounts' => count($perDay),
            'projectCounts' => count($projects),
            'unpriced' => $unpriced,
            'imported' => $imported,
            'perDay' => $perDay,
            'projects' => $projects,
            'kinds' => $kinds,
            'foreignKinds' => $foreign,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $fold
     */
    private static function totalsLine(array $facts, array $fold, bool $blind, string $reason): string
    {
        if ($blind) {
            return Lang::t('cmd.insights.totals', [
                'sessions' => number_format($fold['sessions']),
                'turns' => number_format($fold['turns']),
                'amount' => Lang::t('cmd.insights.amount-unmeasured', ['reason' => $reason]),
            ]);
        }

        return Lang::t('cmd.insights.totals', [
            'sessions' => number_format($fold['sessions']),
            'turns' => number_format($fold['turns']),
            'amount' => self::amounts($facts),
        ]);
    }

    /**
     * The totals' money clause, folded from the per-model model fold — one
     * source, so the totals line and the grid rows cannot disagree.
     *
     * @param array<string, mixed> $facts
     */
    private static function amounts(array $facts): string
    {
        $calls = 0;
        $tokens = 0;
        $cost = 0.0;
        $bound = false;
        foreach ($facts['spendModels'] as $row) {
            $calls += (int) $row['calls'];
            $tokens += (int) $row['totalTokens'];
            $cost += (float) $row['costUsd'];
            $bound = $bound || (bool) $row['unpriced'];
        }

        return Lang::t('cmd.insights.amount', [
            'calls' => number_format($calls),
            'tokens' => number_format($tokens),
            'cost' => self::amount($cost, $bound),
        ]);
    }

    /** @param array<string, mixed> $row */
    private static function spendRow(string $day, array $row): string
    {
        return sprintf(
            self::GRID_FORMAT,
            Chat::reportField($day),
            Chat::reportField(self::modelName((string) ($row['model'] ?? ''))),
            number_format((int) $row['calls']),
            number_format((int) $row['totalTokens']),
            self::amount((float) $row['costUsd'], (bool) $row['unpriced'])
        );
    }

    /** Mirrors `/usage`: an empty model spelling is the store's `(unrecorded)` case. */
    private static function modelName(string $model): string
    {
        return $model === '' ? Lang::t('cmd.insights.unrecorded') : mb_strimwidth($model, 0, 22, '…');
    }

    /** The `≥` law in one call: a bound fold's dollars say so. */
    private static function amount(float $cost, bool $bound): string
    {
        return Lang::t('cmd.insights.cost', ['bound' => $bound ? '≥' : '', 'value' => number_format($cost, 4)]);
    }

    /** @param array<string, mixed> $facts */
    private static function pageIsBound(array $facts, array $fold, bool $blind): bool
    {
        if ($blind || $fold['unpriced'] > 0) {
            return true;
        }

        foreach ($facts['spendModels'] as $row) {
            if ((bool) $row['unpriced']) {
                return true;
            }
        }

        return false;
    }

    private static function share(int $count, int $total): string
    {
        return number_format($total === 0 ? 0.0 : 100.0 * $count / $total, 1) . '%';
    }

    // ── HTML assembly ────────────────────────────────────────────────────

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param string $headingKey
     * @param list<array<string, mixed>> $rows
     */
    private static function spendTable(string $headingKey, array $rows, bool $withDay): string
    {
        $columns = [
            '<th class="l">' . self::escape(Lang::t('cmd.insights.col.day')) . '</th>',
            '<th class="l">' . self::escape(Lang::t('cmd.insights.col.model')) . '</th>',
            '<th>' . self::escape(Lang::t('cmd.insights.col.calls')) . '</th>',
            '<th>' . self::escape(Lang::t('cmd.insights.col.tokens')) . '</th>',
            '<th>' . self::escape(Lang::t('cmd.insights.col.spend')) . '</th>',
        ];

        $body = array_map(
            static fn (array $row): string => '<tr><td class="l">' . self::escape($withDay ? (string) ($row['day'] ?? '') : '')
                . '</td><td class="l">' . self::escape(self::modelName((string) ($row['model'] ?? '')))
                . '</td><td>' . self::escape(number_format((int) $row['calls']))
                . '</td><td>' . self::escape(number_format((int) $row['totalTokens']))
                . '</td><td>' . self::escape(self::amount((float) $row['costUsd'], (bool) $row['unpriced'])) . '</td></tr>',
            $rows
        );

        return '<h2>' . self::escape(Lang::t($headingKey, ['count' => (string) count($rows)])) . "</h2>\n" . self::table($columns, $body, count($rows));
    }

    /** @return list<string> */
    private static function htmlColumns3(string $lead): array
    {
        return [
            '<th class="l">' . self::escape($lead) . '</th>',
            '<th>' . self::escape(Lang::t('cmd.insights.col.sessions')) . '</th>',
            '<th>' . self::escape(Lang::t('cmd.insights.col.turns')) . '</th>',
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $bodyRows
     */
    private static function table(array $columns, array $bodyRows, int $count): string
    {
        $out = "<table>\n<thead>\n<tr>" . implode('', $columns) . "</tr>\n</thead>\n<tbody>\n";
        if ($count === 0) {
            $out .= '<tr class="none"><td colspan="' . count($columns) . '">' . self::escape(Lang::t('cmd.insights.none')) . "</td></tr>\n";
        }
        foreach ($bodyRows as $row) {
            $out .= $row . "\n";
        }

        return $out . "</tbody>\n</table>\n";
    }

    /** @param array<string, mixed> $fold */
    private static function perDayRows(array $fold): array
    {
        $rows = [];
        foreach ($fold['perDay'] as $day => $cell) {
            $rows[] = '<tr><td class="l">' . self::escape((string) $day) . '</td><td>' . self::escape(number_format($cell[0]))
                . '</td><td>' . self::escape(number_format($cell[1])) . '</td></tr>';
        }

        return $rows;
    }

    /** @param array<string, mixed> $fold */
    private static function projectRows(array $fold): array
    {
        $rows = [];
        foreach ($fold['projects'] as $cwd => $cell) {
            $rows[] = '<tr><td class="l">' . self::escape((string) $cwd) . '</td><td>' . self::escape(number_format($cell[0]))
                . '</td><td>' . self::escape(number_format($cell[1])) . '</td></tr>';
        }

        return $rows;
    }

    /** @param array<string, mixed> $fold */
    private static function kindRows(array $fold): array
    {
        $rows = [];
        foreach (self::KIND_ROWS as $label) {
            $rows[] = '<tr><td class="l">' . self::escape($label) . '</td><td>' . self::escape(number_format($fold['kinds'][$label] ?? 0)) . '</td></tr>';
        }

        foreach ($fold['foreignKinds'] as $label => $count) {
            $rows[] = '<tr><td class="l">' . self::escape((string) $label) . '</td><td>' . self::escape(number_format($count)) . '</td></tr>';
        }

        return $rows;
    }
}
