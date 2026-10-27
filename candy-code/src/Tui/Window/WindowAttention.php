<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

use SugarCraft\Code\Theme;
use SugarCraft\Core\Util\Color;

/**
 * How much a window wants the user — the state a strip tab is painted in.
 *
 * Every case owns a DISTINCT non-colour glyph (`●  ⠋  ⏳  ✱  ✗`), so the
 * distinction survives NO_COLOR and a monochrome terminal: colour is a
 * second cue on top of the glyph, never the only one. The theme token comes
 * from the shell's own `shell*` palette ({@see Theme}) the way
 * {@see \SugarCraft\Code\Skills\SkillSource::color()} does it, and the rank
 * orders the states for the window title's aggregate count.
 */
enum WindowAttention: string
{
    /** Nothing pending; the window is just there. */
    case Idle = 'idle';

    /** A turn or delegated run is live right now. */
    case Working = 'working';

    /** Something is blocked on the user answering. */
    case Waiting = 'waiting';

    /** It finished or moved on while the user was elsewhere. */
    case Unseen = 'unseen';

    /** It ended badly. */
    case Failed = 'failed';

    /** The filled-circle resting state. */
    public const GLYPH_IDLE = "\u{25CF}";

    /** The braille spinner dot. */
    public const GLYPH_WORKING = "\u{280B}";

    /** The hourglass. */
    public const GLYPH_WAITING = "\u{23F3}";

    /** The heavy teardrop-spoked asterisk. */
    public const GLYPH_UNSEEN = "\u{2731}";

    /** The ballot-check cross. */
    public const GLYPH_FAILED = "\u{2717}";

    /** The glyph that stands for this state — legible without any colour. */
    public function glyph(): string
    {
        return match ($this) {
            self::Idle => self::GLYPH_IDLE,
            self::Working => self::GLYPH_WORKING,
            self::Waiting => self::GLYPH_WAITING,
            self::Unseen => self::GLYPH_UNSEEN,
            self::Failed => self::GLYPH_FAILED,
        };
    }

    /** The shell-theme token this state is painted in. */
    public function color(Theme $theme): Color
    {
        return match ($this) {
            self::Idle => $theme->shellMuted,
            self::Working => $theme->shellPrimary,
            self::Waiting => $theme->shellWarning,
            self::Unseen => $theme->shellInfo,
            self::Failed => $theme->shellError,
        };
    }

    /**
     * Position in this enum's own declaration order: quiet states first,
     * louder ones after, so the title count can rank windows by how much
     * they want attention without ever naming a case literal. Derived from
     * cases() so inserting a state renumbers rather than desyncs.
     */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    /** Whether a window in this state counts toward "needs attention". */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Waiting, self::Unseen, self::Failed => true,
            self::Idle, self::Working => false,
        };
    }
}
