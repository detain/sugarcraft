<?php

declare(strict_types=1);

namespace SugarCraft\Top\Input;

/**
 * Every key candy-top answers, in btop's help order — the ONE table the
 * help overlay ({@see \SugarCraft\Top\Overlay\HelpMenu}) lists and a docs
 * drift test can re-derive README / docs key rosters from
 * ({@see bindings()}).
 *
 * Rows follow btop's `help_text` (src/btop_menu.cpp:172-218) minus what
 * candy-top does not do (yet): `ctrl + z` (no suspend). btop's `5` row
 * lists every gpu slot key, 5 through 0 (btop PR #1730); btop PR #1873
 * adds `x` and `[, ]` (the containers box) right after it. btop PR #1476 adds the proc-width rows after `Selected N`;
 * `O` is candy-top's #1873 extension; the vim_keys rows (btop documents
 * those only in its options text) are listed so the roster is complete.
 * `g, ctrl + g` is btop #1552's gpu-only filter (`ctrl + g` because
 * vim_keys keeps `g` for "top").
 *
 * {@see bindings()} is pinned against the handlers by KeyTableTest, which
 * drives every key through a running App and fails on any key that acts
 * but is missing here, or is listed here but acts nowhere.
 */
final class KeyTable
{
    /**
     * Help-overlay grouping: heading Lang key => 0-based indexes into
     * {@see rows()}. Purely metadata on TOP of the flat roster — `rows()`
     * itself stays in btop `help_text` order for the `--help` KEYS section
     * and the README drift tests. Every index must appear exactly once;
     * {@see sections()} fails loud on any gap or duplicate. The order of
     * this map is the order the help grid streams its sections, and the
     * order within a section is the roster order of its indexes.
     *
     * @var array<string, list<int>>
     */
    public const SECTIONS = [
        'help.section.general' => [2, 18, 19, 20],
        'help.section.panels' => [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 37, 38, 39, 40, 41, 42, 43],
        'help.section.navigation' => [21, 25, 26, 54, 55],
        'help.section.process_list' => [22, 23, 24, 27],
        'help.section.filter' => [33, 34, 35, 36],
        'help.section.net' => [28, 29, 30, 31, 32],
        'help.section.actions' => [44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 56],
        'help.section.overlays' => [16, 17],
        'help.section.mouse' => [0, 1],
        'help.section.info' => [57, 58, 59],
    ];

    private function __construct()
    {
    }

    /** @return list<KeyRow> */
    public static function rows(): array
    {
        return [
            new KeyRow('keys.mouse_1', 'help.mouse_click', ['mouse_click', 'mouse_drag', 'mouse_release'], true),
            new KeyRow('keys.mouse_scroll', 'help.mouse_scroll', ['mouse_scroll_up', 'mouse_scroll_down'], true),
            new KeyRow('Esc, m', 'help.menu', ['escape', 'm']),
            new KeyRow('p', 'help.preset_next', ['p']),
            new KeyRow('shift + p', 'help.preset_prev', ['P']),
            new KeyRow('1', 'help.toggle_cpu', ['1']),
            new KeyRow('2', 'help.toggle_mem', ['2']),
            new KeyRow('3', 'help.toggle_net', ['3']),
            new KeyRow('4', 'help.toggle_proc', ['4']),
            new KeyRow('5, 6, 7, 8, 9, 0', 'help.toggle_gpu', ['5', '6', '7', '8', '9', '0']),
            new KeyRow('x', 'help.toggle_ctr', ['x']),
            new KeyRow('[, ]', 'help.select_ctr', ['[', ']']),
            // candy-top's VM dashboard: `v` toggles it, `s`/`S` cycle its card order (arrows/Enter as listed below).
            new KeyRow('v', 'help.toggle_vms', ['v']),
            new KeyRow('s, S', 'help.vms_sort', ['s', 'S']),
            // candy-top's BMC box (`i` is io_mode, so shift).
            new KeyRow('shift + i', 'help.toggle_ipmi', ['I']),
            new KeyRow('d', 'help.toggle_disks', ['d']),
            new KeyRow('F2, o', 'help.options', ['f2', 'o']),
            new KeyRow('F1, ?, h', 'help.help', ['f1', '?', 'h']),
            new KeyRow('ctrl + r', 'help.reload', ['ctrl+r']),
            new KeyRow('q, ctrl + c', 'help.quit', ['q', 'ctrl+c']),
            new KeyRow('+, -, =', 'help.update_ms', ['+', '-', '=']),
            new KeyRow('Up, Down', 'help.select', ['up', 'down']),
            new KeyRow('Enter', 'help.detailed', ['enter']),
            new KeyRow('keys.space', 'help.expand', ['space'], true),
            new KeyRow('C', 'help.expand_children', ['C']),
            new KeyRow('Pg Up, Pg Down', 'help.page', ['page_up', 'page_down']),
            new KeyRow('Home, End', 'help.home_end', ['home', 'end']),
            new KeyRow('Left, Right', 'help.sort_column', ['left', 'right']),
            new KeyRow('b, n', 'help.net_device', ['b', 'n']),
            new KeyRow('i', 'help.io_mode', ['i']),
            new KeyRow('z', 'help.net_zero', ['z']),
            new KeyRow('a', 'help.net_auto', ['a']),
            new KeyRow('y', 'help.net_sync', ['y']),
            new KeyRow('f, /', 'help.filter', ['f', '/']),
            new KeyRow('F', 'help.follow', ['F']),
            new KeyRow('u', 'help.pause', ['u']),
            new KeyRow('delete', 'help.clear_filter', ['delete']),
            new KeyRow('c', 'help.per_core', ['c']),
            new KeyRow('r', 'help.reverse', ['r']),
            new KeyRow('e', 'help.tree', ['e']),
            new KeyRow('E', 'help.collapse_all', ['E']),
            new KeyRow('%', 'help.mem_mode', ['%']),
            new KeyRow('O', 'help.omit_containers', ['O']),
            new KeyRow('g, ctrl + g', 'help.gpu_only', ['g', 'ctrl+g']),
            new KeyRow('keys.selected_plus_minus', 'help.expand', ['+', '-', '='], true),
            new KeyRow('keys.selected_t', 'help.terminate', ['t'], true),
            new KeyRow('keys.selected_k', 'help.kill', ['k'], true),
            new KeyRow('keys.selected_s', 'help.signal', ['s'], true),
            new KeyRow('keys.selected_n', 'help.renice', ['N'], true),
            new KeyRow('Shift + Left, Right', 'help.proc_width', ['shift_left', 'shift_right']),
            new KeyRow('Alt+Shift+Left/Right', 'help.proc_width10', ['alt_shift_left', 'alt_shift_right']),
            new KeyRow('Ctrl+Shift+Left', 'help.proc_width_max', ['ctrl_shift_left']),
            new KeyRow('Ctrl+Shift+Right', 'help.proc_width_min', ['ctrl_shift_right']),
            new KeyRow('Ctrl+Shift+Down', 'help.proc_width_reset', ['ctrl_shift_down']),
            new KeyRow('h, j, k, l', 'help.vim_move', ['h', 'j', 'k', 'l'], false),
            new KeyRow('g, G', 'help.vim_home_end', ['g', 'G']),
            new KeyRow('H, K', 'help.vim_help_kill', ['H', 'K']),
            new KeyRow('', ''),
            new KeyRow('', 'help.footer'),
            new KeyRow('', 'help.url'),
        ];
    }

    /**
     * The rows that document a key (the `--help` KEYS section): every row
     * with a key column.
     *
     * @return list<KeyRow>
     */
    public static function keyRows(): array
    {
        return array_values(array_filter(self::rows(), static fn (KeyRow $r): bool => $r->keys !== ''));
    }

    /**
     * Every btop key name the table documents, in table order, without
     * duplicates — what a drift test compares against the handlers.
     *
     * @return list<string>
     */
    public static function bindings(): array
    {
        $out = [];
        foreach (self::rows() as $row) {
            foreach ($row->bindings as $binding) {
                if (!in_array($binding, $out, true)) {
                    $out[] = $binding;
                }
            }
        }

        return $out;
    }

    /**
     * The roster grouped into its {@see SECTIONS} headings: heading Lang
     * key => rows, in stream order. Always derived from {@see rows()} so
     * the grouping can never carry a roster of its own; fails loud if the
     * section map stops covering the roster exactly once (a row added to
     * `rows()` without a home, or indexed twice).
     *
     * @return array<string, list<KeyRow>>
     */
    public static function sections(): array
    {
        $rows = self::rows();
        $used = [];
        foreach (self::SECTIONS as $indexes) {
            foreach ($indexes as $i) {
                if (isset($used[$i])) {
                    throw new \RuntimeException("KeyTable::SECTIONS indexes row {$i} twice.");
                }
                if (!isset($rows[$i])) {
                    throw new \RuntimeException("KeyTable::SECTIONS names row {$i}, outside the " . count($rows) . '-row roster.');
                }
                $used[$i] = true;
            }
        }
        $missing = array_diff(array_keys($rows), array_keys($used));
        if ($missing !== []) {
            throw new \RuntimeException('KeyTable::SECTIONS leaves rows ungrouped: ' . implode(', ', $missing) . '.');
        }
        $out = [];
        foreach (self::SECTIONS as $heading => $indexes) {
            $section = [];
            foreach ($indexes as $i) {
                $section[] = $rows[$i];
            }
            $out[$heading] = $section;
        }

        return $out;
    }
}
