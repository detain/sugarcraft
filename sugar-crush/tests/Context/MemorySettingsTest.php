<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Context\SymbolMapBlock;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Memory\AutoMemoryConsolidator;
use SugarCraft\Crush\Memory\DreamPass;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap N-P4d: the memory constants as settings — the prompt index caps
 * (40 notes / 4 KiB / 512 B per line, the user-scope 4 / 1 KiB inside them),
 * the auto-memory switch, the dream pass's interval and the symbol-map
 * toggle. Every default is the constant it replaced.
 */
final class MemorySettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    private MemoryStore $store;

    private string|false $autoMemoryEnv;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_memsettings_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/store', 0o700, true);
        $this->useHomeSandbox($this->dir . '/home');
        $this->store = new MemoryStore($this->dir . '/store');
        $this->autoMemoryEnv = getenv(AutoMemoryConsolidator::ENV_DISABLE);
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
    }

    protected function tearDown(): void
    {
        $this->autoMemoryEnv === false
            ? putenv(AutoMemoryConsolidator::ENV_DISABLE)
            : putenv(AutoMemoryConsolidator::ENV_DISABLE . '=' . $this->autoMemoryEnv);
        $this->restoreHomeSandbox();
        $this->rmrf($this->dir);
    }

    // =====================================================================
    // The index caps
    // =====================================================================

    public function testTheDefaultCapsAreTheConstants(): void
    {
        $block = MemoryBlock::capture($this->store);

        $this->assertSame([
            'maxEntries' => MemoryBlock::MAX_ENTRIES,
            'maxBytes' => MemoryBlock::MAX_BYTES,
            'maxEntryBytes' => MemoryBlock::MAX_ENTRY_BYTES,
            'userMaxEntries' => MemoryBlock::USER_MAX_ENTRIES,
            'userMaxBytes' => MemoryBlock::USER_MAX_BYTES,
        ], $block->limits());
        $this->assertSame($block, $block->withSettings([]), 'no settings is the same block');
    }

    public function testTheNoteCountCapIsASetting(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->store->add("project note {$i}", MemoryScope::Project);
        }

        $index = MemoryBlock::capture($this->store)->withSettings([MemoryBlock::SETTING_MAX_ENTRIES => 2])->index();

        $this->assertSame(2, preg_match_all('/^- \[/m', $index));
        $this->assertStringContainsString('At most 2 notes and 4096 bytes', $index, 'the header states the cap that applied');
        $this->assertStringContainsString('4 further note(s) were omitted', $index);
    }

    public function testTheUserSubCapsAreSettings(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->store->add("personal note {$i}", MemoryScope::User);
        }
        $this->store->add('project note', MemoryScope::Project);

        $index = MemoryBlock::capture($this->store)->withSettings([MemoryBlock::SETTING_USER_MAX_ENTRIES => 1])->index();

        $this->assertSame(1, preg_match_all('/personal note \d/', $index));
        $this->assertStringContainsString('project note', $index);
    }

    public function testThePerLineCapIsASetting(): void
    {
        $this->store->add(str_repeat('word ', 40), MemoryScope::Project, array_map(static fn (int $i): string => "tag{$i}", range(1, 40)));

        $index = MemoryBlock::capture($this->store)->withSettings([MemoryBlock::SETTING_MAX_ENTRY_BYTES => 100])->index();

        $this->assertSame(1, preg_match('/^(- \[.*)$/m', $index, $m));
        $this->assertSame(100, \strlen($m[1]), 'the line is cut to the configured ceiling, marker included');
        $this->assertStringEndsWith(' […truncated]', $m[1]);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, int>}> */
    public static function nestedSettings(): iterable
    {
        yield 'a line over the user budget is lowered to it' => [[MemoryBlock::SETTING_MAX_ENTRY_BYTES => 2048], ['maxEntryBytes' => 1024]];
        yield 'a user budget over the total is lowered to it' => [[MemoryBlock::SETTING_USER_MAX_BYTES => 8192], ['userMaxBytes' => 4096]];
        yield 'fewer notes than user notes lowers the user count' => [[MemoryBlock::SETTING_MAX_ENTRIES => 3], ['maxEntries' => 3, 'userMaxEntries' => 3]];
        yield 'a smaller total lowers both sub-budgets' => [[MemoryBlock::SETTING_MAX_BYTES => 300], ['maxBytes' => 300, 'userMaxBytes' => 300, 'maxEntryBytes' => 300]];
        yield 'a string is ignored' => [[MemoryBlock::SETTING_MAX_ENTRIES => '10'], []];
        yield 'zero is ignored' => [[MemoryBlock::SETTING_MAX_BYTES => 0], []];
        yield 'a line too short for its marker leaves every default' => [[MemoryBlock::SETTING_MAX_ENTRY_BYTES => 5, MemoryBlock::SETTING_MAX_ENTRIES => 9], []];
    }

    /**
     * @dataProvider nestedSettings
     *
     * @param array<string, mixed> $settings
     * @param array<string, int>   $changed  the limits that differ from the defaults
     */
    public function testSubCapsAreLoweredToNestAndNonsenseIsIgnored(array $settings, array $changed): void
    {
        $block = MemoryBlock::capture($this->store);

        $this->assertSame([...$block->limits(), ...$changed], $block->withSettings($settings)->limits());
    }

    public function testWithLimitsRefusesCapsThatDoNotNest(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MemoryBlock::capture($this->store)->withLimits(40, 4096, 2048, 4, 1024);
    }

    /**
     * Through the real reader: the turn's Runtime lays the `config.json`
     * caps over the session's captured notes.
     */
    public function testTheTurnsRuntimeReadsTheCapsFromTheSettings(): void
    {
        Bootstrap::writeUserConfig([MemoryBlock::SETTING_MAX_ENTRIES => 5, MemoryBlock::SETTING_MAX_BYTES => 2048]);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'm')->withMemoryStore($this->store);

        $method = new \ReflectionMethod(Runtime::class, 'memorySnapshot');
        $block = $method->invoke($runtime, $app);

        $this->assertSame(5, $block->limits()['maxEntries']);
        $this->assertSame(2048, $block->limits()['maxBytes']);
        $this->assertSame($block, $method->invoke($runtime, $app), 'still one memoized block per Runtime');
    }

    // =====================================================================
    // Auto-memory, the dream pass, the symbol map
    // =====================================================================

    public function testAutoMemoryIsOnUnlessTheSettingOrTheEnvironmentSaysOff(): void
    {
        $this->assertTrue(AutoMemoryConsolidator::enabled([]));
        $this->assertFalse(AutoMemoryConsolidator::enabled([AutoMemoryConsolidator::SETTING => false]));
        $this->assertTrue(AutoMemoryConsolidator::enabled([AutoMemoryConsolidator::SETTING => 'no']), 'only an explicit false turns it off');

        putenv(AutoMemoryConsolidator::ENV_DISABLE . '=1');
        $this->assertFalse(AutoMemoryConsolidator::enabled([AutoMemoryConsolidator::SETTING => true]), 'the environment outranks the file');
    }

    public function testAutoMemoryReadsTheWrittenSetting(): void
    {
        $this->assertTrue(AutoMemoryConsolidator::enabled());
        Bootstrap::writeUserConfig([AutoMemoryConsolidator::SETTING => false]);
        $this->assertFalse(AutoMemoryConsolidator::enabled());
    }

    public function testTheDreamIntervalIsASetting(): void
    {
        $pass = DreamPass::new(MemoryWriter::new($this->store, $this->dir));

        $this->assertSame(DreamPass::INTERVAL_SECONDS, $pass->interval([]));
        $this->assertSame(600, $pass->interval([DreamPass::SETTING_INTERVAL => 600]));
        $this->assertSame(DreamPass::INTERVAL_SECONDS, $pass->interval([DreamPass::SETTING_INTERVAL => 5]), 'under a minute is ignored');
        $this->assertSame(30, $pass->withInterval(30)->interval([DreamPass::SETTING_INTERVAL => 600]), 'a pinned interval wins');

        Bootstrap::writeUserConfig([DreamPass::SETTING_INTERVAL => 900]);
        $this->assertSame(900, $pass->interval());
    }

    public function testTheSymbolMapCanBeTurnedOffInTheSettings(): void
    {
        $this->assertFalse(SymbolMapBlock::disabledBySettings([]));
        $this->assertFalse(SymbolMapBlock::disabledBySettings([SymbolMapBlock::SETTING => 'off']), 'only an explicit false');
        $this->assertTrue(SymbolMapBlock::disabledBySettings([SymbolMapBlock::SETTING => false]));

        Bootstrap::writeUserConfig([SymbolMapBlock::SETTING => false]);
        $block = SymbolMapBlock::capture($this->dir);
        $this->assertSame('', $block->render());
        $this->assertStringContainsString(SymbolMapBlock::SETTING, $block->skipReason());
    }

    // =====================================================================
    // The schema
    // =====================================================================

    public function testTheSchemaRowsCarryTheConstantsAsDefaults(): void
    {
        $expected = [
            MemoryBlock::SETTING_MAX_ENTRIES => 40,
            MemoryBlock::SETTING_MAX_BYTES => 4096,
            MemoryBlock::SETTING_MAX_ENTRY_BYTES => 512,
            MemoryBlock::SETTING_USER_MAX_ENTRIES => 4,
            MemoryBlock::SETTING_USER_MAX_BYTES => 1024,
            AutoMemoryConsolidator::SETTING => true,
            DreamPass::SETTING_INTERVAL => 7200,
            SymbolMapBlock::SETTING => true,
        ];

        foreach ($expected as $key => $default) {
            $definition = SettingsSchema::byKey($key);
            $this->assertNotNull($definition, "{$key} has a schema row");
            $this->assertSame($default, $definition->default, "{$key}'s default");
            $this->assertFalse($definition->layered, "{$key} is read from config.json only");
            $this->assertFalse($definition->projectSettable);
        }

        $this->assertSame(ApplyMode::NextTurn, SettingsSchema::byKey(MemoryBlock::SETTING_MAX_ENTRIES)?->applyMode);
        $this->assertSame(AutoMemoryConsolidator::ENV_DISABLE, SettingsSchema::byKey(AutoMemoryConsolidator::SETTING)?->envVar);
        $this->assertSame(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV, SettingsSchema::byKey(SymbolMapBlock::SETTING)?->envVar);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
