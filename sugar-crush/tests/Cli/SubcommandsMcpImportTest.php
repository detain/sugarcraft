<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Cli\Subcommands;

/**
 * E710 — the `sugarcrush mcp import claude|opencode <path>` one-shot.
 *
 * THE ACCEPTANCE SPAN, in-process: ArgvParser → Subcommands::dispatch down to
 * the translator call, exactly the way SubcommandsMcpAuthLoginTest drives its
 * verb. stdout is ob-captured (the document, or the JSON envelope); stderr is
 * NOT capturable in-process — the note-channel content is asserted through
 * `--output-format json`'s `notes` array and through the real-bin arms in
 * BinSugarcrushDispatchTest, so nothing here pretends to read a stream it
 * cannot see.
 *
 * THE NEVER-WRITE LAW gets two independent pins: a BEHAVIOURAL one (the temp
 * directory holds the same files before and after both a success and a
 * refusal — no `.mcp.json`, no scratch, no backup) and a STRUCTURAL one (the
 * method's own source span contains no write-call shape at all, so a future
 * edit cannot add the behaviour the test would otherwise only catch when it
 * picked the right filename to look for).
 */
final class SubcommandsMcpImportTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = (string) realpath(sys_get_temp_dir()) . '/pg-e710-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->tempDir, 0700, true) !== false);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tempDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->tempDir . '/' . $entry);
            }
        }
        @rmdir($this->tempDir);
    }

    public function testTheImportDoorRefusesEveryIncompleteOperandShape(): void
    {
        self::assertSame(
            'sugarcrush: mcp import: no source given',
            $this->usageDoor(['mcp', 'import', '--output-format', 'json']),
        );
        self::assertSame(
            'sugarcrush: mcp import gemini: unknown source',
            $this->usageDoor(['mcp', 'import', 'gemini', $this->operandFile('cfg.json', '{}'), '--output-format', 'json']),
        );
        self::assertSame(
            'sugarcrush: mcp import: no file given',
            $this->usageDoor(['mcp', 'import', 'claude', '--output-format', 'json']),
        );
        self::assertSame(
            'sugarcrush: mcp import: unexpected operand second',
            $this->usageDoor(['mcp', 'import', 'claude', $this->operandFile('cfg.json', '{"mcpServers":{}}'), 'second', '--output-format', 'json']),
        );
    }

    public function testAnUnreadablePathIsAUsageDoorNotAPartialTranslation(): void
    {
        $missing = $this->tempDir . '/does-not-exist.json';

        self::assertSame(
            'sugarcrush: mcp import: cannot read ' . $missing,
            $this->usageDoor(['mcp', 'import', 'claude', $missing, '--output-format', 'json']),
        );
    }

    public function testAMalformedFileExitsOneWithNoDocumentOnStdout(): void
    {
        // The file EXISTS and was read — this ran and failed, so it is exit
        // 1 (EXIT_FAILURE), not a usage door. And the acceptance half: stdout
        // carries NO partial document in either format.
        $path = $this->operandFile('broken.json', '{ not json');

        [$rc, $stdout] = $this->dispatchImport(['mcp', 'import', 'claude', $path]);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc);
        self::assertSame('', trim($stdout), 'a refused file must leave stdout empty — a half-translated block is worse than none');

        [$rc, $stdout] = $this->dispatchImport(['mcp', 'import', 'claude', $path, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc);
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded);
        self::assertSame('mcp-config', $decoded['error']['type'] ?? null, trim($stdout));
        self::assertStringContainsString('is not valid JSON', (string) ($decoded['error']['message'] ?? ''));
        self::assertArrayNotHasKey('result', $decoded, 'the failure envelope carries an error, never a document');
    }

    public function testTheWrongContainerIsRefusedNamedByBlock(): void
    {
        // A Claude document handed to the opencode door fails with the block
        // opencode would have read — the named-dialect error, not a quiet
        // empty import.
        $path = $this->operandFile('claude-shape.json', json_encode(['mcpServers' => ['x' => ['type' => 'stdio', 'command' => 'run']]]));

        [$rc, $stdout] = $this->dispatchImport(['mcp', 'import', 'opencode', $path, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc);
        self::assertStringContainsString(
            'no "mcp" block to import',
            (string) (json_decode(trim($stdout), true)['error']['message'] ?? ''),
        );
    }

    public function testAClaudeFilePrintsItsBlockAndExitsZero(): void
    {
        $document = ['mcpServers' => ['x' => ['type' => 'stdio', 'command' => 'run', 'args' => ['-y']]]];
        $path = $this->operandFile('native.json', json_encode($document));

        [$rc, $stdout] = $this->dispatchImport(['mcp', 'import', 'claude', $path]);

        self::assertSame(NonInteractive::EXIT_OK, $rc);
        self::assertSame($document, json_decode(trim((string) $stdout), true), 'a conforming document must survive the round trip unchanged');
    }

    public function testTheJsonEnvelopeCarriesServersAndNotesAndNamesItsSource(): void
    {
        $path = $this->operandFile('oc.json', json_encode(['mcp' => [
            'on' => ['type' => 'local', 'command' => ['npx', '-y', 'srv'], 'enabled' => true],
            'off' => ['type' => 'local', 'command' => ['sleep'], 'enabled' => false],
        ]]));

        [$rc, $stdout] = $this->dispatchImport(['mcp', 'import', 'opencode', $path, '--output-format', 'json']);

        self::assertSame(NonInteractive::EXIT_OK, $rc);
        $decoded = json_decode(trim((string) $stdout), true);
        self::assertIsArray($decoded, $stdout);
        self::assertSame('opencode', $decoded['result']['source'] ?? null);
        self::assertSame($path, $decoded['result']['path'] ?? null);
        self::assertSame(
            ['on' => ['type' => 'stdio', 'command' => 'npx', 'args' => ['-y', 'srv']]],
            $decoded['result']['mcpServers'] ?? null,
        );
        $notes = (array) ($decoded['result']['notes'] ?? []);
        self::assertContains('moved the "mcp" block to "mcpServers"', $notes);
        self::assertContains('dropped "off" — its own "enabled": false declines to start', $notes, 'the disabled-drop note is the loud half of the drop');
        self::assertContains('on: redundant "enabled": true dropped', $notes);
    }

    public function testTheVerbWritesNothingToTheFilesystemUnderAnyExit(): void
    {
        // The behavioural half of the never-write law: both a success and a
        // refusal must leave the directory exactly as they found it. The
        // canonical accident — an importer "helpfully" dropping .mcp.json
        // (or a .bak, or a scratch copy) next to the operand — reddens here.
        $good = $this->operandFile('oc.json', json_encode(['mcp' => ['s' => ['type' => 'local', 'command' => ['npx']]]]));
        $broken = $this->operandFile('broken.json', '{"mcp": }');
        $before = scandir($this->tempDir);
        sort($before);

        self::assertSame(NonInteractive::EXIT_OK, $this->dispatchImport(['mcp', 'import', 'opencode', $good])[0]);
        self::assertSame(NonInteractive::EXIT_FAILURE, $this->dispatchImport(['mcp', 'import', 'claude', $broken])[0]);

        $after = scandir($this->tempDir);
        sort($after);
        self::assertSame($before, $after, 'import printed into the filesystem — stdout is the only output channel');
        self::assertFileDoesNotExist($this->tempDir . '/.mcp.json');
    }

    public function testTheImportMethodBodyCarriesNoWriteShapeAtAll(): void
    {
        // The structural half: the census this verb lives under reads SOURCES,
        // and so does this pin — from `function mcpImport(` to the stderr
        // funnel's definition, not ONE filesystem-mutating call spelling, not
        // even a raw fwrite (every stderr line must keep going through
        // mcpImportLine so the DIRECT_SITES census stays single per file).
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php');
        $from = strpos($source, 'private static function mcpImport(');
        $to = strpos($source, 'private static function mcpImportLine(');
        self::assertIsInt($from, 'mcpImport() vanished — the never-write law lost its subject');
        self::assertIsInt($to);
        self::assertGreaterThan($from, $to);
        $body = substr($source, $from, $to - $from);

        foreach (['file_put_contents', 'fwrite(', 'fopen(', 'mkdir(', 'rename(', 'unlink(', 'chmod(', 'touch(', 'copy(', 'symlink(', 'link(', 'rmdir('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body, "the import verb grew a write shape ({$forbidden}) — the verb PRINTS, the operator decides");
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{0:int,1:string} [exit code, captured stdout]
     */
    private function dispatchImport(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['sugarcrush', ...$args]));
        $stdout = (string) ob_get_clean();

        return [$rc, $stdout];
    }

    /**
     * Run a door expected to answer `2` with the usage envelope, and hand
     * back its message. The stderr line failUsage also writes is NOT
     * observable in-process — the envelope on stdout is the contract.
     *
     * @param list<string> $args
     */
    private function usageDoor(array $args): string
    {
        [$rc, $stdout] = $this->dispatchImport($args);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$stdout}");
        self::assertSame('usage', $decoded['error']['type'] ?? null, $stdout);

        return (string) ($decoded['error']['message'] ?? '');
    }

    private function operandFile(string $name, string $contents): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
