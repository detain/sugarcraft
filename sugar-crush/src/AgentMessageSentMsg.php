<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * What became of a message the user sent a delegated run from the Agent View
 * composer (roadmap P-D2, Appendix P §5.3). {@see Chat} answers the composer's
 * {@see AgentControlMsg} with one of these per run, and the shell
 * ({@see \SugarCraft\Crush\App\App}) keeps the row the view draws for it —
 * `you → explore · also check the cookie   ⧗ queued` — until the run's own
 * log shows the message delivered.
 *
 * A run that had already finished is not messaged but CONTINUED: the message
 * opens a follow-up run of the same conversation
 * ({@see \SugarCraft\Crush\Host\AgentResume}), reported {@see RESUMING} when
 * it starts and {@see REPLIED} (or {@see FAILED}) when it ends.
 */
final readonly class AgentMessageSentMsg implements Msg
{
    /** In the run's mailbox; it reads it at its next step. */
    public const QUEUED = 'queued';
    /** The run had finished; a follow-up run of its conversation is under way. */
    public const RESUMING = 'resuming';
    /** The follow-up run ended with a reply. */
    public const REPLIED = 'replied';
    /** Not sent, or the follow-up run failed; {@see $detail} says why. */
    public const FAILED = 'failed';

    public function __construct(
        public string $agentId,
        public string $name,
        public string $text,
        public string $status,
        public ?string $msgId = null,
        public ?string $detail = null,
    ) {
    }

    /** The badge the view's row ends with. */
    public function badge(): string
    {
        return match ($this->status) {
            self::QUEUED => '⧗ queued',
            self::RESUMING => '↻ follow-up running',
            self::REPLIED => '↩ replied',
            default => '✗ ' . ($this->detail ?? 'not sent'),
        };
    }
}
