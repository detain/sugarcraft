<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\VideoPlayer;

use SugarCraft\Core\Msg as CoreMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Theme;
use SugarCraft\Video\Msg\ResizeRebuildMsg;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;

/**
 * The full-screen video player surface (crush-media W5.4).
 *
 * RULING (plan §W5.4, decided): the player is a PUSHED FULL-SCREEN screen
 * — Option A, the phlix `graphicsChrome` shape — not an ImageLayer/Veil
 * overlay: sugar-video's `Player::view()` emits one whole-terminal blob
 * (sixel/kitty/iterm2 payloads or a full cell grid), which the line-diff
 * compositor can only paint honestly as its own band, exactly like the
 * settings editor and the media form before it.
 *
 * sugar-video owns decode and frame painting; this class owns composition
 * around it: the "Preparing…" placeholder while the probe+spawn promise
 * runs, the scrubber chrome row, the close interception that keeps the
 * Player's own quit-trio from tearing down the whole app, and the
 * process-lifetime safety net. The Player instance itself stays the
 * single inner TEA model — keys ride into
 * {@see Player::update()}, and whatever Cmd the Player arms (its tick)
 * rides straight back out to the Program.
 *
 * Teardown law (W5.5 watch-list): every Player this screen adopts is
 * registered via {@see adopt()}, and a shutdown hook stops it — so ffmpeg
 * children die on EVERY exit path that runs PHP shutdown, including the
 * global Ctrl+C chord this screen deliberately yields to (the frame loop
 * stops without a model turn, so no update() would ever see the close).
 * No wall-clock deadline exists anywhere here (E646): playback advances
 * only on ticks the Player itself arms while unpaused.
 *
 * Audio (W5.8 / Appendix C-4 ruling: silent v1): this screen wires no
 * audio surface of its own. Honest probe fact — sugar-video's Player is
 * NOT audio-opt-in on the open() path: a source WITH an audio track gets
 * an AudioPlayer companion auto-created (Player.php:197) and started on
 * first play (Player.php:721). Generated videos carry no audio track, so
 * crush's own artifacts are inherently silent; a user-typed /play on an
 * audio-bearing file plays sound when ffplay/mpv exist — accepted library
 * behavior for v1. Either way the companion dies with the Player: stop()
 * bounds-kills it (BoundedShutdown ladder) and the teardown law above
 * already routes every exit path through stop(). Whether silence should be
 * ENFORCED (factory builds via Player::fromDecoder with audioPlayer: null)
 * was the Wave-7 operator question recorded at C-4 and CLOSED by the C-10
 * ruling as accepted-as-is: crush's generated artifacts stay inherently
 * silent, the library's inherent-audio play of user files stands.
 */
final class VideoPlayerScreen
{
    /** Probe/spawn has not answered yet. */
    public const STATE_PREPARING = 'preparing';

    /** A Player holds the band. */
    public const STATE_READY = 'ready';

    /** The build answered an error; the screen shows it until closed. */
    public const STATE_FAILED = 'failed';

    /** The blob protocols whose bytes a width clip would corrupt. */
    private const GRAPHICS_MODES = [Mode::Sixel, Mode::Kitty, Mode::Iterm2];

    /** The one live player whose children a shutdown must reap. */
    private static ?Player $activePlayer = null;

    private static bool $shutdownRegistered = false;

    private function __construct(
        public readonly string $state,
        public readonly string $path,
        private readonly ?Player $player,
        public readonly Mode $mode,
        public readonly ?string $error,
    ) {
    }

    /** This wrapper's own stop latch — the Player's stop closes its decoder every call. */
    private bool $stopped = false;

    /** The band placeholder while the build promise runs. */
    public static function preparing(string $path, Mode $mode): self
    {
        return new self(self::STATE_PREPARING, $path, null, $mode, null);
    }

    /** The screen after a Player answered the build. */
    public static function ready(string $path, Player $player, Mode $mode): self
    {
        return new self(self::STATE_READY, $path, $player, $mode, null);
    }

    /** The screen after the build answered an error sentence. */
    public static function failed(string $path, string $error, Mode $mode): self
    {
        return new self(self::STATE_FAILED, $path, null, $mode, $error);
    }

    public function player(): ?Player
    {
        return $this->player;
    }

    /**
     * Drive the inner Player. Video ticks and rebuilds, keystrokes the gate
     * forwarded, and resizes are the Player's own contract; everything else
     * — including a late PlayerReadyMsg on an already-closed screen — is a
     * no-op. Returns the Player's Cmd verbatim so its tick cadence rides
     * the Program, never a cadence invented here.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function update(CoreMsg $msg): array
    {
        if ($msg instanceof PlayerReadyMsg) {
            if ($this->state !== self::STATE_PREPARING) {
                // Late answer for a screen that moved on (closed, or an
                // error already shown): the child, if any, was never
                // adopted, so stop it HERE rather than leak the builder.
                $msg->player?->stop();

                return [$this, null];
            }

            return [$msg->player !== null
                ? self::ready($this->path, $msg->player, $this->mode)
                : self::failed($this->path, (string) $msg->error, $this->mode), null];
        }

        if ($this->player === null) {
            return [$this, null];
        }

        $forwards = $msg instanceof TickMsg
            || $msg instanceof ResizeRebuildMsg
            || $msg instanceof WindowSizeMsg
            || $msg instanceof KeyMsg;

        if (!$forwards) {
            return [$this, null];
        }

        [$next, $cmd] = $this->player->update($msg);

        if (!$next instanceof Player || $next === $this->player) {
            return [$this, $cmd];
        }

        // The Player re-minted itself (seek, resize rebuild, mode cycle all
        // mutate to a new wrapper — R13-C1): the shutdown memo must follow
        // this wrapper, or stopActive() would stop the superseded object
        // while the live decoder child rides the new one.
        self::reattach($next);

        return [new self($this->state, $this->path, $next, $this->mode, $this->error), $cmd];
    }

    /**
     * Stop playback and release the shutdown registration. Idempotent —
     * the Player's own stop latch survives every rebuild, so a second
     * call (close, then teardown) is free.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->player?->stop();

        if (self::$activePlayer !== null && $this->player !== null && self::$activePlayer === $this->player) {
            self::$activePlayer = null;
        }
    }

    /**
     * Claim a freshly built Player for process-lifetime safety BEFORE the
     * "Preparing…" answer can be lost (the app may quit between the build
     * and the message landing). One hook for the whole process; the memo
     * always holds the newest player.
     */
    public static function adopt(Player $player): void
    {
        // At most one adopted player is live at any instant: a superseded
        // build whose answer never landed (slot closed under it) dies HERE
        // rather than waiting for a hook that only knows the newest memo.
        // Player::stop is latched, so double-stopping is free.
        self::$activePlayer?->stop();
        self::$activePlayer = $player;
        self::registerShutdownTeardown();
    }

    /**
     * Move the shutdown memo onto a Player that re-minted itself under an
     * already-adopted wrapper (R13-C1). Unlike {@see adopt()} this stops
     * NOTHING: the video Player is immutable, so every handled key returns a
     * fresh wrapper around the SAME live decoder — halting the superseded
     * one here would kill the stream the new wrapper is playing. The old
     * wrapper's own stop() stays harmless: its memo guard simply no longer
     * matches, and Player::stop is latched.
     */
    public static function reattach(Player $player): void
    {
        self::$activePlayer = $player;
        self::registerShutdownTeardown();
    }

    /**
     * Arm the shutdown stop once (house precedent: Bootstrap's MCP/LSP
     * shutdown registration). PHP runs shutdown functions on every exit
     * that is not SIGKILL — normal quit, the Ctrl+C quit chord, and a
     * fatal — which is the coverage the frame-loop-free exits need.
     */
    public static function registerShutdownTeardown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        // A plain closure, not an arrow fn: an arrow body is a RETURN
        // expression, and returning a void call is a compile error.
        \register_shutdown_function(static function (): void {
            self::stopActive();
        });
    }

    /** Stop the adopted player if one is still live (teardown entry). */
    public static function stopActive(): void
    {
        $player = self::$activePlayer;
        self::$activePlayer = null;
        $player?->stop();
    }

    /** Test seam: the current shutdown state of the memo. */
    public static function hasActivePlayer(): bool
    {
        return self::$activePlayer !== null;
    }

    /** Test seam: the memoized live player itself — identity checks need the instance, not just presence. */
    public static function activePlayer(): ?Player
    {
        return self::$activePlayer;
    }

    /**
     * Whether the frame renderer may width-clip this screen's output.
     * Graphics blobs are protocol bytes — a truncate would corrupt them —
     * so they answer false and the renderer trusts the geometry this
     * screen asked the Player for at open.
     */
    public function clipsLines(): bool
    {
        return !\in_array($this->mode, self::GRAPHICS_MODES, true);
    }

    /**
     * Compose the band: placeholder, error card, or the Player's blob
     * with the scrubber chrome — appended as a plain row on text modes,
     * pinned to the band's bottom row with absolute cursor operations on
     * graphics modes (the phlix law: the blob is ONE logical multi-row
     * write, so a `"\n"` chrome would land inside row 3 of the picture,
     * not under it).
     */
    public function view(Theme $theme, int $cols, int $rows): string
    {
        $name = basename($this->path);

        if ($this->state === self::STATE_PREPARING) {
            return '  preparing ' . $name . ' …'
                . "\r\n"
                . '  space play/pause · q close';
        }

        if ($this->state === self::STATE_FAILED) {
            return '  cannot play ' . $name
                . "\r\n"
                . '  ' . (string) $this->error
                . "\r\n"
                . '  q closes this screen';
        }

        $player = $this->player;
        \assert($player !== null);

        $chrome = $this->chrome($cols);
        $blob = $player->view();

        if ($this->clipsLines()) {
            return $blob . "\r\n" . $chrome;
        }

        return $blob . Ansi::cursorTo(max(1, $rows), 1) . Ansi::eraseLine() . $chrome;
    }

    /** Scrubber row, plus the control hint when the band is wide enough. */
    private function chrome(int $cols): string
    {
        $player = $this->player;
        \assert($player !== null);

        $hint = VideoPlayerKeys::hint();
        // The budget is in DISPLAY cells — the hint carries box glyphs and
        // middots, whose byte length would overstate the reservation.
        $hintCols = Width::of($hint);
        $track = $cols;

        if ($cols >= $hintCols + 2 + Scrubber::MIN_WIDTH) {
            $track = $cols - $hintCols - 2;
        } else {
            $hint = '';
        }

        $row = Scrubber::render($player->position(), $player->duration(), $track);

        return $hint === '' ? $row : $row . '  ' . $hint;
    }
}
