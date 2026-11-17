<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookEvent;

/**
 * Factual claims in candy-code's own docs that the code once contradicted
 * (the PD-P0 correction set). Each assertion pins the corrected wording, so
 * the pre-fix text fails it on master and a regression of the claim fails it
 * again. The guards read the docs as text on purpose: the claims are prose,
 * and the code side is named in each message so the fix can be re-derived.
 *
 * @internal
 */
final class CandyCodeFactsDocDriftTest extends TestCase
{
    private static function candyCodeRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $path = self::candyCodeRoot() . '/' . $relative;
        $body = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($body)) {
            self::fail($relative . ' must exist and be readable at ' . $path);
        }

        return $body;
    }

    public function testComparisonStatesTheLiveHookEventCount(): void
    {
        $count = \count(HookEvent::cases());

        self::assertStringContainsString(
            'hooks (' . $count . ' events;',
            self::read('COMPARISON.md'),
            'COMPARISON.md must state the live hook event count (' . $count . ', src/Hooks/HookEvent.php)',
        );
    }

    public function testComparisonDescribesTheMcpServeMode(): void
    {
        self::assertStringContainsString(
            'candy-code mcp serve',
            self::read('COMPARISON.md'),
            'COMPARISON.md must say that MCP has a server mode (`candy-code mcp serve`, docs/MCP.md)',
        );
    }

    public function testTroubleshootingDescribesTheLspStartPath(): void
    {
        $troubleshooting = self::read('docs/TROUBLESHOOTING.md');

        self::assertStringNotContainsString(
            'no server is ever started',
            $troubleshooting,
            'TROUBLESHOOTING must not say no language server is ever started: lspClient() in src/Cli/Bootstrap.php starts the configured ones',
        );
        self::assertStringContainsString('lspClient()', $troubleshooting);
    }

    public function testPythonPortIsLabelledExperimentalAndDoesNotImport(): void
    {
        $readme = self::read('python_port/README.md');

        self::assertStringContainsString('Experimental', $readme);
        self::assertStringContainsString(
            'does not import',
            $readme,
            'python_port/README.md must warn that the package does not import while its import paths are wrong',
        );
    }

    public function testFleetDriverFlagsAreDocumentedAsShippedNotAsQueued(): void
    {
        $fleet = self::read('docs/FLEET-DRIVING.md');

        self::assertStringContainsString('`--session-id <16hex>`', $fleet);
        self::assertStringContainsString('`--input-format stream-json`', $fleet);
        self::assertStringNotContainsString(
            'unrecognized on shipped builds',
            $fleet,
            'FLEET-DRIVING must not say the driver flags exit 2 on shipped builds: src/Cli/ArgvParser.php implements both',
        );
        self::assertStringNotContainsString(
            'queued for merge',
            $fleet,
            'FLEET-DRIVING must not describe implemented flags as queued for merge',
        );
    }

    public function testEnvironmentDocumentsTheColumnsAndCpuCountReads(): void
    {
        $environment = self::read('docs/ENVIRONMENT.md');

        self::assertStringContainsString('| `COLUMNS` |', $environment, 'src/Cli/Help.php reads COLUMNS');
        self::assertStringContainsString(
            '| `NUMBER_OF_PROCESSORS` |',
            $environment,
            'src/Workflows/CpuCount.php reads NUMBER_OF_PROCESSORS',
        );
    }

    public function testAllowedToolsSentenceStatesThePlainLaunchCount(): void
    {
        self::assertStringContainsString(
            'all thirty-seven of the others on a plain launch',
            self::read('README.md'),
            'the README allowedTools sentence must state the 37 removals of a plain launch (docs/SETTINGS.md)',
        );
    }

    public function testInternalProcessIdsAreGoneFromTheUserDocs(): void
    {
        $banned = ['weld', 'wf/driver', 'W-1/D4', 'P3.5', 'N-P4', 'C-AGT-'];
        foreach (['docs/FLEET-DRIVING.md', 'docs/WORKFLOWS.md', 'docs/PROMPT_ENGINEERING.md', 'docs/SETTINGS.md', 'docs/MEMORY.md'] as $doc) {
            $body = self::read($doc);
            foreach ($banned as $token) {
                self::assertStringNotContainsString(
                    $token,
                    $body,
                    $doc . ' names an internal process id (' . $token . ') in a user-facing doc',
                );
            }
        }
    }
}
