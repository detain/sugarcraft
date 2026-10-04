<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Messages\UserMessage;

/**
 * The main turn's {@see TurnInbox}: the `steer` frames the parent writes down
 * the turn socket when the user presses Enter while the turn runs (roadmap
 * 1.C-3, frame vocabulary D1), read through the turn child's
 * {@see ChildChannel}.
 *
 * Each steer is delivered as a user row ({@see content()}) and acknowledged
 * up the socket with a `steer_ack{steerId, step}` frame, so the parent knows
 * where it landed. A steer the turn never drains — it arrived after the last
 * step boundary — is not this class's to deliver: Chat sends it as the next
 * prompt instead ({@see \SugarCraft\Crush\Chat}'s queue release).
 *
 * {@see pending()} moves whatever the socket holds into a local buffer
 * without delivering it, so the probe before a sequential tool call does not
 * consume a steer the next {@see drain()} must still hand out.
 */
final class SocketSteerInbox implements TurnInbox
{
    /** The lead of a delivered steer's row, so the model can tell it from the turn's opening prompt. */
    public const PREFIX = '[steering] ';

    /** @var list<array{steerId: string, text: string}> */
    private array $held = [];

    private function __construct(private readonly ChildChannel $channel)
    {
    }

    public static function new(ChildChannel $channel): self
    {
        return new self($channel);
    }

    /**
     * The row a steer of $text is delivered as. Public because the parent's
     * queue release recognises a delivered steer by exactly these bytes.
     */
    public static function content(string $text): string
    {
        return self::PREFIX . $text;
    }

    public function drain(int $step): array
    {
        $steers = [...$this->held, ...$this->channel->takeSteers()];
        $this->held = [];

        $messages = [];
        foreach ($steers as $steer) {
            if (trim($steer['text']) === '') {
                continue;
            }
            $this->channel->send(ChildChannel::STEER_ACK, ['steerId' => $steer['steerId'], 'step' => $step]);
            $messages[] = new UserMessage(self::content($steer['text']));
        }

        return $messages;
    }

    public function pending(): bool
    {
        array_push($this->held, ...$this->channel->takeSteers());

        foreach ($this->held as $steer) {
            if (trim($steer['text']) !== '') {
                return true;
            }
        }

        return false;
    }
}
