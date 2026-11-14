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
 * {@see BuiltinSerializer} (sugar-core Msgs) and the
 * {@see JsonableSerializer} catch-all (user Msgs implementing
 * `\JsonSerializable`). The catch-all's DECODE side is allow-listed —
 * name a user class through {@see allow()} or its envelopes are refused
 * (finding 034). Order is priority: the first serializer that
 * claims a Msg / envelope wins. {@see register()} therefore lands a
 * custom serializer *behind* the catch-all — use {@see registerFirst()}
 * when a custom serializer has to outrank what `default()` already
 * installed.
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
     * Prepend a serializer to the list, so it is consulted before every
     * already-registered one. Returns `$this` for chaining.
     *
     * This is the hook for overriding what {@see default()} installs: an
     * appended serializer sits behind {@see JsonableSerializer}, whose
     * `canEncode()` claims every `\JsonSerializable` Msg, so a custom
     * codec for such a Msg has to be registered first to be seen at all.
     */
    public function registerFirst(MsgSerializer $serializer): self
    {
        array_unshift($this->serializers, $serializer);
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
     * Default registry: `BuiltinSerializer` first (specific sugar-core
     * Msgs), then `JsonableSerializer` (catch-all for user Msgs
     * implementing `\JsonSerializable` — DECODE side allow-listed, see
     * {@see allow()}). Order is priority, so anything
     * {@see register()} appends lands after both — reach for
     * {@see registerFirst()} to give a custom codec precedence over the
     * catch-all.
     */
    public static function default(): self
    {
        return (new self())
            ->register(new BuiltinSerializer())
            ->register(new JsonableSerializer());
    }

    /**
     * The host's opt-in door for user Msg classes: forwards to the
     * registered {@see JsonableSerializer}'s allow list (finding 034 —
     * the catch-all decodes only what was named here). When no catch-all
     * is registered, one configured for these classes is appended.
     *
     * @param class-string ...$classes
     */
    public function allow(string ...$classes): self
    {
        foreach ($this->serializers as $serializer) {
            if ($serializer instanceof JsonableSerializer) {
                $serializer->allow(...$classes);
                return $this;
            }
        }

        return $this->register((new JsonableSerializer())->allow(...$classes));
    }
}
