<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
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
 * It is deliberately READ-ONLY. The two settings that genuinely can be
 * changed at runtime — theme and model — are changed through `/theme` and
 * `/model` (and their Ctrl+P palette entries), and {@see FOOTER} says so.
 * Offering a control here that dispatched nothing is the exact failure mode
 * the empty pane already was.
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
     * Why the pane offers no controls, naming the commands that do work.
     * Both are real {@see \SugarCraft\Crush\Commands\CommandRegistry} rows.
     */
    private const FOOTER = 'read-only — /theme, /model';

    /**
     * Placeholder for a value this App genuinely has no answer for, kept
     * distinct from a real value so the pane never reads as if a default had
     * been configured.
     */
    private const UNKNOWN = '(none)';

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

        // getcwd() rather than a stored field: `Bootstrap::app()` defaults its
        // $root to getcwd() and hands that same string to every loader, and
        // App carries no root of its own to read back. On the live boot path
        // this IS the root; an embedder that passes a custom $root leaves no
        // trace in App, so reporting the process directory is the honest
        // answer rather than a guess at theirs.
        $root = getcwd();

        return [
            ['Provider', $a->provider->name()],
            ['Model', $a->model],
            ['Theme', $chat?->theme()->name ?? self::UNKNOWN],
            ['Root', $root === false ? self::UNKNOWN : $root],
            ['Session', $a->sessionId ?? $chat?->currentSessionId() ?? self::UNKNOWN],
            ['Mouse', Chat::mouseMode()->value],
            ['Mouse clicks', Chat::mouseClicksEnabled() ? 'on' : 'off'],
            ['Streaming', $chat === null ? self::UNKNOWN : ($chat->isStreaming() ? 'on' : 'off')],
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

        $labelStyle = Style::new()->foreground(Color::hex('#7d6e98'));
        $valueStyle = Style::new()->foreground(Color::hex('#c5b6dd'));

        $lines = [];
        foreach (self::settings($a) as [$label, $value]) {
            $lines[] = $labelStyle->render(Width::truncate($label, $inner));
            $lines[] = $valueStyle->render(str_repeat(' ', self::VALUE_INDENT) . Width::truncateMiddle(
                PaneLabel::of($value),
                max(1, $inner - self::VALUE_INDENT),
            ));
        }
        $lines[] = $labelStyle->render(Width::truncate(self::FOOTER, $inner));

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' settings '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === Pane::Settings
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render(implode("\n", array_slice($lines, 0, $budget)));
    }
}
