<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flappy\Support\Version;

/**
 * bin/candy-flappy argument contract (M13/M14). The diagnostic flags are the
 * ONLY arguments the game accepts; each child run exits long before the TUI
 * boots, so these are safe to execute headless. Unknown flags must refuse
 * with exit 2 and show usage on stderr — they used to launch the game.
 */
final class BinFlagsTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-flappy';

    /**
     * @param array<int, string> $args
     * @return array{0:int, 1:string, 2:string}
     */
    private static function drive(array $args): array
    {
        $handle = proc_open(
            ['php', self::BIN, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($handle);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($handle), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
    }

    public function testVersionFlagPrintsTheStampedLine(): void
    {
        [$code, $out] = self::drive(['--version']);
        self::assertSame(0, $code);
        self::assertSame(Version::line(), $out);
    }

    public function testHelpAdvertisesEveryAcceptedFlagIncludingShortForm(): void
    {
        foreach ([['--help'], ['-h']] as $args) {
            [$code, $out] = self::drive($args);
            self::assertSame(0, $code, implode(' ', $args) . ' should exit 0');
            self::assertStringContainsString('--version', $out);
            // M13: -h was a working flag the usage text never mentioned.
            self::assertStringContainsString('-h, --help', $out);
        }
    }

    public function testUnknownArgumentsRefuseWithExitTwoAndUsage(): void
    {
        [$code, $out, $err] = self::drive(['--play-please']);
        self::assertSame(2, $code);
        self::assertSame('', $out, 'refusal must keep stdout clean');
        self::assertStringContainsString('unknown argument', $err);
        self::assertStringContainsString('--play-please', $err);
        self::assertStringContainsString('Usage:', $err);
    }
}
