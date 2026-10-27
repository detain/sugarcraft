<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Context\ContextCategory;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Util\TokenCount;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

/**
 * The context sidebar box (P-CTX.c) — the live view over the attribution
 * model P-CTX.a welded: a stacked category bar, one row per category with a
 * distinct glyph and theme colour, sub-rows on a drilled category, and a
 * collapsed summary of the totals.
 *
 * PURE BY DESIGN. Unlike the sibling panes this one renders from a
 * {@see ContextPaneModel} rather than from `App` — the measurement lives
 * behind the model and the shell wiring (the `Pane` case, the dock, the
 * click-zone plumbing, `layout(App)`) arrives with P-CTX.e, which will
 * adapt this renderer the way it adapts its siblings. Nothing here names a
 * colour: every hue is read from the {@see Theme} (the ShellContrast law
 * over `src/Tui/`).
 *
 * THE GLYPHS ARE THE DISTINCTNESS CARRIER. A theme offers eight or nine
 * distinct colours for twelve tokens at best (pair() only separates tokens
 * with different sources; `userLabel`/`shellPrimary`, `assistantLabel`/
 * `shellSuccess` and the `systemLabel`/`shellMuted`/`border` family
 * coincide per palette), so eleven categories cannot each hold a unique
 * hue. Every category gets its OWN block glyph instead — visible under
 * NO_COLOR where colours vanish — and the colour map is arranged so that
 * no two rows that can coincide in any shipped palette are ever adjacent
 * (pinned in both directions by ContextPaneTest walking every palette).
 *
 * UNMEASURED IS NEVER ZERO. A snapshot leg that was never measured shows
 * the "not measured" wording; before the first snapshot the whole box
 * reads "measuring…".
 */
final class ContextPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    /** Click-key prefix marking a row's category (zone vocabulary for P-CTX.e). */
    public const ROW_KEY_PREFIX = 'cat:';

    /** The click key of the "dump prompt" row. */
    public const DUMP_KEY = 'raw';

    /** The click key of the collapsed total row (the box's expander). */
    public const TOGGLE_KEY = 'toggle';

    /**
     * One glyph per category, keyed by the enum's value — PHP const arrays
     * cannot key on enum cases. All width-1, all pairwise distinct; the
     * block shades read the same visual family as the single '█' in
     * {@see ContextCategories::bar()}.
     *
     * @var array<string, string>
     */
    private const GLYPHS = [
        'system-prompt' => '█',
        'system-tools' => '▓',
        'mcp-tools' => '▒',
        'custom-agents' => '░',
        'memory-files' => '▚',
        'project-memory' => '▞',
        'repo-map' => '▛',
        'skills' => '▜',
        'messages' => '▝',
        'autocompact-buffer' => '▘',
        'free-space' => '▙',
    ];

    /**
     * The theme token each category's rows take their colour from. The
     * ordering keeps every pair that can coincide in a shipped palette at
     * least one row apart: `userLabel`≡`shellPrimary` on rows 1/3,
     * `assistantLabel`≡`shellSuccess` on 2/5, and the
     * `systemLabel`/`shellMuted`/`border` family spread over 4/7/10.
     *
     * @var array<string, string>
     */
    private const COLOR_TOKENS = [
        'system-prompt' => 'userLabel',
        'system-tools' => 'assistantLabel',
        'mcp-tools' => 'shellPrimary',
        'custom-agents' => 'shellMuted',
        'memory-files' => 'shellSuccess',
        'project-memory' => 'shellWarning',
        'repo-map' => 'systemLabel',
        'skills' => 'shellError',
        'messages' => 'shellForeground',
        'autocompact-buffer' => 'border',
        'free-space' => 'shellInfo',
    ];

    public static function renderModel(ContextPaneModel $model, Theme $theme, int $width, int $rows): string
    {
        return self::layoutModel($model, $theme, $width, $rows)[0];
    }

    /**
     * The box and the click key of each BODY line (null where a line is not
     * a row head). Same contract shape as the sibling panes' `layout()`,
     * without the scroll slot: the sidebar box is short by construction and
     * P-CTX.e clamps it when it docks.
     *
     * @return array{0: string, 1: list<?string>}
     */
    public static function layoutModel(ContextPaneModel $model, Theme $theme, int $width, int $rows): array
    {
        $inner = max(1, $width - self::CHROME_COLS);
        $budget = max(1, $rows - self::CHROME_ROWS);

        [$lines, $keys] = $model->expanded
            ? self::expandedBody($model, $theme, $inner)
            : self::collapsedBody($model, $theme, $inner);

        $lines = array_slice($lines, 0, $budget);
        $keys = array_slice($keys, 0, $budget);

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' ' . Lang::t('tui.context.title') . ' '))
            ->padding(0, 1)
            // width() is sugar-style's CONTENT width — border and padding
            // are added outside it, so the box as a whole lands on $width.
            ->width(max(1, $inner))
            ->borderForeground($theme->border);

        return [PaneFrame::render($st, implode("\n", $lines), $theme->border), $keys];
    }

    /** The category's marker glyph — distinct across all eleven. */
    public static function glyph(ContextCategory $category): string
    {
        return self::GLYPHS[$category->value];
    }

    /** The theme colour the category's rows are painted in. */
    public static function color(ContextCategory $category, Theme $theme): Color
    {
        $token = self::COLOR_TOKENS[$category->value];

        return $theme->{$token};
    }

    /**
     * The stacked category bar: every row's apportioned run in its own
     * colour, concatenated into one row. A bar cell is a share of history,
     * so an unmeasured row simply holds no cell (bar() apportioned over the
     * known weights).
     */
    public static function barLine(ContextPaneModel $model, Theme $theme, int $inner): string
    {
        if ($model->categories === null) {
            return Style::new()->foreground($theme->shellMuted)
                ->render(Width::truncate(Lang::t('tui.context.measuring'), $inner));
        }

        $runs = $model->categories->bar($inner);
        $out = '';
        foreach ($model->categories->rows as $index => $row) {
            $run = $runs[$index] ?? '';
            if ($run === '') {
                continue;
            }
            $out .= Style::new()->foreground(self::color($row['category'], $theme))->render($run);
        }

        return $out;
    }

    /**
     * Collapsed: the bar, then the total line `81K / 200K · 41% ▸` (the ▸
     * marks the whole box as the expander — key {@see self::TOGGLE_KEY}).
     *
     * @return array{0: list<string>, 1: list<?string>}
     */
    private static function collapsedBody(ContextPaneModel $model, Theme $theme, int $inner): array
    {
        $used = $model->usedTokens();
        $usedLeg = $used === null
            ? Lang::t('tui.context.unmeasured')
            : TokenCount::compact($used);
        $percent = $model->percentOfWindow();
        $figures = ['used' => $usedLeg, 'window' => TokenCount::compact($model->window)];
        $total = $percent === null
            ? Lang::t('tui.context.total_partial', $figures)
            : Lang::t('tui.context.total', $figures + ['percent' => (string) $percent]);

        return [
            [
                self::barLine($model, $theme, $inner),
                Style::new()->foreground($theme->shellForeground)
                    ->render(Width::truncate($total, max(1, $inner - 2)) . ' ▸'),
            ],
            [null, self::TOGGLE_KEY],
        ];
    }

    /**
     * Expanded: the bar, one row per category in case order, the drilled
     * category's sub-rows in place under it, then the cache/pruning footer
     * and the `dump prompt` row (key {@see self::DUMP_KEY}).
     *
     * @return array{0: list<string>, 1: list<?string>}
     */
    private static function expandedBody(ContextPaneModel $model, Theme $theme, int $inner): array
    {
        $lines = [self::barLine($model, $theme, $inner)];
        $keys = [null];
        if ($model->categories === null) {
            return [$lines, $keys];
        }

        $muted = Style::new()->foreground($theme->shellMuted);
        foreach ($model->categories->rows as $row) {
            $category = $row['category'];
            // The Messages row follows the live turn; every other row keeps
            // its snapshot (ContextPaneModel::messageTokens()).
            $tokens = $category === ContextCategory::Messages
                ? $model->messageTokens()
                : $row['tokens'];
            $drillable = $row['sub'] !== [];
            $figure = Width::truncate(
                ($tokens === null ? Lang::t('tui.context.unmeasured') : '~' . TokenCount::compact($tokens))
                . ($drillable ? ' ▸' : ''),
                max(1, $inner - 2)
            );

            $head = self::glyph($category) . ' ' . $category->label();
            $headCells = max(1, $inner - Width::of($figure));
            $lines[] = Style::new()->foreground(self::color($category, $theme))
                ->render(Width::padRight(Width::truncate($head, $headCells), $inner - Width::of($figure)) . $figure);
            $keys[] = self::ROW_KEY_PREFIX . $category->value;

            if (!$drillable || $model->drill !== $category) {
                continue;
            }
            foreach ($row['sub'] as $sub) {
                // Figure precedence mirrors the /context text surface:
                // note, else bytes, else tokens, else "not measured".
                $subFigure = Width::truncate($sub['note'] ?? ($sub['bytes'] === null
                    ? ($sub['tokens'] === null ? Lang::t('tui.context.unmeasured') : '~' . TokenCount::compact($sub['tokens']))
                    : self::bytes($sub['bytes'])), max(1, $inner - 3));
                $subHead = '  ' . $sub['label'];
                $subCells = max(1, $inner - Width::of($subFigure));
                $lines[] = $muted->render(Width::padRight(Width::truncate($subHead, $subCells), $inner - Width::of($subFigure)) . $subFigure);
                $keys[] = null;
            }
        }

        $footer = Lang::t('tui.context.cache', [
            'percent' => $model->cachePercent === null
                ? Lang::t('tui.context.unmeasured')
                : $model->cachePercent . '%',
        ]);
        if ($model->prunedTokens !== null) {
            $footer .= ' · ' . Lang::t('tui.context.pruned', ['count' => TokenCount::compact($model->prunedTokens)]);
        }
        $lines[] = $muted->render(Width::truncate($footer, $inner));
        $keys[] = null;

        $lines[] = Style::new()->foreground($theme->shellInfo)
            ->render(Width::truncate(Lang::t('tui.context.dump'), $inner));
        $keys[] = self::DUMP_KEY;

        return [$lines, $keys];
    }

    /** Byte figure, spelled like the /context surface does. */
    private static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        return rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KB';
    }

    /**
     * The docked box for $a's shell state — P-CTX.e's adapter, the same
     * shape the sibling panes take at the renderPane() arm.
     *
     * @return array{0: string, 1: list<?string>}
     */
    public static function layout(App $a, int $width, int $rows): array
    {
        return self::layoutModel(self::model($a), $a->theme(), $width, $rows);
    }

    /**
     * The model for $a: the last settled measurement (or null before the
     * first), the live token totals, and the shell's expand/drill state.
     */
    public static function model(App $a): ContextPaneModel
    {
        $chat = $a->chat;
        $freed = $chat?->contextLedgerView()?->freedTokens();

        return new ContextPaneModel(
            categories: self::heldCategories($a),
            historyTokens: $chat?->contextTokens() ?? 0,
            window: $chat?->contextTokenLimit() ?? 0,
            expanded: $a->contextPaneExpanded,
            drill: $a->contextDrill,
            stale: $chat?->inFlight ?? false,
            cachePercent: self::cachePercent($chat),
            // Zero freed means nothing has been pruned — the model reads
            // null as "no pruning measured", the same honesty the class
            // doc demands of unmeasured figures.
            prunedTokens: ($freed === null || $freed === 0) ? null : $freed,
        );
    }

    /**
     * The measurement held for $a's session, read the way
     * {@see TodoPane::todos()} reads its list: the session host's runner
     * first, nothing else as fallback — an unmeasured box says so.
     */
    public static function heldCategories(App $a): ?ContextCategories
    {
        $chat = $a->chat;
        $runner = $chat?->workspace()?->service(TurnRunner::class);
        if ($runner instanceof TurnRunner) {
            return $runner->heldContext($chat?->currentSessionId() ?? $a->sessionId);
        }

        return null;
    }

    /**
     * The cache-hit percent on the box's total line — the newest usable
     * report, by the same rule (and the same OpenAI-shaped fallback) as
     * {@see \SugarCraft\Code\Renderer::cacheIndicator()} on the status bar.
     * Kept to the percent alone: the box has no room for the age suffix,
     * and the bar beside it already spends the columns on it.
     */
    private static function cachePercent(?Chat $chat): ?int
    {
        if ($chat === null) {
            return null;
        }

        $history = $chat->history;
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $usage = $history[$i]->usage;
            if ($usage === null) {
                continue;
            }

            $prompt = $usage->promptTokens();
            if ($prompt === null
                && $usage->cacheCreationTokens === null
                && $usage->cacheReadTokens !== null
                && $usage->inputTokens !== null
            ) {
                $prompt = $usage->cacheReadTokens + $usage->inputTokens;
            }
            if ($prompt === null || $prompt <= 0) {
                continue;
            }

            return (int) round($usage->cacheReadTokens / $prompt * 100);
        }

        return null;
    }
}
