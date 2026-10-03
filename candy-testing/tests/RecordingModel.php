<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use SugarCraft\Core\Kind;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteEndMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\PasteStartMsg;
use SugarCraft\Core\Subscription;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;

/**
 * Model that appends a token for every message it sees, so a test can
 * assert the exact order the simulator delivered them in: a key's rune
 * (or KeyType name), `[` / `]` for PasteStartMsg / PasteEndMsg, and
 * `(<content>)` for a PasteMsg. The view echoes the trace verbatim.
 *
 * With $subscribed, one subscription produces KeyMsg('*') every time it
 * fires.
 */
final class RecordingModel implements Model
{
    public function __construct(
        public readonly string $trace = '',
        private readonly bool $subscribed = false,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        $token = match (true) {
            $msg instanceof KeyMsg        => $msg->rune !== '' ? $msg->rune : '<' . $msg->type->name . '>',
            $msg instanceof PasteStartMsg => '[',
            $msg instanceof PasteEndMsg   => ']',
            $msg instanceof PasteMsg      => '(' . $msg->content . ')',
            default                       => '?',
        };

        return [new self($this->trace . $token, $this->subscribed), null];
    }

    public function view(): string|View
    {
        return $this->trace;
    }

    public function subscriptions(): ?Subscriptions
    {
        if (!$this->subscribed) {
            return null;
        }

        return new Subscriptions([
            new Subscription(
                id: 'star',
                kind: Kind::Custom,
                params: [],
                produce: static fn (): Msg => new KeyMsg(\SugarCraft\Core\KeyType::Char, rune: '*'),
            ),
        ]);
    }
}
