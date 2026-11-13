<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * scriptui-29 grep-pin: no command may (int)-cast an option value.
 * A silent cast turns a typo'd numeric into a DIFFERENT MODE; every
 * int-ish flag must route through Runtime\PositiveInt::parse instead.
 * Deleting a door call from any command reddens the census row naming
 * that flag (mutation-checked), exactly like the lib's other
 * source-shape guards.
 */
final class NumericDoorCensusTest extends TestCase
{
    public function testNoIntCastsOnOptionValuesRemain(): void
    {
        // scriptui-53 KEEP (census-pin, -29 cast-guard family): a repo-wide
        // absence cannot be witnessed by any single behaviour run — every
        // command file must be walked. The parse doors themselves are
        // behaviour-tested (PositiveIntTest, NumericDoor legs).
        $files = glob(__DIR__ . '/../../src/Command/*.php');
        $this->assertNotEmpty($files, 'command roster must be discoverable');

        $offenders = [];
        foreach ((array) $files as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all('/\(int\)\s*\$input->getOption\(/', $src, $m) > 0) {
                $offenders[] = basename($file) . ' (' . count($m[0]) . ' casts)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Option values must pass through PositiveInt::parse, never a silent (int) cast.'
        );
    }

    public function testNumericFlagRosterIsDoorGuarded(): void
    {
        // scriptui-53 KEEP (census-pin): pairing option registration with
        // its parse door is a static roster question across every command
        // file — behaviour runs cover the doors individually (NumericDoorTest).
        // Every command that registers an int-ish VALUE_REQUIRED option
        // must call PositiveInt::parse (numeric flag roster).
        $numericFlags = ['height', 'width', 'limit', 'char-limit', 'max-lines'];
        $files        = (array) glob(__DIR__ . '/../../src/Command/*.php');

        $offenders = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match('/addOption\((?:\'|")(\w[\w-]*)/', $src, $any)) {
                continue;
            }
            foreach ($numericFlags as $flag) {
                if (preg_match('/addOption\(\'' . preg_quote($flag, '/') . '\',\s*null,\s*InputOption::VALUE_REQUIRED/', $src) === 1
                    && preg_match('/PositiveInt::(?:parse|optional)\(\'' . preg_quote($flag, '/') . '\'/', $src) !== 1) {
                    $offenders[] = basename($file) . ' --' . $flag;
                }
            }
        }

        $this->assertSame([], $offenders, 'Un-guarded numeric flag door(s) found.');
    }
}
