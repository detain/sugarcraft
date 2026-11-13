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
 *   ┌ both scores · next · hold · keys ┐   (match strip)
 *   └──────────────────────────────────┘
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
     */
    public const NATURAL_COLS = 54;
    public const NATURAL_ROWS = 29;

    /**
     * Hard floor for the split screen: both framed boards with their
     * titles (23 rows) plus the match strip, and every overlay banner
     * fact must stay ON-frame. Below it a resize notice replaces the
     * frame (C-2).
     */
    public const MIN_COLS = 54;
    public const MIN_ROWS = 29;

    public static function render(VsGame $game): string
    {
        // Posture dispatch, mirror of Renderer::render (C-2): NoTty gets a
        // plain refusal, sub-floor terminals a resize notice, and overlays
        // are painted INTO the fixed frame — never appended below it.
        if ($game->colorProfile === ColorProfile::NoTty) {
            return self::refusal();
        }

        if ($game->tooSmall() === true) {
            return self::tooSmallNotice($game->frameCols, $game->frameRows);
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

        if ($game->over === true) {
            return self::paintBanner($body, self::overLines($game));
        }

        // MA-8: the VS pair has a p binding (README promises it) and a
        // pause gate in VsGame::update — but until now the paused world
        // was indistinguishable from a hang: zero on-frame evidence.
        if ($game->player->paused === true) {
            return self::paintBanner($body, [Lang::t('paused')]);
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
        // (VsGame::update), so the banner teaches both live keys:
        // restart_hint carries 'press r to restart — press q to quit'.
        $lines[] = Lang::t('over.restart_hint');
        return $lines;
    }

    /**
     * Replace the vertically-centred board band rows with plain-text
     * banner lines, centred across the body (see Renderer::paintBanner —
     * identical posture so solo and VS overlay the same way).
     *
     * @param list<string> $lines
     */
    private static function paintBanner(string $body, array $lines): string
    {
        $rows = explode("\n", $body);
        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, Width::of($row));
        }

        $top = intdiv(Board::VISIBLE_ROWS - count($lines), 2);
        foreach ($lines as $i => $line) {
            $row = $top + $i;
            if ($row < 0 || $row >= count($rows)) {
                continue;
            }
            $text = Width::of($line) > $width
                ? Width::truncateAnsi($line, $width)
                : $line;
            $left = max(0, intdiv($width - Width::of($text), 2));
            $rows[$row] = \str_repeat(' ', $left) . $text;
        }

        return implode("\n", $rows);
    }

    /**
     * No-TTY refusal — one plain line, zero SGR bytes. Kept verbatim with
     * {@see Renderer::refusal()}; colour is optional, the TTY is not
     * (candy-tetris-041).
     */
    private static function refusal(): string
    {
        return Lang::t('cli.refusal') . "\n";
    }

    private static function tooSmallNotice(int $cols, int $rows): string
    {
        $notice = Lang::t('cli.too_small_vs', [
            'size' => self::MIN_COLS . 'x' . self::MIN_ROWS,
            'actual' => $cols . 'x' . $rows,
        ]) . "\n";
        $width = max(20, $cols);
        $out = [];
        foreach (explode("\n", $notice) as $paragraph) {
            $line = '';
            foreach (preg_split('/ +/', $paragraph) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (Width::of($candidate) > $width && $line !== '') {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $out[] = $line;
        }
        return implode("\n", $out) . "\n";
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
        $pieceCells = self::cellMap($piece->cells());
        $ghostCells = self::cellMap($ghost->cells());

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
     * cue), and the key list. Deliberately ONE card: per-panel cards under
     * 23-row boards would overshoot the height budget far worse, and the
     * computer's queue is kept private on purpose. Plain-text rows (no
     * SGR) so the card is identical in both colour postures.
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
        // half-cut it into 'TETR'. A level fits ≤2 digits at any sane
        // score (3600 lines/level → level ≤ 4 digits even absurdly), so
        // the score/lines fields give way before the badge does.
        $youAward = $player->lastAward !== null ? '  ' . $player->lastAward : '';

        $body = implode("\n", [
            $line($youAward === ''
                ? sprintf(
                    'you       score: %-7d lines: %-3d level: %d',
                    $player->score->points,
                    $player->score->lines,
                    $player->score->level,
                )
                : sprintf(
                    'you       score: %-6d lines: %-2d lvl %d',
                    $player->score->points,
                    $player->score->lines,
                    $player->score->level,
                ) . $youAward),
            $line(sprintf(
                'computer  score: %-7d lines: %-3d level: %d',
                $computer->score->points,
                $computer->score->lines,
                $computer->score->level,
            )),
            $line(sprintf('%s %s        %s %s', Lang::t('next_label'), $nextLetters, Lang::t('hold_label'), $holdText)),
            $line(Support\Bindings::vsStrip($player->bindings ?? Support\Bindings::table())),
        ]);

        $card = Style::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->width(50);

        return $card->render($body);
    }

    /**
     * @param list<array{int,int}> $cells
     * @return array<string,true>
     */
    private static function cellMap(array $cells): array
    {
        $out = [];
        foreach ($cells as [$x, $y]) {
            $out["$x,$y"] = true;
        }
        return $out;
    }

    /**
     * Filled cell. MA-12: the block carries the '█' GLYPH as well as the
     * colour — the old two styled spaces made every locked cell (and the
     * falling piece) disappear wherever the background SGR did not land,
     * inverting the board so holes looked like blocks.
     */
    private static function block(Tetromino $kind, bool $plain = false): string
    {
        return $plain === true
            ? '██'
            : Ansi::bg256($kind->color()) . '██' . Ansi::reset();
    }

    private static function ghost(Tetromino $kind, bool $plain = false): string
    {
        return $plain === true
            ? '▒▒'
            : Ansi::sgr(38, 5, $kind->color(), 2) . '▒▒' . Ansi::reset();
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
        return $plain === true
            ? '██'
            : Ansi::bg256(self::COMPUTER_COLORS[$kind->value]) . '██' . Ansi::reset();
    }

    private static function ghostComputer(Tetromino $kind, bool $plain = false): string
    {
        return $plain === true
            ? '▒▒'
            : Ansi::sgr(38, 5, self::COMPUTER_COLORS[$kind->value], 2) . '▒▒' . Ansi::reset();
    }
}
