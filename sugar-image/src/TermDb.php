<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * F-01: the declarative terminal database — chafa's built-in term table ported to SugarCraft.
 *
 * The port of chafa's hardcoded terminal knowledge (chafa/chafa-term-db.c, the
 * 1338-line `term_def[]` census: rows, env rules, priorities, version gates,
 * per-type matching and outer:inner chaining) plus the pixel-protocol claims
 * the s2-5 Detect ladder had learned by hand. Before this table, Detect's env
 * stage was three hardcoded `if` blocks; every terminal not named there fell
 * through to a `infocmp` subprocess or a live DA1 round-trip for an answer
 * that is a published fact of the terminal's own environment.
 *
 * Shape kept from chicle:
 * - `type` (TERM/MUX/APP): each class is matched separately and the results
 *   chained (chafa-term-db.c:1140-1160 `detect_capabilities()` loop).
 * - `env` rules with op INCL/EXCL, cmp ISSET/EXACT/PREFIX/SUFFIX/VER_GE and a
 *   per-rule priority (chicle `EnvRule`, max 8/def). INCL rules OR together;
 *   any EXCL rule that fails excludes the whole row — that gate is how
 *   "konsole only from 22.03.70", "xterm only when it advertises a version"
 *   and "tmux sixel only from 3.4" are expressed (chicle:805-818).
 * - Dot-split $TERM heads: panes often spell the outer terminal into $TERM
 *   ("screen.xterm-256color" — chicle's own comment at :784). For rules keyed
 *   on TERM every dot-separated subterm AND the whole value are tried, so the
 *   mux head and the outer tail each hit their own rows (:986-1012).
 * - Priority + tie-break: higher-priority rule wins the row; equal priority
 *   with equal name prefers the versioned row (:1114-1121).
 *
 * Shape SugarCraft changed (each divergence is named in the row comment):
 * - PROTOCOL UNION: chicle keeps ONE def per type and copies its seq lists;
 *   SC keeps every matching row's claims and lets {@see Detect}'s family
 *   precedence (Kitty > iTerm2 > Sixel) resolve them — this reproduces the
 *   old hardcoded ladder exactly (a KITTY_WINDOW_ID advertisement used to win
 *   over a leaked $TERM=mlterm regardless of row priority).
 * - No escape-sequence payloads: SC renderers emit protocol bytes directly;
 *   rows carry only the claim + passthrough metadata.
 * - `mux` rows never DELETE an outer claim (chicle drops iterm2 inside tmux
 *   because tmux has no OSC 1337 passthrough); SC keeps the union because the
 *   tmux DISPLAY policy is owned by the F-04 termfeatures veto, not the table
 *   — laundering it through the database was the behavior the s2-5 pins fix.
 * - ISSET means set AND non-empty: an empty env value is not an advertisement.
 *
 * Pure data + pure functions: nothing here reads the environment, spawns a
 * process, or touches a terminal. {@see snapshotEnv()} is the only getenv
 * site, and {@see matchChain()} takes its output, so the whole table is
 * testable with string arguments (the hermetic law).
 */
final class TermDb
{
    /** Row match ops — chicle `EnvOp` (ENV_OP_INCL / ENV_OP_EXCL). */
    public const ENV_OP_INCL = 'incl';
    public const ENV_OP_EXCL = 'excl';

    /** Rule comparators — chicle `EnvCmp`. */
    public const ENV_CMP_ISSET = 'isset';
    public const ENV_CMP_EXACT = 'exact';
    public const ENV_CMP_PREFIX = 'prefix';
    public const ENV_CMP_SUFFIX = 'suffix';
    public const ENV_CMP_VER_GE = 'ver_ge';

    /** Row classes — chicle `TermType` (TERM_TYPE_TERM/MUX/APP). */
    public const TYPE_TERM = 'term';
    public const TYPE_MUX = 'mux';

    /**
     * Every env var any row consults. {@see snapshotEnv()} reads exactly this
     * set; a test suite that wants a hermetic environment clears exactly this
     * set before asserting a negative.
     */
    public const ENV_KEYS = [
        'TERM',
        'TERM_PROGRAM',
        'TERM_PROGRAM_VERSION',
        'TMUX',
        'LC_TERMINAL',
        'KITTY_WINDOW_ID',
        'KITTY_PID',
        'XTERM_VERSION',
        'KONSOLE_VERSION',
        'VTE_VERSION',
        'MLTERM',
        'GHOSTTY_BIN_DIR',
        'WEZTERM_EXECUTABLE',
        'TERMINAL_NAME',
        'ComSpec',
    ];

    /**
     * The table. Priorities are chicle's literal rule values so the two
     * databases can be diffed by eye; row order is irrelevant except as the
     * stable label tie-break.
     *
     * @var list<array{
     *     name: string,
     *     type: string,
     *     version: string|null,
     *     claims: list<TermInfo::PROTOCOL_*>,
     *     passthrough: array<string, bool>,
     *     env: list<array{op: string, cmp: string, key: string, value: string|null, priority: int}>
     * }>
     */
    public const ROWS = [
        // ---- Kitty family (chafa-term-db.c:689-694: kitty_seqs+kitty_virt, no passthrough) ----
        ['name' => 'kitty', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_KITTY], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm-kitty', 'priority' => 10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'KITTY_PID', 'value' => null, 'priority' => 0],
                // SC addition: the old ladder keyed on the window id, which
                // kitty exports in every pane; keep it so the table claims
                // exactly what Detect claimed before F-01.
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'KITTY_WINDOW_ID', 'value' => null, 'priority' => 0],
            ]],

        // ghostty (chicle:667-674: kitty+kitty_virt seqs, TERM xterm-ghostty)
        ['name' => 'ghostty', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_KITTY], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm-ghostty', 'priority' => 10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'ghostty', 'priority' => 0],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'GHOSTTY_BIN_DIR', 'value' => null, 'priority' => 0],
            ]],

        // wezterm. SC DIVERGENCE: chicle (:848-855) gives wezterm sixel+iterm2
        // and notes it lacks Kitty virtual placements; SugarCraft classifies
        // WezTerm as the Kitty protocol family outright (wezterm speaks the
        // Kitty graphics protocol, and the family precedence predates F-01 —
        // pinned by testWezTermIsKittyOnlyNotIterm2 / DeleteApiTest).
        ['name' => 'wezterm', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_KITTY], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'WezTerm', 'priority' => 0],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'WEZTERM_EXECUTABLE', 'value' => null, 'priority' => 0],
            ]],

        // warp (chicle:840-846: kitty_seqs). SC addition — the old ladder did
        // not know warp at all; chicle's evidence-backed row claims Kitty.
        ['name' => 'warp', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_KITTY], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'WarpTerminal', 'priority' => 0],
            ]],

        // ---- iTerm2 family (chicle:682-687: iterm2_seqs only) ----
        ['name' => 'iterm', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_ITERM2], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'LC_TERMINAL', 'value' => 'iTerm2', 'priority' => 0],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'iTerm.app', 'priority' => 0],
                // SC addition: the old ladder also spelled the advertisement
                // as plain "iTerm2"; keep both so the table claims exactly
                // what Detect claimed before F-01.
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'iTerm2', 'priority' => 0],
            ]],

        // mintty (chicle:731-738: iterm2+sixel, sixel overshoot quirk). Both
        // claims ride the union; Detect precedence resolves to iTerm2 exactly
        // as the old ladder hardcoded (OSC 1337 carries full-fidelity PNG).
        ['name' => 'mintty', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_ITERM2, TermInfo::PROTOCOL_SIXEL], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'mintty', 'priority' => 10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'mintty', 'priority' => 0],
            ]],

        // rio (chicle:761-767: iterm2+sixel, overshoot quirk).
        ['name' => 'rio', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_ITERM2, TermInfo::PROTOCOL_SIXEL], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'rio', 'priority' => 10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'rio', 'priority' => 0],
            ]],

        // ---- Sixel family ----
        // mlterm (chicle:741-746: iterm2+6ixel). SC DIVERGENCE: SugarCraft
        // classifies mlterm as a SIXEL terminal (its OSC 1337 emulation is
        // partial, and the old ladder + testSixelViaTermAloneWithoutXtermVersion
        // pin sixel-only). chicle's -256color coverage relied on its terminfo
        // consult; SC names the head directly (the old ladder's regex did too).
        ['name' => 'mlterm', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_SIXEL], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'mlterm', 'priority' => 10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'mlterm-256color', 'priority' => 10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'MLTERM', 'value' => null, 'priority' => 0],
            ]],

        // foot (chicle:660-665: sixel_seqs, no passthrough).
        ['name' => 'foot', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_SIXEL], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'foot', 'priority' => 10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_PREFIX, 'key' => 'TERM', 'value' => 'foot-', 'priority' => 10],
            ]],

        // contour (chicle:605-609: sixel via TERMINAL_NAME).
        ['name' => 'contour', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_SIXEL], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERMINAL_NAME', 'value' => 'contour', 'priority' => 0],
            ]],

        // yaft (chicle:893-897: sixel).
        ['name' => 'yaft', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_SIXEL], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'yaft-256color', 'priority' => 10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'yaft', 'priority' => 10],
            ]],

        // konsole (chicle:696-709): a plain Konsole is a color-only terminal;
        // from 22.03.70 (KDE release 220370) its terminal display speaks sixel
        // — the version-gate EXCL row, the same shape as the tmux 3.4 row.
        ['name' => 'konsole', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'KONSOLE_VERSION', 'value' => null, 'priority' => 0],
            ]],
        ['name' => 'konsole', 'type' => self::TYPE_TERM, 'version' => '220370',
            'claims' => [TermInfo::PROTOCOL_SIXEL], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'KONSOLE_VERSION', 'value' => null, 'priority' => 0],
                ['op' => self::ENV_OP_EXCL, 'cmp' => self::ENV_CMP_VER_GE, 'key' => 'KONSOLE_VERSION', 'value' => '220370', 'priority' => 0],
            ]],

        // xterm: the version-advertisement row, the color rows, and SC's
        // sixel claim. chicle (:862-891) never claims sixel from xterm — the
        // live probe (DA1/terminfo) was supposed to notice it. SugarCraft's
        // ladder did pre-claim (xterm ≥ 261 ships sixel; the old
        // hasSixelEnvHints ANDed the TERM head with XTERM_VERSION so that the
        // $TERM=xterm* namespace — claimed by nearly every emulator — only
        // counts for the real xterm, the one program that exports it). The
        // gate is one EXCL ISSET rule, the same chicle mechanic as konsole.
        ['name' => 'xterm', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [TermInfo::PROTOCOL_SIXEL], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm', 'priority' => 0],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm-256color', 'priority' => 0],
                ['op' => self::ENV_OP_EXCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'XTERM_VERSION', 'value' => null, 'priority' => 0],
            ]],
        ['name' => 'xterm', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'XTERM_VERSION', 'value' => null, 'priority' => 0],
            ]],
        ['name' => 'xterm', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm', 'priority' => -10],
            ]],
        ['name' => 'xterm-256color', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm-256color', 'priority' => -10],
            ]],
        ['name' => 'xterm-direct', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm-direct', 'priority' => -10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm-direct2', 'priority' => -10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm-direct16', 'priority' => -10],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'xterm-direct256', 'priority' => -10],
            ]],

        // ---- color-only / no-pixel rows (knowledge for the chain label and
        // for --dump-detect; the claims list is empty by evidence) ----
        // alacritty (chicle:594-598: mainline has no sixel — only PR#4763).
        ['name' => 'alacritty', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'alacritty', 'priority' => 10],
            ]],
        // st (chicle:792-796).
        ['name' => 'st', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'st-256color', 'priority' => 10],
            ]],
        // the Linux console (chicle:725-729: "TERM=linux is probably the Linux
        // console" — 16 colors, no graphics).
        ['name' => 'linux', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM', 'value' => 'linux', 'priority' => 10],
            ]],
        // apple Terminal.app (chicle:600-603).
        ['name' => 'apple', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'Apple_Terminal', 'priority' => 0],
            ]],
        // VTE-based emitters (chicle:827-838; the 5202 REP variant belongs to
        // F-02, not to the pixel claim — no VTE version speaks sixel here).
        ['name' => 'vte', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'VTE_VERSION', 'value' => null, 'priority' => 0],
            ]],
        // Windows console (chicle:857-860: ComSpec suffix).
        ['name' => 'windows-console', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_SUFFIX, 'key' => 'ComSpec', 'value' => '\\cmd.exe', 'priority' => -5],
            ]],
        // catch-all: a $TERM that matched nothing else is still a vt220-class
        // color terminal (chicle:821-825, priority -1000).
        ['name' => 'vt220', 'type' => self::TYPE_TERM, 'version' => null,
            'claims' => [], 'passthrough' => [],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'TERM', 'value' => null, 'priority' => -1000],
            ]],

        // ---- multiplexers: own claims minimal, passthrough maps complete ----
        // tmux base (chicle:805-810): tmux_seqs passthrough wrapper, inherits
        // the outer's sixel+kitty protocols (tmux_inherit_seqs :461-471 — no
        // iterm2: tmux cannot carry OSC 1337; SC keeps the outer claim anyway
        // and marks it passthrough-needed, see the class docblock), needs
        // passthrough for every pixel protocol.
        ['name' => 'tmux', 'type' => self::TYPE_MUX, 'version' => null,
            'claims' => [],
            'passthrough' => [TermInfo::PROTOCOL_SIXEL => true, TermInfo::PROTOCOL_KITTY => true, TermInfo::PROTOCOL_ITERM2 => true],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_ISSET, 'key' => 'TMUX', 'value' => null, 'priority' => 0],
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'tmux', 'priority' => 0],
            ]],
        // tmux ≥ 3.4 (chicle:812-818, the tmux_3_4_pixel_pt EXCL VER_GE
        // precedent at :482-488): tmux 3.4 decodes sixels into its own cell
        // grid, so the pane speaks Sixel natively and needs no passthrough.
        // SC DIVERGENCE: chicle pairs the gate with a bare `ISSET TMUX` rule;
        // SC requires the self-advertisement `TERM_PROGRAM=tmux` (which every
        // ≥ 3.0 pane carries) before the native claim may fire — a leaked
        // $TMUX socket path alone does not prove the server's version, and a
        // wrong native claim silently disables the F-04 termfeatures veto.
        ['name' => 'tmux', 'type' => self::TYPE_MUX, 'version' => '3.4',
            'claims' => [TermInfo::PROTOCOL_SIXEL],
            'passthrough' => [TermInfo::PROTOCOL_SIXEL => false, TermInfo::PROTOCOL_KITTY => true, TermInfo::PROTOCOL_ITERM2 => true],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_EXACT, 'key' => 'TERM_PROGRAM', 'value' => 'tmux', 'priority' => 0],
                ['op' => self::ENV_OP_EXCL, 'cmp' => self::ENV_CMP_VER_GE, 'key' => 'TERM_PROGRAM_VERSION', 'value' => '3.4', 'priority' => 0],
            ]],
        // GNU screen (chicle:786-790: screen_seqs wrapper, same inherit set
        // as tmux, passthrough needed for everything).
        ['name' => 'screen', 'type' => self::TYPE_MUX, 'version' => null,
            'claims' => [],
            'passthrough' => [TermInfo::PROTOCOL_SIXEL => true, TermInfo::PROTOCOL_KITTY => true, TermInfo::PROTOCOL_ITERM2 => true],
            'env' => [
                ['op' => self::ENV_OP_INCL, 'cmp' => self::ENV_CMP_PREFIX, 'key' => 'TERM', 'value' => 'screen', 'priority' => -5],
            ]],
    ];

    /**
     * Read the environment variables the table consults, as a key=>value map
     * with unset keys absent. The only getenv site in the table.
     *
     * @return array<string, string>
     */
    public static function snapshotEnv(): array
    {
        $env = [];
        foreach (self::ENV_KEYS as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Resolve an environment snapshot to the chained terminal info.
     *
     * TERM-type and MUX-type rows are matched independently, then chained
     * innermost-first (the mux label leads the chain name, exactly chicle's
     * `detect_capabilities()` → `chafa_term_info_chain()` order; APP-type is
     * a recorded seam — no app row changes a pixel claim for SugarCraft).
     *
     * @param array<string, string> $env key => value, unset keys absent
     */
    public static function matchChain(array $env): TermInfo
    {
        $term = self::detectOfType($env, self::TYPE_TERM);
        $mux = self::detectOfType($env, self::TYPE_MUX);

        if ($term === null && $mux === null) {
            return TermInfo::unknown();
        }

        $claims = self::mergeClaims($term['claims'] ?? [], $mux['claims'] ?? []);

        $passthrough = [];
        foreach ($claims as $protocol) {
            $passthrough[$protocol] = self::needsPassthroughFor($protocol, $term, $mux);
        }

        $chain = self::chainLabel($term, $mux);

        return new TermInfo($chain, $claims, $passthrough, $mux === null ? null : self::rowLabel($mux));
    }

    /**
     * Pick the label row of $type and merge the union of every matching row's
     * claims — SC union semantics, see the class docblock.
     *
     * @param array<string, string> $env
     * @return array{name: string, version: string|null, claims: list<string>, passthrough: array<string, bool>}|null
     */
    private static function detectOfType(array $env, string $type): ?array
    {
        $best = null;
        $bestPri = null;
        $union = [];

        foreach (self::ROWS as $row) {
            if ($row['type'] !== $type) {
                continue;
            }

            $pri = self::matchRow($row, $env);
            if ($pri === null) {
                continue;
            }

            $union = array_merge($union, $row['claims']);

            if ($bestPri === null || $pri > $bestPri || ($pri === $bestPri && self::isMoreSpecific($row, $best))) {
                $bestPri = $pri;
                $best = $row;
            }
        }

        if ($best === null) {
            return null;
        }

        return ['name' => $best['name'], 'version' => $best['version'],
            'claims' => array_values(array_unique($union)), 'passthrough' => $best['passthrough']];
    }

    /** chicle's tie-break (:1114-1121): same name, a versioned row outranks its unversioned base. */
    private static function isMoreSpecific(array $candidate, array $best): bool
    {
        if ($candidate['name'] !== $best['name']) {
            return false;
        }

        return $candidate['version'] !== null && $best['version'] === null;
    }

    /**
     * Evaluate one row's env rules. INCL rules OR together and each carries
     * the row's match priority; every EXCL rule is a gate — one that fails
     * excludes the row outright (chicle `match_term_def`, :986-1012).
     *
     * @return int|null the match priority, or null when the row does not match
     */
    private static function matchRow(array $row, array $env): ?int
    {
        $best = null;

        foreach ($row['env'] as $rule) {
            $matched = self::ruleMatches($rule, $env);

            if ($rule['op'] === self::ENV_OP_EXCL) {
                if (!$matched) {
                    return null;
                }

                continue;
            }

            if ($matched && ($best === null || $rule['priority'] >= $best)) {
                $best = max($best ?? PHP_INT_MIN, $rule['priority']);
            }
        }

        return $best;
    }

    /**
     * One rule against the snapshot. Rules keyed on TERM also try every
     * dot-separated subterm of the value plus the whole value, which is how
     * "screen.xterm-256color" answers for both the mux head and the outer
     * terminal tail (chicle :1003-1012).
     */
    private static function ruleMatches(array $rule, array $env): bool
    {
        $value = $env[$rule['key']] ?? null;

        if (strcasecmp($rule['key'], 'TERM') === 0 && $value !== null) {
            foreach (explode('.', $value) as $subterm) {
                if (self::matchValue($rule, $subterm)) {
                    return true;
                }
            }
        }

        return self::matchValue($rule, $value);
    }

    /** @param string|null $value the raw env value, null when unset */
    private static function matchValue(array $rule, ?string $value): bool
    {
        // ISSET means set AND non-empty: an empty advertisement is no
        // advertisement (SC refinement over chicle, which only checks NULL).
        // An unset key also fails VER_GE gates, excluding gated rows exactly
        // as chicle's parse_version(NULL) = -1 does.
        if ($value === null || $value === '') {
            return false;
        }

        return match ($rule['cmp']) {
            self::ENV_CMP_ISSET => true,
            self::ENV_CMP_EXACT => strcasecmp($value, (string) $rule['value']) === 0,
            self::ENV_CMP_PREFIX => strncasecmp($value, (string) $rule['value'], strlen((string) $rule['value'])) === 0,
            self::ENV_CMP_SUFFIX => strcasecmp(substr($value, -strlen((string) $rule['value'])), (string) $rule['value']) === 0,
            self::ENV_CMP_VER_GE => self::parseVersion($value) >= self::parseVersion((string) $rule['value']),
            default => throw new \InvalidArgumentException("unknown env cmp: {$rule['cmp']}"),
        };
    }

    /**
     * Digits-only version folding — chicle's `parse_version` (:907-954):
     * "1.2.3" → 123, "XTerm(388)" → 388, "next-3.6" → 36. Versions with more
     * digits compare above versions with fewer, so 3.10 (310) > 3.4 (34) >
     * 3.3 (33) without any per-field math.
     */
    public static function parseVersion(string $version): int
    {
        $digits = preg_replace('/\D+/', '', $version) ?? '';

        return $digits === '' ? -1 : (int) $digits;
    }

    /**
     * Chain the protocol claim through the mux, chicle's `pixel_pt` lookup:
     * a row's own claim decides by its own passthrough map (the tmux-3.4 row
     * claims Sixel with the entry FALSE); a claim inherited from the outer
     * terminal decides by the mux's map too (tmux: everything TRUE except
     * its native 3.4 sixel); a bare terminal claim never needs a wrapper.
     *
     * @param array{name: string, version: string|null, claims: list<string>, passthrough: array<string, bool>}|null $term
     * @param array{name: string, version: string|null, claims: list<string>, passthrough: array<string, bool>}|null $mux
     */
    private static function needsPassthroughFor(string $protocol, ?array $term, ?array $mux): bool
    {
        if ($mux === null) {
            return false;
        }

        return ($mux['passthrough'][$protocol] ?? true) === true;
    }

    /**
     * Union the two claim lists, de-duplicated and sorted into precedence
     * order (Kitty, iTerm2, Sixel) so callers can trust the first element.
     *
     * @param list<string> $outer
     * @param list<string> $inner
     *
     * @return list<string>
     */
    private static function mergeClaims(array $outer, array $inner): array
    {
        $union = array_values(array_unique(array_merge($outer, $inner)));

        usort($union, static fn (string $a, string $b): int => array_search($a, TermInfo::PROTOCOLS, true) <=> array_search($b, TermInfo::PROTOCOLS, true));

        return $union;
    }

    private static function rowLabel(array $row): string
    {
        return $row['version'] === null ? $row['name'] : $row['name'] . '(' . $row['version'] . ')';
    }

    private static function chainLabel(?array $term, ?array $mux): string
    {
        $parts = [];
        if ($mux !== null) {
            $parts[] = self::rowLabel($mux);
        }
        if ($term !== null) {
            $parts[] = self::rowLabel($term);
        }

        return implode(':', $parts);
    }

    private function __construct()
    {
        // static table — no instances
    }
}
