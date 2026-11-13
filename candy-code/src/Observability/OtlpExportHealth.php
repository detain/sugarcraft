<?php

declare(strict_types=1);

namespace SugarCraft\Code\Observability;

/**
 * Process-wide tally of OTLP export losses, the counters
 * {@see BatchEventSink} keeps per instance made readable by the one surface
 * that has no sink in hand: the `otlp export` row of
 * {@see \SugarCraft\Code\Cli\DoctorChecks} (S-O27).
 *
 * WHY STATIC, AND WHAT IT CAN AND CANNOT SAY. Sinks are built per launch (and
 * per hosted session under serve) and nothing hands doctor a reference to
 * them. The tally therefore describes THIS process only: the in-session
 * `/doctor` sees the live session's failures, while a fresh `candy-code
 * doctor` reads zeros and says exactly that rather than claiming the endpoint
 * is healthy. It stays silent about the endpoint itself (it can carry
 * credentials) and counts only.
 */
final class OtlpExportHealth
{
    private static int $refused = 0;
    private static int $encodeFailures = 0;
    private static int $carryEvictions = 0;
    private static int $undelivered = 0;

    public static function recordRefused(int $count): void
    {
        self::$refused += \max(0, $count);
    }

    public static function recordEncodeFailure(): void
    {
        ++self::$encodeFailures;
    }

    public static function recordEvictions(int $count): void
    {
        self::$carryEvictions += \max(0, $count);
    }

    public static function recordUndelivered(int $count): void
    {
        self::$undelivered += \max(0, $count);
    }

    /** @return array{refused: int, encodeFailures: int, carryEvictions: int, undelivered: int} */
    public static function snapshot(): array
    {
        return [
            'refused' => self::$refused,
            'encodeFailures' => self::$encodeFailures,
            'carryEvictions' => self::$carryEvictions,
            'undelivered' => self::$undelivered,
        ];
    }

    /** True when any loss has been recorded by this process. */
    public static function degraded(): bool
    {
        return self::$refused > 0 || self::$encodeFailures > 0 || self::$carryEvictions > 0 || self::$undelivered > 0;
    }

    /** Test seam: forget every recorded loss. */
    public static function reset(): void
    {
        self::$refused = 0;
        self::$encodeFailures = 0;
        self::$carryEvictions = 0;
        self::$undelivered = 0;
    }
}
