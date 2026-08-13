<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Doctor;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;

/**
 * @see Tool
 * @see Read
 * @see Bash
 * @see Edit
 * @see Grep
 * @see Glob
 * @see WebFetch
 * @see WebSearch
 * @see Doctor
 */
final class BuiltInToolTest extends TestCase
{
    // =========================================================================
    // Interface Implementation Tests
    // =========================================================================

    /**
     * @dataProvider builtInToolProvider
     */
    public function testAllBuiltInToolsImplementToolInterface(string $className): void
    {
        $tool = $this->createTool($className);

        $this->assertInstanceOf(Tool::class, $tool);
    }

    /**
     * @dataProvider builtInToolProvider
     */
    public function testAllBuiltInToolsImplementAllInterfaceMethods(string $className): void
    {
        $tool = $this->createTool($className);

        $this->assertIsString($tool->name());
        $this->assertIsString($tool->description());
        $this->assertIsArray($tool->inputSchema());
        $this->assertInstanceOf(ToolResult::class, $tool->execute([]));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function builtInToolProvider(): iterable
    {
        yield Read::class => [Read::class];
        yield Bash::class => [Bash::class];
        yield Edit::class => [Edit::class];
        yield Grep::class => [Grep::class];
        yield Glob::class => [Glob::class];
        yield WebFetch::class => [WebFetch::class];
        yield WebSearch::class => [WebSearch::class];
        yield Doctor::class => [Doctor::class];
    }

    // =========================================================================
    // Name and Description Tests
    // =========================================================================

    public function testReadToolHasCorrectNameAndDescription(): void
    {
        $tool = new Read();

        $this->assertSame('Read', $tool->name());
        $this->assertSame('Read contents of a file', $tool->description());
    }

    public function testBashToolHasCorrectNameAndDescription(): void
    {
        $tool = new Bash();

        $this->assertSame('Bash', $tool->name());
        $this->assertSame('Execute a bash command', $tool->description());
    }

    public function testEditToolHasCorrectNameAndDescription(): void
    {
        $tool = new Edit();

        $this->assertSame('Edit', $tool->name());
        $this->assertSame('Edit a file by replacing text', $tool->description());
    }

    public function testGrepToolHasCorrectNameAndDescription(): void
    {
        $tool = new Grep();

        $this->assertSame('Grep', $tool->name());
        $this->assertSame('Search for a pattern in files', $tool->description());
    }

    public function testGlobToolHasCorrectNameAndDescription(): void
    {
        $tool = new Glob();

        $this->assertSame('Glob', $tool->name());
        $this->assertStringStartsWith('Find files matching a glob pattern', $tool->description());
    }

    /**
     * The default prune list is the tool's single biggest behavioural
     * surprise, and a model only ever learns about a default it is told
     * about. Leaving it out of description() meant the escape hatch existed
     * but was undiscoverable, so an empty result for a file living in
     * vendor/ read as "that file does not exist".
     */
    public function testGlobDescriptionNamesThePrunedDirectoriesAndTheOptOut(): void
    {
        $description = (new Glob())->description();

        foreach (['.git', 'vendor', 'node_modules', '.phpunit.cache'] as $dir) {
            $this->assertStringContainsString($dir, $description, $dir);
        }
        $this->assertStringContainsString('vendor/**/*.php', $description, 'the opt-out has to be spelled out');
    }

    /** A caller that turned pruning off must not advertise a prune list. */
    public function testGlobDescriptionDropsThePruneNoteWhenPruningIsDisabled(): void
    {
        $this->assertSame(
            'Find files matching a glob pattern',
            (new Glob(prunedDirs: []))->description(),
        );
    }

    public function testWebFetchToolHasCorrectNameAndDescription(): void
    {
        $tool = new WebFetch();

        $this->assertSame('WebFetch', $tool->name());
        $this->assertSame('Fetch content from a URL', $tool->description());
    }

    public function testWebSearchToolHasCorrectNameAndDescription(): void
    {
        $tool = new WebSearch();

        $this->assertSame('WebSearch', $tool->name());
        $this->assertSame('Search the web for information via a configurable SearXNG endpoint. Returns answers, top results with snippets, suggestions, and corrections.', $tool->description());
    }

    public function testDoctorToolHasCorrectName(): void
    {
        $tool = new Doctor();

        $this->assertSame('doctor', $tool->name());
        $this->assertNotSame('', $tool->description());
    }

    // =========================================================================
    // Input Schema Tests
    // =========================================================================

    /**
     * @dataProvider builtInToolProvider
     */
    public function testInputSchemaReturnsValidJsonSchemaFormat(string $className): void
    {
        $tool = $this->createTool($className);
        $schema = $tool->inputSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('type', $schema);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        // NOT assertIsArray(): JSON Schema requires `properties` to be an
        // OBJECT, and a PHP empty array encodes as the JSON array `[]`. A
        // strict server (SGLang) rejects the entire chat/completions request
        // over one such tool, so the agent could not send any message at all.
        // Assert the wire shape, which is the contract that actually binds.
        $this->assertStringStartsWith(
            '{',
            (string) json_encode($schema['properties']),
            'properties must encode as a JSON object, not an array',
        );
        $this->assertArrayHasKey('required', $schema);
        $this->assertIsArray($schema['required']);
    }

    public function testReadToolInputSchemaHasRequiredFields(): void
    {
        $tool = new Read();
        $schema = $tool->inputSchema();

        $this->assertArrayHasKey('file_path', $schema['properties']);
        $this->assertContains('file_path', $schema['required']);
    }

    public function testBashToolInputSchemaHasRequiredFields(): void
    {
        $tool = new Bash();
        $schema = $tool->inputSchema();

        $this->assertArrayHasKey('command', $schema['properties']);
        $this->assertContains('command', $schema['required']);
    }

    public function testEditToolInputSchemaHasRequiredFields(): void
    {
        $tool = new Edit();
        $schema = $tool->inputSchema();

        $this->assertArrayHasKey('file_path', $schema['properties']);
        $this->assertArrayHasKey('old_string', $schema['properties']);
        $this->assertArrayHasKey('new_string', $schema['properties']);
        $this->assertContains('file_path', $schema['required']);
        $this->assertContains('old_string', $schema['required']);
        $this->assertContains('new_string', $schema['required']);
    }

    public function testGrepToolInputSchemaHasRequiredFields(): void
    {
        $tool = new Grep();
        $schema = $tool->inputSchema();

        $this->assertArrayHasKey('pattern', $schema['properties']);
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('include', $schema['properties']);
        $this->assertContains('pattern', $schema['required']);
        $this->assertContains('path', $schema['required']);
    }

    public function testGlobToolInputSchemaHasRequiredFields(): void
    {
        $tool = new Glob();
        $schema = $tool->inputSchema();

        $this->assertArrayHasKey('pattern', $schema['properties']);
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertContains('pattern', $schema['required']);
        $this->assertContains('path', $schema['required']);
    }

    public function testWebFetchToolInputSchemaHasRequiredFields(): void
    {
        $tool = new WebFetch();
        $schema = $tool->inputSchema();

        $this->assertArrayHasKey('url', $schema['properties']);
        $this->assertContains('url', $schema['required']);
    }

    public function testWebSearchToolInputSchemaHasRequiredFields(): void
    {
        $tool = new WebSearch();
        $schema = $tool->inputSchema();

        $this->assertArrayHasKey('query', $schema['properties']);
        $this->assertArrayHasKey('description', $schema['properties']);
        $this->assertContains('query', $schema['required']);
        $this->assertContains('description', $schema['required']);
        $this->assertArrayHasKey('safesearch', $schema['properties']);
        $this->assertArrayHasKey('time_range', $schema['properties']);
        $this->assertNotContains('safesearch', $schema['required']);
        $this->assertNotContains('time_range', $schema['required']);
    }

    /**
     * crush_feat.md §3 E2: every built-in tool marks a human-readable
     * `description` required, so a compliant backend always emits the label
     * {@see \SugarCraft\Crush\Message::describeToolCall()} prefers - no extra
     * LLM call needed. Fails against the old schemas, which had no such field.
     *
     * @return list<array{0:Tool}>
     */
    public static function describableToolProvider(): array
    {
        return [
            'read' => [new Read()],
            'bash' => [new Bash()],
            'edit' => [new Edit()],
            'grep' => [new Grep()],
            'glob' => [new Glob()],
            'webfetch' => [new WebFetch()],
            'websearch' => [new WebSearch()],
        ];
    }

    /**
     * @dataProvider describableToolProvider
     */
    public function testInputSchemaRequiresAHumanReadableDescription(Tool $tool): void
    {
        $schema = $tool->inputSchema();

        $this->assertArrayHasKey('description', $schema['properties']);
        $this->assertSame('string', $schema['properties']['description']['type']);
        $this->assertStringContainsString(
            'active voice',
            $schema['properties']['description']['description'],
        );
        $this->assertContains('description', $schema['required']);
    }

    public function testDoctorToolInputSchemaHasNoRequiredFields(): void
    {
        $tool = new Doctor();
        $schema = $tool->inputSchema();

        $this->assertSame([], $schema['required']);
    }

    // =========================================================================
    // Execute with Valid Input Tests
    // =========================================================================

    public function testReadToolExecutesWithValidFile(): void
    {
        $tool = new Read();
        $tempFile = $this->createTempFile('Hello World');

        $result = $tool->execute(['id' => 'call_1', 'file_path' => $tempFile]);

        $this->assertSame('call_1', $result->toolCallId());
        $this->assertSame('Hello World', $result->content());
        $this->assertFalse($result->isError());

        unlink($tempFile);
    }

    public function testBashToolExecutesSuccessfully(): void
    {
        $tool = new Bash();

        $result = $tool->execute(['id' => 'call_bash', 'command' => 'echo "test"']);

        $this->assertSame('call_bash', $result->toolCallId());
        $this->assertNotEmpty($result->content());
        $this->assertFalse($result->isError());
    }

    public function testEditToolExecutesWithValidInput(): void
    {
        $tool = new Edit();
        $tempFile = $this->createTempFile('Hello World');

        $result = $tool->execute([
            'id' => 'call_edit',
            'file_path' => $tempFile,
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        $this->assertSame('call_edit', $result->toolCallId());
        $this->assertStringContainsString('updated', $result->content());
        $this->assertFalse($result->isError());

        unlink($tempFile);
    }

    public function testGlobToolExecutesWithValidPattern(): void
    {
        $tool = new Glob();

        $result = $tool->execute([
            'id' => 'call_glob',
            'pattern' => '*.php',
            'path' => __DIR__,
        ]);

        $this->assertSame('call_glob', $result->toolCallId());
        $this->assertIsString($result->content());
        $this->assertFalse($result->isError());
    }

    public function testGrepToolExecutesWithValidPattern(): void
    {
        $tool = new Grep();

        $result = $tool->execute([
            'id' => 'call_grep',
            'pattern' => 'ToolResult',
            'path' => __DIR__ . '/../../src/Tools',
        ]);

        $this->assertSame('call_grep', $result->toolCallId());
        $this->assertFalse($result->isError());
    }

    public function testWebFetchToolReturnsErrorForNonHttpUrl(): void
    {
        $tool = new WebFetch();

        $result = $tool->execute(['id' => 'call_web', 'url' => 'ftp://example.com']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('http', $result->content());
    }

    /**
     * The real production caller of an image-bearing Tools\ToolResult
     * (W1.G2 reachability fix): SugarCraft\Crush\Runtime::executeToolCalls()
     * calls exactly this method for every 'doctor' ToolCall the live
     * EngineBackend/Runtime/App loop resolves.
     */
    public function testDoctorToolExecutesAndAttachesAnImage(): void
    {
        $tool = new Doctor();

        $result = $tool->execute(['id' => 'call_doctor']);

        $this->assertSame('call_doctor', $result->toolCallId());
        $this->assertFalse($result->isError());
        $this->assertTrue($result->hasImage());
        $this->assertStringStartsWith("\x89PNG", (string) $result->imageBytes());
        $this->assertNotNull($result->imageProtocol());
    }

    public function testDoctorToolProbesTheMosaicCapabilityOnceAcrossCalls(): void
    {
        $tool = new Doctor();

        $first = $tool->execute([]);
        $second = $tool->execute([]);

        $this->assertSame($first->imageProtocol(), $second->imageProtocol());
    }

    // =========================================================================
    // Execute with Invalid Input Tests (Error Cases)
    // =========================================================================

    public function testReadToolReturnsErrorForNonexistentFile(): void
    {
        $tool = new Read();

        $result = $tool->execute(['id' => 'call_err', 'file_path' => '/nonexistent/file/path.txt']);

        $this->assertTrue($result->isError());
        $this->assertNotEmpty($result->content());
    }

    public function testEditToolReturnsErrorForEmptyOldString(): void
    {
        $tool = new Edit();
        $tempFile = $this->createTempFile('content');

        $result = $tool->execute([
            'id' => 'call_empty',
            'file_path' => $tempFile,
            'old_string' => '',
            'new_string' => 'new',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('empty', $result->content());

        unlink($tempFile);
    }

    public function testEditToolReturnsErrorForNonexistentFile(): void
    {
        $tool = new Edit();

        $result = $tool->execute([
            'id' => 'call_missing',
            'file_path' => '/nonexistent/file.txt',
            'old_string' => 'old',
            'new_string' => 'new',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not found', $result->content());
    }

    public function testGrepToolReturnsErrorForEmptyPattern(): void
    {
        $tool = new Grep();

        $result = $tool->execute([
            'id' => 'call_grep_err',
            'pattern' => '',
            'path' => '/tmp',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('empty', $result->content());
    }

    public function testGrepToolReturnsErrorForNonexistentDirectory(): void
    {
        $tool = new Grep();

        $result = $tool->execute([
            'id' => 'call_grep_dir',
            'pattern' => 'test',
            'path' => '/nonexistent/directory',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('directory', $result->content());
    }

    public function testGlobToolReturnsErrorForEmptyPattern(): void
    {
        $tool = new Glob();

        $result = $tool->execute([
            'id' => 'call_glob_err',
            'pattern' => '',
            'path' => '/tmp',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('empty', $result->content());
    }

    public function testGlobToolReturnsErrorForNonexistentDirectory(): void
    {
        $tool = new Glob();

        $result = $tool->execute([
            'id' => 'call_glob_dir',
            'pattern' => '*',
            'path' => '/nonexistent/directory',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('directory', $result->content());
    }

    public function testWebFetchToolReturnsErrorForEmptyUrl(): void
    {
        $tool = new WebFetch();

        $result = $tool->execute(['id' => 'call_url_err', 'url' => '']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('empty', $result->content());
    }

    public function testWebFetchToolReturnsErrorForInvalidUrlScheme(): void
    {
        $tool = new WebFetch();

        $result = $tool->execute(['id' => 'call_scheme_err', 'url' => 'javascript:alert(1)']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('http', $result->content());
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testBashToolWithFailingCommand(): void
    {
        $tool = new Bash();

        $result = $tool->execute(['id' => 'call_fail', 'command' => 'exit 1']);

        $this->assertTrue($result->isError());
    }

    public function testEditToolWithNoMatchInFile(): void
    {
        $tool = new Edit();
        $tempFile = $this->createTempFile('Hello World');

        $result = $tool->execute([
            'id' => 'call_nomatch',
            'file_path' => $tempFile,
            'old_string' => 'NonExistentString',
            'new_string' => 'Replaced',
        ]);

        // A zero-match edit is a failed edit. This previously asserted
        // isError:false because str_replace() no-op'd and file_put_contents()
        // "succeeded" writing identical bytes - which reported success for an
        // edit that never happened.
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('old_string not found', $result->content());
        $this->assertSame('Hello World', file_get_contents($tempFile));

        unlink($tempFile);
    }

    public function testGrepToolWithNoMatches(): void
    {
        $tool = new Grep();
        $tempDir = $this->createTempDir();

        $result = $tool->execute([
            'id' => 'call_nomatch_grep',
            'pattern' => 'THIS_PATTERN_DOES_NOT_EXIST_12345',
            'path' => $tempDir,
        ]);

        // grep returns exit code 1 when no matches, but content may be empty
        $this->assertTrue($result->isError() || $result->content() === '');

        rmdir($tempDir);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createTool(string $className): Tool
    {
        return match ($className) {
            Read::class => new Read(),
            Bash::class => new Bash(),
            Edit::class => new Edit(),
            Grep::class => new Grep(),
            Glob::class => new Glob(),
            WebFetch::class => new WebFetch(),
            WebSearch::class => new WebSearch(),
            Doctor::class => new Doctor(),
            default => throw new \InvalidArgumentException("Unknown tool class: $className"),
        };
    }

    private function createTempFile(string $content): string
    {
        $tempFile = sys_get_temp_dir() . '/test_tool_' . uniqid() . '.txt';
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    private function createTempDir(): string
    {
        $tempDir = sys_get_temp_dir() . '/test_tool_dir_' . uniqid();
        mkdir($tempDir);

        return $tempDir;
    }
}
