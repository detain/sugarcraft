<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\LspTool;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;
use SugarCraft\Crush\Tools\McpToolBridge;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolLimits;

/**
 * Roadmap N-P4c: the tool bounds are settings.
 *
 * Pins the defaults (each key's default IS the constant the tool is built
 * with), the parsing (only a value its schema definition accepts is honoured;
 * anything else leaves the tool as built), the tier (a cap on what reaches the
 * model is never project-settable), the rebind ({@see ToolLimits::applyTo()}
 * replaces exactly the named bound and nothing else), and the wiring: a saved
 * key reaches the tools a launch built through `EngineBackend::turnTools()`,
 * the search tool's constructor, and the interactive-Bash idle ceiling.
 */
final class ToolCapSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_tool_caps_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/work', 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testEveryDefaultIsTheConstantTheToolIsBuiltWith(): void
    {
        self::assertSame(65536, Grep::DEFAULT_MAX_OUTPUT_BYTES);
        self::assertSame(Grep::DEFAULT_MAX_OUTPUT_BYTES, $this->field(new Bash(), 'maxOutputBytes'));
        self::assertSame(McpToolBridge::DEFAULT_MAX_OUTPUT_BYTES, $this->field($this->mcpBridge(), 'maxOutputBytes'));
        self::assertSame(Read::DEFAULT_MAX_BYTES, $this->field(new Read(), 'maxBytes'));
        self::assertSame(Read::PAGE_LINES, $this->field(new Read(), 'pageLines'));
        self::assertSame(Read::PAGE_BYTES, $this->field(new Read(), 'pageByteLimit'));
        self::assertSame(Glob::DEFAULT_MAX_MATCHES, $this->field(new Glob(), 'maxMatches'));
        self::assertSame(WebFetch::MAX_WIRE_BYTES, $this->field(new WebFetch(), 'maxWireBytes'));
        self::assertSame(WebFetch::READ_TIMEOUT_SECONDS, $this->field(new WebFetch(), 'timeoutSeconds'));
        self::assertSame(WebSearch::DEFAULT_MAX_RESULTS, $this->field(new WebSearch(), 'maxResults'));
        self::assertSame(WebSearch::DEFAULT_TIMEOUT_SECONDS, $this->field(new WebSearch(), 'timeout'));

        $expected = [
            ToolLimits::OUTPUT_CAP_KEY => Grep::DEFAULT_MAX_OUTPUT_BYTES,
            ToolLimits::MCP_RESULT_CAP_KEY => McpToolBridge::DEFAULT_MAX_OUTPUT_BYTES,
            ToolLimits::READ_MAX_BYTES_KEY => Read::DEFAULT_MAX_BYTES,
            ToolLimits::READ_PAGE_LINES_KEY => Read::PAGE_LINES,
            ToolLimits::READ_PAGE_BYTES_KEY => Read::PAGE_BYTES,
            ToolLimits::SPILL_WINDOW_PERCENT_KEY => \SugarCraft\Crush\Support\ToolOutputSpill::WINDOW_SHARE_PERCENT,
            ToolLimits::GLOB_MAX_MATCHES_KEY => Glob::DEFAULT_MAX_MATCHES,
            ToolLimits::WEB_FETCH_MAX_BYTES_KEY => WebFetch::MAX_WIRE_BYTES,
            ToolLimits::WEB_FETCH_TIMEOUT_KEY => WebFetch::READ_TIMEOUT_SECONDS,
            ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY => WebSearch::DEFAULT_MAX_RESULTS,
            ToolLimits::WEB_SEARCH_TIMEOUT_KEY => WebSearch::DEFAULT_TIMEOUT_SECONDS,
            ToolLimits::WEB_SEARCH_ENDPOINT_KEY => null,
            ToolLimits::INTERACTIVE_IDLE_KEY => (new \ReflectionClassConstant(Bash::class, 'INTERACTIVE_IDLE_CEILING_SECONDS'))->getValue(),
            ToolLimits::BASH_TIMEOUT_KEY => Bash::DEFAULT_TIMEOUT_SECONDS,
            ToolLimits::BASH_MAX_TIMEOUT_KEY => Bash::MAX_TIMEOUT_SECONDS,
            ToolLimits::PARALLEL_TIMEOUT_KEY => Chat::PARALLEL_TOOL_TIMEOUT_SECONDS,
        ];
        self::assertSame(ToolLimits::KEYS, array_keys($expected));
        foreach ($expected as $key => $default) {
            self::assertSame($default, SettingsSchema::byKey($key)?->default, "{$key}'s default");
        }
    }

    /**
     * A cap on what reaches the model costs money every later request of the
     * turn, so no repository may raise it; timeouts and memory bounds cost
     * time and are project-settable.
     */
    public function testACapOnWhatReachesTheModelIsUserTierOnly(): void
    {
        $spend = [
            ToolLimits::OUTPUT_CAP_KEY,
            ToolLimits::MCP_RESULT_CAP_KEY,
            ToolLimits::READ_MAX_BYTES_KEY,
            ToolLimits::READ_PAGE_LINES_KEY,
            ToolLimits::READ_PAGE_BYTES_KEY,
            ToolLimits::SPILL_WINDOW_PERCENT_KEY,
        ];
        // The endpoint is Egress, pinned by WebSearchEndpointSettingTest.
        foreach (array_diff(ToolLimits::KEYS, [ToolLimits::WEB_SEARCH_ENDPOINT_KEY]) as $key) {
            $definition = SettingsSchema::byKey($key);
            self::assertNotNull($definition);
            self::assertTrue($definition->layered, "{$key} is layered");
            if (\in_array($key, $spend, true)) {
                self::assertSame(RiskClass::Spend, $definition->riskClass, $key);
                self::assertFalse($definition->projectSettable, "{$key} must stay out of a repository's reach");
            } else {
                self::assertSame(RiskClass::Tuning, $definition->riskClass, $key);
                self::assertTrue($definition->projectSettable, $key);
            }
        }

        self::assertSame(ApplyMode::Restart, SettingsSchema::byKey(ToolLimits::WEB_SEARCH_TIMEOUT_KEY)?->applyMode);
        self::assertSame(ApplyMode::NextTurn, SettingsSchema::byKey(ToolLimits::OUTPUT_CAP_KEY)?->applyMode);
    }

    #[DataProvider('parsing')]
    public function testOnlyAValueTheSchemaAcceptsIsHonoured(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, ToolLimits::fromConfig([ToolLimits::OUTPUT_CAP_KEY => $value])->int(ToolLimits::OUTPUT_CAP_KEY));
    }

    /** @return array<string, array{0: mixed, 1: ?int}> */
    public static function parsing(): array
    {
        return [
            'in range' => [32768, 32768],
            'the floor' => [4096, 4096],
            'the ceiling' => [1048576, 1048576],
            'integral float' => [8192.0, 8192],
            'below the floor' => [4095, null],
            'above the ceiling' => [1048577, null],
            'fractional' => [8192.5, null],
            'numeric string' => ['8192', null],
            'zero is not "unbounded"' => [0, null],
            'negative' => [-1, null],
            'bool' => [true, null],
            'list' => [[8192], null],
            'infinite' => [INF, null],
            'null' => [null, null],
        ];
    }

    public function testNothingSetLeavesEveryToolAsItWasBuilt(): void
    {
        $limits = ToolLimits::fromConfig([]);
        foreach ($this->tools() as $tool) {
            self::assertSame($tool, $limits->applyTo($tool), $tool->name());
        }
    }

    public function testTheOutputCapRebindsEveryToolThatTakesIt(): void
    {
        $limits = ToolLimits::fromConfig([ToolLimits::OUTPUT_CAP_KEY => 8192]);

        foreach ([new Bash($this->dir), new Grep($this->dir), new Glob($this->dir), new LspTool(), new WebFetch()] as $tool) {
            $bound = $limits->applyTo($tool);
            self::assertNotSame($tool, $bound, $tool->name());
            self::assertSame(8192, $this->field($bound, 'maxOutputBytes'), $tool->name());
            self::assertSame(Grep::DEFAULT_MAX_OUTPUT_BYTES, $this->field($tool, 'maxOutputBytes'), 'the launch-built tool is untouched');
        }

        // Bash says the figure it is held to, so the model sees the new cap.
        self::assertStringContainsString('8,192', $limits->applyTo(new Bash($this->dir))->description());

        // Read's bound is its own key; MCP has its own; Edit has no result cap.
        foreach ([new Read($this->dir), $this->mcpBridge(), new Edit($this->dir)] as $tool) {
            self::assertSame($tool, $limits->applyTo($tool), $tool->name());
        }
    }

    public function testTheRebindKeepsEveryOtherField(): void
    {
        $bash = (new Bash($this->dir))->withGitGuidance(false, 'Trailer: x');
        $bound = ToolLimits::fromConfig([ToolLimits::OUTPUT_CAP_KEY => 8192])->applyTo($bash);

        foreach (get_object_vars($this->fields($bash)) as $name => $value) {
            if ($name !== 'maxOutputBytes') {
                self::assertSame($value, $this->field($bound, $name), $name);
            }
        }
    }

    public function testTheMcpCapIsItsOwnKey(): void
    {
        $bridge = $this->mcpBridge();

        self::assertSame($bridge, ToolLimits::fromConfig([ToolLimits::OUTPUT_CAP_KEY => 8192])->applyTo($bridge));
        self::assertSame(
            16384,
            $this->field(ToolLimits::fromConfig([ToolLimits::MCP_RESULT_CAP_KEY => 16384])->applyTo($bridge), 'maxOutputBytes'),
        );
    }

    public function testReadTakesItsBoundAndPageAndPagesByThem(): void
    {
        file_put_contents($this->dir . '/work/long.txt', implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 50))) . "\n");
        $read = ToolLimits::fromConfig([ToolLimits::READ_PAGE_LINES_KEY => 10, ToolLimits::READ_PAGE_BYTES_KEY => 2048])
            ->applyTo(new Read($this->dir . '/work'));

        self::assertInstanceOf(Read::class, $read);
        self::assertSame(Read::DEFAULT_MAX_BYTES, $this->field($read, 'maxBytes'), 'an unset bound is kept');
        self::assertStringContainsString('at most 10 lines and 2,048 bytes', $read->description());
        self::assertStringContainsString('(default 10)', $read->inputSchema()['properties']['limit']['description']);

        $page = $read->execute(['file_path' => $this->dir . '/work/long.txt', 'description' => 'page']);
        self::assertFalse($page->isError(), $page->content());
        self::assertStringContainsString('10: line 10', $page->content());
        self::assertStringNotContainsString('11: line 11', $page->content());
        self::assertStringContainsString('offset=11', $page->content());

        $bounded = ToolLimits::fromConfig([ToolLimits::READ_MAX_BYTES_KEY => 4096])->applyTo(new Read());
        self::assertSame(4096, $this->field($bounded, 'maxBytes'));
    }

    public function testGlobStopsAtTheConfiguredCount(): void
    {
        for ($i = 0; $i < 25; $i++) {
            touch(sprintf('%s/work/f%02d.txt', $this->dir, $i));
        }
        $glob = ToolLimits::fromConfig([ToolLimits::GLOB_MAX_MATCHES_KEY => 10])->applyTo(new Glob($this->dir . '/work'));

        self::assertSame(10, $this->field($glob, 'maxMatches'));
        $result = $glob->execute(['pattern' => '*.txt', 'description' => 'list']);
        self::assertSame(10, substr_count($result->content(), '.txt'), $result->content());
    }

    public function testWebFetchTakesItsTimeoutAndMemoryBound(): void
    {
        $fetch = ToolLimits::fromConfig([ToolLimits::WEB_FETCH_TIMEOUT_KEY => 5, ToolLimits::WEB_FETCH_MAX_BYTES_KEY => 65536])
            ->applyTo(new WebFetch(maxOutputBytes: 4096, saveOverflow: false));

        self::assertSame(5, $this->field($fetch, 'timeoutSeconds'));
        self::assertSame(65536, $this->field($fetch, 'maxWireBytes'));
        self::assertSame(4096, $this->field($fetch, 'maxOutputBytes'), 'the result cap is its own key');
        self::assertFalse($this->field($fetch, 'saveOverflow'));
    }

    public function testTheWithersRefuseABoundThatWouldMeanUnbounded(): void
    {
        foreach ([
            static fn () => (new Read())->withReadLimits(pageLines: 0),
            static fn () => (new Glob())->withMaxMatches(0),
            static fn () => (new WebFetch())->withFetchLimits(timeoutSeconds: 0),
        ] as $wither) {
            try {
                $wither();
                self::fail('a bound of 0 was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWebSearchReadsItsLimitsAtConstructionAndAnArgumentWins(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY => 3, ToolLimits::WEB_SEARCH_TIMEOUT_KEY => 7]);

        self::assertSame(3, $this->field(new WebSearch(), 'maxResults'));
        self::assertSame(7, $this->field(new WebSearch(), 'timeout'));
        self::assertSame(20, $this->field(new WebSearch(maxResults: 20), 'maxResults'));
        self::assertSame(7, $this->field(new WebSearch(maxResults: 20), 'timeout'));
    }

    public function testTheProcessWaitBoundsAreReadThroughTheMergedConfig(): void
    {
        self::assertNull(ToolLimits::current()->seconds(ToolLimits::INTERACTIVE_IDLE_KEY));

        Bootstrap::writeUserConfig([ToolLimits::INTERACTIVE_IDLE_KEY => 1.5, ToolLimits::PARALLEL_TIMEOUT_KEY => 90]);

        self::assertSame(1.5, ToolLimits::current()->seconds(ToolLimits::INTERACTIVE_IDLE_KEY));
        self::assertSame(90, ToolLimits::current()->int(ToolLimits::PARALLEL_TIMEOUT_KEY));
    }

    /** The idle ceiling really is the setting: a silent program ends at 1 s, not the shipped 8. */
    public function testAnInteractiveRunEndsAtTheConfiguredIdleCeiling(): void
    {
        if (!ProcessContainment::interactiveAvailable()) {
            self::markTestSkipped('no pty mechanism on this host');
        }

        Bootstrap::writeUserConfig([ToolLimits::INTERACTIVE_IDLE_KEY => 1]);

        $started = microtime(true);
        $result = (new Bash($this->dir))->execute([
            'command' => 'sleep 30',
            'description' => 'Wait silently',
            'interactive' => true,
            'timeout' => 60,
        ]);
        $elapsed = microtime(true) - $started;

        self::assertTrue($result->isError(), $result->content());
        self::assertLessThan(6.0, $elapsed, 'the run outlived a 1-second idle ceiling');
    }

    /** End to end: a saved key reaches the tool schema the provider is sent. */
    public function testASavedLimitReachesTheToolsATurnIsSent(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::READ_PAGE_LINES_KEY => 123]);
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);
        $read = new Read($this->dir);

        EngineBackend::new($provider, 'm')->withTools([$read])->complete([Message::user('hi')]);

        $sent = $provider->requests[0]->tools ?? [];
        self::assertCount(1, $sent);
        self::assertInstanceOf(Read::class, $sent[0]);
        self::assertStringContainsString('at most 123 lines', $sent[0]->description());
        self::assertSame(Read::PAGE_LINES, $this->field($read, 'pageLines'), 'the engine\'s own tool is untouched');
    }

    /** @return list<Tool> */
    private function tools(): array
    {
        return [
            new Bash($this->dir),
            new Grep($this->dir),
            new Glob($this->dir),
            new Read($this->dir),
            new LspTool(),
            new WebFetch(),
            new WebSearch(),
            new Edit($this->dir),
            $this->mcpBridge(),
        ];
    }

    private function mcpBridge(): McpToolBridge
    {
        return new McpToolBridge(
            new McpClient('/nonexistent/caps.json'),
            McpTool::fromArray(['name' => 'dump'], 'db'),
        );
    }

    private function field(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    private function fields(object $object): object
    {
        $fields = new \stdClass();
        foreach ((new \ReflectionObject($object))->getProperties() as $property) {
            if (!$property->isStatic()) {
                $fields->{$property->getName()} = $property->getValue($object);
            }
        }

        return $fields;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
