<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Lang;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Tui\Pane;

/**
 * The shell's settings sidebar: the configuration the app is ACTUALLY
 * running with.
 *
 * {@see Pane::Settings} existed, {@see \SugarCraft\Crush\Tui\KeyboardHandler}
 * has always bound Ctrl+, to it, and nothing rendered it — so selecting it
 * drew an empty band, which is why it was dropped from the Tab cycle. This
 * pane is what puts it back.
 *
 * Every row is READ BACK from live state ({@see App::$provider},
 * {@see App::$model}, the hosted {@see Chat}'s theme and session, the
 * process's own working directory, and the mouse env switches
 * {@see Chat::mouseMode()} enforces). Nothing here is a setting invented for
 * the pane: a value the app cannot report is shown as an explicit placeholder
 * rather than a plausible default, because a settings screen that guesses is
 * worse than one that admits it does not know.
 *
 * It is deliberately a SUMMARY. Every key, with its source and when a change
 * to it applies, is the full settings view's job
 * ({@see \SugarCraft\Crush\Tui\Settings\SettingsEditor}, roadmap N-P1),
 * which Enter on this pane — or `/settings` — opens, and {@see FOOTER} says so.
 * Theme and model are still changed through `/theme` and `/model` (and their
 * Ctrl+P palette entries). Offering a control here that dispatched nothing is
 * the exact failure mode the empty pane already was.
 */
final class SettingsPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    /** Cells a value row is indented under its label. */
    private const VALUE_INDENT = 2;

    /**
     * The way to the full view this pane summarises: Enter on the focused pane
     * (an empty draft — {@see \SugarCraft\Crush\Tui\KeyboardHandler}), or
     * the real `/settings` {@see \SugarCraft\Crush\Commands\CommandRegistry}
     * row.
     */
    private const FOOTER = 'tui.settings_pane.footer';

    /**
     * Placeholder for a value this App genuinely has no answer for, kept
     * distinct from a real value so the pane never reads as if a default had
     * been configured.
     */
    private const UNKNOWN = 'tui.settings_pane.unknown';

    /**
     * The live configuration as ordered label/value pairs.
     *
     * Exposed (rather than folded into {@see render()}) so the values can be
     * asserted without going through ANSI styling, and so a future full-width
     * settings view can reuse the same single source of truth.
     *
     * @return list<array{0: string, 1: string}> [label, value]
     */
    public static function settings(App $a): array
    {
        $chat = $a->chat;

        // App::$root is the configured root (`--root`), so a
        // `sugarcrush --root candy-shine` run shows the library it is jailed
        // to rather than the directory the binary was started from. It stayed
        // getcwd() here only because App carried no root to read back — the
        // same gap that let the model be told the wrong repository
        // (crush_code.md Phase 0 item 6). Null means the App was built
        // without one (a test, an embedder), and the process directory is
        // then the honest answer.
        $root = $a->root ?? getcwd();
        $unknown = Lang::t(self::UNKNOWN);
        $on = Lang::t('tui.settings_pane.on');
        $off = Lang::t('tui.settings_pane.off');

        return [
            [Lang::t('tui.settings_pane.provider'), $a->provider->name()],
            // The served model when the provider talks to one other than
            // the configured id (audit 15b-35); see Renderer::modelLabel().
            [Lang::t('tui.settings_pane.model'), \SugarCraft\Crush\Tui\Renderer::modelLabel($a)],
            [Lang::t('tui.settings_pane.theme'), $chat?->theme()->name ?? $unknown],
            [Lang::t('tui.settings_pane.root'), $root === false ? $unknown : $root],
            [Lang::t('tui.settings_pane.session'), $a->sessionId ?? $chat?->currentSessionId() ?? $unknown],
            [Lang::t('tui.settings_pane.mouse'), Chat::mouseMode()->value],
            [Lang::t('tui.settings_pane.mouse_clicks'), Chat::mouseClicksEnabled() ? $on : $off],
            [Lang::t('tui.settings_pane.streaming'), $chat === null ? $unknown : ($chat->isStreaming() ? $on : $off)],
        ];
    }

    /**
     * The bordered sidebar box, sized exactly like every other pane.
     *
     * Laid out two rows per setting — a dim label, then the value indented
     * beneath it — rather than as an aligned `label  value` table. A sidebar
     * is a quarter of the terminal, so a table would spend a third of that on
     * labels and end-truncate the values; a path or session id that has lost
     * its tail tells the user nothing. Values that still overflow are
     * MIDDLE-truncated ({@see Width::truncateMiddle()}) because both ends of a
     * path and of a session id carry meaning.
     */
    public static function render(App $a, int $width, int $rows): string
    {
        $inner = max(1, $width - self::CHROME_COLS);
        $budget = max(1, $rows - self::CHROME_ROWS);

        $theme = $a->theme();
        $labelStyle = Style::new()->foreground($theme->shellMuted);
        $valueStyle = Style::new()->foreground($theme->shellForeground);

        $lines = [];
        foreach (self::settings($a) as [$label, $value]) {
            $lines[] = $labelStyle->render(Width::truncate($label, $inner));
            $lines[] = $valueStyle->render(str_repeat(' ', self::VALUE_INDENT) . Width::truncateMiddle(
                PaneLabel::of($value),
                max(1, $inner - self::VALUE_INDENT),
            ));
        }
        $lines[] = $labelStyle->render(Width::truncate(Lang::t(self::FOOTER), $inner));

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' ' . Pane::Settings->icon() . ' ' . Lang::t('tui.pane.title.settings') . ' '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === Pane::Settings
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return PaneFrame::render($st, implode("\n", array_slice($lines, 0, $budget)), $theme->border);
    }
}
