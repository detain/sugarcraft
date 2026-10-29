<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

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

    /**
     * A screen-only follow-up (the media form modal, crush-media W3.1): like
     * the title editor, a headless driver cannot honour it and answers
     * client-only. Carries an optional draft seed.
     */
    case OpenMediaForm = 'open-media-form';

    /**
     * A screen-only follow-up (the full-screen video player, crush-media
     * W5.4): like the media form, a headless driver cannot honour it and
     * answers client-only. Carries the resolved absolute path to play.
     */
    case OpenVideoPlayer = 'open-video-player';

    /** Off-turn work whose answer lands later as a row; the session stays free. */
    case Async = 'async';

    /** Work that holds the session like a turn until its report lands (`/workflow run`). */
    case OccupyTurn = 'occupy-turn';

    /** Replace the session's "always" grants (`/permissions revoke`). */
    case SetPermissionGrants = 'set-permission-grants';

    /** Switch the session's permission mode for its next turns (`/permissions mode`). */
    case SetPermissionMode = 'set-permission-mode';

    /**
     * The session's next turns run on another provider/model (`/model` —
     * WEB-2). The driver stores the built backend as a SESSION override; the
     * TUI ignores this kind (its `/model` handler never routes the body), and
     * nothing is written to settings: a served switch lives for the session,
     * the way `SetPermissionMode` does, not for the launch config.
     */
    case SetModel = 'set-model';

    /**
     * Fold the transcript now (typed `/compact` — WEB-2). The driver runs the
     * fold through the same {@see \SugarCraft\Code\Host\CompactionService}
     * its automatic tier uses, splicing a landed model summary when one is
     * held, and honoring the CTX-4 typed-HOLD refusal: a held HOLD verdict
     * refuses the fold outright instead of degrading it.
     */
    case CompactTranscript = 'compact-transcript';

    /**
     * Submit $text as the session's next prompt (the commands that START A
     * TURN — `/init`, `/goal`, `/grind`; WEB-2). The driver applies it after
     * the command's own rows have landed, so the transcript reads echo,
     * markers, then the turn — the TUI's `withInputBuf(...)->submit()` order
     * rendered onto a headless session.
     */
    case SubmitPrompt = 'submit-prompt';

    /**
     * Make one roster preset the session's primary agent — or, with a null
     * name, hand the session back to its launch default
     * ({@see \SugarCraft\Code\Agents\PrimaryAgent::apply()}). `/agent use`
     * carries it (C-AGT-4.b) and the Alt+A cycle rides the same effect, so
     * the TUI and the host apply through one channel. The choice is
     * SESSION-scoped: no driver persists it, and a restart launches on the
     * plain session default again — the same contract the mode switch of
     * `set-permission-mode` keeps.
     */
    case SetPrimaryAgent = 'set-primary-agent';
}
