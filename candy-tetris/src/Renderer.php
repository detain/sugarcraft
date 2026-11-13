<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use LogicException;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Style as SugarStyle;

/**
 * Pure view function for {@see Game}. Returns the rendered frame
 * string ready to hand to the SugarCraft Renderer.
 *
 * Decomposes the screen into two side-by-side blocks:
 *
 *   ┌─────────────┐  ┌────────────┐
 *   │  playfield  │  │ hold       │
 *   │   (board)   │  │ next       │
 *   │             │  │ score      │
 *   │             │  │ help       │
 *   └─────────────┘  └────────────┘
 *
 * The sidebar is a stack of cards in that order (see `renderSidebar()`):
 * level and lines ride inside the score card, and the help card is the
 * first casualty of the row budget — dropped for a short terminal (the
 * C-2 compact posture) or an expanded score card (candy-tetris-004).
 *
 * The board is painted from the visible 20 rows of {@see Board},
 * with the falling {@see Piece} composited over locked cells. A
 * "ghost" piece (faded preview of where a hard-drop would land)
 * is drawn underneath the live piece — the standard QoL
 * affordance that's been in every Tetris since the late '90s.
 *
 * Rendering uses a {@see Buffer} for the playfield interior:
 * each cell carries a per-tetromino style (background colour) for
 * crisp Buffer-backed ANSI output. The sidebar is built from
 * sugar-style cards stacked with Layout::joinVertical(), then
 * composited beside the framed playfield with Layout::joinHorizontal().
 *
 * Mirrors Broderick-Westrope/tetrigo board/sidebar layout intent —
 * playfield through sugar-cellbuffer, chrome and layout joins through
 * sugar-style (its Style/Layout/Border are the only chrome imports).
 */
final class Renderer
{
    /**
     * Natural frame: the full board + full sidebar as laid out below
     * (measured: 33 rows x 40 cols — candy-tetris-016's rotate-180 row
     * grew the derived card to ten verbs and the natural height with it;
     * matching the constants two lines down and the README's frame
     * figure, which the docs chain row re-spells). A terminal at least
     * this wide and tall gets the complete experience with nothing
     * reflowed.
     */
    public const NATURAL_COLS = 40;
    public const NATURAL_ROWS = 33;

    /**
     * Hard floor: the framed board is 22 rows x 14 cols and the sidebar
     * cards are fixed-width (20 + borders + the 2-col gap = 26 cols);
     * 40x24 is the smallest frame in which every gameplay-relevant fact
     * (board, score, next, hold, and any overlay banner) is ON-frame and
     * unclipped. Below it the renderer shows a resize notice instead —
     * the C-2 defect was banners appending BELOW the fold and silently
     * falling off an 80x24 terminal.
     */
    public const MIN_COLS = 40;
    public const MIN_ROWS = 24;

    public static function render(Game $game): string
    {
        // Posture dispatch (C-2): the frame is decided by what the terminal
        // CAN show before anything else. NoTty gets a plain refusal — no
        // escape bytes for a terminal that cannot parse them; below the
        // floor gets a resize notice that itself fits; everything else
        // renders ON the fixed frame, banners painted INTO it (the old
        // append-below-fold posture pushed the game-over banner and the
        // paused line off an 80x24 terminal — invisible and unrecoverable).
        if ($game->colorProfile === ColorProfile::NoTty) {
            return Support\Paint::refusal();
        }

        if ($game->tooSmall() === true) {
            return Support\Paint::tooSmallNotice(
                'cli.too_small',
                self::MIN_COLS,
                self::MIN_ROWS,
                $game->frameCols,
                $game->frameRows,
            );
        }

        // MA-12 colour posture: Ascii (NO_COLOR / CLICOLOR=0 / plain ANSI)
        // gets a zero-SGR frame where SHAPE carries every fact colour used
        // to carry alone — the old colored-space previews rendered as blank
        // nothing without colour. Null (profile not yet reported) renders
        // the full-colour path, byte-identical to the frozen pins.
        $plain = $game->colorProfile === ColorProfile::Ascii;

        $playfield = self::renderBoard($game, $plain);
        $sidebar  = self::renderSidebar($game, $plain);
        $body = Layout::joinHorizontal(0.0, $playfield, '  ', $sidebar);

        if ($game->introPending === true) {
            // MA-24: the on-ramp card overlays the fresh board it teaches.
            return Support\Paint::banner($body, self::withNotices(self::introLines($game), $game->notices));
        }
        if ($game->helpOpen === true) {
            // candy-tetris-006: the reachable key reference — the FULL
            // derived card painted over the frame, on any posture, even
            // where the sidebar budget had to drop it.
            return Support\Paint::banner($body, self::withNotices(self::helpLines($game), $game->notices));
        }
        if ($game->over === true) {
            return Support\Paint::banner($body, self::withNotices(self::overLines($game), $game->notices));
        }
        if ($game->paused === true) {
            // candy-tetris-005: the overlay names the LIVE pause binding —
            // a remapped table must not strand the player under a key the
            // game no longer answers.
            return Support\Paint::banner($body, self::withNotices([Lang::t('paused', ['keys' => $game->keyLabel('pause')])], $game->notices));
        }
        if ($game->armed !== null) {
            // candy-tetris-054/-083: the confirm question. Reached only in
            // bare live play — the intro, help, over and pause banners all
            // answer first, and behind those postures q is a menu choice,
            // never a misreach that needs guarding. The live binding name
            // rides the sentence (the -005 derivation law, applied to the
            // destructive family: a remapped q must not ask for a key the
            // game no longer answers).
            return Support\Paint::banner($body, self::withNotices([self::confirmLine($game)], $game->notices));
        }
        if ($game->notices !== []) {
            // candy-tetris-025/-092: a plain-play notice (a clear moment,
            // an incoming row, a save refusal) paints through the SAME
            // band machinery — the loop owns the terminal, so the frame
            // is the only honest channel.
            return Support\Paint::banner($body, $game->notices);
        }
        return $body;
    }

    /**
     * The first-run on-ramp as plain text lines (MA-24): what to do, the
     * losing condition, how to start — with the LIVE begin tokens so a
     * remapped table remaps the card too — and the versus affordance the
     * CLI-only mode selection lacked.
     *
     * @return list<string>
     */
    private static function introLines(Game $game): array
    {
        return [
            Lang::t('intro.title'),
            Lang::t('intro.goal'),
            Lang::t('intro.press', ['keys' => $game->introBeginLabel()]),
            Lang::t('intro.keys', ['keys' => $game->keyLabel('help')]),
            Lang::t('intro.vs'),
        ];
    }

    /**
     * The key reference as plain text lines (candy-tetris-006): the same
     * derived card the sidebar paints at full height — live key column,
     * translated verb column — plus the press that closes it. Reachable
     * from any posture on any terminal at or above the floor, which is
     * exactly what the budgeted sidebar cannot promise.
     *
     * @return list<string>
     */
    /**
     * The pending slot (candy-tetris-025/-092) appended under whatever
     * banner the posture already paints; the banner machinery centres the
     * combined band on the visible board, so the row-count contract holds.
     *
     * @param list<string> $lines
     * @param list<string> $notices
     *
     * @return list<string>
     */
    private static function withNotices(array $lines, array $notices): array
    {
        if ($notices === []) {
            return $lines;
        }

        return [...$lines, ...$notices];
    }

    private static function helpLines(Game $game): array
    {
        $lines = [Lang::t('help.keys')];
        foreach (explode("\n", Support\Bindings::helpCard($game->bindings ?? Support\Bindings::table())) as $row) {
            $lines[] = $row;
        }
        $lines[] = Lang::t('help.close', ['keys' => $game->keyLabel('help')]);

        return $lines;
    }

    /**
     * The confirm question for the armed destructive act (candy-tetris-
     * 054/-083): name the live key that carries it out, name the act.
     */
    private static function confirmLine(Game $game): string
    {
        return match ($game->armed) {
            'quit'    => Lang::t('confirm.quit', ['keys' => $game->keyLabel('quit')]),
            'restart' => Lang::t('confirm.restart', ['keys' => $game->keyLabel('restart')]),
            default   => throw new \LogicException('unknown armed act: ' . var_export($game->armed, true)),
        };
    }

    /**
     * The game-over banner as plain text lines (MA-10: the reason the run
     * ended rides the banner — the fatal piece spawns inside the hidden
     * rows, so the banner is the ONLY on-frame evidence of what happened).
     *
     * @return list<string>
     */
    private static function overLines(Game $game): array
    {
        $lines = [
            Lang::t('over.title'),
            Lang::t('over.final_score', ['points' => $game->score->points]),
        ];
        // MA-23: the record finally gives the final score something to be
        // read against. soloBest is null only when no game ever finished.
        if ($game->scores !== null && $game->scores->soloBest !== null) {
            $lines[] = Lang::t('over.best', ['points' => $game->scores->soloBest]);
        }
        // candy-tetris-096: the finished-games denominator rode the same
        // JSON but never reached the frame — the banner now says how many
        // runs this best came out of.
        if ($game->scores !== null && $game->scores->soloGames > 0) {
            $lines[] = Lang::t('over.games', ['games' => $game->scores->soloGames]);
        }
        if ($game->newBest === true) {
            $lines[] = Lang::t('over.new_best');
        }
        if ($game->overReason !== null) {
            $lines[] = Lang::t('over.reason', ['reason' => self::reasonText($game->overReason)]);
        }
        // candy-tetris-005: both verbs are live bindings, and the hint
        // must arrive WHOLE — a wide remap or the two-token quit would be
        // clipped mid-word by the board-width banner otherwise, so the
        // two sentences own one line each (the default table reproduces
        // the original pair byte-for-byte, stacked).
        foreach (self::restartHintLines($game) as $line) {
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * The over-screen hint as one or two banner lines (candy-tetris-005):
     * the clause pair is joined in the catalogue by the house em-dash, and
     * the fold re-splits it so a wide remap — or the honest two-token
     * quit — can never be clipped mid-word by the frame width. With the
     * shipped table this renders exactly the two sentences the old single
     * line held, stacked; a locale that stops carrying the joiner simply
     * stays one line (and LocaleParityTest fails the roster first).
     *
     * @return non-empty-list<string>
     */
    public static function restartHintLines(Game $game): array
    {
        $hint = Lang::t('over.restart_hint', [
            'restart' => $game->keyLabel('restart'),
            'quit'    => $game->keyLabel('quit'),
        ]);

        return preg_split('/\s+—\s+/u', $hint) ?: [$hint];
    }

    private static function reasonText(string $reason): string
    {
        return match ($reason) {
            Game::OVER_SPAWN_BLOCKED => Lang::t('over.reason.spawn_blocked'),
            Game::OVER_TOP_OUT       => Lang::t('over.reason.top_out'),
            Game::OVER_CRUSHED       => Lang::t('over.reason.crushed'),
            default                  => $reason,
        };
    }

    private static function renderBoard(Game $game, bool $plain = false): string
    {
        $rows = $game->board->rows();
        $piece = $game->piece;
        $ghost = $game->board->dropPiece($piece);
        $pieceCells = Support\Paint::cellMap($piece->cells());
        $ghostCells = Support\Paint::cellMap($ghost->cells());

        // Build a Buffer-backed interior, then frame it with the sugar-style border.
        // candy-tetris-009: the row-major flat grid goes through fromGrid in one
        // wrap — the old withCellAt chain copied the whole cell set per square,
        // making one frame quadratic in the board it paints.
        $grid = [];

        for ($dy = 0; $dy < Board::VISIBLE_ROWS; $dy++) {
            $y = Board::HIDDEN_ROWS + $dy;
            for ($dx = 0; $dx < Board::COLS; $dx++) {
                $key = "$dx,$y";
                $cellKind = $rows[$y][$dx] ?? null;
                // '·' (matching the VS board's empty-cell vocabulary) keeps
                // the playfield readable on terminals without colour.
                $rune = '·';
                $style = null;

                if ($cellKind !== null) {
                    $rune = '█';
                    $style = $plain ? null : self::blockStyle($cellKind);
                } elseif (isset($pieceCells[$key]) === true) {
                    $rune = '█';
                    $style = $plain ? null : self::blockStyle($piece->kind);
                } elseif (isset($ghostCells[$key]) === true) {
                    $rune = '▒';
                    $style = $plain ? null : self::ghostStyle($piece->kind);
                }

                $grid[] = Cell::new($rune, $style);
            }
        }

        // Frame the Buffer interior with the sugar-style border.
        $interior = Buffer::fromGrid(Board::COLS, Board::VISIBLE_ROWS, $grid)->toAnsi();
        return SugarStyle::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($interior);
    }

    /**
     * Sidebar cards, top to bottom: hold, next, score, help.
     *
     * The column is BUDGETED (candy-tetris-004): it may never stand
     * taller than min(frameRows, NATURAL_ROWS) — a taller sidebar pushes
     * the frame's bottom border past the terminal, the same below-the-fold
     * defect C-2 cured for banners, reached now by content instead of by
     * window size. When the column would overflow, casualties drop in a
     * fixed order:
     *
     *  1. the help card — the C-2 compact-reflow casualty, now also the
     *     victim of an armed-badge or --seed expansion at full height;
     *     where it drops, a one-row compact key line (candy-tetris-006)
     *     takes its place, so the frame always teaches at least the press
     *     that re-shows the card;
     *  2. the SEED stamp — developer replay data, sacrificed one rung
     *     before the pointer gives way;
     *  3. the compact key line itself — paid ONLY on a fully armed floor
     *     terminal (stamped or not), where no badge and no frame row is
     *     left to give.
     *
     * Score, next, hold and the earned MA-13 badges are never dropped:
     * hold/next/score are the facts play is decided on, and a badge that
     * cannot paint is a moment the player earned and never saw.
     */
    private static function renderSidebar(Game $game, bool $plain = false): string
    {
        $budget = min($game->frameRows, self::NATURAL_ROWS);
        $next = $game->bag->peek(3);
        $previews = [];
        foreach ($next as $kind) {
            $previews[] = self::renderMini($kind, $plain);
        }
        $score = Lang::t('score_card', [
            'score' => (string) $game->score->points,
            'lines' => (string) $game->score->lines,
            'level' => (string) $game->score->level,
        ]);
        // MA-13: badge the earned moments INSIDE the score card — rows are
        // added only while something is armed, and the candy-tetris-004
        // budget below never sacrifices an armed row: when the expanded
        // card will not fit, the help card (and finally the seed stamp)
        // gives way instead, so a fresh game keeps the exact frame the
        // goldens and ViewportTest pin and an armed one stays on-screen.
        if ($game->lastAward !== null) {
            $score .= "\n" . $game->lastAward;
        }
        if ($game->combo >= 2) {
            $score .= "\n" . \sprintf(Lang::t('combo_line'), $game->combo);
        }
        if ($game->backToBack === true) {
            $score .= "\n" . Lang::t('b2b_line');
        }
        // candy-tetris-018: the reproducible-run stamp rides the score
        // card ONLY when --seed named the run — a plain launch captures
        // hidden entropy and stamps nothing, so every pre-feature frame
        // and golden stays byte-identical. 'SEED <n>' is developer-facing
        // replay data (a number to retype), kept raw English on purpose:
        // translating the token would make it unsearchable in help text.
        // Under the candy-tetris-004 budget this is the LAST casualty (it
        // is dropped only at the MIN_ROWS floor), so the stamp is built
        // apart from the card body to keep that rung addressable.
        $seedStamp = $game->reproducible === true ? "\nSEED " . $game->seed : '';
        // candy-tetris-006: the compact key line — one row, naming the
        // live 'help' binding — replaces the full card wherever the budget
        // has to drop it, so a floor-sized terminal always shows at least
        // one key reference. It rides the score card's tail: a free-standing
        // box would cost three rows the armed floor terminal does not have.
        $mini = "\n" . Lang::t('keys.mini', ['keys' => $game->keyLabel('help')]);
        // m27: the key column is DERIVED from the live table, so a remap
        // moves the card text with it — the card can no longer lie.
        $help = Support\Bindings::helpCard($game->bindings ?? Support\Bindings::table());

        $nextStr = Lang::t('next_label') . "\n" . implode("\n\n", $previews);

        $hold = '';
        if ($game->hold !== null) {
            if ($game->canHold === false) {
                // MA-12/m35: the exhausted state gets an ON-FRAME TEXT cue
                // riding the LABEL row — an extra body line would resize the
                // card mid-play (the very reflow m35 complains of), and the
                // dim SGR alone was a visible no-op where style does not
                // land. Colour posture keeps the dim over the whole card.
                $hold = Lang::t('hold_label') . ' ' . Lang::t('hold_used') . "\n" . self::renderMini($game->hold, $plain);
                if ($plain === false) {
                    $hold = SugarStyle::new()->dim(true)->render($hold);
                }
            } else {
                $hold = Lang::t('hold_label') . "\n" . self::renderMini($game->hold, $plain);
            }
        } else {
            $hold = Lang::t('hold_label') . "\n" . self::renderMiniPlaceholder();
        }

        $card = static fn(string $body): string => SugarStyle::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->width(20)
            ->render($body);

        $column = [$card($hold), $card($nextStr), $card($score . $seedStamp)];

        $full = Layout::joinVertical(0.0, ...[...$column, $card($help)]);
        if (self::height($full) <= $budget) {
            return $full;
        }

        // Below the card's budget (candy-tetris-006) the column keeps a
        // compact key line instead: the casualty is the CARD, never the
        // education.
        $taught = Layout::joinVertical(0.0, $card($hold), $card($nextStr), $card($score . $seedStamp . $mini));
        if (self::height($taught) <= $budget) {
            return $taught;
        }

        // Seed stamp next: developer replay data sacrificed before the
        // one-row key pointer.
        $unstamped = Layout::joinVertical(0.0, $card($hold), $card($nextStr), $card($score . $mini));
        if (self::height($unstamped) <= $budget) {
            return $unstamped;
        }

        // Last rung: a fully armed floor terminal (two or more badge
        // rows, stamped or not) — every badge must paint, so even the
        // one-row pointer pays here and the press itself stays reachable
        // via --help. No other state lands on this rung (measured: the
        // armed column with the pointer is 25 rows on a 24 floor).
        $naked = Layout::joinVertical(0.0, $card($hold), $card($nextStr), $card($score));
        if (self::height($naked) <= $budget) {
            return $naked;
        }

        // Unreachable while tooSmall() refuses below MIN_ROWS and the
        // stripped column measures 24 rows even with all three badges
        // armed; kept loud per Fail Fast in case a future card grows.
        throw new LogicException('sidebar cannot fit a ' . $budget . '-row budget even fully stripped');
    }

    /** Row count of a rendered block — the sidebar's budget measure. */
    private static function height(string $block): int
    {
        return \count(\explode("\n", $block));
    }

    private static function renderMini(Tetromino $kind, bool $plain = false): string
    {
        // candy-tetris-009: the preview of a kind in a colour posture is a
        // deterministic string — fourteen in all. Built once, reused every
        // frame; the sidebar asks for three-plus-one of them per render.
        $cacheKey = $kind->name . ($plain === true ? '/plain' : '/colour');
        $cached = self::$MINIS[$cacheKey] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $cells = Support\Paint::cellMap($kind->cells(0));

        // 4×2 preview grid, two Buffer columns per tetromino cell so the
        // mini blocks keep the playfield's width. Rendered through a Buffer
        // (same path as renderBoard) instead of hand-rolled SGR strings.
        //
        // MA-12: the plain posture carries the shape in the GLYPH ('█')
        // with no style — the colour posture's styled SPACE is invisible
        // once colour is off, which is exactly how a no-colour preview used
        // to read as an empty card.
        $buf = Buffer::new(8, 2);
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if (isset($cells["$x,$y"]) === false) {
                    continue;
                }
                $cell = $plain === true
                    ? Cell::new('█')
                    : Cell::new(' ', self::blockStyle($kind));
                $buf = $buf
                    ->withCellAt($x * 2, $y, $cell)
                    ->withCellAt($x * 2 + 1, $y, $cell);
            }
        }

        return self::$MINIS[$cacheKey] = $buf->toAnsi();
    }

    /**
     * m35: eight box-rule cells at the SAME 8-column width the occupied
     * previews carry — the old 5-space body was neither the preview's width
     * nor an 'empty' anyone could see, so the card changed shape on the
     * first hold. Deliberately not '·': the dot belongs to the playfield's
     * empty-cell vocabulary and the rune census pins it there.
     */
    private static function renderMiniPlaceholder(): string
    {
        return "────────\n────────";
    }

    /**
     * Rendered one-kind previews and the two per-kind styles, cached
     * (candy-tetris-009/-113): a frame painted up to two Style objects per
     * occupied cell, and rebuilt every preview string from scratch. All
     * cached values are deterministic pure functions of (kind, posture).
     *
     * @var array<string, string>
     */
    private static array $MINIS = [];

    /** @var array<string, Style> */
    private static array $BLOCK_STYLES = [];

    /** @var array<string, Style> */
    private static array $GHOST_STYLES = [];

    /**
     * Style for a filled block cell using the tetromino's colour as background.
     */
    private static function blockStyle(Tetromino $kind): Style
    {
        return self::$BLOCK_STYLES[$kind->name] ??= Style::new(null, $kind->rgbColor());
    }

    /**
     * Style for a ghost (landing-preview) cell — faint/dim foreground.
     */
    private static function ghostStyle(Tetromino $kind): Style
    {
        return self::$GHOST_STYLES[$kind->name] ??= Style::new($kind->rgbColor(), null, Style::ATTR_FAINT);
    }
}
