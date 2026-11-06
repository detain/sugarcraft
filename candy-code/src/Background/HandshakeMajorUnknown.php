<?php

declare(strict_types=1);

namespace SugarCraft\Code\Background;

/**
 * Raised when a background daemon greets the supervisor with a handshake
 * major this supervisor has never heard of (A7 capability handshake).
 *
 * Protocol skew is a DIFFERENT fact from malformed noise. The silent
 * shape-refusal pile is right for garbage; burying "the daemon speaks a
 * future protocol" inside that pile is the fallback the audit M5 rationale
 * in BackgroundSessionRunner refuses — it would let a version the gate
 * cannot reason about be laundered as "another bad line", and the fixed
 * string on the wire would go on evicting real refusals from the operator's
 * view forever.
 *
 * The named-error style (no Exception suffix) follows the house pattern of
 * src/Support/RunPromotedToBackground. `$major` carries only the
 * parse-validated shape ('v' plus one to four digits), so a refusal record
 * built from it can never embed free bytes off the wire.
 */
final class HandshakeMajorUnknown extends \RuntimeException
{
    public function __construct(public readonly string $major)
    {
        parent::__construct('unknown background handshake major version: ' . $major);
    }
}
