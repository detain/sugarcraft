<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\EnvBool;

/**
 * Pins the two documented boolean-environment vocabularies
 * (W7 EnvBool unify): the narrow house law `isTrue()` — the
 * `BootstrapMcpDisableEnvTest` set of `1`, `true`, `yes` case-insensitive —
 * and the presence law `isSet()` documented in docs/ENVIRONMENT.md for the
 * `CANDY_CODE_DISABLE_*` family (anything but unset, empty, literal `0`).
 *
 * The matrix deliberately re-derives the MCP-gate rows (disable vs keep
 * running) and the mouse/title rows (`CANDY_CODE_DISABLE_MOUSE=0` leaves the
 * mouse ON), so a future merge of the two laws cannot pass silently.
 */
final class EnvBoolTest extends TestCase
{
    /** @return array<string, array{string|false}> */
    public static function narrowTrueSpellings(): array
    {
        return [
            'one' => ['1'],
            'lower true' => ['true'],
            'upper TRUE' => ['TRUE'],
            'mixed True' => ['True'],
            'lower yes' => ['yes'],
            'upper YES' => ['YES'],
        ];
    }

    /** @return array<string, array{string|false}> */
    public static function narrowFalseSpellings(): array
    {
        return [
            'unset' => [false],
            'empty' => [''],
            'zero' => ['0'],
            'on' => ['on'],
            'off' => ['off'],
            'false' => ['false'],
            'two' => ['2'],
            'space' => [' '],
        ];
    }

    /** @return array<string, array{string|false}> */
    public static function presenceTrueSpellings(): array
    {
        return [
            'one' => ['1'],
            'yes' => ['yes'],
            'on' => ['on'],
            'off' => ['off'],
            'false' => ['false'],
            'zero-zero' => ['00'],
            'two' => ['2'],
        ];
    }

    /** @return array<string, array{string|false}> */
    public static function presenceFalseSpellings(): array
    {
        return [
            'unset' => [false],
            'empty' => [''],
            'literal zero' => ['0'],
        ];
    }

    #[DataProvider('narrowTrueSpellings')]
    public function testTheNarrowHouseLawAcceptsExactlyOneTrueYes(string|false $raw): void
    {
        self::assertTrue(EnvBool::isTrue($raw), var_export($raw, true));
    }

    #[DataProvider('narrowFalseSpellings')]
    public function testTheNarrowHouseLawRefusesEverythingElse(string|false $raw): void
    {
        self::assertFalse(EnvBool::isTrue($raw), var_export($raw, true));
    }

    #[DataProvider('presenceTrueSpellings')]
    public function testThePresenceLawReadsAnyOtherTextAsSet(string|false $raw): void
    {
        self::assertTrue(EnvBool::isSet($raw), var_export($raw, true));
    }

    #[DataProvider('presenceFalseSpellings')]
    public function testThePresenceLawReadsUnsetEmptyAndZeroAsNotSet(string|false $raw): void
    {
        self::assertFalse(EnvBool::isSet($raw), var_export($raw, true));
    }

    public function testTheTwoLawsDisagreeOnOffAndFalseByDesign(): void
    {
        // The contrast the class docblock promises: `off` DISABLES a presence
        // flag (the documented CANDY_CODE_DISABLE_* trap, kept for compatibility
        // with already-deployed configs) but never ENABLES a narrow flag.
        self::assertTrue(EnvBool::isSet('off'));
        self::assertFalse(EnvBool::isTrue('off'));
        self::assertTrue(EnvBool::isSet('false'));
        self::assertFalse(EnvBool::isTrue('false'));
    }
}
