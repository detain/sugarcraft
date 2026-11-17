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
        // The prose wraps, so a phrase can sit across a line break; collapse the
        // whitespace first or a re-wrap would hide a regression of the claim.
        $flowing = (string) preg_replace('/\s+/', ' ', $troubleshooting);

        self::assertStringNotContainsString(
            'no server is ever started',
            $flowing,
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

    public function testExitCodeTableNamesWorkflowRunExitingThree(): void
    {
        // src/Cli/Subcommands.php returns 3 from `workflow run`/`resume` when a run stopped for a person.
        self::assertStringContainsString(
            '| `3` | `workflow run` and `workflow resume` only:',
            self::read('README.md'),
            'the README exit-code table must name exit 3 for workflow run (docs/WORKFLOWS.md, src/Cli/Subcommands.php)',
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

    /**
     * Each source cite FLEET-DRIVING.md makes (by its first line), pinned to a token
     * its cited line must carry. The key set is closed both ways, so a new cite
     * or a moved one fails until its row is re-derived from the source.
     */
    private const FLEET_CITES = [
        'src/Cli/ArgvParser.php:435' => '--root=',
        'src/Cli/ArgvParser.php:622' => '--permission-mode=',
        'src/Cli/ArgvParser.php:789' => '--json-schema=',
        'src/Cli/ArgvParser.php:939' => '--resume=',
        'src/Cli/NonInteractive.php:108' => 'EXIT_OK',
        'src/Cli/NonInteractive.php:147' => 'EXIT_FAILURE',
        'src/Cli/NonInteractive.php:187' => 'EXIT_CONFIG',
        'src/Cli/NonInteractive.php:194' => 'MAX_STDIN_BYTES',
        'src/Cli/NonInteractive.php:222' => 'STDIN_FIRST_BYTE_GRACE_SECONDS',
        'src/Cli/NonInteractive.php:1814' => '$outputFormat !== self::FORMAT_JSON',
        'src/Cli/NonInteractive.php:1823' => "'error' =>",
        'src/Cli/NonInteractive.php:1824' => '$refusals !== []',
        'src/Cli/NonInteractive.php:2746' => "'result' =>",
        'src/Cli/StreamJsonEmitter.php:164' => 'function emitLine',
        'src/Cli/Subcommands.php:1111' => 'match ($verb)',
        'src/Cli/Subcommands.php:1114' => "'import' =>",
        'src/Cli/Subcommands.php:3333' => "'list' =>",
        'src/Cli/Subcommands.php:3933' => 'function renderUsage',
        'src/Cli/Bootstrap.php:7468' => 'autoReview',
        'src/Config/LayeredSettings.php:589' => "'autoReview'",
        'src/Config/Settings/Definitions/PermissionSettings.php:79' => "'autoReview'",
        'src/Host/Commands/UsageHostCommand.php:82' => 'function facts',
        'src/Providers/SglangProvider.php:1910' => 'response_format',
        'src/Usage.php:140' => 'public int $totalTokens',
    ];

    public function testInternalProcessIdsAreGoneFromTheUserDocs(): void
    {
        $banned = ['weld', 'wf/driver', 'W-1/D4', 'P3.5', 'N-P4', 'C-AGT-'];
        $docs = [
            'docs/FLEET-DRIVING.md', 'docs/WORKFLOWS.md', 'docs/PROMPT_ENGINEERING.md', 'docs/SETTINGS.md',
            'docs/MEMORY.md', 'docs/PLUGINS.md', 'docs/ENVIRONMENT.md', 'docs/PERMISSIONS.md',
        ];
        foreach ($docs as $doc) {
            $body = self::read($doc);
            foreach ($banned as $token) {
                self::assertStringNotContainsString(
                    $token,
                    $body,
                    $doc . ' names an internal process id (' . $token . ') in a user-facing doc',
                );
            }
            self::assertDoesNotMatchRegularExpression(
                '/roadmap\s+[0-9]/',
                $body,
                $doc . ' cites a roadmap item number (e.g. "roadmap 5.3-1") in a user-facing doc',
            );
        }
    }

    public function testComparisonHookCountNoLongerStatesTwelve(): void
    {
        $comparison = self::read('COMPARISON.md');

        foreach (['hooks (12 events', '✅ 12 events', 'All 12 events'] as $stale) {
            self::assertStringNotContainsString(
                $stale,
                $comparison,
                'COMPARISON.md still states the pre-fix hook count (' . $stale . '); HookEvent has 16 cases',
            );
        }
    }

    public function testPromptEngineeringDescribesTheRuntimeConditionsOfEntries3_5_12(): void
    {
        $prompt = self::read('docs/PROMPT_ENGINEERING.md');

        self::assertStringNotContainsString(
            'present only in some builds',
            $prompt,
            'entries 3, 5 and 12 are runtime conditions (wired tool, symbol-map capture, plan-mode gate), not build conditions',
        );
        self::assertStringContainsString('present only when their condition holds at runtime', $prompt);
    }

    public function testFleetDrivingSourceCitesPointAtTheirBehaviour(): void
    {
        $doc = self::read('docs/FLEET-DRIVING.md');
        preg_match_all('/src\/[A-Za-z0-9_\/]+\.php:\d+/', $doc, $matches);
        $cited = array_values(array_unique($matches[0]));
        $pinned = array_keys(self::FLEET_CITES);
        sort($cited);
        sort($pinned);

        self::assertSame(
            $pinned,
            $cited,
            'FLEET-DRIVING.md source cites must be exactly the pinned set: re-derive any new or moved cite from src/ and update FLEET_CITES',
        );
        foreach (self::FLEET_CITES as $cite => $token) {
            [$file, $line] = explode(':', $cite);
            $lines = file(self::candyCodeRoot() . '/' . $file, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines, $file . ' must exist');
            self::assertStringContainsString(
                $token,
                $lines[(int) $line - 1] ?? '',
                $cite . ' must carry ' . $token . ' (the behaviour FLEET-DRIVING.md describes there)',
            );
        }
    }
}
