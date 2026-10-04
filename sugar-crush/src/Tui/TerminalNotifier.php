<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Util\Sanitize;

/**
 * The `notify` setting (roadmap 5.14a): tell the terminal when a turn ends or
 * when the agent stops to wait for an approval, so a user who switched away
 * comes back at the right moment.
 *
 *  - `off` (the default) — nothing.
 *  - `bell` — BEL. Every terminal understands it (as a sound, a flash, an
 *    urgent window hint or a tmux bell flag, by its own settings), which is
 *    why Aider and Goose ring it for the same two events.
 *  - `osc9` — `OSC 9 ; text BEL`, the desktop-notification sequence iTerm2,
 *    WezTerm, Ghostty, kitty and Windows Terminal show as a system
 *    notification with the text; a terminal that does not know it drops it.
 *    tmux does not pass it through without `allow-passthrough`, so under tmux
 *    `bell` is the one that arrives.
 *
 * The bytes leave as a candy-core `Cmd::raw()`, never from `view()`: writing
 * to the terminal is a side effect, and `raw` writes beside the frame without
 * disturbing the renderer's diff state.
 */
final class TerminalNotifier
{
    public const SETTINGS_KEY = 'notify';

    public const OFF = 'off';
    public const BELL = 'bell';
    public const OSC9 = 'osc9';

    /** @var list<string> */
    public const MODES = [self::OFF, self::BELL, self::OSC9];

    /** Longest notification text sent; a desktop notification shows a line or two. */
    public const TEXT_MAX_CHARS = 120;

    private function __construct(
        public readonly string $mode,
    ) {
    }

    /** A notifier in $mode; anything unrecognised is `off`. */
    public static function new(string $mode = self::OFF): self
    {
        $mode = strtolower(trim($mode));

        return new self(\in_array($mode, self::MODES, true) ? $mode : self::OFF);
    }

    /**
     * The notifier the merged layered config asks for ({@see SETTINGS_KEY}).
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $mode = $config[self::SETTINGS_KEY] ?? self::OFF;

        return self::new(\is_string($mode) ? $mode : self::OFF);
    }

    /**
     * The bytes that announce $text, or null when notifications are off.
     *
     * The text is app-written, but it can quote a tool name a model chose, so
     * it is folded to one control-free line and stripped of `;` (which would
     * start a second OSC 9 parameter — `9;4;…` is ConEmu's progress sequence)
     * before it is put inside an escape sequence.
     */
    public function sequence(string $text): ?string
    {
        if ($this->mode === self::BELL) {
            return "\x07";
        }
        if ($this->mode !== self::OSC9) {
            return null;
        }

        $line = trim((string) preg_replace('/\s+/u', ' ', str_replace(';', ',', Sanitize::untrusted($text))));
        if (mb_strlen($line) > self::TEXT_MAX_CHARS) {
            $line = mb_substr($line, 0, self::TEXT_MAX_CHARS - 1) . '…';
        }

        return "\x1b]9;" . ($line === '' ? 'sugarcrush' : $line) . "\x07";
    }

    /** The Cmd that writes {@see sequence()} for $text, or null when notifications are off. */
    public function cmd(string $text): ?\Closure
    {
        $bytes = $this->sequence($text);

        return $bytes === null ? null : Cmd::raw($bytes);
    }
}
