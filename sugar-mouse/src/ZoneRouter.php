<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\Zone as ZonesZone;

/**
 * Dispatch a zone hit to a handler keyed by a typed {@see ZoneId}, instead of
 * a chain of `str_starts_with($id, 'prefix:')` tests and `substr` parsing.
 *
 * ```php
 * $router = ZoneRouter::new()
 *     ->on(ZoneId::of('menu', 'quit'), fn (ZoneId $id, MouseMsg $m) => $app->quit())
 *     ->onNamespace('toolcall', fn (ZoneId $id, MouseMsg $m) => $app->toggleToolCall($id->intKey()));
 *
 * $result = $router->routeMouse($msg, $scanner); // null when nothing routed
 * ```
 *
 * Resolution: an exact {@see on()} route for the hit id beats an
 * {@see onNamespace()} route for its namespace; a namespace matches by
 * EQUALITY, never by string prefix (`tab` does not catch `tabs:1`). A zone
 * whose id is not a typed id ({@see ZoneId::parse()} returns null) or has no
 * route is simply unrouted. The string-id API ({@see Mark::wrap()},
 * {@see Scanner::get()}) is untouched: a typed id is ordinary id material on
 * the wire.
 *
 * Immutable: every registration returns a new router, so a router can be
 * built once and shared.
 *
 * Original work (bubblezone routes nothing; callers compare ids by hand).
 */
final class ZoneRouter
{
    /**
     * @param array<string, \Closure> $exact      wire id => handler
     * @param array<string, \Closure> $namespaces namespace => handler
     */
    private function __construct(
        private readonly array $exact = [],
        private readonly array $namespaces = [],
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * Route the exact id $id to $handler, replacing any earlier route for it.
     *
     * The handler receives the parsed {@see ZoneId} first, then whatever the
     * caller routes with ({@see route()}'s extra arguments, or the MouseMsg /
     * MouseEvent and the hit zone for {@see routeMouse()}).
     */
    public function on(ZoneId $id, \Closure $handler): self
    {
        return $this->mutate(exact: [$id->wire() => $handler] + $this->exact);
    }

    /**
     * Route every id in $namespace that has no exact route to $handler,
     * replacing any earlier route for the namespace.
     *
     * @throws \InvalidArgumentException when $namespace could not be a
     *         {@see ZoneId} namespace
     */
    public function onNamespace(string $namespace, \Closure $handler): self
    {
        $namespace = ZoneId::of($namespace)->namespace;
        return $this->mutate(namespaces: [$namespace => $handler] + $this->namespaces);
    }

    /** True when {@see route()} would call a handler for $id. */
    public function handles(string|ZoneId $id): bool
    {
        return $this->resolve($id) !== null;
    }

    /**
     * Call the handler routed for $id with `($zoneId, ...$args)` and return
     * its result, or null when no route matches. A handler may itself return
     * null; ask {@see handles()} first when the two must be told apart.
     */
    public function route(string|ZoneId $id, mixed ...$args): mixed
    {
        $resolved = $this->resolve($id);
        if ($resolved === null) {
            return null;
        }
        [$zoneId, $handler] = $resolved;
        return $handler($zoneId, ...$args);
    }

    /**
     * Hit-test $event against $zones (the shared smallest-area rule) and
     * route the owning zone: the handler receives `($zoneId, $event, $zone)`.
     * Null when no zone covers the cell or the hit zone has no route.
     *
     * A prefixed {@see Manager}'s own prefix is stripped from the wire id
     * before it is parsed, so routes are written against the ids passed to
     * `mark()`, not their namespaced spelling.
     */
    public function routeMouse(MouseMsg|MouseEvent $event, Scanner|Manager $zones): mixed
    {
        $zone = $zones instanceof Scanner
            ? $zones->hit($event->x, $event->y)
            : $zones->zoneAt($event->x, $event->y);
        if ($zone === null) {
            return null;
        }
        return $this->route(self::localId($zone, $zones), $event, $zone);
    }

    /**
     * @return array{0:ZoneId,1:\Closure}|null
     */
    private function resolve(string|ZoneId $id): ?array
    {
        $zoneId = is_string($id) ? ZoneId::parse($id) : $id;
        if ($zoneId === null) {
            return null;
        }
        $handler = $this->exact[$zoneId->wire()] ?? $this->namespaces[$zoneId->namespace] ?? null;
        return $handler === null ? null : [$zoneId, $handler];
    }

    private static function localId(Zone|ZonesZone $zone, Scanner|Manager $zones): string
    {
        $prefix = $zones instanceof Manager ? $zones->prefix() : '';
        if ($prefix !== '' && str_starts_with($zone->id, $prefix)) {
            return substr($zone->id, strlen($prefix));
        }
        return $zone->id;
    }

    /**
     * @param array<string, \Closure>|null $exact
     * @param array<string, \Closure>|null $namespaces
     */
    private function mutate(?array $exact = null, ?array $namespaces = null): self
    {
        return new self($exact ?? $this->exact, $namespaces ?? $this->namespaces);
    }
}
