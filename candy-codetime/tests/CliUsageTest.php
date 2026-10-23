<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;

/**
 * MAJOR-1 guard: the help surface must describe the binary, not a subset of it.
 *
 * The shipped `cli.usage` string used to omit whole commands (milestone,
 * export, gaps, backup) while README documented even fewer. This test
 * derives the parseable command set straight from the bin source and
 * compares it with what usage advertises, in both directions.
 */
final class CliUsageTest extends TestCase
{
    /** @return list<string> every literal the bin dispatches `$cmd` against */
    private static function dispatchedCommands(): array
    {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/bin/candy-codetime');
        preg_match_all("/\\\$cmd (?:===|!==) '([^']+)'/", $bin, $m);
        return array_values(array_unique($m[1]));
    }

    private static function usage(): string
    {
        $lang = include dirname(__DIR__) . '/lang/en.php';
        return (string) $lang['cli.usage'];
    }

    public function testEveryDispatchedPrimaryCommandIsAdvertisedInUsage(): void
    {
        $usage = self::usage();
        // -h/--help/-v are aliases; "help, --help" and "--version, -v" are the
        // advertised spellings, so aliases themselves need no separate row.
        $aliases = ['--help', '-h', '-v'];
        foreach (self::dispatchedCommands() as $command) {
            if (in_array($command, $aliases, true)) {
                continue;
            }
            $this->assertStringContainsString(
                $command,
                $usage,
                "usage text must document the dispatched command '{$command}'",
            );
        }
    }

    public function testEveryCommandRowInUsageIsActuallyDispatched(): void
    {
        $dispatched = self::dispatchedCommands();
        $advertised = [];
        foreach (explode("\n", self::usage()) as $line) {
            if (preg_match('/^  (dashboard|push|milestone|export|gaps|backup|help|--version|-v)(?:,|  |\s)/u', $line, $m)) {
                $advertised[] = $m[1];
            }
        }
        $this->assertNotEmpty($advertised, 'usage lost its command table shape');
        foreach (array_unique($advertised) as $name) {
            $this->assertContains(
                $name,
                $dispatched,
                "usage advertises '{$name}' but the bin never dispatches it",
            );
        }
    }

    public function testUsageDocumentsTheStorageBackendOption(): void
    {
        $usage = self::usage();
        $this->assertStringContainsString('--backend=file|sqlite', $usage);
        $this->assertStringContainsString('CANDY_CODETIME_DIR', $usage);
    }
}
