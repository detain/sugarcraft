<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

/**
 * Decode-through-assertion helpers for format tests.
 *
 * `json_decode()` hands back `mixed`; reaching through it with array
 * offsets is exactly the unchecked-boundary pattern the suite should
 * not replicate. These helpers parse the value into an array through a
 * real PHPUnit assertion first (so a regression in the encoder fails
 * here loudly), and only then let the test read offsets.
 */
trait DecodedJson
{
    /**
     * @return array<array-key, mixed>
     */
    private static function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function decodeNested(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value);

        return $value;
    }
}
