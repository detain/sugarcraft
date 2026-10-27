<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use DirectoryIterator;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\DumpsSystemPrompt;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\ContextHostCommand;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * GAP-PRM-15: `/context --raw [path]` hands over the request's exact bytes —
 * the assembled system prompt and the tool schemas — as files, under the
 * SAME target rules `/export` follows (one shelf, one jail), atomically at
 * 0600, and it never prints the prompt body into the transcript.
 *
 * The pins are the claims the feature makes: the files are owner-only (mode
 * 0600 — an assembled prompt is instruction text with the operator's own
 * paths and memory notes in it, nothing to leave group- or world-readable),
 * a `../` name is refused by the jail before a byte is written, the dumped
 * prompt is byte-identical to what the `/context` panel prices (its length
 * IS the sum of the section-row bytes — one fold behind both), and a backend
 * without the {@see DumpsSystemPrompt} capability is refused BY NAME instead
 * of answered with a dump invented around it.
 */
final class ContextRawDumpTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-ctxraw-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /** The default shelf dump: both files exist, both are 0600, the reply names paths and sizes only. */
    public function testTheDefaultDumpWritesBothFilesOwnerOnly(): void
    {
        $result = (new ContextHostCommand())->run($this->engineContext(), '/context --raw');
        self::assertFalse($result->isRefused());
        $reply = self::content($result);

        self::assertStringContainsString('/.candy-code/exports/prompt-', $reply, 'the shelf name carries the prompt- prefix');
        self::assertStringContainsString('.json', $reply);
        self::assertStringContainsString('.txt', $reply);

        $exports = $this->home . '/.candy-code/exports';
        $json = $this->onlySuffixed($exports, '.json');
        $txt = $this->onlySuffixed($exports, '.txt');

        self::assertSame(0600, \fileperms($json) & 0777, 'an assembled prompt is the user\'s own bytes, owner-read only');
        self::assertSame(0600, \fileperms($txt) & 0777);
        self::assertStringStartsWith('prompt-', basename($json));
        self::assertStringStartsWith('prompt-', basename($txt));

        // The sizes the reply quotes are the files' sizes.
        self::assertStringContainsString('(' . \strlen((string) file_get_contents($json)) . ' bytes)', $reply);
        self::assertStringContainsString('(' . (string) \filesize($txt) . ' bytes of prompt text)', $reply);

        // And no slice of the prompt body rides the reply row that named them.
        $body = (string) file_get_contents($txt);
        self::assertNotSame('', $body);
        self::assertStringNotContainsString(substr($body, 0, 60), $reply, 'the dump is the file, the transcript keeps only the pointer');
    }

    /** A `../` name misses both jail roots and is refused before a byte is written. */
    public function testAnEscapeNameIsRefusedByTheJail(): void
    {
        $result = (new ContextHostCommand())->run($this->engineContext(), '/context --raw ../escaped-prompt');
        $reply = self::content($result);

        self::assertStringContainsString('escaped-prompt', $reply, 'the refusal names what was refused');
        self::assertStringNotContainsString('Prompt dumped', $reply);
        self::assertFileDoesNotExist(\dirname($this->project) . '/escaped-prompt.json');
        self::assertFileDoesNotExist(\dirname($this->project) . '/escaped-prompt.txt');
        self::assertDirectoryDoesNotExist($this->home . '/.candy-code/exports', 'a refusal writes nothing, not even a shelf');
    }

    /** The dumped prompt is the priced prompt: its bytes are the sum of the section rows, exactly. */
    public function testTheDumpedPromptIsThePricedPromptByteForByte(): void
    {
        $context = $this->engineContext();
        $backend = $context->backend;
        self::assertInstanceOf(EngineBackend::class, $backend);

        $result = (new ContextHostCommand())->run($context, '/context --raw out/prompt');
        self::assertStringContainsString('Prompt dumped', self::content($result));

        $jsonFile = $this->project . '/out/prompt.json';
        self::assertFileExists($jsonFile);
        $decoded = json_decode((string) file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertSame(
            \strlen((string) $decoded['prompt']),
            array_sum(array_column($backend->promptSectionSizes(), 'bytes')),
            'one fold behind both — the dump cannot fork the panel',
        );

        // The .txt sibling is the prompt body alone, and the JSON tools
        // mirror the turn's tool list in wire shape.
        self::assertSame($decoded['prompt'], (string) file_get_contents($this->project . '/out/prompt.txt'));
        self::assertNotEmpty($decoded['tools']);
        foreach ($decoded['tools'] as $tool) {
            self::assertSame(['name', 'description', 'input_schema'], array_keys($tool));
        }
    }

    /** The suffix the user spelled is read as the stem's, never doubled. */
    public function testASpelledSuffixIsReadAsTheStem(): void
    {
        (new ContextHostCommand())->run($this->engineContext(), '/context --raw notes.json');
        self::assertFileExists($this->project . '/notes.json');
        self::assertFileExists($this->project . '/notes.txt');
        self::assertFileDoesNotExist($this->project . '/notes.json.json');
    }

    /** A backend without the capability is refused BY NAME — no bytes are invented around it. */
    public function testANonDumpingBackendIsRefusedByName(): void
    {
        $context = CommandContext::new(root: $this->project, backend: new EchoBackend());
        $result = (new ContextHostCommand())->run($context, '/context --raw');
        $reply = self::content($result);

        self::assertStringContainsString('EchoBackend', $reply, 'the refusal names the door that is missing');
        self::assertStringContainsString('Cannot dump', $reply);
        self::assertDirectoryDoesNotExist($this->home . '/.candy-code/exports');
    }

    /** Two names are a usage refusal, not a silent first-wins. */
    public function testTwoPathsAreRefusedAsBadArguments(): void
    {
        $result = (new ContextHostCommand())->run($this->engineContext(), '/context --raw one two');
        self::assertStringContainsString('at most one file name', self::content($result));
    }

    /**
     * The capability is opt-in and carried by the engine alone: the roster is
     * every class in src/Backend that implements {@see DumpsSystemPrompt}, so
     * a second implementor is a decision that must land here saying so.
     */
    public function testTheCapabilityIsCarriedByTheEngineAlone(): void
    {
        $implementors = [];
        foreach (new DirectoryIterator(__DIR__ . '/../../../src/Backend') as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $class = 'SugarCraft\\Code\\Backend\\' . $file->getBasename('.php');
            if (class_exists($class) && is_subclass_of($class, DumpsSystemPrompt::class)) {
                $implementors[] = $file->getBasename('.php');
            }
        }

        self::assertSame(['EngineBackend'], $implementors);
    }

    private function engineContext(): CommandContext
    {
        $tool = new class implements Tool {
            public function name(): string
            {
                return 'ctxraw_probe_tool';
            }

            public function description(): string
            {
                return 'Seeds the dumped schema list so the wire shape is pinned, never vacuous.';
            }

            /** @return array<mixed> */
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['note' => ['type' => 'string']]];
            }

            public function execute(array $input): ToolResult
            {
                return new ToolResult(toolCallId: 'ctxraw', content: 'probe');
            }
        };

        return CommandContext::new(
            root: $this->project,
            backend: EngineBackend::new(new ScriptedProvider([]), 'm')->withoutHooks()->withRoot($this->project)->withTools([$tool]),
        );
    }

    private function onlySuffixed(string $directory, string $suffix): string
    {
        $hits = array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $f): bool => str_ends_with($f, $suffix),
        ));
        self::assertCount(1, $hits, "exactly one {$suffix} file on the shelf");

        return rtrim($directory, '/') . '/' . $hits[0];
    }

    /** The reply row's text — the last row every arm of this command ends with. */
    private static function content(CommandResult $result): string
    {
        return $result->rows[\count($result->rows) - 1]->content;
    }
}
