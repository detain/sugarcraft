<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Sql\Pane;

/**
 * SQL-O21 (once-028): the key binding roster as ONE machine-readable
 * source. The legends draw from the lang strings, README is the human
 * mirror, and KeysOverlayTest holds README and this table together
 * cell-for-cell (one fact, one place). The `cell` strings are EXACTLY the
 * README key tables' first columns (backticks stripped); `text` is the
 * short `help.*` lang key per row.
 *
 * The `?` overlay cannot advertise a dead roster: the cross-check test
 * fails on any README row that is not mirrored here, and the coverage
 * test fails on any cell whose driving suite citation has rotted. Pane
 * scope lives in the cell text itself (the README already says
 * "(Rows pane)" etc.), so the Tables/Rows/Admin overlays list the full
 * client roster and the Admin overlay appends the admin chords — every
 * key the app arms is visible from every pane that can open help.
 */
final class KeyMap
{
    /**
     * Client (browser/editor) bindings.
     *
     * @var list<array{cell:string, text:string}>
     */
    public const CLIENT = [
        ['cell' => 'Tab', 'text' => 'help.cycle'],
        ['cell' => '↑/↓ or j/k', 'text' => 'help.list_cursor'],
        ['cell' => '↑/↓ (query pane)', 'text' => 'help.editor_cursor'],
        ['cell' => '←/→ or h/l', 'text' => 'help.cell_cursor'],
        ['cell' => 'e (Rows pane)', 'text' => 'help.edit_cell'],
        ['cell' => 'o (Rows pane)', 'text' => 'help.insert_row'],
        ['cell' => 'D (Rows pane)', 'text' => 'help.delete_row'],
        ['cell' => 'y (Rows pane)', 'text' => 'help.yank_cell'],
        ['cell' => 'Enter / Space (Tables pane)', 'text' => 'help.load_table'],
        ['cell' => 'Enter (query pane)', 'text' => 'help.newline'],
        ['cell' => 'Ctrl+R', 'text' => 'help.run'],
        ['cell' => 'Ctrl+E', 'text' => 'help.run'],
        ['cell' => 'Ctrl+Enter', 'text' => 'help.run'],
        ['cell' => 'Ctrl+F', 'text' => 'help.fav_add'],
        ['cell' => 'Ctrl+Shift+F', 'text' => 'help.fav_remove'],
        ['cell' => 'Backspace', 'text' => 'help.backspace'],
        ['cell' => 'x / i (Tables or Rows pane)', 'text' => 'help.file_line'],
        ['cell' => 'H (Tables, Rows or Admin pane)', 'text' => 'help.history_trend'],
        ['cell' => ':tx … (query pane, run with the run keys)', 'text' => 'help.tx'],
        ['cell' => 'run with ? / :name placeholders in the statement (query pane)', 'text' => 'help.param_prompt'],
        ['cell' => '? (Tables, Rows or Admin pane)', 'text' => 'help.overlay'],
        ['cell' => 'q / Esc', 'text' => 'help.quit'],
        ['cell' => 'Ctrl+C', 'text' => 'help.cancel_quit'],
    ];

    /**
     * Admin-pane chords (the README 'Admin Dashboard Keys' table).
     *
     * @var list<array{cell:string, text:string}>
     */
    public const ADMIN = [
        ['cell' => '[p]', 'text' => 'help.ad_pause'],
        ['cell' => '[r]', 'text' => 'help.ad_reset'],
        ['cell' => '[j/k] [↑/↓]', 'text' => 'help.ad_rows'],
        ['cell' => '[e]', 'text' => 'help.ad_edit'],
        ['cell' => '[w]', 'text' => 'help.ad_rw_filter'],
        ['cell' => '[s]', 'text' => 'help.ad_search'],
        ['cell' => '[f]', 'text' => 'help.ad_sleeping_filter'],
        ['cell' => 'K / X', 'text' => 'help.ad_kill'],
        ['cell' => 'y', 'text' => 'help.ad_confirm'],
        ['cell' => '[c]', 'text' => 'help.ad_stage'],
        ['cell' => '[c]', 'text' => 'help.ad_units'],
        ['cell' => '[x]', 'text' => 'help.ad_csv'],
        ['cell' => '[a]', 'text' => 'help.ad_alerts'],
        ['cell' => '[c] / [f]', 'text' => 'help.ad_debug'],
        ['cell' => '1 – 8', 'text' => 'help.ad_pages'],
    ];

    /**
     * Rows the `?` overlay lists for a pane: the full client roster always
     * (pane scope is spelled out in each cell), admin chords on the Admin
     * pane only — they are meaningless elsewhere.
     *
     * @return list<array{cell:string, text:string}>
     */
    public static function rowsFor(Pane $pane): array
    {
        return $pane === Pane::Admin ? [...self::CLIENT, ...self::ADMIN] : self::CLIENT;
    }
}
