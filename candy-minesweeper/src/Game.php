<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Minesweeper\Stats\DifficultyStats;
use SugarCraft\Minesweeper\Ui\CustomDifficulty;
use SugarCraft\Minesweeper\Ui\CustomForm;

/**
 * Minesweeper as a SugarCraft Model. Keys:
 *   - arrows / hjkl        → move cursor
 *   - space / enter        → reveal
 *   - f                    → toggle flag
 *   - c                    → chord
 *   - S                    → save the live game (single slot, 0600, under
 *                            $XDG_CONFIG_HOME/candy-minesweeper/ when that
 *                            env is set, else $HOME/.config/candy-minesweeper/)
 *   - L                    → load that save — works even after game over,
 *                            so a lost board can step back to the saved one
 *   - r                    → restart
 *   - n                    → new-game picker: 1-4 start a preset, c opens
 *                            the custom-board digit form, q/Esc walk back
 *   - q / esc / Ctrl+C     → quit (q/Esc ask twice on a live unsaved board)
 * Mouse: left-click reveals, right-click flags, middle-click chords.
 *
 * The PRNG is injected as a `Closure(int $maxInclusive): int` so
 * fixture tests can pin the layout. Default is `random_int(0, $max)`.
 */
final class Game implements Model
{
    use Mutable;

    /**
     * Version tag of the savegame envelope (the inner board payload carries
     * its own {@see Board::serialize()} version — both must match on read
     * or the file is refused, never half-parsed).
     */
    private const SAVE_VERSION = 1;

    /**
     * Inter-click window for the double-click chord (msw-29, finding
     * candy-minesweeper-094): trackpads, most macOS terminals and touch
     * surfaces cannot reliably emit middle clicks, and the keyboard `c`
     * was the only chord path. Two left presses on the SAME revealed
     * number cell inside this window route the chord — measured against
     * the previous press (classic semantics), never against game start,
     * and the first press never waits: reveal resolves as it always did,
     * so the primary action pays zero latency tax.
     */
    private const DOUBLE_CLICK_SECONDS = 0.3;

    /** @var \Closure(int):int */
    private \Closure $rand;

    public function __construct(
        public readonly Board $board,
        public readonly int $cursorX = 0,
        public readonly int $cursorY = 0,
        ?\Closure $rand = null,
        public readonly ?float $startedAt = null,
        public readonly ?int $elapsedSeconds = null,
        public readonly ?Stats $stats = null,
        /**
         * Opt-in absolute path for persisting {@see Stats} across sessions.
         * Null (the default) keeps the game entirely in-memory. When set, a
         * completed game's result is written via {@see DifficultyStats} on the
         * win/lose transition — best-effort, never fatal.
         */
        public readonly ?string $statsPath = null,
        /**
         * One-shot toast announced on screen (save/load/restart outcomes,
         * the boot resume hint). It rides every clone until a state-change
         * action dismisses it — ticks deliberately keep it, so the message
         * stays readable while the clock pulses.
         */
        public readonly ?string $notice = null,
        /**
         * True when the finished game below IS the preset's new best time —
         * stamped once on the win/lose transition by afterAction() and
         * rendered as the banner's trailing badge. Losing can never set it.
         */
        public readonly bool $newBest = false,
        /**
         * The preset's previous best time (seconds) that the NEW BEST badge
         * on this finished game beat — null when the badge is a first win
         * (no record existed to name). Stamped by afterAction() together
         * with $newBest so the banner can quote the fallen record (010);
         * the badge alone would otherwise be the only hint a tally existed,
         * and the merged tally has already overwritten the old number.
         */
        public readonly ?int $beatenRecord = null,
        /**
         * Live terminal size, seeded at boot and replaced by every
         * {@see WindowSizeMsg} (startup dispatch + SIGWINCH). The default is
         * the classic 80×24 so headless fixtures render as they always did;
         * {@see tooSmall()} is judged against the real board shape.
         */
        public readonly int $frameCols = 80,
        public readonly int $frameRows = 24,
        /**
         * True once this session has loaded a savegame. A restored clock is
         * re-anchored from an unvalidated file, so the game can never earn a
         * best time with it — the flag survives restart (one-way, monotonic
         * per session) so loading cannot be "laundered" into a record run.
         */
        public readonly bool $restoredGame = false,
        /**
         * True while the board has diverged from the savegame slot (or from
         * nothing, when nothing was saved yet). This is the msw-15/-019
         * trigger for the two-stroke quit: q/Esc on a live, dirty board arms
         * {@see $confirmQuit} instead of discarding silently. Reveals, flags
         * and chords dirty the board; a cursor-only move does not (quitting
         * then discards no progress), and a save, a load or a fresh restart
         * cleans it again.
         */
        public readonly bool $dirty = false,
        /**
         * Armed between the first q/Esc on a dirty live board and the press
         * that acts on it. Any real action disarms it; Ctrl+C never arms —
         * the terminal interrupt contract outranks the in-app confirm
         * (ruling G3(a)).
         */
        public readonly bool $confirmQuit = false,
        /**
         * True while the new-game picker overlay (msw-09/G5-b) is on
         * screen — the `n` shortcut's destination, reached from a live or
         * finished board. The overlay owns the keyboard: q/Esc walk back
         * out (form → picker → game) instead of quitting, while Ctrl+C
         * keeps the terminal's immediate-exit contract even here.
         */
        public readonly bool $picking = false,
        /**
         * The custom-board digit form while `c` has been pressed inside
         * the picker; null while the plain preset list shows. Riding the
         * model (not the renderer) keeps the form's typed digits alive
         * across frames like every other piece of game state.
         */
        public readonly ?CustomForm $form = null,
        /**
         * Double-click chord tracker (msw-29): the board cell of the most
         * recent left-button press and its arrival instant. A second left
         * press on the same REVEALED NUMBER cell inside
         * {@see DOUBLE_CLICK_SECONDS} routes the chord path. Session-local
         * ergonomics: never written to the save envelope, cleared by the
         * picker starts and restarts like any other per-board state.
         */
        public readonly ?float $lastClickAt = null,
        public readonly int $lastClickX = -1,
        public readonly int $lastClickY = -1,
    ) {
        $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
    }

    /**
     * Terminal rows the current frame consumes: board height, the rounded
     * border (2), the status line (1), the four-line help footer, and a
     * spare line — plus one more while a toast rides above the status.
     * Below this the board cannot be shown whole, and the game refuses
     * rather than painting a clipped minefield.
     */
    public function requiredRows(): int
    {
        return $this->board->height + 8 + ($this->notice !== null ? 1 : 0);
    }

    /** True when the live terminal cannot host the full frame. */
    public function tooSmall(): bool
    {
        return $this->frameRows < $this->requiredRows();
    }

    /** Seed the live viewport at boot (the runtime then owns updates). */
    public function withFrame(int $cols, int $rows): self
    {
        if ($cols === $this->frameCols && $rows === $this->frameRows) {
            return $this;
        }
        return $this->mutate(['frameCols' => $cols, 'frameRows' => $rows]);
    }

    public static function start(int $width = 10, int $height = 10, int $mines = 12, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null, ?string $notice = null): self
    {
        return new self(
            board: Board::blank($width, $height, $mines),
            rand:  $rand,
            stats: $stats,
            statsPath: $statsPath,
            notice: $notice,
        );
    }

    public static function withDifficulty(Difficulty $d, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null, ?string $notice = null): self
    {
        return self::start($d->width(), $d->height(), $d->mines(), $rand, $stats, $statsPath, $notice);
    }

    /**
     * Convenience alias of {@see start()} for named-argument call sites
     * ("custom" dimensions vs the preset ladder). Deliberately carries no
     * validation of its own — callers coming from user input route through
     * {@see \SugarCraft\Minesweeper\Ui\CustomDifficulty::fromInput()} first
     * (the CLI does), which is what makes the alias safe to keep.
     */
    public static function withCustom(int $width, int $height, int $mines, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null, ?string $notice = null): self
    {
        return self::start($width, $height, $mines, $rand, $stats, $statsPath, $notice);
    }

    public function difficulty(): ?Difficulty
    {
        return Difficulty::fromDimensions(
            $this->board->width,
            $this->board->height,
            $this->board->mineCount,
        );
    }

    /**
     * The injected PRNG. Exposed because {@see Board::reveal()} takes the
     * closure per call — a caller cloning a Game (or driving the Board
     * directly, as the save round-trip pins do) must reuse this game's
     * sequence rather than silently switching to `random_int`.
     */
    public function rand(): \Closure
    {
        return $this->rand;
    }

    public function stats(): Stats
    {
        return $this->stats ?? new Stats();
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        // Snapshot terminal state BEFORE the action so afterAction() can detect
        // the exact frame a game is won/lost and record the result once.
        $wasOver = $this->board->exploded || $this->board->isWon();

        // Wire mouse before the KeyMsg guard so clicks are handled first.
        if ($msg instanceof MouseMsg) {
            return [$this->afterAction($this->onMouse($msg), $wasOver), null];
        }
        if ($msg instanceof TickMsg) {
            // Clock pulse — repaint only. The model is returned untouched:
            // view() recomputes elapsed() from the wall clock, and the runtime
            // marks the frame dirty after every dispatched message, so the
            // status line advances without a single keypress.
            return [$this, null];
        }
        // Live viewport (terminal fit): the runtime sends this at startup and
        // on every SIGWINCH, so the frame the Model renders always matches the
        // screen the player actually has. It MUST be consumed before the
        // non-KeyMsg discard below — dropping it is what let a shrunk or
        // never-fitting terminal repaint a clipped board forever.
        if ($msg instanceof WindowSizeMsg) {
            if ($msg->cols === $this->frameCols && $msg->rows === $this->frameRows) {
                return [$this, null];
            }
            return [$this->withFrame($msg->cols, $msg->rows), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        // Ctrl/Alt-modified characters are never gameplay commands. The
        // decoder reports Ctrl+R as Char with rune 'r', so without this gate
        // the browser's reload chord silently hard-restarts the board,
        // Ctrl+S/Ctrl+L collide with the save/load keys, and Ctrl+H/I/J/K
        // steer the cursor. Ctrl+C is the one modified rune with meaning —
        // it quits — and is handled (alongside Esc and plain q) below.
        if ($msg->type === KeyType::Char
            && ($msg->ctrl || $msg->alt)
            && !($msg->ctrl && $msg->rune === 'c')) {
            return [$this, null];
        }
        // Gameplay runes fold to lowercase: the binding table is not uniform
        // (q/r/f/c/h/j/k/l are lowercase keys while save/load ride uppercase
        // S/L), so a CapsLock-on player arrives as 'Q','R','F'… and every
        // press used to die in the default arm — the game silently stopped
        // existing. The fold covers only runes whose uppercase form carries
        // no separate meaning; S and L stay case-sensitive because their
        // lowercase twins are taken ('l' moves the cursor, 's' is unbound
        // so plain-lowercase never saves by accident).
        $char = $msg->type === KeyType::Char
            ? match ($msg->rune) {
                'S', 'L' => $msg->rune,
                default  => strtolower($msg->rune),
            }
            : $msg->rune;
        // The picker overlay (msw-09/G5-b) owns the keyboard while it is
        // up: q/Esc walk back out rather than quit (the quit branch below
        // is unreachable from here), Ctrl+C still quits on the spot, and
        // every board key the overlay does not name is inert. Routing
        // AFTER the fold means CapsLock players arrive as lowercase, and
        // AFTER the Ctrl/Alt gate means a browser Ctrl+R cannot steer a
        // preset (finding: modified runes are never gameplay).
        if ($this->picking) {
            return $this->onPicker($msg, $char);
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $char === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            // msw-15/-019 (ruling G3(a)): the first q/Esc on a live, dirty
            // board arms a confirm instead of discarding silently — the
            // antecedent classic prompted mid-play, and the Meta-sends-ESC
            // browser chord (GAMEUX-003) could quit a running game under a
            // laggy link. Finished or unspoiled boards keep the one-press
            // exit (there is nothing to lose), and Ctrl+C keeps the
            // immediate terminal contract by design.
            $dirtyLive = $this->dirty && !($this->board->exploded || $this->board->isWon());
            if (!$dirtyLive || $this->confirmQuit || ($msg->ctrl && $msg->rune === 'c')) {
                return [$this, Cmd::quit()];
            }
            return [$this->mutate([
                'confirmQuit' => true,
                'notice'      => Lang::t('ui.confirm_quit'),
            ]), null];
        }
        if ($msg->type === KeyType::Char && $char === 'r') {
            // Restart carries the accumulated stats + persistence path forward so
            // a session's tally survives replays.
            return [$this->mutate([
                'board'          => Board::blank($this->board->width, $this->board->height, $this->board->mineCount),
                'cursorX'        => 0,
                'cursorY'        => 0,
                'startedAt'      => null,
                'elapsedSeconds' => null,
                'newBest'        => false,
                'beatenRecord'   => null,
                // A fresh board has no progress to lose: the quit confirm
                // neither needs the old game nor this blank one (msw-15).
                'dirty'          => false,
                'confirmQuit'    => false,
                // The double-click pair never crosses a fresh board (msw-29).
                'lastClickAt'    => null,
                'lastClickX'     => -1,
                'lastClickY'     => -1,
                'notice'         => Lang::t('ui.restarted'),
            ]), null];
        }
        if ($msg->type === KeyType::Char && $char === 'n') {
            // msw-09/G5-b: the new-game shortcut opens the difficulty
            // picker from a live OR finished board (the post-game banner
            // names it), so switching shape needs no shell round-trip
            // (finding 011). It sits with r/L — above the over-gate and
            // the tooSmall gate — because opening an overlay is not a
            // board action: nothing is discarded yet, the quit-confirm
            // arming rides through untouched, and the notice screen
            // cannot strand a player who wants a smaller board.
            return [$this->mutate(['picking' => true, 'form' => null]), null];
        }
        if ($msg->type === KeyType::Char && $char === 'L') {
            // Load deliberately sits ABOVE the over-gate: the point of loading
            // a save is often to step away from a board you just lost.
            return [$this->loadSaved(), null];
        }
        if ($this->board->exploded || $this->board->isWon()) {
            // Only r, q and L work after the game ends — saving a finished
            // board would only overwrite a live savegame with a dead one.
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $char === 'S') {
            return [$this->saveGame(), null];
        }
        if ($this->tooSmall()) {
            // Board-mutating keys are dead while the board cannot be shown
            // whole: quit, restart and load above still route. Movement,
            // reveal, flag and chord against an invisible board would be
            // blind fire, so the frame stays a notice screen until the
            // terminal grows back over requiredRows().
            return [$this, null];
        }
        $next = match (true) {
            $msg->type === KeyType::Up    || ($msg->type === KeyType::Char && $char === 'k')
                => $this->moveCursor(0, -1),
            $msg->type === KeyType::Down  || ($msg->type === KeyType::Char && $char === 'j')
                => $this->moveCursor(0, +1),
            $msg->type === KeyType::Left  || ($msg->type === KeyType::Char && $char === 'h')
                => $this->moveCursor(-1, 0),
            $msg->type === KeyType::Right || ($msg->type === KeyType::Char && $char === 'l')
                => $this->moveCursor(+1, 0),
            // Large-board navigation aids (msw-27, finding -046): Expert
            // crossing was one cell per keystroke from the 0,0 spawn.
            $msg->type === KeyType::Home                       => $this->jumpToColumn(0),
            $msg->type === KeyType::End                        => $this->jumpToColumn($this->board->width - 1),
            $msg->type === KeyType::PageUp                     => $this->pageRows(-1),
            $msg->type === KeyType::PageDown                   => $this->pageRows(+1),
            $msg->type === KeyType::Space || $msg->type === KeyType::Enter
                => $this->reveal(),
            $msg->type === KeyType::Char && $char === 'f'
                => $this->flag(),
            $msg->type === KeyType::Char && $char === 'c'
                => $this->chord(),
            default => $this,
        };
        return [$this->afterAction($next, $wasOver), null];
    }

    /**
     * The picker's digit → preset table in display order (msw-09). One
     * source for both the renderer's rows and the model's key handling:
     * a re-ordered enum can never make key `2` start what the screen
     * labelled `3` (finding 058's same-table-different-meaning class).
     *
     * @return array<string,Difficulty> digit key => preset
     */
    public static function presetKeys(): array
    {
        return [
            '1' => Difficulty::Easy,
            '2' => Difficulty::Standard,
            '3' => Difficulty::Medium,
            '4' => Difficulty::Expert,
        ];
    }

    /**
     * Keyboard handling while the picker overlay is up. Returns the same
     * [Game, ?Cmd] contract as update(); Ctrl+C keeps the terminal's
     * immediate-exit promise inside the overlay too.
     */
    private function onPicker(KeyMsg $msg, string $char): array
    {
        if ($msg->ctrl && $msg->rune === 'c') {
            return [$this, Cmd::quit()];
        }
        if ($this->form !== null) {
            return [$this->onForm($msg, $char), null];
        }
        if ($msg->type === KeyType::Escape || $char === 'q') {
            // Back to the game — the board underneath was never touched.
            return [$this->mutate(['picking' => false]), null];
        }
        $preset = self::presetKeys()[$char] ?? null;
        if ($msg->type === KeyType::Char && $preset !== null) {
            return [$this->startBoard($preset->width(), $preset->height(), $preset->mines()), null];
        }
        if ($msg->type === KeyType::Char && $char === 'c') {
            // The digit form opens seeded from CustomDifficulty::new()
            // (-032's rename lands with this, its first production caller).
            return [$this->mutate(['form' => CustomForm::seeded()]), null];
        }
        return [$this, null];
    }

    /**
     * Digit entry inside the custom-board form. Esc/q retreat one step
     * (form → picker), digits and Backspace edit the active field,
     * Enter/Tab advance — and on the mines field, submit. A refused
     * submit keeps every typed digit and shows the translated rule that
     * blamed it (msw-18's law is the same validator the CLI door uses).
     */
    private function onForm(KeyMsg $msg, string $char): self
    {
        $form = $this->form;
        if ($form === null) {
            return $this;
        }
        if ($msg->type === KeyType::Escape || $char === 'q') {
            return $this->mutate(['form' => null]);
        }
        if ($msg->type === KeyType::Backspace) {
            return $this->mutate(['form' => $form->withBackspace()]);
        }
        if ($msg->type === KeyType::Enter || $msg->type === KeyType::Tab) {
            if (!$form->atSubmitField()) {
                return $this->mutate(['form' => $form->advanced()]);
            }
            try {
                $custom = $form->submit();
            } catch (\InvalidArgumentException $e) {
                // Translated by the validator itself — the form carries
                // the message verbatim, never a second English copy.
                return $this->mutate(['form' => $form->withError($e->getMessage())]);
            }
            // cols=width, rows=height: the bridge CustomForm documented.
            return $this->startBoard($custom->cols, $custom->rows, $custom->mines);
        }
        if ($msg->type === KeyType::Char && ctype_digit($char)) {
            return $this->mutate(['form' => $form->withDigit($char)]);
        }
        return $this;
    }

    /**
     * Start a fresh board of the given shape from the picker. The carry-
     * forward law matches `r` exactly — session stats and the restore
     * history persist (a loaded-clock session stays record-ineligible,
     * one-way), and everything per-board resets: a new board has no
     * progress the quit-confirm could lose, so dirty and its arming
     * clear, and the overlay closes with the board it chose.
     */
    private function startBoard(int $width, int $height, int $mines): self
    {
        return $this->mutate([
            'board'          => Board::blank($width, $height, $mines),
            'cursorX'        => 0,
            'cursorY'        => 0,
            'startedAt'      => null,
            'elapsedSeconds' => null,
            'newBest'        => false,
            'beatenRecord'   => null,
            'dirty'          => false,
            'confirmQuit'    => false,
            'picking'        => false,
            'form'           => null,
            // The double-click pair never crosses a fresh board (msw-29).
            'lastClickAt'    => null,
            'lastClickX'     => -1,
            'lastClickY'     => -1,
            'notice'         => null,
        ]);
    }

    /**
     * Detect the win/lose transition produced by a board-mutating action and
     * record the result exactly once. This is the wiring that makes the whole
     * Stats system live from the CLI — previously {@see recordResult()} was only
     * ever called by tests, so every game's outcome was silently dropped.
     *
     * The transition is "was not over → is now over". Re-entrant key/mouse
     * events on an already-finished board keep $wasOver true and record nothing.
     * Persistence to {@see $statsPath} is opt-in and best-effort.
     */
    private function afterAction(self $next, bool $wasOver): self
    {
        // A toast announced by S/L/r lives until the next STATE-CHANGE action
        // dismisses it; ticks never route through here, so the message stays
        // readable while the clock pulses. Dead inputs must not count: space
        // on a revealed cell, an unmapped key and a click on finished ground
        // leave the board and cursor exactly where they were, and wiping the
        // boot "save is waiting" hint on such a press made the hint
        // vanish under the first stray keystroke (finding 063's live probe).
        $changed = $next->board !== $this->board
            || $next->cursorX !== $this->cursorX
            || $next->cursorY !== $this->cursorY;
        if ($changed && $next->notice !== null) {
            $next = $next->mutate(['notice' => null]);
        }
        if ($changed && $next->confirmQuit) {
            // Any real action answers the quit confirm by declining it.
            $next = $next->mutate(['confirmQuit' => false]);
        }
        if ($next->board !== $this->board && !$next->dirty) {
            // Board state the savegame slot does not hold — the quit
            // confirm's trigger condition (msw-15).
            $next = $next->mutate(['dirty' => true]);
        }
        if ($wasOver) {
            return $next;
        }
        $nowOver = $next->board->exploded || $next->board->isWon();
        if (!$nowOver) {
            return $next;
        }
        // Freeze the elapsed time at the transition frame — once the board is
        // over, elapsed() reads elapsedSeconds, which recordResult() stamps.
        $elapsed = $next->startedAt !== null ? microtime(true) - $next->startedAt : null;
        // Best-beat must be judged against the PREVIOUS tally — recordResult()
        // folds the new time in — and only for preset games (custom boards
        // tally nothing, so they have no bucket to set a record in), only
        // when the tally is actually being kept (stats off = no record to
        // beat, and a badge that celebrates nothing is a lie), and never on
        // a restored clock (see $restoredGame).
        $seconds = $elapsed !== null ? (int) $elapsed : null; // honest wall clock, floored
        $preset  = $next->difficulty();
        $won     = $next->board->isWon();
        $prev    = $preset !== null && $next->stats !== null ? $next->stats->bestTime($preset) : null;
        $isBest  = $preset !== null && $next->stats !== null && !$next->restoredGame
            && $won && $seconds !== null && ($prev === null || $seconds < $prev);
        $recorded = $next->recordResult($elapsed)->mutate([
            'newBest'      => $isBest,
            // The fallen record must be named while it is still knowable:
            // recordResult() folds the new time into the tally, so $prev is
            // the last moment the beaten number exists in memory.
            'beatenRecord' => $isBest ? $prev : null,
        ]);
        $recorded->persistStats();

        return $recorded;
    }

    /**
     * Best-effort write of the accumulated stats to the opt-in path. A stats
     * write failure must never crash a game in progress, so everything is
     * swallowed; a no-op when no path is configured.
     */
    private function persistStats(): void
    {
        if ($this->statsPath === null) {
            return;
        }
        try {
            DifficultyStats::fromStats($this->stats())->save($this->statsPath);
        } catch (\RuntimeException | \JsonException) {
            // Non-fatal: stats persistence is a convenience, not a game
            // invariant. Scoped to the io/format faults the writer declares —
            // a genuine code fault inside it must crash loudly, not vanish.
        }
    }

    /**
     * Absolute path of the single-slot savegame, resolved from the
     * environment at call time (never cached — tests sandbox HOME per test,
     * and a process that learned its HOME after boot should still find the
     * file). XDG_CONFIG_HOME wins when set AND absolute; a relative value
     * is ignored and the slot falls to $HOME/.config (finding -064: the
     * docblock promises an absolute path, and a relative XDG leg would make
     * the slot CWD-dependent — the XDG spec bars relative values too).
     * Null when neither carries a usable value: save then
     * degrades to a no-op and load keeps the current game, mirroring the
     * best-effort stats writes.
     */
    public static function savePath(): ?string
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        if ($xdg !== false && $xdg !== '' && str_starts_with($xdg, '/')) {
            return rtrim($xdg, '/') . '/candy-minesweeper/savegame.json';
        }
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            return null;
        }
        return rtrim($home, '/') . '/.config/candy-minesweeper/savegame.json';
    }

    /**
     * 'S' — persist the live board to the single savegame slot. Written
     * through {@see AtomicJsonFile} with 0600. TRUST BOUNDARY: the slot
     * contains the FULL live mine layout (serialization is the whole grid
     * state), so treat the file as private data — reading it is a solitaire
     * wall-hack the loader cannot and does not try to prevent. What the
     * loader DOES enforce is semantic coherence ({@see Board::unserialize}):
     * hand-edited payloads that describe impossible boards (lying mine
     * counts, adjacency, first-click detonations, fake wins) are refused.
     * The clock rides as elapsed SECONDS at save time, so 'L' in a fresh
     * process (later this session or next week) resumes the timer from
     * where it stood. Failures are swallowed exactly like
     * {@see persistStats()} — a full disk must never crash a game.
     */
    private function saveGame(): self
    {
        $path = self::savePath();
        if ($path === null) {
            return $this->withNotice(Lang::t('ui.save_failed'));
        }
        try {
            AtomicJsonFile::new($path)
                ->withPermissions(0600)
                ->write([
                    'v'       => self::SAVE_VERSION,
                    'board'   => $this->board->serialize(),
                    'cursorX' => $this->cursorX,
                    'cursorY' => $this->cursorY,
                    'elapsed' => $this->elapsed(),
                ]);
        } catch (\RuntimeException | \JsonException) {
            // Non-fatal by design — but only for the filesystem and JSON
            // faults AtomicJsonFile declares; anything else is a code fault
            // and must surface, not be painted over with save_failed.
            return $this->withNotice(Lang::t('ui.save_failed'));
        }
        return $this->mutate([
            'notice'      => Lang::t('ui.saved'),
            // The slot now holds this exact board: quitting at this point
            // discards nothing the confirm would protect (msw-15).
            'dirty'       => false,
            'confirmQuit' => false,
        ]);
    }

    /**
     * Attach a one-shot toast to the current state. The renderer shows
     * it as its own line and {@see afterAction()} clears it on the next
     * action; the game state itself is untouched either way.
     */
    private function withNotice(string $notice): self
    {
        return $this->mutate(['notice' => $notice]);
    }

    /**
     * 'L' — replace the running game with the savegame slot's state, keeping
     * this session's stats tally (a load is a time machine, not a memory
     * wipe). Every refusal is announced, never silent: no usable slot
     * (missing file or no config path) toasts {@see Lang} key ui.no_save;
     * a present-but-untrustworthy file (unreadable, unversioned, a junk
     * envelope, or a board whose wire fails {@see Board::unserialize()}'s
     * coherence census — strict types, mine-count, adjacency recompute,
     * impossible-state refusal) toasts ui.load_refused. Either way the
     * current game continues untouched: loadSaved() never dies and never
     * coerces. Envelope fields are type-checked here with the same
     * discipline the board loader applies.
     */
    private function loadSaved(): self
    {
        $path = self::savePath();
        if ($path === null) {
            return $this->withNotice(Lang::t('ui.no_save'));
        }
        // Missing slot = nothing to load; present-but-garbage = refused.
        // (read() over a non-existent file answers [], which the envelope
        // check would otherwise mislabel as tampering.)
        $store = AtomicJsonFile::new($path);
        if (!$store->exists()) {
            return $this->withNotice(Lang::t('ui.no_save'));
        }
        try {
            $payload = $store->read();
        } catch (\RuntimeException | \JsonException) {
            // Exactly the read faults AtomicJsonFile declares (io failure,
            // non-array top level, malformed JSON) refuse the load; a code
            // fault stays a code fault.
            return $this->withNotice(Lang::t('ui.load_refused'));
        }
        if (($payload['v'] ?? null) !== self::SAVE_VERSION
            || !is_string($payload['board'] ?? null)) {
            return $this->withNotice(Lang::t('ui.load_refused'));
        }
        $cursorX = $payload['cursorX'] ?? 0;
        $cursorY = $payload['cursorY'] ?? 0;
        $elapsed = $payload['elapsed'] ?? null;
        // Strict envelope typing: junk shapes refuse the load instead of
        // being coerced (an (int) cast over an array is a TypeError, and a
        // negative elapsed would render as a garbage '-1:-40' clock). The
        // clock is additionally clamped to the finite, non-negative,
        // one-hour band a real save could carry — 1e400 arrives as INF
        // through json_decode, and a huge finite claim ("win in 9e9 s"
        // round-trips an inverted leaderboard) is just as forged.
        if (!is_int($cursorX) || !is_int($cursorY)
            || !($elapsed === null || is_int($elapsed) || is_float($elapsed))
            || !is_finite((float) $elapsed)
            || (float) $elapsed < 0.0
            || (float) $elapsed > 3600.0) {
            return $this->withNotice(Lang::t('ui.load_refused'));
        }
        try {
            $board = Board::unserialize($payload['board']);
        } catch (\InvalidArgumentException) {
            return $this->withNotice(Lang::t('ui.load_refused'));
        }
        $over = $board->exploded || $board->isWon();
        return $this->mutate([
            'board'   => $board,
            'cursorX' => max(0, min($board->width  - 1, $cursorX)),
            'cursorY' => max(0, min($board->height - 1, $cursorY)),            // Re-anchor the wall clock so elapsed() continues from the
            // saved reading; a save taken before the first reveal carries
            // elapsed null and stays a clock-not-started game. A board
            // that loads already OVER (a crafted finished save) keeps the
            // saved seconds as its frozen final time instead of reporting
            // a running clock from a re-anchored start.
            'startedAt'      => $over ? null : ($elapsed !== null ? microtime(true) - (float) $elapsed : null),
            'elapsedSeconds' => $over && $elapsed !== null ? (int) $elapsed : null,
            'newBest'        => false,
            'restoredGame'   => true,
            // The board now equals the slot — clean slate for the quit
            // confirm, and any pending arming is stale (msw-15).
            'dirty'          => false,
            'confirmQuit'    => false,
            'notice'         => Lang::t('ui.loaded'),
        ]);
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    private function moveCursor(int $dx, int $dy): self
    {
        return $this->mutate([
            'cursorX' => max(0, min($this->board->width  - 1, $this->cursorX + $dx)),
            'cursorY' => max(0, min($this->board->height - 1, $this->cursorY + $dy)),
        ]);
    }

    /**
     * Navigation aids for large boards (msw-27, finding candy-minesweeper-
     * 046). Home/End snap the cursor to the ends of the current row;
     * PageUp/PageDown jump by half the board's rows (never less than one),
     * clamped at the edges exactly like the single-cell moves they ride.
     * The finding's optional H/M column keys were deliberately skipped:
     * the lowercase fold above makes H arrive as the vim-left binding — a
     * direct collision — and M would teach a second single-letter mnemonic
     * the 80-cell legend has no room for. The wheel stays inert by msw-01's
     * ruling; these four keys are the keyboard's answer to a 30-wide board.
     */
    private function jumpToColumn(int $column): self
    {
        return $this->moveCursor(max(0, min($this->board->width - 1, $column)) - $this->cursorX, 0);
    }

    /**
     * @param int $direction -1 for PageUp (towards row 0), +1 for PageDown
     */
    private function pageRows(int $direction): self
    {
        return $this->moveCursor(0, $direction * max(1, intdiv($this->board->height, 2)));
    }

    private function reveal(): self
    {
        // Compute the board FIRST: a no-op reveal (already-revealed or flagged
        // cell) returns the same Board, and must not arm the clock — the timer
        // is documented (README, subscriptions()) to start on the first actual
        // reveal, not on the first keypress that happened to hit a dud.
        // Re-pressing the reveal key on a satisfied number is likewise a pure
        // no-op: go-sweep's sweep key re-chorded there (userInitiatedSweep);
        // here the chord lives only on `c` and middle-click.
        $board = $this->board->reveal($this->cursorX, $this->cursorY, $this->rand);
        if ($board === $this->board) {
            return $this;
        }
        return $this->mutate([
            'board'          => $board,
            'startedAt'      => $this->startedAt ?? microtime(true),
            'elapsedSeconds' => null,
        ]);
    }

    private function flag(): self
    {
        return $this->mutate([
            'board' => $this->board->cycleMarker($this->cursorX, $this->cursorY),
        ]);
    }

    private function chord(): self
    {
        // Same first-reveal discipline as reveal(): a refused chord (number not
        // satisfied, or cell unchordable) leaves the board untouched and must
        // leave the clock disarmed.
        $board = $this->board->chord($this->cursorX, $this->cursorY);
        if ($board === $this->board) {
            return $this->withChordNotice($this->cursorX, $this->cursorY);
        }
        return $this->mutate([
            'board'          => $board,
            'startedAt'      => $this->startedAt ?? microtime(true),
            'elapsedSeconds' => null,
        ]);
    }

    /**
     * The one refusal worth teaching: a chord attempted on a REVEALED number
     * whose flag count does not match it. Silence there reads as "the key is
     * broken" (finding 063); a named toast teaches the satisfaction rule at
     * the exact moment the player needs it. Chording unexplored ground or a
     * zero cell stays silent — there is no rule there to learn.
     */
    private function withChordNotice(int $x, int $y): self
    {
        $cell = $this->board->cell($x, $y);
        if ($cell !== null && $cell->revealed && $cell->adjacent > 0) {
            return $this->withNotice(Lang::t('ui.chord_not_ready'));
        }
        return $this;
    }

    /**
     * Dispatch a mouse message into a board action (reveal / flag / chord).
     *
     * MouseMsg coordinates are 1-based absolute terminal positions; the
     * frame-to-interior translation runs through
     * {@see Renderer::INTERIOR_COL_OFFSET} / {@see Renderer::INTERIOR_ROW_OFFSET}
     * (documented there: border + padding geometry, Scanner zones 1-based).
     *
     * Ignores clicks outside the board and does nothing after game ends.
     */
    private function onMouse(MouseMsg $msg): self
    {
        // Only handle press events to avoid double-firing on release.
        if (!$msg instanceof MouseClickMsg) {
            return $this;
        }
        if ($this->picking) {
            // The picker overlay paints no board zones (msw-09): clicks
            // are inert here by construction, never guessed at.
            return $this;
        }
        if ($this->tooSmall()) {
            // Clicks are blind while the notice screen is up (see update()).
            return $this;
        }
        if ($this->board->exploded || $this->board->isWon()) {
            return $this;
        }
        // Convert absolute terminal coords → 1-based scanner coords. The
        // frame coupling lives in Renderer as named constants, so border or
        // padding changes move this translation with them.
        $col = $msg->x - Renderer::INTERIOR_COL_OFFSET;
        $row = $msg->y - Renderer::INTERIOR_ROW_OFFSET;
        $cell = Renderer::resolveClick($this, $col, $row);
        if ($cell === null) {
            return $this;
        }
        [$cx, $cy] = $cell;
        return match ($msg->button) {
            MouseButton::Left   => $this->revealAt($cx, $cy),
            MouseButton::Right  => $this->flagAt($cx, $cy),
            MouseButton::Middle => $this->chordAt($cx, $cy),
            default             => $this,
        };
    }

    private function revealAt(int $x, int $y): self
    {
        // A reveal click that reveals nothing (flagged or already-open cell)
        // is a full no-op: no cursor jump, no clock arming — matching the
        // keyboard, where Space on a dud cell does not start the timer.
        $cell = $this->board->cell($x, $y);
        $now  = microtime(true);

        // Double-click chord (msw-29): the second left press on the same
        // revealed number inside the window is the middle-click gesture —
        // the pair lands here because the first press already resolved
        // (reveal or no-op) and refreshed the tracker below.
        if ($cell->revealed
            && $cell->adjacent > 0
            && $this->lastClickX === $x
            && $this->lastClickY === $y
            && $this->lastClickAt !== null
            && $now - $this->lastClickAt <= self::DOUBLE_CLICK_SECONDS) {
            // The pair is consumed whether or not the number is satisfied:
            // chordAt owns that verdict (and its refusal toast).
            return $this->chordAt($x, $y)
                ->mutate(['lastClickAt' => null, 'lastClickX' => -1, 'lastClickY' => -1]);
        }

        $track = ['lastClickAt' => $now, 'lastClickX' => $x, 'lastClickY' => $y];
        $board = $this->board->reveal($x, $y, $this->rand);
        if ($board === $this->board) {
            return $this->mutate($track);
        }
        return $this->mutate(array_merge([
            'board'          => $board,
            'cursorX'        => $x,
            'cursorY'        => $y,
            'startedAt'      => $this->startedAt ?? $now,
            'elapsedSeconds' => null,
        ], $track));
    }

    private function flagAt(int $x, int $y): self
    {
        return $this->mutate([
            'board'   => $this->board->cycleMarker($x, $y),
            'cursorX' => $x,
            'cursorY' => $y,
        ]);
    }

    private function chordAt(int $x, int $y): self
    {
        // No-op chord = no-op click (see revealAt()), with the same
        // educational refusal toast as the keyboard route.
        $board = $this->board->chord($x, $y);
        if ($board === $this->board) {
            return $this->withChordNotice($x, $y);
        }
        return $this->mutate([
            'board'          => $board,
            'cursorX'        => $x,
            'cursorY'        => $y,
            'startedAt'      => $this->startedAt ?? microtime(true),
            'elapsedSeconds' => null,
        ]);
    }

    /**
     * Elapsed seconds: the live wall clock while the game runs, the frozen
     * {@see $elapsedSeconds} stamp once it is over (set at the transition or
     * restored from a finished save), null before the first reveal.
     */
    public function elapsed(): ?float
    {
        if ($this->board->exploded || $this->board->isWon()) {
            // Finished boards read the frozen stamp — set by recordResult() at
            // the transition or restored by loadSaved() from the envelope —
            // even when no wall clock was ever armed (a crafted finished save).
            return $this->elapsedSeconds !== null ? (float) $this->elapsedSeconds : null;
        }
        if ($this->startedAt === null) {
            return null;
        }
        return microtime(true) - $this->startedAt;
    }

    /**
     * Record the result of this game and return a new Game with updated stats.
     * The returned Game has the same board state but updated stats.
     *
     * Recording is per-preset ({@see Difficulty}): the shipped default shape
     * (10×10, 12 mines) is the Standard preset, so default games DO tally.
     * Truly custom boards match no preset and record no stats — a mixed-size
     * "custom" bucket would make best-time meaningless, so the gap is
     * deliberate and documented rather than silently dishonest. They still
     * freeze the elapsed clock, so {@see elapsed()} reports the real final
     * time instead of null once the board is over.
     *
     * Time folding: the (int) is a floor — every caller hands a validated
     * non-negative value, and truncating the sub-second tail down (never up)
     * cannot inflate a record. A session that restored a save passes null
     * instead of its elapsed time: the game and win still count, but a clock
     * re-anchored from a user-editable file is not evidence for a best time.
     */
    public function recordResult(?float $elapsed): self
    {
        $difficulty = $this->difficulty();
        if ($difficulty === null) {
            // Custom boards tally nothing (no preset bucket to file the
            // result under) — but the end-of-game clock must still freeze.
            // Without this, elapsed() falls through to null once the board
            // is over and a finished custom game has no final time at all.
            return $this->mutate([
                'elapsedSeconds' => $elapsed !== null ? (int) $elapsed : null,
            ]);
        }
        $won = $this->board->isWon();
        return $this->mutate([
            'elapsedSeconds' => $elapsed !== null ? (int) $elapsed : null,
            'stats'          => $this->stats()->withGame(
                $difficulty,
                $won,
                $elapsed !== null && !$this->restoredGame ? (int) $elapsed : null,
            ),
        ]);
    }

    /**
     * Arm the 1 Hz 'clock' tick while — and only while — the timer is live:
     * from the first actual reveal (startedAt set; dud keypresses and refused
     * clicks never arm it) to the win/lose transition (board
     * over, elapsed frozen into elapsedSeconds). The runtime reconciles
     * subscriptions after every update cycle, so the tick starts on the first
     * reveal and is cancelled when this method stops listing the id.
     */
    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        if ($this->startedAt === null || $this->board->exploded || $this->board->isWon()) {
            return null;
        }
        return (new \SugarCraft\Core\Subscriptions())->withTick(
            'clock',
            1.0,
            static fn (): Msg => new TickMsg(),
        );
    }
}
