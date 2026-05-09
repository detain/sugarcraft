<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

use SugarCraft\Vcr\Cassette;

/**
 * Cassette serializer. Format-specific implementations decide how the
 * value-object tree is laid out on disk; the round-trip contract is that
 * `read(write(c))` returns a value-object-equal cassette modulo `t` rounding
 * documented per format.
 */
interface Format
{
    public function write(Cassette $cassette, string $path): void;

    public function read(string $path): Cassette;

    /**
     * Encode a cassette to a string without writing to disk. Round-trips with
     * `decode()`. Useful for tests, CLI piping, and in-memory inspection.
     */
    public function encode(Cassette $cassette): string;

    public function decode(string $contents): Cassette;
}
