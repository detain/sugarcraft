<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

/**
 * Loads the committed graphics fixtures that drive the decoder round-trips.
 *
 * Every byte here was produced by a candy-mosaic renderer and pinned to disk, so
 * the decoders are tested against real wire output rather than hand-typed
 * approximations.
 */
final class Fixture
{
    private const DIR = __DIR__ . '/../fixtures/graphics';

    public static function path(string $name): string
    {
        return self::DIR . '/' . $name;
    }

    public static function bytes(string $name): string
    {
        $bytes = file_get_contents(self::path($name));
        if ($bytes === false) {
            throw new \RuntimeException('Missing graphics fixture: ' . $name);
        }

        return $bytes;
    }
}
