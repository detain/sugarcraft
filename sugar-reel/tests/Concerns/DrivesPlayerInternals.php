<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Concerns;

use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Reel\Player;

/**
 * Reflection seams for the two Player members that carry the library's state
 * contract but are deliberately not public: the private `mutate()` builder and
 * the non-public readonly constructor properties.
 *
 * Tests reach for these when the thing under proof IS the internal seam — the
 * `array_key_exists()` semantics of `mutate()` (finding #8) and which fields a
 * rebuild is allowed to touch (findings #13/#52). Anything observable through the
 * public API should be asserted through the public API instead, so this trait
 * stays small on purpose.
 */
trait DrivesPlayerInternals
{
    /**
     * @param array<string, mixed> $changes
     */
    private function mutatePlayer(Player $player, array $changes): Player
    {
        $method = new ReflectionMethod(Player::class, 'mutate');
        $method->setAccessible(true);

        return $method->invoke($player, $changes);
    }

    private function playerProperty(Player $player, string $name): mixed
    {
        $property = (new ReflectionClass($player))->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($player);
    }
}
