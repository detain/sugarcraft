<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Minesweeper\Stats\DifficultyStats;

/**
 * Minesweeper as a SugarCraft Model. Keys:
 *   - arrows / hjkl        → move cursor
 *   - space / enter        → reveal
 *   - f                    → toggle flag
 *   - c                    → chord
 *   - S                    → save the live game (single slot under
 *                            $HOME/.config/candy-minesweeper/, 0600)
 *   - L                    → load that save — works even after game over,
 *                            so a lost board can step back to the saved one
 *   - r                    → restart
 *   - q / esc / Ctrl+C     → quit
 * Mouse: left-click reveals, right-click flags, middle-click chords.
 *
 * The PRNG is injected as a `Closure(int $maxInclusive): int` so
 * fixture tests can pin the layout. Default is `random_int(0, $max)`.
 */
final class Game implements Model
{
    /**
     * Version tag of the savegame envelope (the inner board payload carries
     * its own {@see Board::serialize()} version — both must match on read
     * or the file is refused, never half-parsed).
     */
    private const SAVE_VERSION = 1;

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
    ) {
        $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
    }

    public static function start(int $width = 10, int $height = 10, int $mines = 12, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null): self
    {
        return new self(
            board: Board::blank($width, $height, $mines),
            rand:  $rand,
            stats: $stats,
            statsPath: $statsPath,
        );
    }

    public static function withDifficulty(Difficulty $d, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null): self
    {
        return self::start($d->width(), $d->height(), $d->mines(), $rand, $stats, $statsPath);
    }

    /**
     * Convenience alias of {@see start()} for named-argument call sites
     * ("custom" dimensions vs the preset ladder). Deliberately carries no
     * validation of its own — callers coming from user input route through
     * {@see \SugarCraft\Minesweeper\Ui\CustomDifficulty::fromInput()} first
     * (the CLI does), which is what makes the alias safe to keep.
     */
    public static function withCustom(int $width, int $height, int $mines, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null): self
    {
        return self::start($width, $height, $mines, $rand, $stats, $statsPath);
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
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            // Restart carries the accumulated stats + persistence path forward so
            // a session's tally survives replays.
            return [self::withCustom(
                $this->board->width, $this->board->height, $this->board->mineCount,
                $this->rand, $this->stats, $this->statsPath,
            ), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'L') {
            // Load deliberately sits ABOVE the over-gate: the point of loading
            // a save is often to step away from a board you just lost.
            return [$this->loadSaved(), null];
        }
        if ($this->board->exploded || $this->board->isWon()) {
            // Only r, q and L work after the game ends — saving a finished
            // board would only overwrite a live savegame with a dead one.
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'S') {
            return [$this->saveGame(), null];
        }
        $next = match (true) {
            $msg->type === KeyType::Up    || ($msg->type === KeyType::Char && $msg->rune === 'k')
                => $this->moveCursor(0, -1),
            $msg->type === KeyType::Down  || ($msg->type === KeyType::Char && $msg->rune === 'j')
                => $this->moveCursor(0, +1),
            $msg->type === KeyType::Left  || ($msg->type === KeyType::Char && $msg->rune === 'h')
                => $this->moveCursor(-1, 0),
            $msg->type === KeyType::Right || ($msg->type === KeyType::Char && $msg->rune === 'l')
                => $this->moveCursor(+1, 0),
            $msg->type === KeyType::Space || $msg->type === KeyType::Enter
                => $this->reveal(),
            $msg->type === KeyType::Char && $msg->rune === 'f'
                => $this->flag(),
            $msg->type === KeyType::Char && $msg->rune === 'c'
                => $this->chord(),
            default => $this,
        };
        return [$this->afterAction($next, $wasOver), null];
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
        $recorded = $next->recordResult($elapsed);
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
        } catch (\Throwable) {
            // Non-fatal: stats persistence is a convenience, not a game invariant.
        }
    }

    /**
     * Absolute path of the single-slot savegame, resolved from $HOME at call
     * time (never cached — tests sandbox HOME per test, and a process that
     * learned its HOME after boot should still find the file). Null when the
     * environment carries no usable HOME: save then degrades to a no-op and
     * load keeps the current game, mirroring the best-effort stats writes.
     */
    public static function savePath(): ?string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            return null;
        }
        return rtrim($home, '/') . '/.config/candy-minesweeper/savegame.json';
    }

    /**
     * 'S' — persist the live board to the single savegame slot. Written
     * through {@see AtomicJsonFile} with 0600: a savegame reveals nothing
     * secret, but it lives under $HOME like the user's other config and the
     * strict mode costs nothing. The clock rides as elapsed SECONDS at save
     * time, so 'L' in a fresh process (later this session or next week)
     * resumes the timer from where it stood. Failures are swallowed exactly
     * like {@see persistStats()} — a full disk must never crash a game.
     */
    private function saveGame(): self
    {
        $path = self::savePath();
        if ($path === null) {
            return $this;
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
        } catch (\Throwable) {
            // Non-fatal by design; the in-memory game stays fully playable.
        }
        return $this;
    }

    /**
     * 'L' — replace the running game with the savegame slot's state, keeping
     * this session's stats tally (a load is a time machine, not a memory
     * wipe). Missing/unreadable/unversioned files are refused quietly and
     * the current game continues. The board itself goes through
     * {@see Board::unserialize()}, whose tamper hardening (recomputed
     * revealed/flagged counts, enforced version) is what makes trusting a
     * file on disk safe at all.
     */
    private function loadSaved(): self
    {
        $path = self::savePath();
        if ($path === null) {
            return $this;
        }
        try {
            $payload = AtomicJsonFile::new($path)->read();
        } catch (\Throwable) {
            return $this;
        }
        if (($payload['v'] ?? null) !== self::SAVE_VERSION
            || !is_string($payload['board'] ?? null)) {
            return $this;
        }
        try {
            $board = Board::unserialize($payload['board']);
        } catch (\InvalidArgumentException) {
            return $this;
        }
        $elapsed = $payload['elapsed'] ?? null;
        return new self(
            board: $board,
            cursorX: max(0, min($board->width  - 1, (int) ($payload['cursorX'] ?? 0))),
            cursorY: max(0, min($board->height - 1, (int) ($payload['cursorY'] ?? 0))),
            rand: $this->rand,
            // Re-anchor the wall clock so elapsed() continues from the
            // saved reading; a save taken before the first reveal carries
            // elapsed null and stays a clock-not-started game.
            startedAt: (is_float($elapsed) || is_int($elapsed))
                ? microtime(true) - (float) $elapsed
                : null,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    private function moveCursor(int $dx, int $dy): self
    {
        return new self(
            board: $this->board,
            cursorX: max(0, min($this->board->width  - 1, $this->cursorX + $dx)),
            cursorY: max(0, min($this->board->height - 1, $this->cursorY + $dy)),
            rand: $this->rand,
            startedAt: $this->startedAt,
            elapsedSeconds: $this->elapsedSeconds,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    private function reveal(): self
    {
        // Compute the board FIRST: a no-op reveal (already-revealed or flagged
        // cell) returns the same Board, and must not arm the clock — the timer
        // is documented (README, subscriptions()) to start on the first actual
        // reveal, not on the first keypress that happened to hit a dud.
        $board = $this->board->reveal($this->cursorX, $this->cursorY, $this->rand);
        if ($board === $this->board) {
            return $this;
        }
        return new self(
            board: $board,
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $this->startedAt ?? microtime(true),
            elapsedSeconds: null,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    private function flag(): self
    {
        return new self(
            board: $this->board->toggleFlag($this->cursorX, $this->cursorY),
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $this->startedAt,
            elapsedSeconds: $this->elapsedSeconds,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    private function chord(): self
    {
        // Same first-reveal discipline as reveal(): a refused chord (number not
        // satisfied, or cell unchordable) leaves the board untouched and must
        // leave the clock disarmed.
        $board = $this->board->chord($this->cursorX, $this->cursorY);
        if ($board === $this->board) {
            return $this;
        }
        return new self(
            board: $board,
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $this->startedAt ?? microtime(true),
            elapsedSeconds: null,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
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
        $board = $this->board->reveal($x, $y, $this->rand);
        if ($board === $this->board) {
            return $this;
        }
        return new self(
            board: $board,
            cursorX: $x,
            cursorY: $y,
            rand: $this->rand,
            startedAt: $this->startedAt ?? microtime(true),
            elapsedSeconds: null,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    private function flagAt(int $x, int $y): self
    {
        return new self(
            board: $this->board->toggleFlag($x, $y),
            cursorX: $x,
            cursorY: $y,
            rand: $this->rand,
            startedAt: $this->startedAt,
            elapsedSeconds: $this->elapsedSeconds,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    private function chordAt(int $x, int $y): self
    {
        // No-op chord = no-op click (see revealAt()).
        $board = $this->board->chord($x, $y);
        if ($board === $this->board) {
            return $this;
        }
        return new self(
            board: $board,
            cursorX: $x,
            cursorY: $y,
            rand: $this->rand,
            startedAt: $this->startedAt ?? microtime(true),
            elapsedSeconds: null,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    /**
     * Returns the elapsed time in seconds (sub-second precision) if the game is in progress.
     */
    public function elapsed(): ?float
    {
        if ($this->startedAt === null) {
            return null;
        }
        if ($this->board->exploded || $this->board->isWon()) {
            return $this->elapsedSeconds !== null ? (float) $this->elapsedSeconds : null;
        }
        return microtime(true) - $this->startedAt;
    }

    /**
     * Record the result of this game and return a new Game with updated stats.
     * The returned Game has the same board state but updated stats.
     *
     * Recording is per-preset ({@see Difficulty}): the shipped default shape
     * (10×10, 12 mines) is the STANDARD preset, so default games DO tally.
     * Truly custom boards match no preset and record no stats — a mixed-size
     * "custom" bucket would make best-time meaningless, so the gap is
     * deliberate and documented rather than silently dishonest. They still
     * freeze the elapsed clock, so {@see elapsed()} reports the real final
     * time instead of null once the board is over.
     */
    public function recordResult(?float $elapsed): self
    {
        $difficulty = $this->difficulty();
        if ($difficulty === null) {
            // Custom boards tally nothing (no preset bucket to file the
            // result under) — but the end-of-game clock must still freeze.
            // Without this, elapsed() falls through to null once the board
            // is over and a finished custom game has no final time at all.
            return new self(
                board: $this->board,
                cursorX: $this->cursorX,
                cursorY: $this->cursorY,
                rand: $this->rand,
                startedAt: $this->startedAt,
                elapsedSeconds: $elapsed !== null ? (int) $elapsed : null,
                stats: $this->stats,
                statsPath: $this->statsPath,
            );
        }
        $won = $this->board->isWon();
        return new self(
            board: $this->board,
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $this->startedAt,
            elapsedSeconds: $elapsed !== null ? (int) $elapsed : null,
            stats: $this->stats()->withGame($difficulty, $won, $elapsed !== null ? (int) $elapsed : null),
            statsPath: $this->statsPath,
        );
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
