<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;

/**
 * MAJOR-1 guard: the help surface must describe the binary, not a subset of it.
 *
 * The shipped `cli.usage` string used to omit whole commands (milestone,
 * export, gaps, backup) while README documented even fewer. The README
 * Commands table — the user-facing documentation — is policed here by
 * SET EQUALITY against the bin's dispatch arms in both directions: a
 * deleted row or a phantom verb reddens the suite, which a "command name
 * appears somewhere in prose" containment check would not.
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

    /**
     * Alias spellings ride their primary's README row ("help, --help, -h"
     * and "--version, -v"), so the row's FIRST backticked token is the verb.
     */
    private const ALIAS_SPELLINGS = ['--help', '-h', '-v'];

    /**
     * First backticked token of every row of the first table under the
     * README `## Commands` heading. The table closes on the first non-`|`
     * line after it starts, so the Options table that follows cannot leak
     * its option names into the command set.
     *
     * @return list<string>
     */
    private static function readmeCommandRows(): array
    {
        $verbs = [];
        $headingSeen = false;
        $inTable = false;
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__) . '/README.md')) as $line) {
            if (!$headingSeen) {
                $headingSeen = (rtrim($line) === '## Commands');
                continue;
            }
            if (preg_match('/^\|\s*`([^`]+)`/', $line, $m)) {
                $verbs[] = $m[1];
                $inTable = true;
                continue;
            }
            if ($inTable && !str_starts_with($line, '|')) {
                break;
            }
        }
        return $verbs;
    }

    public function testTheReadmeCommandsTableEqualsTheDispatchedCommandSet(): void
    {
        $documented = self::readmeCommandRows();
        $this->assertNotEmpty($documented, 'README lost its Commands table shape');
        $dispatched = array_values(array_diff(self::dispatchedCommands(), self::ALIAS_SPELLINGS));

        $undocumented = array_values(array_diff($dispatched, $documented));
        $this->assertSame(
            [],
            $undocumented,
            'the bin dispatches commands the README Commands table omits (forward direction)',
        );

        $phantom = array_values(array_diff($documented, $dispatched));
        $this->assertSame(
            [],
            $phantom,
            'the README Commands table documents verbs the bin never dispatches (reverse direction)',
        );
    }

    /**
     * CT-S50 (finding 062/078): three facts used to live in only one of the
     * two manuals — the default duration, the "of at least" threshold and
     * the mandatory milestone name. Pin the shipped catalogue and the
     * README table against each other so either side drifting goes red.
     */
    public function testTheDocumentedFactsAgreeBetweenUsageAndReadme(): void
    {
        $usage = self::usage();
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $this->assertStringContainsString('Record a heartbeat (duration in seconds, default 60)', $usage);
        $this->assertStringContainsString('default 60', $readme);
        $this->assertStringContainsString('Show untracked gaps of at least 300 seconds', $usage);
        $this->assertStringContainsString('| `milestone` | `add <name> [description]`', $readme);
        $this->assertStringContainsString('add <name> [description] | list', $usage);
        $this->assertStringNotContainsString('[name] [description]', $usage);
        $this->assertStringNotContainsString('release markers', $readme);
    }

    public function testEveryCommandRowInUsageIsActuallyDispatched(): void
    {
        $dispatched = self::dispatchedCommands();
        $advertised = [];
        foreach (explode("\n", self::usage()) as $line) {
            if (preg_match('/^  (dashboard|push|milestone|export|gaps|summary|backup|help|--version|-v)(?:,|  |\s)/u', $line, $m)) {
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
