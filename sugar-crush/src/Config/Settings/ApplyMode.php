<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * When a saved value starts to matter. "Saved" never implies "applied", so
 * every key states which of these it is and the editor badges it.
 *
 *  - Live      the running session picks it up (a `/theme`, a dock move).
 *  - NextTurn  `EngineBackend` re-reads the merged config at every turn start,
 *              in the forked child, so the next turn sees it with no plumbing.
 *  - Restart   read once at launch or at backend build.
 *  - Frozen    deliberately pinned for the life of the process — the trust
 *              lists — so a session cannot be re-trusted from inside itself.
 */
enum ApplyMode: string
{
    case Live = 'live';
    case NextTurn = 'next-turn';
    case Restart = 'restart';
    case Frozen = 'frozen';

    /** The badge text, literal English (D7). */
    public function badge(): string
    {
        return match ($this) {
            self::Live => 'live',
            self::NextTurn => 'next turn',
            self::Restart => 'restart',
            self::Frozen => 'next launch',
        };
    }
}
