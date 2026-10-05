<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Board;

/**
 * What a {@see BoardEntry} is for (roadmap 4.5, Kilo's shared agent board).
 *
 * The five kinds are Kilo's, spelled the way its `board_post` takes them, so
 * a model that learned them there reads them the same here. HOLD and VETO are
 * ADVISORY: a post never stops, pauses, wakes or cancels a peer. They are
 * text a peer reads on its next `BoardRead` and weighs like any other
 * untrusted peer message.
 */
enum BoardKind: string
{
    /** Something the peers may want to know: a finding, a file a run is about to touch. */
    case Info = 'INFO';

    /** A question for a peer, answered with a post whose `reply_to` names it. */
    case Ask = 'ASK';

    /** A finished result a peer can build on before the batch reports. */
    case Result = 'RESULT';

    /** "Please wait before you do X": advisory, never a lock. */
    case Hold = 'HOLD';

    /** "Please do not do X": advisory, never a refusal the harness enforces. */
    case Veto = 'VETO';

    /**
     * Every kind's wire spelling, in declaration order, for the tool schema's
     * enum.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
