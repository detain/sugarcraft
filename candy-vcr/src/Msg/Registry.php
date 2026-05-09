<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Msg;

use SugarCraft\Core\Msg;

/**
 * Ordered list of {@see MsgSerializer}s. {@see encode()} and
 * {@see decode()} walk the list and ask each serializer whether it
 * handles the given Msg / envelope.
 *
 * Use {@see default()} for a registry pre-loaded with the
 * {@see BuiltinSerializer} (candy-core Msgs) and the
 * {@see JsonableSerializer} catch-all (user Msgs implementing
 * `\JsonSerializable`). Custom serializers can be inserted between
 * those two via {@see register()}.
 *
 * Mirrors charmbracelet/x/vcr Msg/Registry.
 */
final class Registry
{
    /** @var list<MsgSerializer> */
    private array $serializers = [];

    /**
     * Append a serializer to the list. Returns `$this` for chaining.
     */
    public function register(MsgSerializer $serializer): self
    {
        $this->serializers[] = $serializer;
        return $this;
    }

    /**
     * Encode a Msg into a cassette envelope. Returns null if no
     * registered serializer can handle the Msg.
     *
     * @return array<string, mixed>|null
     */
    public function encode(Msg $msg): ?array
    {
        foreach ($this->serializers as $s) {
            if ($s->canEncode($msg)) {
                return $s->encode($msg);
            }
        }
        return null;
    }

    /**
     * Decode an envelope back into a Msg. Returns null if no
     * registered serializer claims the envelope's `@type`.
     *
     * @param array<string, mixed> $envelope
     */
    public function decode(array $envelope): ?Msg
    {
        foreach ($this->serializers as $s) {
            if ($s->canDecode($envelope)) {
                return $s->decode($envelope);
            }
        }
        return null;
    }

    /**
     * Default registry: `BuiltinSerializer` first (specific candy-core
     * Msgs), then `JsonableSerializer` (catch-all for user Msgs
     * implementing `\JsonSerializable`). User serializers can be slotted
     * in between with `Registry::default()->register(new MyOne())` —
     * but note that catch-all decode order means BuiltinSerializer wins
     * for the candy-core Msgs it knows.
     */
    public static function default(): self
    {
        return (new self())
            ->register(new BuiltinSerializer())
            ->register(new JsonableSerializer());
    }
}
