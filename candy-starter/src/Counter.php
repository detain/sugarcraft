<?php

declare(strict_types=1);

namespace App;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

/**
 * Demo Model — a counter you can drive with ↑ / ↓ and quit with `q`,
 * `Esc`, or Ctrl+C (all three are pinned in the matrix — the frame's
 * hint line enumerates exactly what `shouldQuit()` accepts).
 *
 * Picked deliberately as the "hello, world" of TUI development: small
 * enough to read in one screen, but exercises every piece of the
 * runtime: Model immutability, Msg dispatch, Cmd::quit() shutdown,
 * keyboard handling, and SugarStyle styling in view().
 *
 * The Model is immutable — `update()` returns a *new* Counter
 * rather than mutating $n. This is the Elm-architecture pattern
 * SugarCraft is built on: state lives in value objects, transitions
 * are pure functions, side effects (timers, HTTP, file I/O) get
 * scheduled as Cmds.
 *
 * Not every Msg is a key: the harness also dispatches `WindowSizeMsg`
 * (at startup and on every resize) and `ExceptionMsg` (the async
 * failure channel for anything you schedule via `Cmd::promise()`).
 * The demo ignores both — honestly, since it renders one fixed box and
 * arms no promises — but a real app capturing layout or surfacing
 * errors handles them BEFORE the key filter; see the arms sketched in
 * `update()` and the README's "Beyond keys" note.
 *
 * To extend:
 *   1. Add a field to the constructor (e.g. `int $step = 1`).
 *   2. Add a Msg handler in update() that returns a new Model with
 *      the field updated. (Key events only? Define your own Msg
 *      class and route it — the contract accepts any Msg.)
 *   3. Reflect the new state in view().
 *   4. Extend the quit/key matrix in tests/CounterTest.php to match —
 *      the pinned matrix is this template's test reference.
 */
final class Counter implements Model
{
    /** One shared immutable frame decoration — built on first render, never per frame. */
    private static ?Style $frameStyle = null;

    public function __construct(public readonly int $n = 0) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        // Beyond keys — sketch the two non-key arms a real app usually
        // needs, BEFORE the key filter:
        // if ($msg instanceof \SugarCraft\Core\Msg\WindowSizeMsg) {
        //     return [new self($this->n), null]; // or store $msg->cols/$msg->rows for layout
        // }
        // if ($msg instanceof \SugarCraft\Core\Msg\ExceptionMsg) {
        //     // Cmd::promise() failures land HERE and nowhere else — a real
        //     // app logs or renders the error instead of swallowing it.
        // }
        if ($msg instanceof KeyMsg === false) {
            // The demo has no layout to recompute and no promises to fail on,
            // so every non-key Msg is genuinely a no-op — see the class
            // docblock before copying this arm into an app that grows either.
            return [$this, null];
        }
        // Mirrors charmbracelet/bubbletea-app-template/Program.update
        // quit with q (any modifier except ctrl), Esc, or ctrl+c
        if ($this->shouldQuit($msg)) {
            return [$this, Cmd::quit()];
        }
        return [match ($msg->type) {
            KeyType::Up   => new self($this->n + 1),
            KeyType::Down => new self($this->n - 1),
            default       => $this,
        }, null];
    }

    // Returns a plain string here; the Model contract also allows a
    // \SugarCraft\Core\View for per-frame extras (cursor, window
    // title, colours — alt-screen itself is ProgramOptions):
    // return new \SugarCraft\Core\View($frame, windowTitle: 'count ' . $this->n);
    //
    // Trust boundary: Program writes the frame to the terminal
    // UNMEDIATED, so anything interpolated here must be trusted. Text
    // from files, the network, or user input goes through sugar-core's
    // Sanitize::untrusted() first (untrustedForMarkedFrames() when the
    // frame carries image/mouse-zone U+E000 markers) — raw SGR, OSC-8
    // and private-use bytes are a terminal-injection surface, not
    // payload.
    public function view(): string
    {
        $body = sprintf("count: %d\n↑ ↓ to change · q / Esc / Ctrl+C quit", $this->n);

        return self::frameStyle()->render($body);
    }

    /**
     * The frame decoration is built ONCE and reused (CALIBER
     * [bug:candy-starter-style-rebuild], shipped 2026-11-03): Style is an
     * immutable value object, so per-frame `Style::new()->…` chains in
     * view() only manufactured throwaways at render rate. Memoized static
     * style is the pattern for any constant decoration.
     */
    private static function frameStyle(): Style
    {
        return self::$frameStyle ??= Style::new()
            ->border(Border::rounded())
            ->padding(1, 2);
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /** Determines if the given key message should trigger program quit. */
    private function shouldQuit(KeyMsg $msg): bool
    {
        // quit: plain `q` under any modifier EXCEPT ctrl (alt+q quits — the
        // rune-exact gap is intentional teaching surface, pinned in
        // CounterTest), Esc under any modifier, or ctrl+c.
        if ($msg->type === KeyType::Char && $msg->rune === 'q' && $msg->ctrl === false) {
            return true;
        }
        if ($msg->type === KeyType::Escape) {
            return true;
        }
        if ($msg->ctrl && $msg->rune === 'c') {
            return true;
        }
        return false;
    }
}
