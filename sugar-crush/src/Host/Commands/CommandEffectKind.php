<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

/**
 * What a {@see CommandEffect} asks its driver to do to the session beyond
 * appending the command's rows (roadmap O-2h, Appendix O §4.2).
 *
 * Named for the SESSION change, never for a TUI field: `Chat` and
 * `SessionHost` each translate a kind into their own state, so the TUI can
 * also reset its scroll offset on {@see self::ClearTranscript} while a server
 * only drops the rows.
 */
enum CommandEffectKind: string
{
    /** The transcript is emptied (`/clear`); the compaction breaker resets with it. */
    case ClearTranscript = 'clear-transcript';

    /** The transcript becomes a checkpoint's rows, and its draft is offered back. */
    case RestoreCheckpoint = 'restore-checkpoint';

    /** The driver now writes to another session id (`/branch`). */
    case SwitchSession = 'switch-session';

    /** The session's title (and who chose it) changed; null clears it. */
    case RenameSession = 'rename-session';

    /** A screen-only follow-up (the inline title editor): a headless driver cannot honour it. */
    case OpenTitleEditor = 'open-title-editor';

    /** Off-turn work whose answer lands later as a row; the session stays free. */
    case Async = 'async';

    /** Work that holds the session like a turn until its report lands (`/workflow run`). */
    case OccupyTurn = 'occupy-turn';
}
