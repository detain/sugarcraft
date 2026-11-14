<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Msg;

use SugarCraft\Core\Msg;

/**
 * Catch-all serializer for user Msg classes that implement
 * `\JsonSerializable`. Encodes the FQCN as `@type` and the
 * `jsonSerialize()` result as `data`.
 *
 * DECODING IS ALLOW-LISTED (finding 034): the `@type` tag names a class
 * in the host process, so a cassette that can drive this decoder is a
 * class-instantiation sink — `new $tag(...$data)` with attacker-chosen
 * named arguments, for ANY class shipping in vendor/ that happens to
 * implement Msg + \JsonSerializable. The catch-all therefore decodes
 * only classes the host explicitly {@see allow()}s, mirroring how
 * {@see BuiltinSerializer} confines itself to its tag→factory map.
 * Encoding stays unconditional: writing a cassette is not an attack
 * surface.
 *
 * Before instantiating, the `data` keys are validated against the
 * constructor's REAL parameter names (no unknown or positional keys,
 * no missing required arguments), so a forged envelope fails with a
 * named error instead of a TypeError leaking through the pump.
 *
 * The contract for round-trip: the keys returned by `jsonSerialize()`
 * must match the constructor's parameter names. The common case —
 * `__construct(public readonly string $foo, public readonly int $bar)`
 * paired with `jsonSerialize()` returning
 * `['foo' => $this->foo, 'bar' => $this->bar]` — round-trips with no
 * extra plumbing once the class is allowed.
 *
 * Classes that need a different shape can register a dedicated
 * {@see MsgSerializer} ahead of this catch-all in the
 * {@see Registry}.
 */
final class JsonableSerializer implements MsgSerializer
{
    /**
     * tag FQCN => allow-listed class. Fail-closed empty default: an
     * un-allowed class is neither canDecode() nor decodable.
     *
     * @var array<string, class-string>
     */
    private array $allowed = [];

    /**
     * Permit one or more classes for decoding. Validated at the door:
     * the class must exist, implement Msg AND \JsonSerializable — a
     * typo or a wrong-shape class is refused with a named error rather
     * than discovered mid-replay (or never, which is worse).
     *
     * @param class-string ...$classes
     */
    public function allow(string ...$classes): self
    {
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new \InvalidArgumentException("JsonableSerializer cannot allow unknown class {$class}");
            }
            if (!is_a($class, Msg::class, true)) {
                throw new \InvalidArgumentException("JsonableSerializer: class {$class} does not implement SugarCraft\\Core\\Msg");
            }
            if (!is_a($class, \JsonSerializable::class, true)) {
                throw new \InvalidArgumentException("JsonableSerializer: class {$class} does not implement \\JsonSerializable");
            }
            $this->allowed[$class] = $class;
        }

        return $this;
    }

    /**
     * The allow-listed FQCNs, sorted — inspection seam for hosts and tests.
     *
     * @return list<class-string>
     */
    public function allowedClasses(): array
    {
        $list = array_keys($this->allowed);
        sort($list);

        return $list;
    }

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

        return isset($this->allowed[$tag]) && class_exists($tag);
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
        if (!is_string($tag) || $tag === '' || !isset($this->allowed[$tag])) {
            // The class may exist and be perfectly instantiable — the
            // point is that the host never said yes to it. Name the door.
            $label = is_string($tag) && $tag !== '' ? "'{$tag}'" : get_debug_type($tag);
            throw new \RuntimeException("JsonableSerializer: class {$label} is not on the allow list - call allow() before decoding cassettes carrying it");
        }
        if (!class_exists($tag)) {
            $this->assertNotUnloaded($tag);
        }

        $data = $envelope['data'] ?? [];
        if (!is_array($data)) {
            throw new \RuntimeException("JsonableSerializer: envelope `data` must be an array, got " . get_debug_type($data));
        }

        $this->assertConstructorArgs($tag, $data);

        /** @var Msg */
        return new $tag(...$data);
    }

    /**
     * @param class-string $tag
     */
    private function assertNotUnloaded(string $tag): void
    {
        throw new \RuntimeException("JsonableSerializer cannot resolve class for @type '{$tag}'");
    }

    /**
     * Mirror the constructor's real signature onto the envelope's data
     * BEFORE spreading: unknown/positional keys and missing required
     * parameters are named refusals, not TypeError soup (finding 034).
     *
     * @param class-string  $tag
     * @param array<array-key, mixed> $data
     */
    private function assertConstructorArgs(string $tag, array $data): void
    {
        $reflection = new \ReflectionClass($tag);
        $constructor = $reflection->getConstructor();
        $params = $constructor !== null ? $constructor->getParameters() : [];

        $byName = [];
        foreach ($params as $param) {
            $byName[$param->getName()] = $param;
        }

        foreach ($data as $key => $unused) {
            if (!is_string($key)) {
                throw new \RuntimeException("JsonableSerializer: {$tag} envelope has positional data key '" . (string) $key . "' - round-trip requires named keys");
            }
            if (!isset($byName[$key])) {
                throw new \RuntimeException("JsonableSerializer: {$tag} constructor has no parameter \${$key} - refusing to spread an unknown envelope key");
            }
        }

        foreach ($byName as $name => $param) {
            if (!$param->isOptional() && !array_key_exists($name, $data)) {
                throw new \RuntimeException("JsonableSerializer: {$tag} envelope is missing required constructor argument \${$name}");
            }
        }
    }
}
