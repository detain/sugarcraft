<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Style;

/**
 * Split-screen renderer for VS Computer mode.
 *
 * Renders two boards side by side over one full-width match strip:
 *
 *   ┌─────────────┐    ┌─────────────┐
 *   │   PLAYER    │ VS │  COMPUTER   │
 *   │  playfield  │    │  playfield  │
 *   │   (board)   │    │   (board)   │
 *   │             │    │             │
 *   └─────────────┘    └─────────────┘
 *   ┌ both scores · next · hold · keys · garbage rule ┐  (match strip)
 *   └──────────────────────────────────────────────────┘
 *
 * Player pieces use the shared `Tetromino::color()` palette; computer pieces
 * use `VsRenderer::COMPUTER_COLORS` — an explicit 256-colour map deliberately
 * DISJOINT from it, because the old `(color + 150) % 256` hue shift made
 * computer S land on player Z's red (see that constant's docblock). The two
 * boards never paint the same kind identically.
 */
final class VsRenderer
{
    /**
     * Natural VS frame (both panels complete) — provisional, validated
     * by the viewport tests; see {@see self::MIN_*} for the floor law.
     * candy-tetris-015 grew the match strip to the full teaching table,
     * and candy-tetris-016's flip row made it four key rows + the garbage
     * rule — 23 board rows + a 10-row card = 33 (the same height law the
     * solo frame already names).
     */
    public const NATURAL_COLS = 54;
    public const NATURAL_ROWS = 33;

    /**
     * Hard floor for the split screen: both framed boards with their
     * titles (23 rows) plus the match strip, and every overlay banner
     * fact must stay ON-frame. Below it a resize notice replaces the
     * frame (C-2).
     */
    public const MIN_COLS = 54;
    public const MIN_ROWS = 33;

    public static function render(VsGame $game): string
    {
        // Posture dispatch, mirror of Renderer::render (C-2): NoTty gets a
        // plain refusal, sub-floor terminals a resize notice, and overlays
        // are painted INTO the fixed frame — never appended below it.
        if ($game->colorProfile === ColorProfile::NoTty) {
            return Support\Paint::refusal();
        }

        if ($game->tooSmall() === true) {
            return Support\Paint::tooSmallNotice(
                'cli.too_small_vs',
                self::MIN_COLS,
                self::MIN_ROWS,
                $game->frameCols,
                $game->frameRows,
            );
        }

        // MA-12 colour posture, mirror of Renderer (see its comment).
        $plain = $game->colorProfile === ColorProfile::Ascii;

        $playerBoard = self::renderBoard($game->player, false, $plain);
        $computerBoard = self::renderBoard($game->computer, true, $plain);

        $vsDivider = $plain === true
            ? "  VS  "
            : Style::new()->bold(true)->render("  VS  ");

        $boards = Layout::joinHorizontal(0.0, $playerBoard, $vsDivider, $computerBoard);
        $body   = Layout::joinVertical(0.0, $boards, self::renderStrip($game->player, $game->computer));

        $notices = [...$game->player->notices, ...$game->notices];
        if ($game->over === true) {
            return Support\Paint::banner($body, [...self::overLines($game), ...$notices]);
        }

        // MA-8: the VS pair has a p binding (README promises it) and a
        // pause gate in VsGame::update — but until now the paused world
        // was indistinguishable from a hang: zero on-frame evidence.
        if ($game->player->paused === true) {
            // candy-tetris-005: the overlay names the live pause binding.
            return Support\Paint::banner($body, [...[Lang::t('paused', ['keys' => $game->player->keyLabel('pause')])], ...$notices]);
        }

        if ($notices !== []) {
            // candy-tetris-092: the incoming-garbage alert (and every
            // other VS moment) paints through the same band machinery the
            // banners already own — silent was the complaint.
            return Support\Paint::banner($body, $notices);
        }

        return $body;
    }

    /**
     * @return list<string>
     */
    private static function overLines(VsGame $game): array
    {
        $lines = [Lang::t('over.title')];
        $lines[] = $game->winner === 'PLAYER'
            ? Lang::t('win.player')
            : Lang::t('win.computer');
        $lines[] = Lang::t('over.final_score', ['points' => $game->player->score->points]);
        // MA-10: name why the LOSING board died (the winner keeps playing
        // facts; the loser carries the overReason).
        $loser = $game->winner === 'PLAYER' ? $game->computer : $game->player;
        if ($loser->overReason !== null) {
            $lines[] = Lang::t('over.reason', ['reason' => match ($loser->overReason) {
                Game::OVER_SPAWN_BLOCKED => Lang::t('over.reason.spawn_blocked'),
                Game::OVER_TOP_OUT       => Lang::t('over.reason.top_out'),
                Game::OVER_CRUSHED       => Lang::t('over.reason.crushed'),
                default                  => $loser->overReason,
            }]);
        }
        // MA-23: the versus record is a win/loss tally, not a best —
        // a race between two boards has no single-player score to keep.
        if ($game->scores !== null) {
            $lines[] = Lang::t('vs.record', [
                'wins' => $game->scores->versusWins,
                'losses' => $game->scores->versusLosses,
            ]);
        }
        // The versus over screen answers r exactly like the solo one
        // (VsGame::update), so the banner teaches both live keys
        // (candy-tetris-005: named from the player's table, folded to
        // whole-word lines through the shared solo helper).
        foreach (Renderer::restartHintLines($game->player) as $hintLine) {
            $lines[] = $hintLine;
        }
        return $lines;
    }

    /**
     * Render a single board for VS mode.
     *
     * @param Game $game The game to render
     * @param bool $isComputer Whether this is the computer's board (different hue)
     * @param bool $plain Zero-SGR posture (MA-12): shape-carrying glyphs only.
     */
    private static function renderBoard(Game $game, bool $isComputer, bool $plain = false): string
    {
        $rows = $game->board->rows();
        $piece = $game->piece;
        $ghost = $game->board->dropPiece($piece);
        $pieceCells = Support\Paint::cellMap($piece->cells());
        $ghostCells = Support\Paint::cellMap($ghost->cells());

        $lines = [];
        for ($y = Board::HIDDEN_ROWS; $y < Board::ROWS; $y++) {
            $line = '';
            for ($x = 0; $x < Board::COLS; $x++) {
                $key = "$x,$y";
                $cellKind = $rows[$y][$x] ?? null;
                if ($cellKind !== null) {
                    $line .= $isComputer === true ? self::blockComputer($cellKind, $plain) : self::block($cellKind, $plain);
                    continue;
                }
                if (isset($pieceCells[$key]) === true) {
                    $line .= $isComputer === true ? self::blockComputer($piece->kind, $plain) : self::block($piece->kind, $plain);
                    continue;
                }
                if (isset($ghostCells[$key]) === true) {
                    $line .= $isComputer === true ? self::ghostComputer($piece->kind, $plain) : self::ghost($piece->kind, $plain);
                    continue;
                }
                $line .= '··';
            }
            $lines[] = $line;
        }

        $title = $isComputer === true ? Lang::t('label.computer') : Lang::t('label.player');
        $titleBlock = $plain === true
            ? $title
            : Style::new()->bold(true)->render($title);

        return Layout::joinVertical(0.0,
            $titleBlock,
            Style::new()
                ->border(Border::rounded())
                ->padding(0, 1)
                ->render(implode("\n", $lines))
        );
    }

    /**
     * The match strip: one full-width card under both boards carrying the
     * facts the versus view used to hide — both scores, the player's three-
     * piece next preview, the player's hold state (with the exhausted
     * cue), the FULL live key table in compact rows, and the garbage
     * exchange rule (candy-tetris-015). Deliberately ONE card: per-panel
     * cards under 23-row boards would overshoot the height budget far
     * worse, and the computer's queue is kept private on purpose.
     * Plain-text rows (no SGR) so the card is identical in both colour
     * postures.
     */
    private static function renderStrip(Game $player, Game $computer): string
    {
        $line = static fn(string $row): string => Width::of($row) > 50
            ? Width::truncateAnsi($row, 50)
            : $row;

        $nextLetters = implode('  ', array_map(
            static fn(Tetromino $kind): string => $kind->value,
            $player->bag->peek(3),
        ));

        $holdText = $player->hold === null
            ? '-'
            : $player->hold->value . ($player->canHold === false ? ' ' . Lang::t('hold_used') : '');

        // The award must land whole: the $line truncator would otherwise
        // half-cut it into 'TETR', so the score/lines fields give way by
        // one digit each before the badge does.
        $youAward = $player->lastAward !== null ? '  ' . $player->lastAward : '';

        // candy-tetris-008: the frame words are catalogue keys like every
        // other string the strip speaks — English baked into sprintf made
        // the whole panel un-translatable, and the badge leg's 'lvl' drift
        // from the card's 'level' died with this pass. Columns are derived
        // from the locale's own label widths (findings:130: mb_strwidth
        // semantics through Width::of); the 9-slot name column is the
        // shipped English alignment, so en bytes stand unchanged.
        $nameYou  = Lang::t('vs.strip.you');
        $nameCpu  = Lang::t('vs.strip.computer');
        $nameCol  = max(9, Width::of($nameYou), Width::of($nameCpu));
        $rows = [
            $line(self::stripRow($nameYou, $player->score, $nameCol, $youAward)),
            $line(self::stripRow($nameCpu, $computer->score, $nameCol, '')),
            $line(sprintf('%s %s        %s %s', Lang::t('next_label'), $nextLetters, Lang::t('hold_label'), $holdText)),
        ];

        // The full gameplay table, chunked three rows to a line by
        // Bindings::vsStrip (candy-tetris-015: the old one-line strip
        // taught 5 of the 11 bindings and let movement/rotation go
        // untaught). Each returned line is its own strip row.
        foreach (explode("\n", Support\Bindings::vsStrip($player->bindings ?? Support\Bindings::table())) as $keyRow) {
            $rows[] = $line($keyRow);
        }

        // One-line garbage rule, always on-frame (candy-tetris-015's
        // "never explains the rules" half: clearing lines sinks the
        // opponent's stack — the only way to win besides them topping out).
        $rows[] = $line(Lang::t('vs.garbage_rule'));

        $body = implode("\n", $rows);

        $card = Style::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->width(50);

        return $card->render($body);
    }

    /**
     * One scoreboard row of the match strip (candy-tetris-008): locale
     * labels, display-width-padded numeric columns, and the award badge
     * riding at the tail whole. With a badge present the two wide fields
     * each give a digit so the row still fits the strip's 50-cell budget.
     */
    private static function stripRow(string $name, Score $score, int $nameCol, string $award): string
    {
        $figures = $award === '' ? [7, 3] : [6, 2];

        return $name . str_repeat(' ', max(1, $nameCol - Width::of($name)))
            . ' ' . Lang::t('vs.strip.score') . ' ' . str_pad((string) $score->points, $figures[0])
            . ' ' . Lang::t('vs.strip.lines') . ' ' . str_pad((string) $score->lines, $figures[1])
            . ' ' . Lang::t('vs.strip.level') . ' ' . $score->level
            . $award;
    }

    /**
     * Filled cell. MA-12: the block carries the '█' GLYPH as well as the
     * colour — the old two styled spaces made every locked cell (and the
     * falling piece) disappear wherever the background SGR did not land,
     * inverting the board so holes looked like blocks.
     */
    /**
     * candy-tetris-052 measured against the finding's preferred shape: the
     * Buffer cell-grid path cannot serve these bytes — Buffer::toAnsi emits
     * TRUECOLOUR run-joined cells while the VS boards paint 256-palette SGR
     * around literal block glyphs (verified byte-for-byte before choosing),
     * and migrating would repaint every versus golden, perf rows do not
     * bargain with art. The allocation waste is taken anyway: every cell
     * string is a deterministic function of (kind, posture, panel palette)
     * — 56 in all — so each helper memoises its shape and the per-cell
     * Ansi:: concatenations happen once per process, not twice per painted
     * square per frame.
     *
     * @var array<string, string>
     */
    private static array $CELLS = [];

    private static function cell(string $shape, Tetromino $kind, bool $plain, \Closure $build): string
    {
        return self::$CELLS[$shape . '|' . $kind->name . '|' . ($plain ? 'p' : 'c')] ??= $build();
    }

    private static function block(Tetromino $kind, bool $plain = false): string
    {
        return self::cell('block', $kind, $plain, static fn(): string => $plain === true
            ? '██'
            : Ansi::bg256($kind->color()) . '██' . Ansi::reset());
    }

    private static function ghost(Tetromino $kind, bool $plain = false): string
    {
        return self::cell('ghost', $kind, $plain, static fn(): string => $plain === true
            ? '▒▒'
            : Ansi::sgr(38, 5, $kind->color(), 2) . '▒▒' . Ansi::reset());
    }

    /**
     * Computer-field palette: one 256-colour code per kind, deliberately
     * DISJOINT from the player palette ({@see Tetromino::color()}) so the
     * two boards never paint the same kind identically. The previous
     * `(color + 150) % 256` shift collided — computer S landed exactly on
     * player Z's red 196, and computer O became a second green.
     *
     * Keyed by {@see Tetromino::value} — PHP const arrays cannot key on
     * enum cases (measured 8.3).
     *
     * @var array<string, int>
     */
    public const COMPUTER_COLORS = [
        'I' => 80,
        'O' => 185,
        'T' => 99,
        'S' => 113,
        'Z' => 203,
        'J' => 27,
        'L' => 215,
    ];

    private static function blockComputer(Tetromino $kind, bool $plain = false): string
    {
        return self::cell('blockC', $kind, $plain, static fn(): string => $plain === true
            ? '██'
            : Ansi::bg256(self::COMPUTER_COLORS[$kind->value]) . '██' . Ansi::reset());
    }

    private static function ghostComputer(Tetromino $kind, bool $plain = false): string
    {
        return self::cell('ghostC', $kind, $plain, static fn(): string => $plain === true
            ? '▒▒'
            : Ansi::sgr(38, 5, self::COMPUTER_COLORS[$kind->value], 2) . '▒▒' . Ansi::reset());
    }
}
