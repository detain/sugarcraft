<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\ParsedArgs;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Providers\ModelCatalog;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * PRV-1: the `models all` operand expands each provider row with the merged
 * model catalog (configured model, then the live /models list, then the LiteLLM
 * table), while plain `models` keeps its pre-PRV-1 bytes exactly.
 *
 * THE OPERAND FORM, not the `--all` FLAG: SessionSubcommandsTest pins that
 * `models --all` answers with `--all` in unknownFlags — the verb owns no flags
 * and that roster is frozen for this wave. The catalog therefore rides the
 * verb's own operand; the pin's counterpart (a plain listing when the flag
 * never reaches subcommandArgs) is asserted here so both halves of the shape
 * stay locked.
 *
 * The suite runs with CANDY_CODE_DISABLE_MODEL_METADATA (tests/bootstrap.php),
 * and that opt-out now holds back the WHOLE discovery family — the cached
 * table and the live /models listing alike (ModelCatalog::prefetch and the
 * CLI's expanded listing both consult it). So even when the developer's shell
 * carries an OPENAI_API_KEY that availableProviders() threads into the config
 * under test, a green run over these tests spends zero bytes and touches zero
 * network.
 */
final class SubcommandsModelsCatalogTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;

    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/models_catalog_' . uniqid('', true);
        mkdir($this->tempDir . '/home/.candy-code', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';

        $this->clearBackendSelectionEnv();
        Bootstrap::useModel(null);
        Bootstrap::useConfigPath(null);
        ModelCatalog::resetForTesting();
    }

    protected function tearDown(): void
    {
        ModelCatalog::resetForTesting();
        Bootstrap::useModel(null);
        Bootstrap::useConfigPath(null);

        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->restoreBackendSelectionEnv();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testThePlainListingCarriesNoCatalogTier(): void
    {
        $this->writeConfig(['providers' => ['openai' => ['type' => 'openai', 'model' => 'gpt-sandboxed']]]);

        $document = $this->runJson(['candy-code', 'models', '--output-format', 'json']);
        $rows = $document['result']['providers'];

        self::assertIsArray($rows);
        foreach ($rows as $row) {
            // The pre-PRV-1 row shape stays byte-for-byte: adding a key only
            // under the operand is what keeps every existing reader intact.
            self::assertSame(['provider', 'model', 'selected'], array_keys($row));
        }
    }

    public function testTheAllOperandExpandsEveryRowWithTheCatalog(): void
    {
        $this->writeConfig(['providers' => ['openai' => ['type' => 'openai', 'model' => 'gpt-sandboxed']]]);

        $document = $this->runJson(['candy-code', 'models', 'all', '--output-format', 'json']);
        $rows = $document['result']['providers'];
        self::assertIsArray($rows);

        $byProvider = [];
        foreach ($rows as $row) {
            $byProvider[$row['provider']] = $row;
        }

        self::assertArrayHasKey('catalog', $byProvider['openai']);
        // Configured first; with the table off and no key to spend, the
        // catalog tier is exactly the configured id — the shape, pinned.
        self::assertSame(['gpt-sandboxed'], $byProvider['openai']['catalog']);

        foreach ($rows as $row) {
            self::assertIsList($row['catalog']);
            if ($row['model'] !== 'unknown') {
                self::assertSame($row['model'], $row['catalog'][0], 'the configured model leads its own catalog');
            }
        }
    }

    public function testTheTextListingPrintsCatalogRowsUnderTheirProvider(): void
    {
        $this->writeConfig(['providers' => ['openai' => ['type' => 'openai', 'model' => 'gpt-sandboxed']]]);

        $plain = $this->runText(['candy-code', 'models']);
        $expanded = $this->runText(['candy-code', 'models', 'all']);

        self::assertStringNotContainsString('gpt-sandboxed, ', $plain . "\n");
        // The openai provider line keeps its shape; the catalog rides the
        // indented continuation beneath it, leading with the configured id.
        self::assertMatchesRegularExpression('/openai\s+gpt-sandboxed\n\s+gpt-sandboxed\n/', $expanded);
    }

    public function testTheAllFlagFormStillBelongsToTheUnknownFlagLayer(): void
    {
        // The parser half is pinned by SessionSubcommandsTest; this is the
        // behaviour that follows: dispatched in-process (bin would have exited
        // 2 before reaching here) the verb sees no operands at all and answers
        // exactly the plain listing — the flag neither expands nor errors.
        $args = ArgvParser::parse(['candy-code', 'models', '--all']);
        self::assertSame([], $args->subcommandArgs);
        self::assertSame(['--all'], $args->unknownFlags);

        $this->writeConfig(['providers' => ['openai' => ['type' => 'openai', 'model' => 'gpt-sandboxed']]]);

        $document = $this->runJson(['candy-code', 'models', '--all', '--output-format', 'json']);
        foreach ($document['result']['providers'] as $row) {
            self::assertSame(['provider', 'model', 'selected'], array_keys($row));
        }
    }

    public function testAnyOtherOperandShapeIsStillAUsageDoor(): void
    {
        $this->writeConfig(['providers' => ['openai' => ['type' => 'openai', 'model' => 'gpt-sandboxed']]]);

        // `models delete everything` stayed the original bug's guard, and
        // `all` does not compose with anything else.
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->runTextExit(['candy-code', 'models', 'delete', 'everything']));
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->runTextExit(['candy-code', 'models', 'all', 'again']));
    }

    public function testTheModelsVerbOwnsNoFlagsAndThatFreezeIsLoadBearing(): void
    {
        // The design decision, pinned structurally: models() answers through
        // its OPERAND because SUBCOMMAND_FLAGS stays without a 'models' entry.
        // If a later wave grants the verb `--all`, this arm goes red with the
        // parser pin in SessionSubcommandsTest and forces both to move as one.
        self::assertArrayNotHasKey('models', ParsedArgs::SUBCOMMAND_FLAGS);
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function runJson(array $argv): array
    {
        $args = ArgvParser::parse($argv);
        self::assertNull($args->usageError);

        \ob_start();
        try {
            $status = Subcommands::dispatch($args);
        } finally {
            $captured = (string) \ob_get_clean();
        }

        self::assertSame(NonInteractive::EXIT_OK, $status);
        $document = json_decode($captured, true);
        self::assertIsArray($document, 'expected a JSON document, got: ' . $captured);

        return $document;
    }

    /** @param list<string> $argv */
    private function runText(array $argv): string
    {
        $args = ArgvParser::parse($argv);
        self::assertNull($args->usageError);

        \ob_start();
        try {
            $status = Subcommands::dispatch($args);
        } finally {
            $captured = (string) \ob_get_clean();
        }

        self::assertSame(NonInteractive::EXIT_OK, $status);

        return $captured;
    }

    /** @param list<string> $argv */
    private function runTextExit(array $argv): int
    {
        $args = ArgvParser::parse($argv);

        \ob_start();
        try {
            return Subcommands::dispatch($args);
        } finally {
            \ob_end_clean();
        }
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        file_put_contents($this->tempDir . '/home/.candy-code/config.json', (string) json_encode($config));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
