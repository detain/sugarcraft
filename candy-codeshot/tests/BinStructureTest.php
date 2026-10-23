<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the bin's autoload candidate list and its fail-loud refusal.
 *
 * The review finding (docs-review-1023 C1): the shipped bin probed only the
 * package-local vendor and the monorepo shape, so a plain
 * `composer require sugarcraft/candy-codeshot` consumer — where composer
 * copies the package to <project>/vendor/sugarcraft/candy-codeshot/bin —
 * found no autoload.php anywhere and every class reference fatalled.
 *
 * Two arms, one structural and one behavioural:
 *  - the candidate list must contain all three house paths, in the house
 *    order, and the refusal message;
 *  - an isolated copy of the bin (no vendor tree within reach) must exit 1
 *    with that message instead of a class-not-found error.
 */
final class BinStructureTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-codeshot';

    public function testTheBinProbesAllThreeHouseAutoloadCandidates(): void
    {
        $source = file_get_contents(self::BIN);
        $this->assertIsString($source);

        foreach ([
            "__DIR__ . '/../vendor/autoload.php'",
            "__DIR__ . '/../../../autoload.php'",
            "__DIR__ . '/../../vendor/autoload.php'",
        ] as $candidate) {
            $this->assertStringContainsString(
                $candidate,
                $source,
                'the consumer-install candidate set must stay complete',
            );
        }

        $positionStandalone = strpos($source, "/../vendor/autoload.php");
        $positionInstalled  = strpos($source, "/../../../autoload.php");
        $positionSymlink    = strpos($source, "/../../vendor/autoload.php");
        $this->assertLessThan($positionInstalled, $positionStandalone, 'house probe order: standalone first');
        $this->assertLessThan($positionSymlink, $positionInstalled, 'house probe order: installed dist path second');
    }

    public function testAMissingAutoloadRefusesLoudlyInsteadOfFatalling(): void
    {
        $dir = sys_get_temp_dir() . '/codeshot_noautoload_' . uniqid('', true);
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('could not create scratch dir');
        }

        try {
            // Copy the bin alone: nothing above the scratch dir can satisfy
            // any candidate, so the fail-loud door is the only exit.
            copy(self::BIN, $dir . '/candy-codeshot');

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $handle = proc_open([PHP_BINARY, $dir . '/candy-codeshot', '--help'], $descriptors, $pipes, $dir);
            $this->assertIsResource($handle);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($handle);

            $this->assertSame(1, $exitCode, $stderr);
            $this->assertStringContainsString('cannot find composer autoload.php', $stderr);
            $this->assertStringContainsString('composer install', $stderr);
            $this->assertStringNotContainsString('Class "', $stderr, 'must refuse, not fatal');
            $this->assertSame('', $stdout);
        } finally {
            @unlink($dir . '/candy-codeshot');
            @rmdir($dir);
        }
    }
}
