<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

/**
 * What {@see BackgroundSupervisor::stopSession()} actually achieved (audit BG-1).
 *
 * An enum rather than a bool because the caller — `/bg stop <id>` — owes the
 * user a different sentence for each case: "no such session" and "it had
 * already finished" are not failures to stop, and "stopped by signal" is worth
 * distinguishing from the clean IPC stop because it means the daemon's own
 * command channel was gone.
 */
enum BackgroundStopOutcome: string
{
    /** No session with that id was ever registered on this supervisor. */
    case UnknownSession = 'unknown-session';

    /** The session had already settled (or its daemon had exited) before the stop landed. */
    case AlreadyFinished = 'already-finished';

    /** The daemon acknowledged `STOP` over its authenticated socket and exited. */
    case StoppedViaIpc = 'stopped-ipc';

    /** The socket path was unusable, so the identity-verified daemon pid was signalled instead. */
    case StoppedViaSignal = 'stopped-signal';

    /**
     * Nothing could be done safely: no addressable daemon, a pid whose identity
     * could not be verified (never signalled), or a process that survived
     * every bounded rung.
     */
    case CouldNotStop = 'could-not-stop';

    /** Whether this call is what ended the session. */
    public function stopped(): bool
    {
        return $this === self::StoppedViaIpc || $this === self::StoppedViaSignal;
    }
}
