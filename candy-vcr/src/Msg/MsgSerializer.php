<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Msg;

use SugarCraft\Core\Msg;

/**
 * Encode and decode candy-core {@see Msg}s into the cassette `input`
 * envelope. The envelope is a JSON-able associative array carrying an
 * `@type` tag so the {@see Registry} can route on read.
 *
 * Serializers are tried in registration order. The first one whose
 * {@see canEncode()} returns true wins; same for {@see canDecode()}.
 *
 * Mirrors charmbracelet/x/vcr Msg/Serializer.
 */
interface MsgSerializer
{
    /**
     * True if this serializer can encode the given Msg.
     */
    public function canEncode(Msg $msg): bool;

    /**
     * True if this serializer can decode the given envelope (typically a
     * check on `@type`).
     *
     * @param array<string, mixed> $envelope
     */
    public function canDecode(array $envelope): bool;

    /**
     * Encode the Msg into an envelope. The result MUST include an
     * `@type` key — that is the routing tag for {@see decode()}.
     *
     * @return array<string, mixed>
     */
    public function encode(Msg $msg): array;

    /**
     * Decode a previously-encoded envelope back into a Msg.
     *
     * @param array<string, mixed> $envelope
     */
    public function decode(array $envelope): Msg;
}
