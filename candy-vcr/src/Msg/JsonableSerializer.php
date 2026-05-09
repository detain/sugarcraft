<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Msg;

use SugarCraft\Core\Msg;

/**
 * Catch-all serializer for user Msg classes that implement
 * `\JsonSerializable`. Encodes the FQCN as `@type` and the
 * `jsonSerialize()` result as `data`.
 *
 * Decoding spreads the `data` array into the constructor as named
 * arguments. The contract for round-trip: the keys returned by
 * `jsonSerialize()` must match the constructor's parameter names. The
 * common case — `__construct(public readonly string $foo, public
 * readonly int $bar)` paired with `jsonSerialize()` returning
 * `['foo' => $this->foo, 'bar' => $this->bar]` — round-trips with no
 * extra plumbing.
 *
 * Classes that need a different shape can register a dedicated
 * {@see MsgSerializer} ahead of this catch-all in the
 * {@see Registry}.
 */
final class JsonableSerializer implements MsgSerializer
{
    public function canEncode(Msg $msg): bool
    {
        return $msg instanceof \JsonSerializable;
    }

    public function canDecode(array $envelope): bool
    {
        $tag = $envelope['@type'] ?? null;
        if (!is_string($tag) || $tag === '') {
            return false;
        }
        if (!class_exists($tag)) {
            return false;
        }
        return is_a($tag, Msg::class, true)
            && is_a($tag, \JsonSerializable::class, true);
    }

    public function encode(Msg $msg): array
    {
        if (!$msg instanceof \JsonSerializable) {
            throw new \LogicException(
                'JsonableSerializer requires the Msg to implement \\JsonSerializable, got ' . $msg::class,
            );
        }
        $data = $msg->jsonSerialize();
        if (!is_array($data)) {
            throw new \RuntimeException(
                'JsonableSerializer requires jsonSerialize() to return an array, got ' . get_debug_type($data) . ' from ' . $msg::class,
            );
        }
        return [
            '@type' => $msg::class,
            'data' => $data,
        ];
    }

    public function decode(array $envelope): Msg
    {
        $tag = $envelope['@type'] ?? '';
        if (!is_string($tag) || !class_exists($tag)) {
            throw new \RuntimeException("JsonableSerializer cannot resolve class for @type '{$tag}'");
        }
        if (!is_a($tag, Msg::class, true)) {
            throw new \RuntimeException("JsonableSerializer: class {$tag} does not implement SugarCraft\\Core\\Msg");
        }
        $data = $envelope['data'] ?? [];
        if (!is_array($data)) {
            throw new \RuntimeException("JsonableSerializer: envelope `data` must be an array, got " . get_debug_type($data));
        }
        // Spread named args — works when jsonSerialize() returns the same
        // keys as the constructor's parameter names.
        /** @var Msg */
        return new $tag(...$data);
    }
}
